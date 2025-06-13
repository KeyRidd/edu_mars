document.addEventListener('DOMContentLoaded', function() {
    
    const bodyElementForTheme = document.body;
    const themeToggleBtnNavbar = document.getElementById('theme-toggle-btn'); 
    const themeToggleBtnLanding = document.getElementById('theme-toggle-btn-landing');
    
    function updateButtonIcon(button, theme) {
        if (button) {
            button.textContent = theme === 'dark' ? '☀️' : '🌙';
        }
    }
    
    function applyTheme(theme) {
        if (theme === 'dark') {
            bodyElementForTheme.classList.add('dark-mode');
        } else {
            bodyElementForTheme.classList.remove('dark-mode');
        }
        updateButtonIcon(themeToggleBtnNavbar, theme);
        updateButtonIcon(themeToggleBtnLanding, theme);
    }

    const savedTheme = localStorage.getItem('theme');

    if (savedTheme) {
        applyTheme(savedTheme);
    } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        applyTheme('dark');
    } else {
        applyTheme('light');
    }

    function themeToggleClickHandler() {
        let currentThemeIsDark = bodyElementForTheme.classList.contains('dark-mode');
        let newTheme = currentThemeIsDark ? 'light' : 'dark';
        applyTheme(newTheme);
        localStorage.setItem('theme', newTheme);
    }

    if (themeToggleBtnNavbar) {
        themeToggleBtnNavbar.addEventListener('click', themeToggleClickHandler);
    }
    if (themeToggleBtnLanding) {
       themeToggleBtnLanding.addEventListener('click', themeToggleClickHandler);
    }

    // Логика для мобильного сайдбара
    const sidebarElement = document.getElementById('main-sidebar'); 
    const burgerMenuButton = document.getElementById('burger-menu-toggle-btn'); 
    const sidebarCloseButton = sidebarElement ? sidebarElement.querySelector('.sidebar-close-btn') : null;

    if (burgerMenuButton && sidebarElement) {
        burgerMenuButton.addEventListener('click', function(event) {
            event.stopPropagation(); 
            bodyElementForTheme.classList.toggle('sidebar-open');
        });
    }

    if (sidebarCloseButton && sidebarElement) {
        sidebarCloseButton.addEventListener('click', function() {
            bodyElementForTheme.classList.remove('sidebar-open');
        });
    }

    document.addEventListener('click', function(event) {
        if (bodyElementForTheme.classList.contains('sidebar-open')) {
            const isClickInsideSidebar = sidebarElement ? sidebarElement.contains(event.target) : false;
            const isClickOnBurger = burgerMenuButton ? burgerMenuButton.contains(event.target) : false;
            
            if (!isClickInsideSidebar && !isClickOnBurger) {
                bodyElementForTheme.classList.remove('sidebar-open');
            }
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && bodyElementForTheme.classList.contains('sidebar-open')) {
            bodyElementForTheme.classList.remove('sidebar-open');
        }
    });
});