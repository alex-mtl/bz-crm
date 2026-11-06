{{--
    "Install the application" prompt of the panel (PWA). Chrome / Edge / Android: a button that opens the browser's
    own install dialog. iPhone / iPad (Safari has no such dialog): a hint "Share → Add to Home Screen".
    Hidden when the panel already runs as an installed application, or after the user closed it (kept per browser).
--}}
<div id="bz-pwa-install" style="display:none; position:fixed; left:16px; right:16px; bottom:16px; z-index:60; max-width:420px; margin:0 auto; padding:12px 14px; border-radius:12px; background:#fff; color:#1e293b; box-shadow:0 8px 24px rgba(0,0,0,.18); font-size:14px; line-height:1.4;">
    <div style="display:flex; gap:12px; align-items:center;">
        <img src="/brand/icon-192.png" alt="" style="width:40px; height:40px; border-radius:8px; flex:none;">
        <div style="flex:1;">
            <strong>{{ __('pwa.title') }}</strong>
            <div data-pwa-text="install">{{ __('pwa.install_text') }}</div>
            <div data-pwa-text="ios" style="display:none;">{!! __('pwa.ios_text') !!}</div>
        </div>
        <button type="button" data-pwa-close aria-label="{{ __('pwa.close') }}" style="flex:none; border:0; background:none; font-size:20px; line-height:1; color:#64748b; cursor:pointer;">×</button>
    </div>
    <button type="button" data-pwa-install style="display:none; margin-top:10px; width:100%; padding:8px; border:0; border-radius:8px; background:#d97706; color:#fff; font-weight:600; cursor:pointer;">{{ __('pwa.install') }}</button>
</div>
<script>
    (() => {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/admin-sw.js', { scope: '/admin' }).catch(() => {});
        }
        const box = document.getElementById('bz-pwa-install');
        if (!box) return;
        const KEY = 'bz-pwa-install-closed';
        const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
        let closed = false;
        try { closed = localStorage.getItem(KEY) === '1'; } catch (e) {}
        if (standalone || closed) return;

        box.querySelector('[data-pwa-close]').addEventListener('click', () => {
            box.style.display = 'none';
            try { localStorage.setItem(KEY, '1'); } catch (e) {}
        });

        const ua = navigator.userAgent;
        const ios = /iPhone|iPad|iPod/.test(ua) || (ua.includes('Macintosh') && navigator.maxTouchPoints > 1);
        if (ios) {
            box.querySelector('[data-pwa-text="install"]').style.display = 'none';
            box.querySelector('[data-pwa-text="ios"]').style.display = '';
            box.style.display = '';
            return;
        }

        let deferred = null;
        const button = box.querySelector('[data-pwa-install]');
        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            deferred = event;
            button.style.display = '';
            box.style.display = '';
        });
        button.addEventListener('click', async () => {
            if (!deferred) return;
            deferred.prompt();
            await deferred.userChoice.catch(() => null);
            deferred = null;
            box.style.display = 'none';
        });
        window.addEventListener('appinstalled', () => { box.style.display = 'none'; });
    })();
</script>
