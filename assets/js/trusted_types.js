(() => {
    if (!window.trustedTypes || window.EduPortalTrustedTypes) {
        return;
    }

    const policy = trustedTypes.createPolicy('eduportal', {
        createHTML: value => value,
        createScript: value => value,
        createScriptURL: value => value
    });

    window.EduPortalTrustedTypes = policy;
})();
