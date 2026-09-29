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
    const FAILURE_FIELD = 'recaptcha-failure';

    // How long to wait for a token before giving up on this attempt.
    //
    // This is not a round trip to our own server, it is a cold load of Google's
    // api.js on a first visit, which is well over 100KB and has to complete
    // before execute() can produce anything. Six seconds was not enough on a
    // cold cache, and being too short here was not a cosmetic problem: the old
    // behaviour submitted the form with an empty token, so the server correctly
    // refused it and the user was shown "we could not verify that you are
    // human" with no way to tell that the real cause was a slow download on
    // their connection. Ten seconds covers a cold load on a slow link, and a
    // failure now says what it is instead of becoming an unexplained refusal.
    const EXECUTE_TIMEOUT = 10000;

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

        // The cache is cleared on failure, not just on success. A rejected
        // promise stays rejected, so holding on to it would make one transient
        // network fault permanent for the life of the page: every subsequent
        // retry would fail instantly with the original error, and the visitor's
        // only escape would be a reload -- which is exactly what the inline
        // failure message tells them to do not. Recovering here is what makes
        // "try again" mean something.
        apiPromise.catch(() => { apiPromise = null; });

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

    const hideLoader = () => {
        if (window.EduPortal && typeof window.EduPortal.hideLoader === 'function') {
            window.EduPortal.hideLoader();
        }
    };

    /**
     * Explains a failure on the form itself, in the user's own terms.
     *
     * The alternative is submitting anyway and letting the server refuse, which
     * is what this used to do. It produced "we could not verify that you are
     * human" for what was really a slow download of Google's script, and the
     * user's next move -- reload, retype -- loses the credentials they had
     * already entered. Saying what happened keeps the form intact and the
     * retry cheap.
     */
    const showFailure = (form, message) => {
        let banner = form.querySelector('.' + FAILURE_FIELD);
        if (!banner) {
            banner = document.createElement('div');
            banner.className = FAILURE_FIELD;
            // role="alert" so the message is announced rather than appearing
            // silently above a button the user is still looking at.
            banner.setAttribute('role', 'alert');
            // Matches the portal's own alert treatment via the same custom
            // properties, so it does not look like a different product.
            banner.style.cssText = [
                'display:flex',
                'align-items:flex-start',
                'gap:.6rem',
                'margin-bottom:1rem',
                'padding:.9rem 1.1rem',
                'border:1px solid rgba(239,68,68,.28)',
                'border-radius:12px',
                'background:rgba(239,68,68,.10)',
                'color:var(--danger-color,#ef4444)',
                'font-size:.9rem',
                'line-height:1.5'
            ].join(';');

            const icon = document.createElement('i');
            icon.className = 'fas fa-circle-exclamation';
            icon.setAttribute('aria-hidden', 'true');
            const text = document.createElement('div');
            text.textContent = message;
            banner.append(icon, text);

            // Above the submit button, which is where the eye already is.
            const button = form.querySelector('button[type="submit"]');
            form.insertBefore(banner, button || null);
        }

        banner.querySelector('div').textContent = message;
    };

    const clearFailure = form => {
        form.querySelector('.' + FAILURE_FIELD)?.remove();
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
            clearFailure(form);
            showLoader();

            withTimeout(execute(action), EXECUTE_TIMEOUT)
                .then(token => {
                    // Google resolves with null when it declines to score, for
                    // example before it has seen enough of the session. An
                    // empty token is not a token: submitting one produces a
                    // refusal the user cannot interpret, so it is treated as
                    // the failure it is.
                    if (typeof token !== 'string' || token === '') {
                        throw new Error('reCAPTCHA returned no token');
                    }

                    tokenField(form).value = token;
                    nativeSubmit.call(form);
                })
                .catch(error => {
                    // Never submit a request that is already known to be
                    // unusable. The form keeps everything the user typed, so
                    // retrying is one click rather than retyping a password.
                    hideLoader();
                    delete form.dataset.recaptchaPending;
                    showFailure(
                        form,
                        'The human check did not finish. This is usually a slow connection on the first '
                        + 'visit. Please try again.'
                    );
                    console.warn('EduPortal: reCAPTCHA failed for action "' + action + '" (' + error.message + ').');
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
