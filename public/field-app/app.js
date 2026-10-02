/*
 * The agitator's application (ТЗ §33–34, ADR-013). Plain JavaScript, no build step: the same file works online,
 * from the service worker's cache, in development and in production.
 *
 * What works without a network — and nothing else: the list of one's houses, a house, its flats, recording a
 * visit (status, note, date to come back). Every visit is an operation with an id made here; it waits in
 * IndexedDB and is sent until the server answers. The server applies an id once, so sending twice is safe.
 */
(() => {
    'use strict';

    const boot = JSON.parse(document.getElementById('boot').textContent);
    const root = document.getElementById('app');
    const uid = boot.user.id;
    let csrf = boot.csrf;

    const t = (key, vars = {}) => {
        let text = boot.t[key] ?? key;
        for (const [name, value] of Object.entries(vars)) {
            text = text.replaceAll(':' + name, value);
        }
        return text;
    };
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const today = () => new Date().toISOString().slice(0, 10);
    const dateTime = (iso) => (iso ? new Date(iso).toLocaleString(boot.locale, { dateStyle: 'short', timeStyle: 'short' }) : '');
    const date = (iso) => (iso ? new Date(iso + 'T00:00:00').toLocaleDateString(boot.locale, { dateStyle: 'medium' }) : '');

    // ------------------------------------------------------------------ storage

    let opening;
    const open = () => (opening ??= new Promise((resolve, reject) => {
        const request = indexedDB.open('bz-field', 1);
        request.onupgradeneeded = () => {
            const database = request.result;
            if (!database.objectStoreNames.contains('kv')) {
                database.createObjectStore('kv');
            }
            if (!database.objectStoreNames.contains('queue')) {
                database.createObjectStore('queue', { keyPath: 'operation_id' });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    }));

    const store = async (name, mode, work) => {
        const database = await open();
        return new Promise((resolve, reject) => {
            const transaction = database.transaction(name, mode);
            const request = work(transaction.objectStore(name));
            transaction.oncomplete = () => resolve(request ? request.result : undefined);
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
    };
    const kvGet = (key) => store('kv', 'readonly', (s) => s.get(key));
    const kvSet = (key, value) => store('kv', 'readwrite', (s) => s.put(value, key));
    const queueAll = () => store('queue', 'readonly', (s) => s.getAll());
    const queuePut = (operation) => store('queue', 'readwrite', (s) => s.put(operation));
    const queueDelete = (id) => store('queue', 'readwrite', (s) => s.delete(id));

    const uuid = () => {
        if (crypto.randomUUID) {
            return crypto.randomUUID();
        }
        const b = crypto.getRandomValues(new Uint8Array(16));
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
        return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
    };
    const deviceId = async () => {
        let id = await kvGet('device_id');
        if (!id) {
            id = uuid();
            await kvSet('device_id', id);
        }
        return id;
    };

    // ------------------------------------------------------------------ state

    const state = {
        view: 'houses', houseId: null, apartmentId: null, filter: 'all',
        snapshot: null, queue: [], syncing: false, signedOut: false, share: null,
        form: null, message: null,
    };

    const mine = (status) => state.queue.filter((o) => o.user_id === uid && (!status || o.status === status));
    const house = () => state.snapshot?.houses.find((h) => h.id === state.houseId) ?? null;
    const apartment = () => house()?.apartments.find((a) => a.id === state.apartmentId) ?? null;
    const statusOf = (code) => state.snapshot?.statuses.find((s) => s.code === code) ?? { code, name: code, color: 'gray', visited: false };
    const findApartment = (id) => {
        for (const h of state.snapshot?.houses ?? []) {
            const found = h.apartments.find((a) => a.id === id);
            if (found) {
                return found;
            }
        }
        return null;
    };

    /** What a waiting operation changes on the screen before the server has seen it. */
    const applyLocally = (operation) => {
        const flat = findApartment(operation.entity_id);
        if (!flat) {
            return;
        }
        const payload = operation.payload;
        if (operation.operation === 'visit') {
            flat.status = payload.status_code;
            flat.attempts += 1;
            flat.last_visit_at = operation.client_timestamp;
            flat.next_visit_on = payload.next_visit_on ?? null;
            flat.waiting = true;
        }
        if (payload.note) {
            flat.notes.push({ body: payload.note, visibility: payload.note_visibility, at: operation.client_timestamp, waiting: true });
        }
    };

    const saveSnapshot = () => kvSet('snapshot:' + uid, state.snapshot);

    // ------------------------------------------------------------------ network

    const api = async (method, url, body) => {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined,
        });
        let json = null;
        try {
            json = await response.json();
        } catch (e) {
            // An answer without a body — the status says it all.
        }
        return { ok: response.ok, status: response.status, json };
    };

    /** Before anything is sent: is this still the same person, and what is the token now. */
    const session = async () => {
        const answer = await api('GET', boot.urls.session);
        if (answer.status === 401 || answer.status === 419 || (answer.ok && answer.json.user_id !== uid)) {
            state.signedOut = true;
            return false;
        }
        if (!answer.ok) {
            throw new Error('session ' + answer.status);
        }
        csrf = answer.json.csrf;
        state.signedOut = false;
        return true;
    };

    const refresh = async () => {
        if (!boot.may_visit || !navigator.onLine) {
            return;
        }
        const answer = await api('GET', boot.urls.snapshot);
        if (answer.status === 401) {
            state.signedOut = true;
            return;
        }
        if (!answer.ok) {
            return;
        }
        state.snapshot = answer.json.data;
        state.snapshot.loaded_at = new Date().toISOString();
        mine('pending').sort((a, b) => a.client_timestamp.localeCompare(b.client_timestamp)).forEach(applyLocally);
        await saveSnapshot();
    };

    let retry = null;
    const later = () => {
        clearTimeout(retry);
        retry = setTimeout(sync, 15000);
    };

    const sync = async () => {
        if (state.syncing) {
            return;
        }
        const waiting = mine('pending').sort((a, b) => a.client_timestamp.localeCompare(b.client_timestamp));
        if (waiting.length === 0 || !navigator.onLine) {
            render();
            return;
        }
        state.syncing = true;
        render();
        try {
            if (!(await session())) {
                return;
            }
            const device = await deviceId();
            for (let i = 0; i < waiting.length; i += 50) {
                const batch = waiting.slice(i, i + 50);
                const answer = await api('POST', boot.urls.sync, {
                    device_id: device,
                    operations: batch.map(({ operation_id, entity, entity_id, operation, payload, client_timestamp }) => ({ operation_id, entity, entity_id, operation, payload, client_timestamp })),
                });
                if (answer.status === 401 || answer.status === 419) {
                    state.signedOut = true;
                    return;
                }
                if (!answer.ok) {
                    throw new Error('sync ' + answer.status);
                }
                for (const result of answer.json.data) {
                    const sent = batch.find((o) => o.operation_id === result.operation_id);
                    if (!sent) {
                        continue;
                    }
                    if (result.status === 'applied') {
                        await queueDelete(sent.operation_id);
                    } else {
                        // The server refused: sending again would not help. The person sees why and removes it.
                        await queuePut({ ...sent, status: 'rejected', error: result.error ?? '' });
                    }
                }
            }
            state.queue = await queueAll();
            await refresh();
        } catch (e) {
            // No network after all, or the server is down: the operations stay and are sent again.
            later();
        } finally {
            state.syncing = false;
            state.queue = await queueAll();
            render();
        }
    };

    // ------------------------------------------------------------------ actions

    const saveVisit = async () => {
        const flat = apartment();
        const form = state.form;
        if (!flat || !form?.status) {
            return;
        }
        const status = statusOf(form.status);
        const operation = {
            operation_id: uuid(),
            user_id: uid,
            entity: 'apartment',
            entity_id: flat.id,
            operation: 'visit',
            payload: {
                status_code: form.status,
                note: form.note.trim() || null,
                note_visibility: form.visibility,
                next_visit_on: status.retry && form.next ? form.next : null,
                create_task: Boolean(status.retry && form.next && form.task),
            },
            client_timestamp: new Date().toISOString(),
            status: 'pending',
            label: `${house().label}, ${t('flat')} ${flat.number}`,
        };
        // First to the disk, then to the screen, then to the network: a visit is never lost on the way.
        await queuePut(operation);
        state.queue = await queueAll();
        applyLocally(operation);
        await saveSnapshot();
        state.form = null;
        state.view = 'house';
        state.message = t('saved');
        render();
        sync();
    };

    const startSharing = async (minutes) => {
        try {
            if (!(await session())) {
                return render();
            }
            const answer = await api('POST', boot.urls.location + '/start', { minutes });
            state.message = answer.ok ? null : (answer.json?.message ?? t('error'));
            if (answer.ok) {
                state.share = answer.json.data;
            }
        } catch (e) {
            state.message = t('needs_network');
        }
        watch();
        render();
    };

    const stopSharing = async () => {
        try {
            if (await session()) {
                const answer = await api('POST', boot.urls.location + '/stop');
                if (answer.ok) {
                    state.share = answer.json.data;
                }
            }
        } catch (e) {
            state.message = t('needs_network');
        }
        watch();
        render();
    };

    let watching = null;
    let lastPoint = 0;
    const watch = () => {
        const on = Boolean(state.share?.sharing) && new Date(state.share.until) > new Date();
        if (on && watching === null && 'geolocation' in navigator) {
            watching = navigator.geolocation.watchPosition(async (position) => {
                if (Date.now() - lastPoint < 30000 || !navigator.onLine) {
                    return;
                }
                if (new Date(state.share.until) <= new Date()) {
                    state.share.sharing = false;
                    watch();
                    return render();
                }
                lastPoint = Date.now();
                try {
                    const answer = await api('POST', boot.urls.location, {
                        latitude: position.coords.latitude, longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy, recorded_at: new Date(position.timestamp).toISOString(),
                    });
                    if (answer.status === 422) {
                        state.share.sharing = false;
                        watch();
                        render();
                    }
                } catch (e) {
                    // A point lost without a network is simply not sent: location is never queued.
                }
            }, () => {
                state.message = t('location_denied');
                render();
            }, { enableHighAccuracy: true, maximumAge: 15000 });
        }
        if (!on && watching !== null) {
            navigator.geolocation.clearWatch(watching);
            watching = null;
        }
    };

    const loadShare = async () => {
        if (!boot.may_share || !navigator.onLine) {
            return;
        }
        try {
            const answer = await api('GET', boot.urls.location);
            if (answer.ok) {
                state.share = answer.json.data;
                watch();
            }
        } catch (e) {
            // Offline: the sharing card says so.
        }
    };

    // ------------------------------------------------------------------ screens

    const progress = (h) => {
        const visited = h.apartments.filter((a) => statusOf(a.status).visited).length;
        return { visited, total: h.apartments.length, pct: h.apartments.length ? Math.round((visited * 100) / h.apartments.length) : 0 };
    };
    const due = (a) => a.next_visit_on && a.next_visit_on <= today();

    const header = (title, back) => {
        const pending = mine('pending').length;
        const rejected = mine('rejected').length;
        return `
            <header class="bar">
                ${back ? `<button class="icon" data-go="${back}" data-test="back" aria-label="${esc(t('back'))}">‹</button>` : '<span class="icon"></span>'}
                <h1>${esc(title)}</h1>
                <button class="net ${navigator.onLine ? 'on' : 'off'}" data-go="queue" data-test="net" data-online="${navigator.onLine ? '1' : '0'}">
                    ${navigator.onLine ? esc(t('online')) : esc(t('offline'))}
                    ${pending + rejected > 0 ? `<b data-test="pending-count" data-pending="${pending}" data-rejected="${rejected}">${pending + rejected}</b>` : '<b data-test="pending-count" data-pending="0" data-rejected="0" hidden>0</b>'}
                </button>
            </header>
            ${state.signedOut ? `<p class="warn" data-test="signed-out">${esc(t('signed_out'))} <a href="${boot.urls.login}">${esc(t('sign_in'))}</a></p>` : ''}
            ${state.message ? `<p class="note" data-test="message">${esc(state.message)}</p>` : ''}`;
    };

    const housesView = () => {
        const houses = state.snapshot?.houses ?? [];
        const list = houses.map((h) => {
            const p = progress(h);
            const back = h.apartments.filter(due).length;
            return `
                <button class="card" data-house="${h.id}" data-test="house">
                    <strong>${esc(h.label)}</strong>
                    <small>${esc(h.territory)}</small>
                    <span class="meter"><i style="width:${p.pct}%"></i></span>
                    <small>${esc(t('visited_of', { visited: p.visited, total: p.total }))}${back ? ' · ' + esc(t('to_return', { count: back })) : ''}</small>
                </button>`;
        }).join('');
        const empty = !boot.may_visit ? '' : state.snapshot === null
            ? `<p class="empty">${esc(navigator.onLine ? t('loading') : t('first_time_offline'))}</p>`
            : (houses.length === 0 ? `<p class="empty" data-test="no-houses">${esc(t('no_houses'))}</p>` : '');
        return `
            ${header(t('my_houses'))}
            <main>
                ${list}${empty}
                ${state.snapshot?.loaded_at ? `<p class="hint">${esc(t('loaded_at', { at: dateTime(state.snapshot.loaded_at) }))}</p>` : ''}
                ${shareCard()}
                <p class="hint"><a href="${boot.urls.panel}">${esc(t('to_panel'))}</a> · ${esc(boot.user.name)}</p>
            </main>`;
    };

    const shareCard = () => {
        if (!boot.may_share) {
            return '';
        }
        const share = state.share;
        if (!share) {
            return `<section class="box"><h2>${esc(t('share_title'))}</h2><p class="hint">${esc(t('needs_network'))}</p></section>`;
        }
        if (share.sharing && new Date(share.until) > new Date()) {
            return `
                <section class="box on" data-test="sharing-on">
                    <h2>${esc(t('share_on'))}</h2>
                    <p>${esc(t('share_until', { at: dateTime(share.until) }))}</p>
                    <button class="button danger" data-action="share-stop" data-test="share-stop">${esc(t('share_stop'))}</button>
                </section>`;
        }
        return `
            <section class="box" data-test="sharing-off">
                <h2>${esc(t('share_title'))}</h2>
                <p class="hint">${esc(t('share_consent'))}</p>
                <div class="row">
                    ${share.allowed_minutes.map((m) => `<button class="button" data-action="share-start" data-minutes="${m}">${esc(m >= 60 && m % 60 === 0 ? t('hours', { count: m / 60 }) : t('minutes', { count: m }))}</button>`).join('')}
                </div>
            </section>`;
    };

    const houseView = () => {
        const h = house();
        if (!h) {
            state.view = 'houses';
            return housesView();
        }
        const shown = h.apartments.filter((a) => state.filter === 'all'
            || (state.filter === 'new' && !statusOf(a.status).visited)
            || (state.filter === 'return' && (due(a) || statusOf(a.status).retry)));
        const groups = new Map();
        shown.forEach((a) => {
            const key = a.entrance ?? 0;
            groups.set(key, [...(groups.get(key) ?? []), a]);
        });
        const p = progress(h);
        const chips = ['all', 'new', 'return'].map((f) => `<button class="chip ${state.filter === f ? 'active' : ''}" data-filter="${f}">${esc(t('filter_' + f))}</button>`).join('');
        const body = [...groups.entries()].sort((a, b) => a[0] - b[0]).map(([entrance, flats]) => `
            ${entrance ? `<h2>${esc(t('entrance', { number: entrance }))}</h2>` : ''}
            <div class="grid">
                ${flats.map((a) => `
                    <button class="flat st-${esc(statusOf(a.status).color)} ${a.waiting ? 'waiting' : ''}" data-apartment="${a.id}" data-test="apartment"
                        data-number="${esc(a.number)}" data-status="${esc(a.status)}" title="${esc(statusOf(a.status).name)}">
                        ${esc(a.number)}${due(a) ? '<em>!</em>' : ''}
                    </button>`).join('')}
            </div>`).join('');
        return `
            ${header(h.label, 'houses')}
            <main>
                <p class="hint">${esc(h.territory)} · ${esc(t('visited_of', { visited: p.visited, total: p.total }))}</p>
                <div class="row">${chips}</div>
                ${body || `<p class="empty">${esc(t('nothing_here'))}</p>`}
                <div class="legend">${(state.snapshot.statuses).map((s) => `<span><i class="st-${esc(s.color)}"></i>${esc(s.name)}</span>`).join('')}</div>
            </main>`;
    };

    const apartmentView = () => {
        const a = apartment();
        if (!a) {
            state.view = 'house';
            return houseView();
        }
        const form = (state.form ??= { status: null, note: '', visibility: state.snapshot.default_note_visibility, next: '', task: false });
        const chosen = form.status ? statusOf(form.status) : null;
        const buttons = state.snapshot.statuses.filter((s) => s.visited).map((s) => `
            <button class="status st-${esc(s.color)} ${form.status === s.code ? 'chosen' : ''}" data-status="${esc(s.code)}" data-test="status">${esc(s.name)}</button>`).join('');
        const notes = a.notes.map((n) => `<li><small>${esc(dateTime(n.at))} · ${esc(t('visibility_' + n.visibility))}${n.waiting ? ' · ' + esc(t('waiting')) : ''}</small><br>${esc(n.body)}</li>`).join('');
        return `
            ${header(`${house().label}, ${t('flat')} ${a.number}`, 'house')}
            <main>
                <p class="hint" data-test="apartment-state">
                    <b class="pill st-${esc(statusOf(a.status).color)}">${esc(statusOf(a.status).name)}</b>
                    ${a.attempts ? esc(t('attempts', { count: a.attempts })) : ''}
                    ${a.last_visit_at ? ' · ' + esc(dateTime(a.last_visit_at)) : ''}
                    ${a.next_visit_on ? ' · ' + esc(t('return_on', { date: date(a.next_visit_on) })) : ''}
                    ${a.waiting ? ' · ' + esc(t('waiting')) : ''}
                </p>
                <div class="statuses">${buttons}</div>
                ${chosen?.retry ? `
                    <label class="field">${esc(t('next_visit'))}
                        <input type="date" data-field="next" min="${today()}" value="${esc(form.next)}" data-test="next-visit">
                    </label>
                    <label class="check"><input type="checkbox" data-field="task" ${form.task ? 'checked' : ''}> ${esc(t('create_task'))}</label>` : ''}
                <label class="field">${esc(t('note'))}
                    <textarea rows="3" maxlength="2000" data-field="note" data-test="note" placeholder="${esc(t('note_placeholder'))}">${esc(form.note)}</textarea>
                </label>
                <div class="row">
                    ${['team', 'personal'].map((v) => `<label class="check"><input type="radio" name="visibility" data-field="visibility" value="${v}" ${form.visibility === v ? 'checked' : ''}> ${esc(t('visibility_' + v))}</label>`).join('')}
                </div>
                <button class="button primary wide" data-action="save" data-test="save" ${form.status ? '' : 'disabled'}>${esc(t('save'))}</button>
                ${notes ? `<h2>${esc(t('my_notes'))}</h2><ul class="notes">${notes}</ul>` : ''}
            </main>`;
    };

    const queueView = () => {
        const pending = mine('pending');
        const rejected = mine('rejected');
        const row = (o) => `
            <li data-test="operation" data-status="${o.status}">
                <strong>${esc(o.label ?? o.operation_id)}</strong>
                <small>${esc(dateTime(o.client_timestamp))} · ${esc(statusOf(o.payload.status_code).name)}</small>
                ${o.status === 'rejected' ? `<small class="bad">${esc(t('rejected'))}: ${esc(o.error)}</small>
                    <button class="button" data-action="drop" data-id="${o.operation_id}">${esc(t('remove'))}</button>` : ''}
            </li>`;
        return `
            ${header(t('queue'), 'houses')}
            <main>
                <p class="hint">${esc(navigator.onLine ? t('queue_online') : t('queue_offline'))}</p>
                <ul class="notes">${[...rejected, ...pending].map(row).join('') || `<li class="empty" data-test="queue-empty">${esc(t('queue_empty'))}</li>`}</ul>
                <button class="button primary wide" data-action="sync" data-test="sync" ${pending.length === 0 || state.syncing ? 'disabled' : ''}>
                    ${esc(state.syncing ? t('sending') : t('send_now'))}
                </button>
            </main>`;
    };

    function render() {
        const views = { houses: housesView, house: houseView, apartment: apartmentView, queue: queueView };
        root.innerHTML = views[state.view]();
        root.dataset.view = state.view;
        root.dataset.syncing = state.syncing ? '1' : '0';
    }

    // ------------------------------------------------------------------ events

    root.addEventListener('click', (event) => {
        const el = event.target.closest('button, a');
        if (!el || el.tagName === 'A') {
            return;
        }
        state.message = null;
        if (el.dataset.go) {
            state.view = el.dataset.go;
            state.form = null;
        } else if (el.dataset.house) {
            state.houseId = Number(el.dataset.house);
            state.view = 'house';
        } else if (el.dataset.apartment) {
            state.apartmentId = Number(el.dataset.apartment);
            state.form = null;
            state.view = 'apartment';
        } else if (el.dataset.filter) {
            state.filter = el.dataset.filter;
        } else if (el.dataset.status && state.view === 'apartment') {
            state.form.status = el.dataset.status;
        } else if (el.dataset.action === 'save') {
            return void saveVisit();
        } else if (el.dataset.action === 'sync') {
            return void sync();
        } else if (el.dataset.action === 'drop') {
            return void queueDelete(el.dataset.id).then(async () => {
                state.queue = await queueAll();
                await refresh();
                render();
            });
        } else if (el.dataset.action === 'share-start') {
            return void startSharing(Number(el.dataset.minutes));
        } else if (el.dataset.action === 'share-stop') {
            return void stopSharing();
        }
        render();
        window.scrollTo(0, 0);
    });

    root.addEventListener('input', (event) => {
        const field = event.target.dataset.field;
        if (!field || !state.form) {
            return;
        }
        state.form[field] = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
    });

    window.addEventListener('online', () => {
        render();
        sync().then(refresh).then(loadShare).then(render);
    });
    window.addEventListener('offline', render);
    setInterval(() => {
        if (navigator.onLine && mine('pending').length > 0) {
            sync();
        }
    }, 60000);

    // ------------------------------------------------------------------ start

    (async () => {
        state.snapshot = (await kvGet('snapshot:' + uid)) ?? null;
        state.queue = await queueAll();
        render();
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/field-sw.js', { scope: '/field' }).catch(() => {});
        }
        try {
            await sync();
            await refresh();
            await loadShare();
        } catch (e) {
            // Offline at the start: what was kept is on the screen already.
        }
        render();
        root.dataset.ready = '1';
    })();
})();
