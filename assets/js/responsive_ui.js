document.addEventListener('DOMContentLoaded', () => {
    const toggles = Array.from(document.querySelectorAll('.menu-toggle'));
    if (toggles.length === 0) {
        return;
    }

    const findSidebar = toggle => {
        const wrapper = toggle.closest('.layout-wrapper');
        return (wrapper && wrapper.querySelector('.sidebar')) || document.querySelector('.sidebar');
    };

    const sidebar = findSidebar(toggles[0]);
    if (!sidebar) {
        return;
    }

    const relatedToggles = toggles.filter(toggle => findSidebar(toggle) === sidebar);
    const overlay = document.querySelector('.sidebar-overlay') || (() => {
        const element = document.createElement('div');
        element.className = 'sidebar-overlay';
        document.body.appendChild(element);
        return element;
    })();

    if (!sidebar.id) {
        sidebar.id = 'portal-sidebar';
    }

    // A matchMedia change event fires once per breakpoint crossing. The old
    // unthrottled resize handler interleaved layout reads with attribute
    // writes at 60-100Hz for the whole window drag, forcing synchronous
    // recalculation, and rebuilt the MediaQueryList on every call.
    const mobileQuery = window.matchMedia('(max-width: 992px)');
    const isMobile = () => mobileQuery.matches;
    const isAlwaysHidden = sidebar.classList.contains('home-sidebar');
    let lastTrigger = relatedToggles[0] || null;
    let inertedBackground = [];

    const setAccessibility = isOpen => {
        const hidden = isAlwaysHidden ? !isOpen : (isMobile() && !isOpen);
        sidebar.toggleAttribute('inert', hidden);
        sidebar.setAttribute('aria-hidden', hidden ? 'true' : 'false');
        relatedToggles.forEach(toggle => {
            toggle.setAttribute('aria-controls', sidebar.id);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
        });
    };

    /**
     * While the off-canvas sidebar is open the rest of the page sits behind an
     * opaque overlay but stays in the tab order, so a keyboard user can tab
     * into content they cannot see (WCAG 2.4.3 / 1.4.11). Mark the background
     * inert instead of only hiding body scroll.
     */
    const setBackgroundInert = shouldInert => {
        if (shouldInert === (inertedBackground.length > 0)) {
            return;
        }

        if (shouldInert) {
            inertedBackground = Array.from(document.body.children)
                .filter(element => element !== sidebar && element !== overlay && !element.contains(sidebar))
                .map(element => ({ element, wasInert: element.hasAttribute('inert') }));
            inertedBackground.forEach(({ element }) => element.setAttribute('inert', ''));
        } else {
            inertedBackground.forEach(({ element, wasInert }) => {
                if (wasInert) {
                    element.setAttribute('inert', '');
                } else {
                    element.removeAttribute('inert');
                }
            });
            inertedBackground = [];
        }
    };

    const setOpen = (isOpen, trigger = null) => {
        if (isOpen && !isMobile()) {
            return;
        }

        if (trigger) {
            lastTrigger = trigger;
        }

        sidebar.classList.toggle('active', isOpen);
        overlay.classList.toggle('active', isOpen);
        document.body.style.overflow = isOpen ? 'hidden' : '';
        setBackgroundInert(isOpen);
        setAccessibility(isOpen);

        if (isOpen) {
            const firstLink = sidebar.querySelector('.menu-link');
            if (firstLink) {
                firstLink.focus();
            }
        } else if (lastTrigger && lastTrigger.isConnected) {
            // Restore unconditionally: after Escape, focus may sit on the
            // overlay rather than inside the sidebar, and leaving it on a now
            // -inert element drops the user to <body>.
            lastTrigger.focus();
        }
    };

    relatedToggles.forEach(toggle => {
        toggle.type = 'button';
        toggle.setAttribute('aria-controls', sidebar.id);
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open navigation');
        toggle.addEventListener('click', event => {
            event.stopPropagation();
            setOpen(!sidebar.classList.contains('active'), toggle);
        });
    });

    overlay.addEventListener('click', () => setOpen(false));

    document.addEventListener('click', event => {
        if (!isMobile() || !sidebar.classList.contains('active')) {
            return;
        }
        if (!sidebar.contains(event.target) && !relatedToggles.some(toggle => toggle.contains(event.target))) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && sidebar.classList.contains('active')) {
            setOpen(false);
        }
    });

    sidebar.querySelectorAll('.menu-link').forEach(link => {
        link.addEventListener('click', () => {
            if (isMobile()) {
                setOpen(false);
            }
        });
    });

    const syncWithViewport = () => {
        if (!isMobile() && sidebar.classList.contains('active')) {
            setOpen(false);
        } else {
            setAccessibility(sidebar.classList.contains('active'));
        }
    };

    if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', syncWithViewport);
    } else if (typeof mobileQuery.addListener === 'function') {
        // Safari < 14
        mobileQuery.addListener(syncWithViewport);
    }

    setAccessibility(false);
    // Set only after initialisation: this used to be marked ready on line 1,
    // before the closed sidebar was made inert, so anything depending on it
    // would observe a focusable-but-invisible navigation.
    document.documentElement.dataset.navigationReady = 'true';
});
