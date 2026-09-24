(() => {
    const scriptSource = document.currentScript && document.currentScript.src
        ? document.currentScript.src
        : new URL('../../assets/js/pwa.js', window.location.href).href;
    const serviceWorkerUrl = new URL('../../sw.js', scriptSource).href;
    const scopeUrl = new URL('../../', scriptSource);
    const secureContext = window.isSecureContext || ['localhost', '127.0.0.1'].includes(window.location.hostname);
    let deferredPrompt = null;
    let installButton = null;

    const removeInstallButton = () => {
        if (installButton) {
            installButton.remove();
            installButton = null;
        }
    };

    const showInstallButton = () => {
        if (installButton || !deferredPrompt || !document.body) {
            return;
        }

        installButton = document.createElement('button');
        installButton.type = 'button';
        installButton.className = 'pwa-install-button';
        installButton.setAttribute('aria-label', 'Install EduPortal as an app');
        const installMarkup = '<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Install app</span>';
        installButton.innerHTML = window.EduPortalTrustedTypes
            ? window.EduPortalTrustedTypes.createHTML(installMarkup)
            : installMarkup;
        installButton.addEventListener('click', async () => {
            if (!deferredPrompt) {
                return;
            }
            installButton.disabled = true;
            installButton.setAttribute('aria-busy', 'true');
            const label = installButton.querySelector('span');
            if (label) {
                label.textContent = 'Opening...';
            }
            try {
                await deferredPrompt.prompt();
                await deferredPrompt.userChoice;
            } catch (error) {
                deferredPrompt = null;
            } finally {
                deferredPrompt = null;
                removeInstallButton();
            }
        });
        document.body.appendChild(installButton);
    };

    const registerServiceWorker = () => {
        if (!secureContext || !('serviceWorker' in navigator)) {
            return Promise.resolve(null);
        }

        return navigator.serviceWorker.register(serviceWorkerUrl, {
            scope: scopeUrl.pathname,
            updateViaCache: 'none'
        }).then(registration => {
            if (document.visibilityState === 'visible') {
                registration.update().catch(() => {});
            }
            return registration;
        }).catch(() => null);
    };

    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        deferredPrompt = event;
        showInstallButton();
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        removeInstallButton();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', registerServiceWorker, { once: true });
    } else {
        registerServiceWorker();
    }

    window.EduPortalPWA = {
        register: registerServiceWorker,
        get deferredPrompt() {
            return deferredPrompt;
        }
    };
})();
