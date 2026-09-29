/**
 * reCAPTCHA v3 token acquisition for the public auth forms.
 *
 * v3 is invisible by design: it produces a token, not a checkbox, so there is
 * no "I am not a robot" control to render. The token has to be requested at
 * the moment the form is submitted, written into a hidden field, and verified
 * on the server -- so this file owns the submit interception and nothing else.
 *
 * Submit ordering matters and is the part worth being careful about.
 * system_loader.js listens for `submit` on document and raises the blocking
 * overlay. If that ran first, the request would be in flight with no token.
 * A listener on the form element always fires before one on document during
 * the bubble phase, so stopping propagation here is what keeps the loader
 * waiting for us rather than racing us.
 *
 * `HTMLFormElement.prototype.submit` is called rather than `form.submit()` for
 * the same reason: the native call does not dispatch a submit event, which is
 * what stops this listener -- and the loader's -- from running a second time.
 * That also means the loader has to be raised by hand on the way out.
 *
 * The token is single-use and expires after two minutes, so it is fetched per
 * submit and never cached across one.
 */
(() => {
    'use strict';

    if (window.EduPortalRecaptcha) {
        return;
    }

    const FORM_ATTRIBUTE = 'data-recaptcha-action';
    const TOKEN_FIELD = 'g-recaptcha-response';

    // Long enough for a slow connection to finish the round trip, short
    // enough that a visitor who cannot reach Google is not left staring at a
    // button that does nothing. On timeout the form is submitted anyway and
    // the server renders the refusal, which is the same message a user would
    // have seen had the script never loaded at all.
    const EXECUTE_TIMEOUT = 6000;

    const nativeSubmit = HTMLFormElement.prototype.submit;

    const settings = (() => {
        const script = document.currentScript;
        const dataset = script && script.dataset ? script.dataset : {};
        return {
            siteKey: dataset.siteKey || '',
            loaderText: dataset.loaderText || 'Verifying you are human...'
        };
    })();

    const enabled = settings.siteKey !== '';
    let apiPromise = null;

    /**
     * Loads Google's api.js once and resolves with the live grecaptcha object.
     *
     * The script is injected rather than hardcoded in the page so that a
     * deployment with reCAPTCHA switched off never contacts Google at all,
     * and so the key lives in exactly one place.
     */
    const loadApi = () => {
        if (!enabled) {
            return Promise.reject(new Error('reCAPTCHA is not configured'));
        }
        if (apiPromise) {
            return apiPromise;
        }

        apiPromise = new Promise((resolve, reject) => {
            if (window.grecaptcha && typeof window.grecaptcha.execute === 'function') {
                resolve(window.grecaptcha);
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(settings.siteKey);
            script.async = true;
            script.defer = true;
            script.addEventListener('load', () => {
                if (window.grecaptcha && typeof window.grecaptcha.execute === 'function') {
                    resolve(window.grecaptcha);
                } else {
                    reject(new Error('reCAPTCHA loaded without an API'));
                }
            });
            script.addEventListener('error', () => reject(new Error('reCAPTCHA could not be loaded')));
            document.head.appendChild(script);
        });

        return apiPromise;
    };

    const execute = action => loadApi().then(() => new Promise((resolve, reject) => {
        const run = () => {
            try {
                // Resolves with a token, or with null when reCAPTCHA itself
                // declines -- an unregistered host is the usual cause.
                window.grecaptcha.execute(settings.siteKey, { action }).then(resolve, reject);
            } catch (error) {
                reject(error);
            }
        };

        // grecaptcha.ready is the documented way to wait out the initial
        // stub, and is what makes calling this before the API settles safe.
        if (typeof window.grecaptcha.ready === 'function') {
            window.grecaptcha.ready(run);
        } else {
            run();
        }
    }));

    const withTimeout = (promise, milliseconds) => new Promise((resolve, reject) => {
        const timer = window.setTimeout(() => reject(new Error('reCAPTCHA timed out')), milliseconds);
        promise.then(
            value => { window.clearTimeout(timer); resolve(value); },
            error => { window.clearTimeout(timer); reject(error); }
        );
    });

    const tokenField = form => {
        let field = form.querySelector('input[name="' + TOKEN_FIELD + '"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = TOKEN_FIELD;
            field.autocomplete = 'off';
            form.appendChild(field);
        }
        return field;
    };

    const showLoader = () => {
        if (window.EduPortal && typeof window.EduPortal.showLoader === 'function') {
            window.EduPortal.showLoader('Verifying', settings.loaderText);
        }
    };

    /**
     * Intercepts submit on one form. Returns false when the form carries no
     * action, so callers can tell "nothing to do" from "already bound".
     */
    const bind = form => {
        const action = form.getAttribute(FORM_ATTRIBUTE);
        if (!action) {
            return false;
        }
        if (form.dataset.recaptchaBound === 'true') {
            return true;
        }
        form.dataset.recaptchaBound = 'true';

        // Create the field up front rather than on submit, so a bot that reads
        // the DOM before interacting still sees the form it is expected to
        // fill, and so a JavaScript error later cannot leave a fieldless form.
        tokenField(form);

        form.addEventListener('submit', event => {
            if (form.dataset.recaptchaPending === 'true') {
                // Enter key plus a click, most often. The overlay is already
                // up, so there is nothing to say; just refuse the second one.
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                return;
            }

            event.preventDefault();
            // stopPropagation is what keeps the document-level loader handler
            // from firing; stopImmediatePropagation keeps any other listener
            // on this form from submitting without a token.
            event.stopPropagation();
            event.stopImmediatePropagation();

            form.dataset.recaptchaPending = 'true';
            showLoader();

            withTimeout(execute(action), EXECUTE_TIMEOUT)
                // A failure here is not fatal: the form goes out without a
                // token and the server refuses it with a message the user can
                // act on. Inventing a token or retrying silently would hide a
                // network fault behind a login failure.
                .catch(() => '')
                .then(token => {
                    tokenField(form).value = typeof token === 'string' ? token : '';
                    nativeSubmit.call(form);
                });
        });

        return true;
    };

    const initialize = () => {
        if (!enabled) {
            return;
        }

        // Warm the script so the first submit is not paying for the download.
        // A failure is deliberately ignored: the real verdict is reported on
        // submit, where there is a user to tell.
        loadApi().catch(() => {});

        document.querySelectorAll('form[' + FORM_ATTRIBUTE + ']').forEach(bind);
    };

    window.EduPortalRecaptcha = {
        isEnabled: () => enabled,
        siteKey: settings.siteKey,
        bind,
        execute,
        load: loadApi
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
