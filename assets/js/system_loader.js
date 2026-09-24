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
    let confirmationResolver = null;

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
        const confirmationIcon = document.createElement('i');
        confirmationIcon.className = 'fas fa-check';
        confirmationIcon.setAttribute('aria-hidden', 'true');
        confirmButton.append(confirmationIcon, document.createTextNode(' OK'));
        confirmButton.addEventListener('click', () => {
            if (loaderMode === 'confirm') {
                resolveConfirmation(true);
                return;
            }
            EduPortal.hideLoader();
        });

        const cancelButton = document.createElement('button');
        cancelButton.id = 'loaderCancelButton';
        cancelButton.type = 'button';
        cancelButton.className = 'premium-btn premium-btn-outline';
        cancelButton.textContent = 'Cancel';
        cancelButton.addEventListener('click', () => resolveConfirmation(false));

        actions.append(cancelButton, confirmButton);

        container.append(icon, text, subtext, actions);
        overlay.appendChild(container);
        document.body.insertAdjacentElement('afterbegin', overlay);
    };

    const setButtonContent = (button, iconClass, label) => {
        const icon = document.createElement('i');
        icon.className = iconClass;
        icon.setAttribute('aria-hidden', 'true');
        button.replaceChildren(icon, document.createTextNode(` ${label}`));
    };

    const clearTimers = () => {
        window.clearTimeout(hideTimer);
        window.clearTimeout(fallbackTimer);
        hideTimer = null;
        fallbackTimer = null;
    };

    const resetDialogVisuals = () => {
        const container = document.querySelector('#loaderOverlay .loader-container');
        const text = document.getElementById('loaderText');
        const subtext = document.getElementById('loaderSubtext');
        const actions = document.getElementById('loaderActions');
        const cancelButton = document.getElementById('loaderCancelButton');
        const confirmButton = document.getElementById('loaderConfirmButton');
        container?.classList.remove('loader-confirmation', 'loader-success');
        container?.removeAttribute('style');
        container?.removeAttribute('aria-labelledby');
        container?.removeAttribute('aria-describedby');
        if (text) text.removeAttribute('style');
        if (subtext) subtext.removeAttribute('style');
        if (actions) {
            actions.removeAttribute('style');
            actions.style.display = 'none';
        }
        if (cancelButton) {
            cancelButton.removeAttribute('style');
            cancelButton.style.display = 'none';
        }
        if (confirmButton) {
            confirmButton.className = 'premium-btn premium-btn-primary';
            confirmButton.removeAttribute('style');
        }
    };

    const resetOverlay = () => {
        const overlay = document.getElementById('loaderOverlay');
        const container = overlay?.querySelector('.loader-container');
        const icon = document.getElementById('loaderIcon');
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
        resetDialogVisuals();
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
        resetDialogVisuals();

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
        if (loaderMode === 'confirm' && confirmationResolver) {
            const resolve = confirmationResolver;
            confirmationResolver = null;
            resolve(false);
        }
        clearTimers();
        loaderVersion += 1;
        const version = loaderVersion;
        const restoreFocus = ['success', 'confirm'].includes(loaderMode) ? focusBeforeModal : null;
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

    const resolveConfirmation = result => {
        const resolve = confirmationResolver;
        confirmationResolver = null;
        if (resolve) {
            resolve(result);
        }
        hideLoader();
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
        const confirmButton = actions.querySelector('#loaderConfirmButton');
        const container = overlay.querySelector('.loader-container');
        resetDialogVisuals();
        focusBeforeModal = document.activeElement;

        container.classList.add('loader-confirmation', 'loader-success');
        container.style.cssText = 'width: min(420px, calc(100vw - 32px)); padding: 2.25rem; text-align: center; border-radius: 24px;';
        container.setAttribute('aria-labelledby', 'loaderText');
        container.setAttribute('aria-describedby', 'loaderSubtext');
        confirmButton.style.cssText = 'padding: 0.8rem 1.5rem; border-radius: 12px; font-size: 0.9rem;';
        setButtonContent(confirmButton, 'fas fa-check', 'OK');

        icon.className = 'fas fa-check-circle';
        icon.style.cssText = 'font-size: 4rem; color: #2ecc71; margin-bottom: 20px;';
        text.textContent = title;
        subtext.textContent = message;
        actions.style.display = 'flex';
        actions.style.justifyContent = 'center';
        actions.style.marginTop = '1.5rem';
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

    const showConfirmModal = ({
        title = 'Confirm action',
        message = 'Are you sure you want to continue?',
        confirmLabel = 'Confirm',
        cancelLabel = 'Cancel',
        icon = 'fas fa-right-from-bracket'
    } = {}) => {
        if (confirmationResolver) {
            const previousResolve = confirmationResolver;
            confirmationResolver = null;
            previousResolve(false);
        }

        return new Promise(resolve => {
            injectLoader();
            setBackgroundInert(false);
            clearTimers();
            loaderVersion += 1;
            loaderMode = 'confirm';
            confirmationResolver = resolve;
            focusBeforeModal = document.activeElement;

            const overlay = document.getElementById('loaderOverlay');
            const iconElement = document.getElementById('loaderIcon');
            const text = document.getElementById('loaderText');
            const subtext = document.getElementById('loaderSubtext');
            const actions = document.getElementById('loaderActions');
            const confirmButton = document.getElementById('loaderConfirmButton');
            const cancelButton = document.getElementById('loaderCancelButton');
            const container = overlay.querySelector('.loader-container');
            resetDialogVisuals();

            container.classList.add('loader-confirmation');
            container.style.cssText = 'width: min(440px, calc(100vw - 32px)); padding: 2rem; text-align: left; border-radius: 24px; border-color: rgba(239, 68, 68, 0.24);';
            container.setAttribute('role', 'dialog');
            container.setAttribute('aria-modal', 'true');
            container.setAttribute('aria-busy', 'false');
            container.setAttribute('aria-labelledby', 'loaderText');
            container.setAttribute('aria-describedby', 'loaderSubtext');
            iconElement.className = icon;
            iconElement.style.cssText = 'width: 52px; height: 52px; margin: 0 0 1.25rem; display: flex; align-items: center; justify-content: center; border-radius: 16px; background: rgba(239, 68, 68, 0.12); color: #ef4444; font-size: 1.35rem;';
            text.style.cssText = 'font-size: 1.35rem; line-height: 1.25; font-weight: 700; text-align: left;';
            subtext.style.cssText = 'line-height: 1.6; text-align: left;';
            actions.style.cssText = 'display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 0.75rem; margin-top: 1.75rem;';
            confirmButton.className = 'premium-btn';
            confirmButton.style.cssText = 'padding: 0.8rem 1.25rem; border-radius: 12px; font-size: 0.9rem; background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; box-shadow: 0 10px 24px rgba(239, 68, 68, 0.22);';
            cancelButton.className = 'premium-btn premium-btn-outline';
            cancelButton.style.cssText = 'padding: 0.8rem 1.25rem; border-radius: 12px; font-size: 0.9rem;';
            cancelButton.style.display = 'inline-flex';
            setButtonContent(confirmButton, 'fas fa-right-from-bracket', confirmLabel);
            cancelButton.textContent = cancelLabel;
            text.textContent = title;
            subtext.textContent = message;
            overlay.setAttribute('aria-hidden', 'false');
            overlay.style.opacity = '1';
            overlay.style.display = 'flex';
            setBackgroundInert(true);
            window.EduPortal.isProcessing = false;
            window.requestAnimationFrame(() => cancelButton.focus());
        });
    };

    const confirmLogout = () => showConfirmModal({
        title: 'Log out of EduPortal?',
        message: 'You will need to sign in again to access your dashboard and assignments.',
        confirmLabel: 'Confirm logout',
        cancelLabel: 'Cancel'
    });

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

        document.addEventListener('submit', async event => {
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
                if (form.dataset.logoutPending === 'true') {
                    return;
                }
                form.dataset.logoutPending = 'true';
                const confirmed = await confirmLogout();
                delete form.dataset.logoutPending;
                if (!confirmed) {
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

        document.getElementById('loaderOverlay').addEventListener('click', event => {
            if (loaderMode === 'confirm' && event.target === event.currentTarget) {
                resolveConfirmation(false);
            }
        });

        window.addEventListener('pageshow', () => EduPortal.hideLoader());
        window.addEventListener('focus', () => {
            if (loaderMode === 'download') {
                EduPortal.hideLoader();
            }
        });
        document.addEventListener('keydown', event => {
            const overlay = document.getElementById('loaderOverlay');
            if (event.key !== 'Escape' || overlay?.getAttribute('aria-hidden') !== 'false') {
                return;
            }
            if (loaderMode === 'confirm') {
                resolveConfirmation(false);
            } else if (loaderMode === 'success') {
                EduPortal.hideLoader();
            }
        });
        window.addEventListener('pagehide', () => {
            if (loaderMode === 'confirm') {
                resolveConfirmation(false);
                setBackgroundInert(false);
            } else if (loaderMode !== 'download') {
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
        showConfirmModal,
        confirmLogout
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
