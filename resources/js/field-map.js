/*
 * Maps of the field work (ФО §6.11, ADR-013). One module for the three places a map is shown: the overview
 * (houses coloured by the progress of the canvass, geozones, people and vehicles), the page of a geozone (its
 * outline, with an editor for those who manage it) and the page of a house. The page gives the data it was
 * allowed to get; the map draws it and decides nothing.
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const progressColor = (pct) => (pct >= 100 ? '#16a34a' : pct >= 50 ? '#3b82f6' : pct > 0 ? '#f59e0b' : '#94a3b8');
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const wire = (el) => window.Livewire?.find(el.closest('[wire\\:id]')?.getAttribute('wire:id'));
const dot = (className, text = '') => L.divIcon({ className: '', html: `<span class="bz-dot ${className}">${esc(text)}</span>`, iconSize: [26, 26], iconAnchor: [13, 13] });

function mount(el) {
    if (el.dataset.mounted) {
        return;
    }
    el.dataset.mounted = '1';
    const config = JSON.parse(el.dataset.config);
    const labels = config.labels ?? {};
    const map = L.map(el, { scrollWheelZoom: true }).setView(config.tiles.center, config.tiles.zoom);
    if (config.tiles.url) {
        L.tileLayer(config.tiles.url, { attribution: config.tiles.attribution, maxZoom: 19 }).addTo(map);
    }
    const bounds = L.latLngBounds([]);
    const layers = {};

    // Geozones.
    const zones = L.layerGroup().addTo(map);
    (config.zones ?? []).forEach((zone) => {
        const shape = L.geoJSON(zone.geometry, { style: { color: zone.color, weight: 2, fillOpacity: 0.12 } }).addTo(zones);
        shape.bindPopup(zone.url ? `<a href="${esc(zone.url)}"><b>${esc(zone.name)}</b></a>` : `<b>${esc(zone.name)}</b>`);
        bounds.extend(shape.getBounds());
    });
    if ((config.zones ?? []).length) {
        layers[labels.zones ?? 'Zones'] = zones;
    }

    // Houses: the colour is the share of flats visited.
    const houses = L.layerGroup().addTo(map);
    (config.houses ?? []).forEach((house) => {
        const marker = L.circleMarker([house.lat, house.lng], { radius: 9, color: '#0f172a', weight: 1, fillColor: progressColor(house.visited_pct), fillOpacity: 0.95 }).addTo(houses);
        const title = house.url ? `<a href="${esc(house.url)}"><b>${esc(house.label)}</b></a>` : `<b>${esc(house.label)}</b>`;
        marker.bindPopup(`${title}<br>${esc(labels.apartments)}: ${house.apartments}<br>${esc(labels.visited)}: ${house.visited_pct}%<br>${esc(labels.supporters)}: ${house.supporter_pct}%`);
        bounds.extend([house.lat, house.lng]);
    });
    if ((config.houses ?? []).length) {
        layers[labels.houses ?? 'Houses'] = houses;
    }

    // People who share their location, and vehicles: redrawn whenever the page sends fresh positions.
    const movers = L.layerGroup().addTo(map);
    const drawMovers = (people, vehicles) => {
        movers.clearLayers();
        (people ?? []).forEach((person) => {
            L.marker([person.lat, person.lng], { icon: dot(person.own ? 'bz-own' : 'bz-person', person.name.slice(0, 1)) }).addTo(movers)
                .bindPopup(`<b>${esc(person.name)}</b><br>${esc(new Date(person.at).toLocaleString())}`);
        });
        (vehicles ?? []).forEach((vehicle) => {
            L.marker([vehicle.lat, vehicle.lng], { icon: dot('bz-vehicle', '▣') }).addTo(movers)
                .bindPopup(`<b>${esc(vehicle.name)}</b> ${esc(vehicle.plate ?? '')}<br>${esc(new Date(vehicle.at).toLocaleString())}`);
        });
    };
    drawMovers(config.people, config.vehicles);
    [...(config.people ?? []), ...(config.vehicles ?? [])].forEach((mover) => bounds.extend([mover.lat, mover.lng]));
    if (config.movers) {
        layers[labels.movers ?? 'People'] = movers;
        window.addEventListener('bz-map-movers', (event) => drawMovers(event.detail.people, event.detail.vehicles));
    }

    // A track asked for on the page (the look itself is journaled on the server).
    let track = null;
    window.addEventListener('bz-map-track', (event) => {
        track?.remove();
        const points = event.detail.points ?? [];
        if (points.length === 0) {
            return;
        }
        track = L.polyline(points, { color: '#7c3aed', weight: 4 }).addTo(map);
        map.fitBounds(track.getBounds().pad(0.2));
    });

    // Outlines of the districts: asked from the server only when the layer is turned on.
    if (config.boundaries) {
        const districts = L.layerGroup();
        layers[labels.boundaries ?? 'Districts'] = districts;
        let loaded = false;
        map.on('overlayadd', async (event) => {
            if (event.layer !== districts || loaded) {
                return;
            }
            loaded = true;
            const list = await wire(el)?.call('boundaries');
            (list ?? []).forEach((district) => L.geoJSON(district.geometry, { style: { color: '#475569', weight: 1, fillOpacity: 0.03 } })
                .bindTooltip(district.name).addTo(districts));
        });
    }

    // A single point: the house on its own page, or the point chosen for a new one.
    if (config.point) {
        L.marker(config.point, { icon: dot('bz-house', '⌂') }).addTo(map);
        bounds.extend(config.point);
    }

    if (config.zone) {
        zoneEditor(el, map, config.zone, bounds);
    }

    if (Object.keys(layers).length > 1 || config.boundaries) {
        L.control.layers(null, layers, { collapsed: false }).addTo(map);
    }
    if (bounds.isValid()) {
        map.fitBounds(bounds.pad(0.15), { maxZoom: 17 });
    }
    // The map may have been laid out while hidden (a tab, a modal).
    setTimeout(() => map.invalidateSize(), 200);
}

/**
 * The outline of one geozone. For a reader — a shape. For whoever manages the zone — corners that are dragged,
 * added by a click on the map and removed by a double click on a corner; saved by the button on the page.
 */
function zoneEditor(el, map, zone, bounds) {
    let corners = zone.corners.map((corner) => [...corner]);
    const shape = L.polygon(corners, { color: zone.color, weight: 2, fillOpacity: 0.15 }).addTo(map);
    bounds.extend(shape.getBounds());
    if (!zone.editable) {
        return;
    }

    const handles = L.layerGroup().addTo(map);
    let editing = false;
    const redraw = () => {
        shape.setLatLngs(corners);
        handles.clearLayers();
        if (!editing) {
            return;
        }
        corners.forEach((corner, index) => {
            const handle = L.marker(corner, { draggable: true, icon: dot('bz-corner') }).addTo(handles);
            handle.on('drag', (event) => {
                corners[index] = [event.latlng.lat, event.latlng.lng];
                shape.setLatLngs(corners);
            });
            handle.on('dblclick', (event) => {
                L.DomEvent.stop(event);
                if (corners.length > 3) {
                    corners.splice(index, 1);
                    redraw();
                }
            });
        });
        el.dataset.corners = String(corners.length);
    };

    map.doubleClickZoom.disable();
    map.on('click', (event) => {
        if (!editing) {
            return;
        }
        // The new corner goes between the two corners whose edge is the nearest to the click.
        let best = corners.length;
        let bestDistance = Infinity;
        corners.forEach((corner, index) => {
            const next = corners[(index + 1) % corners.length];
            const middle = L.latLng((corner[0] + next[0]) / 2, (corner[1] + next[1]) / 2);
            const distance = map.distance(middle, event.latlng);
            if (distance < bestDistance) {
                bestDistance = distance;
                best = index + 1;
            }
        });
        corners.splice(best, 0, [event.latlng.lat, event.latlng.lng]);
        redraw();
    });

    window.addEventListener('bz-zone-edit', () => {
        editing = true;
        redraw();
    });
    window.addEventListener('bz-zone-cancel', () => {
        editing = false;
        corners = zone.corners.map((corner) => [...corner]);
        redraw();
    });
    window.addEventListener('bz-zone-save', async () => {
        await wire(el)?.call('saveOutline', corners);
        zone.corners = corners.map((corner) => [...corner]);
        editing = false;
        redraw();
    });
    if (zone.editing) {
        editing = true;
        redraw();
    }
}

const mountAll = () => document.querySelectorAll('[data-bz-map]').forEach(mount);
mountAll();
document.addEventListener('livewire:navigated', mountAll);
window.BzMap = { mountAll };
