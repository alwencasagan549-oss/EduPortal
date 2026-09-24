document.documentElement.dataset.navigationReady = 'true';

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

    const isMobile = () => window.matchMedia('(max-width: 992px)').matches;
    const isAlwaysHidden = sidebar.classList.contains('home-sidebar');
    let lastTrigger = relatedToggles[0] || null;

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
        setAccessibility(isOpen);

        if (isOpen) {
            const firstLink = sidebar.querySelector('.menu-link');
            if (firstLink) {
                firstLink.focus();
            }
        } else if (sidebar.contains(document.activeElement) && lastTrigger) {
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

    window.addEventListener('resize', () => {
        if (!isMobile() && sidebar.classList.contains('active')) {
            setOpen(false);
        }
        setAccessibility(sidebar.classList.contains('active'));
    });

    setAccessibility(false);
});
