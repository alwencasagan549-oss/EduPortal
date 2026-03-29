document.addEventListener('DOMContentLoaded', function() {
    // Mobile Menu Toggle
    const headers = document.querySelectorAll('.main-header, .dashboard-header');
    
    headers.forEach(header => {
        const nav = header.querySelector('.main-nav, .dashboard-nav');
        if (!nav) return;

        // Create toggle button
        const toggleBtn = document.createElement('button');
        toggleBtn.className = 'menu-toggle';
        toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
        toggleBtn.setAttribute('aria-label', 'Toggle Navigation');
        
        // Insert toggle button before the nav
        header.insertBefore(toggleBtn, nav);

        toggleBtn.addEventListener('click', function() {
            nav.classList.toggle('active');
            const icon = toggleBtn.querySelector('i');
            if (nav.classList.contains('active')) {
                icon.className = 'fas fa-times';
            } else {
                icon.className = 'fas fa-bars';
            }
        });
    });

    // Close mobile menu when clicking outside
    document.addEventListener('click', function(event) {
        const headers = document.querySelectorAll('.main-header, .dashboard-header');
        headers.forEach(header => {
            const nav = header.querySelector('.main-nav.active, .dashboard-nav.active');
            const toggle = header.querySelector('.menu-toggle');
            
            if (nav && !header.contains(event.target)) {
                nav.classList.remove('active');
                if (toggle) {
                    toggle.querySelector('i').className = 'fas fa-bars';
                }
            }
        });
    });
});
