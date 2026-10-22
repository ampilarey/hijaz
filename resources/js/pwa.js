// Makes the portals installable: registers the service worker and offers,
// once, to install the app. Android and desktop Chrome raise their own
// prompt event; an iPhone has none, so it is told where the button is.
const DISMISSED = 'hijaz.install.dismissed';

function registerWorker() {
    if (!('serviceWorker' in navigator) || location.pathname.startsWith('/ground')) return;
    navigator.serviceWorker.register('/app-sw.js', { scope: '/' }).catch(() => {});
}

function standalone() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

function dismissed() {
    try { return localStorage.getItem(DISMISSED) !== null; } catch { return true; }
}

function remember() {
    try { localStorage.setItem(DISMISSED, String(Date.now())); } catch { /* private mode */ }
}

function banner(text, action) {
    const bar = document.createElement('div');
    bar.setAttribute('role', 'dialog');
    bar.setAttribute('aria-label', 'Install the app');
    // Above the portal's bottom bar on a phone; plain inline styles, because this runs on pages with different stylesheets.
    const bottom = document.querySelector('.hz-bottom-nav') && window.innerWidth < 1024 ? 'calc(72px + env(safe-area-inset-bottom))' : 'calc(16px + env(safe-area-inset-bottom))';
    bar.style.cssText = `position:fixed;left:12px;right:12px;bottom:${bottom};z-index:60;display:flex;align-items:center;gap:12px;padding:12px;border-radius:12px;background:#fff;color:#353334;border:1px solid #c7d9f1;box-shadow:0 6px 24px rgba(0,0,0,.15);font:15px/1.35 system-ui,-apple-system,'Segoe UI',sans-serif;max-width:480px;margin:0 auto;`;
    bar.innerHTML = '<img src="/images/icons/icon-192.png" alt="" style="width:40px;height:40px;border-radius:8px;flex:none">'
        + '<span style="flex:1 1 auto;min-width:0"></span>';
    bar.querySelector('span').textContent = text;

    const button = (label, primary) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        b.style.cssText = `min-height:44px;padding:0 14px;border-radius:8px;font:inherit;font-weight:600;flex:none;${primary ? 'background:#017a42;color:#fff;border:0;' : 'background:transparent;color:#5e5c5d;border:1px solid #c7d9f1;'}`;
        return b;
    };

    if (action) {
        const install = button('Install', true);
        install.addEventListener('click', () => { action(); bar.remove(); });
        bar.appendChild(install);
    }

    const later = button(action ? 'Not now' : 'OK', false);
    later.addEventListener('click', () => { remember(); bar.remove(); });
    bar.appendChild(later);
    document.body.appendChild(bar);
}

export function startPwa() {
    registerWorker();

    if (standalone() || dismissed()) return;

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        banner('Install the Hijaz app on this phone for one-tap access.', () => {
            event.prompt();
            event.userChoice.finally(remember);
        });
    });

    const iPhone = /iphone|ipad|ipod/i.test(navigator.userAgent) && !/crios|fxios/i.test(navigator.userAgent);
    if (iPhone) {
        banner('Install the Hijaz app: tap Share, then "Add to Home Screen".', null);
    }

    window.addEventListener('appinstalled', remember);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startPwa);
} else {
    startPwa();
}
