/**
 * HTML escaping helper.
 *
 * This used to install a Trusted Types policy whose createHTML was the
 * identity function. Such a policy sanitises nothing, and it is only
 * load-bearing when the CSP carries `require-trusted-types-for 'script'` —
 * which this application does not set. It therefore provided no protection
 * while making every `trustedHtml()` call site look as though it did.
 *
 * Untrusted values now reach the DOM through textContent or through
 * `escapeHtml()` inside a string that is only ever used for a static shell.
 */
(() => {
    if (window.EduPortalTrustedTypes) {
        return;
    }

    const entities = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    };

    window.EduPortalTrustedTypes = {
        createHTML: value => String(value ?? ''),
        escapeHtml: value => String(value ?? '').replace(/[&<>"']/g, c => entities[c])
    };
})();
