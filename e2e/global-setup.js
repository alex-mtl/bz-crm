import net from 'node:net';

/*
 * Inside the Docker network the application answers as nginx:80; the browser must see it as localhost:8100
 * (a secure context, and the address the application builds its links with). A plain TCP forwarder does that —
 * and the same for the asset server the panel pages load their styles from.
 */
const FORWARD = [
    [8100, process.env.E2E_APP_HOST ?? 'nginx', 80],
    [5174, process.env.E2E_VITE_HOST ?? 'node', 5174],
];

export default async function globalSetup() {
    const servers = await Promise.all(FORWARD.map(([port, host, target]) => new Promise((resolve, reject) => {
        const server = net.createServer((client) => {
            const upstream = net.connect(target, host);
            client.pipe(upstream).pipe(client);
            const close = () => {
                client.destroy();
                upstream.destroy();
            };
            client.on('error', close);
            upstream.on('error', close);
        });
        server.on('error', reject);
        server.listen(port, '127.0.0.1', () => resolve(server));
    })));

    return async () => {
        servers.forEach((server) => server.close());
    };
}
