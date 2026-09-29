/**
 * First-visit human check.
 *
 * Runs synchronously in <head> on the pages that emit it, which is the only
 * way to cover the site before it paints. Two mechanisms do that:
 *
 *   - a class on <html> hides the body, so no content is ever visible behind
 *     the overlay, and
 *   - the overlay is appended to <html> rather than <body>, because in <head>
 *     there is no body yet and hiding the body must not hide the overlay.
 *
 * The check is reCAPTCHA v3, which is invisible: it returns a score, not a
 * checkbox, so the branded screen below stands in for the "are you human"
 * moment that v2's widget used to provide. The token is verified server-side
 * by /controllers/human_gate.php, and the browser is marked as verified
 * afterwards so this screen is a once-per-browser event rather than a wall in
 * front of every page.
 *
 * Two properties are load-bearing:
 *
 *   1. The site is never permanently unreachable from this file. A hard
 *      timeout dismisses the overlay whatever happened, so a failed
 *      verification, a dead endpoint or a blocked google.com degrades to "no
 *      gate" instead of "no portal" for every user on the network at once.
 *   2. Without JavaScript there is no gate at all, because the overlay is
 *      created by script. That is deliberate. This is a friction control, not
 *      an access control -- a cookie is clearable by anyone, so treating a
 *      cleared one as a refusal would be theatre that only ever inconveniences
 *      real students. The controls that actually have to hold are the
 *      server-side token checks on the login, signup and password-reset
 *      forms, which run whether or not anything here ever executed.
 */
(() => {
    'use strict';

    if (window.EduPortalHumanGate) {
        return;
    }

    const script = document.currentScript;
    const dataset = script && script.dataset ? script.dataset : {};

    const settings = {
        enabled: dataset.enabled === 'true',
        siteKey: dataset.siteKey || '',
        endpoint: dataset.endpoint || '/controllers/human_gate.php',
        mode: dataset.mode || 'enforce',
        csrf: dataset.csrf || ''
    };

    if (!settings.enabled || settings.siteKey === '') {
        return;
    }

    // Generous enough to cover a slow round trip on a school connection, and
    // short enough that a visitor who is being blocked is told so rather than
    // watching a spinner. Whichever fires first, the site opens.
    const TOTAL_TIMEOUT = 15000;

    // How long the screen stays up once the verdict is in.
    //
    // A gate that appears and vanishes inside a third of a second does not read
    // as a check at all -- the first deployment of this verified in well under
    // that, and the report was that there was "no pop up". The verification
    // was working the whole time; it was simply faster than a person can
    // notice. Held long enough to be legible, and long enough that the
    // difference between a check that ran and a page that never checked is
    // visible to anyone looking.
    const MIN_VISIBLE_MS = 1500;
    const SHOWN_AT = Date.now();

    // Waits out the remainder of the minimum, so a fast verdict is not
    // replaced by a jarring cut.
    const settle = () => new Promise(resolve => {
        const remaining = MIN_VISIBLE_MS - (Date.now() - SHOWN_AT);
        window.setTimeout(resolve, remaining > 0 ? remaining : 0);
    });

    const COLORS = {
        background: '#0a0b10',
        surface: '#14161e',
        text: '#f0f2f5',
        muted: '#94a3b8',
        primary: '#4e73df',
        success: '#10b981',
        danger: '#ef4444',
        border: 'rgba(255,255,255,.08)'
    };

    const overlay = document.createElement('div');
    overlay.id = 'eduportal-human-gate';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'eduportal-gate-title');
    overlay.setAttribute('aria-describedby', 'eduportal-gate-status');
    overlay.style.cssText = [
        'position:fixed',
        'inset:0',
        'z-index:2147483647',
        'display:flex',
        'align-items:center',
        'justify-content:center',
        'padding:1.5rem',
        'background:' + COLORS.background,
        'color:' + COLORS.text,
        'font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif',
        'text-align:center'
    ].join(';');

    const style = document.createElement('style');
    style.textContent = 'html.eduportal-gate-pending body{visibility:hidden}';

    const card = document.createElement('div');
    card.style.cssText = [
        'width:100%',
        'max-width:420px',
        'padding:2.5rem 2rem',
        'border:1px solid ' + COLORS.border,
        'border-radius:20px',
        'background:' + COLORS.surface,
        'box-shadow:0 24px 60px rgba(0,0,0,.45)'
    ].join(';');

    const logo = document.createElement('div');
    logo.setAttribute('aria-hidden', 'true');
    logo.style.cssText = [
        'width:60px',
        'height:60px',
        'margin:0 auto 1.5rem',
        'border-radius:16px',
        'display:flex',
        'align-items:center',
        'justify-content:center',
        'font-size:1.6rem',
        'background:rgba(78,115,223,.14)',
        'color:' + COLORS.primary
    ].join(';');

    // Inline SVG rather than the Font Awesome <i> the rest of the portal
    // uses: this screen is painted before the icon stylesheet has been
    // requested, so a glyph reference here would render as an empty box.
    logo.innerHTML = '<svg viewBox="0 0 640 512" width="28" height="28" fill="currentColor" aria-hidden="true" focusable="false">'
        + '<path d="M320 0c17.7 0 32 14.3 32 32V69.4c1.5 .5 3 .9 4.4 1.4c40.7 12.1 76.6 30.9 106.9 55.2l16-11.2c13.1-9.2 33.3-6 42.5 7.1s6 33.3-7.1 42.5l-16 11.2c2.1 3.9 4.2 7.8 6.2 11.8c14.3 29.4 22.8 60.8 24.6 93.2c.9 16.9-12.5 31.3-29.4 30.7c-16.8-.6-32.2-5.6-45.7-14.3l-3.9 1.8c-5.2 24.1-14.9 46.8-28.6 66.7c-15.4 22.4-36 41.3-59.7 55.2V506c0 17.7-14.3 32-32 32s-32-14.3-32-32V386.8c-23.7-13.9-44.3-32.8-59.7-55.2c-13.7-19.9-23.4-42.6-28.6-66.7l-3.9-1.8c-13.5 8.7-28.9 13.7-45.7 14.3c-16.9 .6-30.3-13.8-29.4-30.7c1.8-32.4 10.3-63.8 24.6-93.2c2-4 4.1-7.9 6.2-11.8l-16-11.2c-13.1-9.2-16.3-29.4-7.1-42.5s29.4-16.3 42.5-7.1l16 11.2c30.3-24.3 66.2-43.1 106.9-55.2c1.4-.5 2.9-.9 4.4-1.4V32c0-17.7 14.3-32 32-32zM160 128a32 32 0 1 0 0 64 32 32 0 1 0 0-64zm96 32a32 32 0 1 0 64 0 32 32 0 1 0-64 0zM284.8 224c-20.8 14.3-52 24.8-84.8 24.8-35.5 0-68.3-11.6-89.8-27.9C98.4 245.2 89.6 268.1 89.6 292.4c0 22.5 20.2 40 45 40 60.6 0 106.7-22.9 145.8-64.8c6.2-6.6 16.1-6.6 22.3 0c39.1 41.9 85.2 64.8 145.8 64.8 24.8 0 45-17.5 45-40 0-24.3-8.8-47.2-25.6-75.5c-21.5 16.3-54.3 27.9-89.8 27.9-32.8 0-64-10.5-84.8-24.8c-6.2-6.6-16.1-6.6-22.3 0z"/>'
        + '</svg>';

    const title = document.createElement('h1');
    title.id = 'eduportal-gate-title';
    title.style.cssText = 'margin:0 0 .6rem; font-size:1.4rem; font-weight:800; letter-spacing:-.5px';
    title.textContent = 'EduPortal';

    const status = document.createElement('p');
    status.id = 'eduportal-gate-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.style.cssText = 'margin:0; color:' + COLORS.muted + '; font-size:.95rem; line-height:1.6';
    status.textContent = 'Verifying you are human…';

    const actions = document.createElement('div');
    actions.style.cssText = 'margin-top:1.75rem; display:none; justify-content:center';

    const retry = document.createElement('button');
    retry.type = 'button';
    retry.style.cssText = [
        'display:inline-flex',
        'align-items:center',
        'justify-content:center',
        'min-height:44px',
        'padding:.8rem 1.6rem',
        'border:1px solid transparent',
        'border-radius:12px',
        'background:linear-gradient(135deg,' + COLORS.primary + ' 0%,#224abe 100%)',
        'color:#fff',
        'font:inherit',
        'font-weight:600',
        'cursor:pointer'
    ].join(';');
    retry.textContent = 'Try again';
    retry.addEventListener('click', () => {
        actions.style.display = 'none';
        setStatus('Verifying you are human…', COLORS.muted, null);
        run();
    });

    const note = document.createElement('p');
    note.style.cssText = 'margin:2rem 0 0; color:' + COLORS.muted + '; font-size:.78rem';
    note.textContent = 'Protected by Google reCAPTCHA';

    actions.appendChild(retry);
    card.append(logo, title, status, actions, note);
    overlay.appendChild(card);

    // The two appends below must both happen before the parser reaches
    // <body>. The style goes in first so the class it defines is live by the
    // time any body content could be rendered.
    document.head.appendChild(style);
    document.documentElement.classList.add('eduportal-gate-pending');
    document.documentElement.appendChild(overlay);

    const spinner = document.createElement('div');
    spinner.className = 'eduportal-gate-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    spinner.style.cssText = [
        'width:34px',
        'height:34px',
        'margin:0 auto 1.25rem',
        'border-radius:50%',
        'border:3px solid rgba(78,115,223,.22)',
        'border-top-color:' + COLORS.primary,
        'animation:eduportal-gate-spin .8s linear infinite'
    ].join(';');
    card.insertBefore(spinner, title);
    style.textContent += '@keyframes eduportal-gate-spin{to{transform:rotate(360deg)}}'
        + '@media (prefers-reduced-motion:reduce){'
        + '.eduportal-gate-spinner{animation:none}'
        + '}';

    function setStatus(message, color, tone) {
        status.textContent = message;
        status.style.color = color || COLORS.muted;

        if (tone === 'working') {
            spinner.style.display = 'block';
            return;
        }

        spinner.style.display = 'none';
        if (tone === 'ok') {
            spinner.style.borderTopColor = COLORS.success;
            spinner.style.borderColor = COLORS.success;
            spinner.style.opacity = '.25';
        }
    }

    function dismiss() {
        window.clearTimeout(deadline);
        document.documentElement.classList.remove('eduportal-gate-pending');
        overlay.remove();
    }

    // Last line of defence. Whatever happened above -- a rejected score, a
    // hung request, a third-party script that never arrived -- the portal
    // opens. Losing the gate is a lesser outcome than losing the portal.
    const deadline = window.setTimeout(() => {
        console.warn('EduPortal: human check did not complete in time; continuing without it.');
        dismiss();
    }, TOTAL_TIMEOUT);

    function loadApi() {
        return new Promise((resolve, reject) => {
            if (window.grecaptcha && typeof window.grecaptcha.execute === 'function') {
                resolve(window.grecaptcha);
                return;
            }

            const tag = document.createElement('script');
            tag.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(settings.siteKey);
            tag.async = true;
            tag.defer = true;
            tag.addEventListener('load', () => {
                if (window.grecaptcha && typeof window.grecaptcha.execute === 'function') {
                    resolve(window.grecaptcha);
                } else {
                    reject(new Error('reCAPTCHA loaded without an API'));
                }
            });
            tag.addEventListener('error', () => reject(new Error('reCAPTCHA could not be loaded')));
            document.head.appendChild(tag);
        });
    }

    function tokenForGate() {
        return loadApi().then(() => new Promise((resolve, reject) => {
            const issue = () => {
                try {
                    window.grecaptcha
                        .execute(settings.siteKey, { action: 'homepage_gate' })
                        .then(resolve, reject);
                } catch (error) {
                    reject(error);
                }
            };

            if (typeof window.grecaptcha.ready === 'function') {
                window.grecaptcha.ready(issue);
            } else {
                issue();
            }
        }));
    }

    function confirm(token) {
        const body = new URLSearchParams();
        body.set('g-recaptcha-response', token || '');
        body.set('csrf_token', settings.csrf);

        return fetch(settings.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'error',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json'
            },
            body: body.toString()
        }).then(response => {
            if (!response.ok) {
                throw new Error('Verification endpoint returned ' + response.status);
            }
            return response.json();
        });
    }

    function run() {
        tokenForGate()
            // A null token means reCAPTCHA declined to issue one, which in
            // practice means this host is not registered for the key. Sending
            // it on as empty lets the server apply its own fail-mode policy and
            // produce the message, instead of the client inventing one.
            .then(token => confirm(typeof token === 'string' ? token : ''))
            .then(result => {
                if (result && result.ok) {
                    setStatus('Verified. Taking you through…', COLORS.success, 'ok');
                    // Held for a beat even when the verdict was instant, so the
                    // screen reads as a deliberate check rather than a flicker.
                    settle().then(dismiss);
                    return;
                }

                const reason = result && result.reason ? result.reason : 'unknown';
                console.warn('EduPortal: human check refused (' + reason + ').');

                // Only a score judgement is a verdict about the visitor. Every
                // other refusal is a fault -- Google unreachable, key and
                // secret mismatched, hostname not yet registered in the
                // console -- and holding a whole school at a "you are not
                // human" screen because an operator has not finished setting
                // up would be the worst possible outcome. The endpoint is the
                // authority on risk; when it reports a fault, the site opens.
                if (settings.mode === 'observe' || reason !== 'low_score') {
                    dismiss();
                    return;
                }

                setStatus(
                    (result && result.error) || 'We could not verify that you are human. Please try again.',
                    COLORS.danger
                );
                actions.style.display = 'flex';
                retry.focus();
            })
            .catch(error => {
                console.warn('EduPortal: human check could not run (' + error.message + ').');
                dismiss();
            });
    }

    window.EduPortalHumanGate = { dismiss, mode: settings.mode };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, { once: true });
    } else {
        run();
    }
})();
