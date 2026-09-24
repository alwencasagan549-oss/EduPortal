/**
 * EduPortal Global System Loader & Traffic Controller
 * @author Alwin T. Casagan | Web Developer
 * @copyright 2026 Alwin T. Casagan. Proprietary Code.
 * Unauthorized modification or redistribution is strictly prohibited.
 */
(() => {
    if (window.EduPortal) {
        return;
    }

    const defaultTimeout = 30000;
    let loaderVersion = 0;
    let hideTimer = null;
    let fallbackTimer = null;
    let loaderMode = 'request';
    let focusBeforeModal = null;
    let inertElements = [];

    const setBackgroundInert = inert => {
        const overlay = document.getElementById('loaderOverlay');
        if (inert) {
            inertElements = Array.from(document.body.children)
                .filter(element => element !== overlay)
                .map(element => ({ element, ariaHidden: element.getAttribute('aria-hidden'), inert: element.inert }));
            inertElements.forEach(({ element }) => {
                element.inert = true;
                element.setAttribute('aria-hidden', 'true');
            });
            return;
        }
        inertElements.forEach(({ element, ariaHidden, inert }) => {
            element.inert = inert;
            if (ariaHidden === null) {
                element.removeAttribute('aria-hidden');
            } else {
                element.setAttribute('aria-hidden', ariaHidden);
            }
        });
        inertElements = [];
    };

    const injectLoader = () => {
        if (document.getElementById('loaderOverlay')) {
            return;
        }

        const overlay = document.createElement('div');
        overlay.id = 'loaderOverlay';
        overlay.className = 'loader-overlay';
        overlay.style.display = 'none';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.setAttribute('aria-live', 'polite');

        const container = document.createElement('div');
        container.className = 'loader-container animate-scale-up';
        container.setAttribute('role', 'status');
        container.setAttribute('aria-busy', 'true');

        const icon = document.createElement('div');
        icon.id = 'loaderIcon';
        icon.className = 'edu-spinner';

        const text = document.createElement('div');
        text.id = 'loaderText';
        text.className = 'loader-text';
        text.textContent = 'EduPortal Core';

        const subtext = document.createElement('div');
        subtext.id = 'loaderSubtext';
        subtext.className = 'loader-subtext';
        subtext.textContent = 'Initializing system streams...';

        const actions = document.createElement('div');
        actions.id = 'loaderActions';
        actions.style.display = 'none';

        const confirmButton = document.createElement('button');
        confirmButton.id = 'loaderConfirmButton';
        confirmButton.type = 'button';
        confirmButton.className = 'premium-btn premium-btn-primary';
        confirmButton.style.cssText = 'padding: 0.8rem 2rem; border-radius: 12px; font-size: 0.9rem;';
        const confirmationMarkup = '<i class="fas fa-check" aria-hidden="true"></i> OK';
        confirmButton.innerHTML = window.EduPortalTrustedTypes
            ? window.EduPortalTrustedTypes.createHTML(confirmationMarkup)
            : confirmationMarkup;
        confirmButton.addEventListener('click', () => EduPortal.hideLoader());
        actions.appendChild(confirmButton);

        container.append(icon, text, subtext, actions);
        overlay.appendChild(container);
        document.body.insertAdjacentElement('afterbegin', overlay);
    };

    const clearTimers = () => {
        window.clearTimeout(hideTimer);
        window.clearTimeout(fallbackTimer);
        hideTimer = null;
        fallbackTimer = null;
    };

    const resetOverlay = () => {
        const overlay = document.getElementById('loaderOverlay');
        const container = overlay?.querySelector('.loader-container');
        const icon = document.getElementById('loaderIcon');
        const actions = document.getElementById('loaderActions');
        if (!overlay) {
            return;
        }
        overlay.style.display = 'none';
        overlay.setAttribute('aria-hidden', 'true');
        container?.setAttribute('aria-busy', 'false');
        container?.setAttribute('role', 'status');
        container?.removeAttribute('aria-modal');
        if (icon) {
            icon.className = 'edu-spinner';
            icon.style.cssText = '';
        }
        if (actions) {
            actions.style.display = 'none';
        }
    };

    const showLoader = (title = 'Processing...', subtext = 'Please wait while we handle your request.', options = {}) => {
        injectLoader();
        setBackgroundInert(false);
        clearTimers();
        loaderVersion += 1;
        loaderMode = options.mode || 'request';

        const overlay = document.getElementById('loaderOverlay');
        const icon = document.getElementById('loaderIcon');
        const text = document.getElementById('loaderText');
        const subtextElement = document.getElementById('loaderSubtext');
        const actions = document.getElementById('loaderActions');
        const container = overlay.querySelector('.loader-container');

        icon.className = 'edu-spinner';
        icon.style.cssText = '';
        text.textContent = title;
        subtextElement.textContent = subtext;
        actions.style.display = 'none';
        container.setAttribute('aria-busy', 'true');
        container.setAttribute('role', 'status');
        container.removeAttribute('aria-modal');
        overlay.setAttribute('aria-hidden', 'false');
        overlay.style.opacity = '1';
        overlay.style.display = 'flex';
        window.EduPortal.isProcessing = true;

        const timeout = Number.isFinite(options.timeout) ? options.timeout : defaultTimeout;
        fallbackTimer = window.setTimeout(() => {
            EduPortal.hideLoader();
            window.dispatchEvent(new CustomEvent('eduportal:loader-timeout', {
                detail: { title, subtext }
            }));
        }, timeout);
    };

    const hideLoader = () => {
        clearTimers();
        loaderVersion += 1;
        const version = loaderVersion;
        const restoreFocus = loaderMode === 'success' ? focusBeforeModal : null;
        const overlay = document.getElementById('loaderOverlay');
        setBackgroundInert(false);
        if (!overlay) {
            window.EduPortal.isProcessing = false;
            return;
        }

        window.EduPortal.isProcessing = false;
        overlay.style.opacity = '0';
        hideTimer = window.setTimeout(() => {
            if (version !== loaderVersion) {
                return;
            }
            resetOverlay();
            if (restoreFocus?.isConnected) {
                restoreFocus.focus();
            }
            focusBeforeModal = null;
            hideTimer = null;
            loaderMode = 'request';
        }, 300);
    };

    const showSuccessModal = (title, message) => {
        injectLoader();
        setBackgroundInert(false);
        clearTimers();
        loaderVersion += 1;
        loaderMode = 'success';

        const overlay = document.getElementById('loaderOverlay');
        const icon = document.getElementById('loaderIcon');
        const text = document.getElementById('loaderText');
        const subtext = document.getElementById('loaderSubtext');
        const actions = document.getElementById('loaderActions');
        const confirmButton = actions.querySelector('button');
        const container = overlay.querySelector('.loader-container');
        focusBeforeModal = document.activeElement;

        icon.className = 'fas fa-check-circle';
        icon.style.cssText = 'font-size: 4rem; color: #2ecc71; margin-bottom: 20px;';
        text.textContent = title;
        subtext.textContent = message;
        actions.style.display = 'block';
        container.setAttribute('aria-busy', 'false');
        container.setAttribute('role', 'dialog');
        container.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-hidden', 'false');
        overlay.style.opacity = '1';
        overlay.style.display = 'flex';
        setBackgroundInert(true);
        window.EduPortal.isProcessing = false;
        window.requestAnimationFrame(() => confirmButton?.focus());
    };

    const confirmLogout = () => window.confirm('Log out of EduPortal?');

    const isInternalNavigation = (anchor, event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return false;
        }
        if (anchor.target && anchor.target !== '_self' || anchor.hasAttribute('download')) {
            return false;
        }

        const href = anchor.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) {
            return false;
        }

        try {
            const destination = new URL(anchor.href, window.location.href);
            return destination.origin === window.location.origin && ['http:', 'https:'].includes(destination.protocol);
        } catch {
            return false;
        }
    };

    const initialize = () => {
        injectLoader();

        document.addEventListener('submit', event => {
            if (window.EduPortal.isProcessing) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            const form = event.target;
            if (event.defaultPrevented) {
                return;
            }
            if (!(form instanceof HTMLFormElement) || form.dataset.loader !== 'true') {
                return;
            }
            if (form.dataset.logoutConfirm === 'true') {
                event.preventDefault();
                if (!confirmLogout()) {
                    return;
                }
                EduPortal.showLoader('Logging out', 'Securely ending your session...');
                form.submit();
                return;
            }
            EduPortal.showLoader('Processing request', 'Connecting to the EduPortal core...');
        });

        document.addEventListener('click', event => {
            const anchor = event.target.closest?.('a[href]');
            if (!anchor || !isInternalNavigation(anchor, event)) {
                return;
            }

            const destination = new URL(anchor.href, window.location.href);
            const isDownload = destination.pathname.includes('/controllers/download') || destination.pathname.endsWith('/download_guide.php');
            if (isDownload) {
                const timeout = destination.pathname.endsWith('/download_all.php') ? 120000 : 15000;
                EduPortal.showLoader('Preparing download', 'Your file is being prepared.', { mode: 'download', timeout });
                return;
            }

            EduPortal.showLoader('Loading page', 'Please wait while we continue...');
        });

        window.addEventListener('pageshow', () => EduPortal.hideLoader());
        window.addEventListener('focus', () => {
            if (loaderMode === 'download') {
                EduPortal.hideLoader();
            }
        });
        document.addEventListener('keydown', event => {
            const overlay = document.getElementById('loaderOverlay');
            if (event.key === 'Escape' && loaderMode === 'success' && overlay?.getAttribute('aria-hidden') === 'false') {
                EduPortal.hideLoader();
            }
        });
        window.addEventListener('pagehide', () => {
            if (loaderMode !== 'download') {
                clearTimers();
            }
        });
    };

    window.EduPortal = {
        isProcessing: false,
        injectLoader,
        showLoader,
        hideLoader,
        showSuccessModal,
        confirmLogout
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
