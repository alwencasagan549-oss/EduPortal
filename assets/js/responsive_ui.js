/**
 * EduPortal Responsive UI Controller
 * Handles mobile sidebar interactions and responsive layout adjustments.
 */

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.querySelector('.sidebar');
    const menuToggle = document.querySelector('.menu-toggle');
    
    if (sidebar && menuToggle) {
        // Create overlay if it doesn't exist
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);
        }

        const toggleSidebar = () => {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        };

        menuToggle.addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', toggleSidebar);

        // Close sidebar when clicking a menu link on mobile
        const menuLinks = sidebar.querySelectorAll('.menu-link');
        menuLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 992) {
                    toggleSidebar();
                }
            });
        });

        // Ensure sidebar is closed when resizing above mobile breakpoint
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992 && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    }

    // Handle form grids - automatically stack on mobile if they have the specific class
    const responsiveGrids = document.querySelectorAll('.responsive-grid-stack');
    const handleGrids = () => {
        const isMobile = window.innerWidth <= 768;
        responsiveGrids.forEach(grid => {
            if (isMobile) {
                grid.style.gridTemplateColumns = '1fr';
            } else {
                // Restore original if needed, but CSS class should handle it
            }
        });
    };

    window.addEventListener('resize', handleGrids);
    handleGrids();
});
