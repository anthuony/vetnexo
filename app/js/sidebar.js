// js/sidebar.js
document.addEventListener('DOMContentLoaded', function() {
    if (!document.querySelector('#sidebar .sidebar-menu')) return;
    // Toggle submenus
    const menuToggles = document.querySelectorAll('.menu-link[data-toggle]');
    
    menuToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const submenuId = this.getAttribute('data-toggle');
            const submenu = document.getElementById(submenuId);
            const parentItem = this.parentElement;
            
            // Close all other submenus
            document.querySelectorAll('.submenu').forEach(menu => {
                if (menu.id !== submenuId) {
                    menu.style.maxHeight = '0';
                    menu.parentElement.classList.remove('active');
                }
            });
            
            // Toggle current submenu
            if (submenu.style.maxHeight === '0px' || !submenu.style.maxHeight) {
                submenu.style.maxHeight = submenu.scrollHeight + 'px';
                parentItem.classList.add('active');
            } else {
                submenu.style.maxHeight = '0';
                parentItem.classList.remove('active');
            }
        });
    });
    
    // Mobile sidebar toggle
    const mobileToggle = document.createElement('button');
    mobileToggle.className = 'mobile-toggle';
    mobileToggle.innerHTML = '<i class="bx bx-menu"></i>';
    document.body.appendChild(mobileToggle);
    
    const sidebarOverlay = document.createElement('div');
    sidebarOverlay.className = 'sidebar-overlay';
    document.body.appendChild(sidebarOverlay);
    
    const sidebar = document.getElementById('sidebar');
    
    mobileToggle.addEventListener('click', function() {
        sidebar.classList.toggle('active');
        sidebarOverlay.classList.toggle('active');
    });
    
    sidebarOverlay.addEventListener('click', function() {
        sidebar.classList.remove('active');
        this.classList.remove('active');
    });
    
    // Auto-expand active submenus
    const activeSubmenuItems = document.querySelectorAll('.submenu .menu-link.active');
    activeSubmenuItems.forEach(activeItem => {
        const submenu = activeItem.closest('.submenu');
        const parentItem = submenu.parentElement;
        
        if (submenu && parentItem) {
            submenu.style.maxHeight = submenu.scrollHeight + 'px';
            parentItem.classList.add('active');
        }
    });
    
    // Close sidebar when clicking on a link (mobile)
    const menuLinks = document.querySelectorAll('.menu-link[href]:not([data-toggle])');
    menuLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            }
        });
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        }
    });
    
    // Add smooth scrolling to sidebar
    const sidebarMenu = document.querySelector('.sidebar-menu');
    sidebarMenu.addEventListener('wheel', function(e) {
        e.preventDefault();
        this.scrollTop += e.deltaY;
    });
});