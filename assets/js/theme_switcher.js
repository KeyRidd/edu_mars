document.addEventListener('DOMContentLoaded', function() {
    const themeToggleButton = document.getElementById('theme-toggle-btn');
    const body = document.body;
    const currentTheme = localStorage.getItem('theme');

    function applyTheme(theme) {
        if (theme === 'dark') {
            body.classList.add('dark-mode');
            if(themeToggleButton) themeToggleButton.textContent = '☀️'; // Обновляем текст кнопки-заглушки
        } else {
            body.classList.remove('dark-mode');
            if(themeToggleButton) themeToggleButton.textContent = '🌙'; // Обновляем текст кнопки-заглушки
        }
    }

    if (currentTheme) {
        applyTheme(currentTheme);
    } else { // Если тема не сохранена, можно использовать системные предпочтения
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            applyTheme('dark');
            localStorage.setItem('theme', 'dark');
        } else {
            applyTheme('light'); // По умолчанию светлая
        }
    }

    if (themeToggleButton) {
        themeToggleButton.addEventListener('click', function() {
            let newTheme = 'light';
            if (body.classList.contains('dark-mode')) {
                body.classList.remove('dark-mode');
                this.textContent = '🌙';
                newTheme = 'light';
            } else {
                body.classList.add('dark-mode');
                this.textContent = '☀️';
                newTheme = 'dark';
            }
            localStorage.setItem('theme', newTheme);
        });
    }
});