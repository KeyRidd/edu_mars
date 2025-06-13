document.addEventListener('DOMContentLoaded', function() {
    console.log('Main.js loaded and DOM ready.');

    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string' || unsafe === null || unsafe === undefined) {
            return '';
        }
        return unsafe
             .replace(/&/g, "&amp;")  // Амперсанд
             .replace(/</g, "&lt;")   // Меньше
             .replace(/>/g, "&gt;")   // Больше
             .replace(/"/g, "&quot;") // Двойная кавычка
             .replace(/'/g, "&#39;"); // Одинарная кавычка
    }

    function truncateText(text, maxLength) {    
        if (!text) return '';
        return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
    }

    function formatRelativeTime(dateString) {
         if (!dateString) return '';
         try {
            const date = new Date(dateString);
             const now = new Date();
             const diffSeconds = Math.round((now - date) / 1000);
             const diffMinutes = Math.round(diffSeconds / 60);
             const diffHours = Math.round(diffMinutes / 60);
             const diffDays = Math.round(diffHours / 24);

             if (diffSeconds < 60) return 'только что';
             if (diffMinutes < 60) return `${diffMinutes} мин. назад`;
             if (diffHours < 24) return `${diffHours} ч. назад`;
             if (diffDays === 1) return 'вчера';
             if (diffDays < 7) return `${diffDays} дн. назад`;
             return date.toLocaleDateString('ru-RU');
         } catch(e) {
             console.warn("Error formatting relative time for:", dateString, e);
             return dateString;
         }
    }
    const burgerBtn = document.getElementById('burgerMenuBtn');
    const sidebar = document.getElementById('mainSidebar');
    const closeBtn = document.getElementById('sidebarCloseBtn');
    const body = document.body;
    const contentOverlay = document.createElement('div'); 

    // Функция открытия сайдбара
    function openSidebar() {
        if (sidebar && !body.classList.contains('sidebar-open')) {
            body.classList.add('sidebar-open');
            burgerBtn?.setAttribute('aria-expanded', 'true');
            // Добавляем оверлей для закрытия по клику вне меню (только на мобильных)
            if (window.innerWidth < 992) {
                 contentOverlay.className = 'sidebar-overlay';
                 contentOverlay.style.position = 'fixed';
                 contentOverlay.style.top = '0';
                 contentOverlay.style.left = '0';
                 contentOverlay.style.width = '100%';
                 contentOverlay.style.height = '100%';
                 contentOverlay.style.zIndex = '1028';
                 contentOverlay.style.background = 'transparent';
                 body.appendChild(contentOverlay);
                 contentOverlay.addEventListener('click', closeSidebar, { once: true });
            }
        }
    }

    // Функция закрытия сайдбара
    function closeSidebar() {
        if (sidebar && body.classList.contains('sidebar-open')) {
            body.classList.remove('sidebar-open');
            burgerBtn?.setAttribute('aria-expanded', 'false');
             if (body.contains(contentOverlay)) {
                 body.removeChild(contentOverlay);
             }
        }
    }

    // Обработчик клика на бургер
    if (burgerBtn) {
        burgerBtn.addEventListener('click', (event) => {
            event.stopPropagation();
            if (body.classList.contains('sidebar-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    // Обработчик клика на кнопку закрытия внутри сайдбара
    if (closeBtn) {
        closeBtn.addEventListener('click', closeSidebar);
    }

    // Закрытие по клику на ссылку внутри сайдбара (на мобильных)
    if (sidebar) {
        sidebar.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 992 && body.classList.contains('sidebar-open')) {
                    setTimeout(closeSidebar, 150);
                }
            });
        });
    }

    // Выпадющий список уведомлений
    const notificationBell = document.getElementById('notificationsBell');
    const notificationsDropdown = document.getElementById('notificationsDropdown');
    const notificationsList = document.getElementById('notificationsList');
    const markAllReadBtn = document.getElementById('markAllReadBtn');
    const notificationItemInHeader = document.querySelector('.notification-item');

    async function loadNotifications() {
    if (!notificationsList || !notificationBell) return;

    if (typeof siteConfig === 'undefined' || !siteConfig.baseUrl) {
        console.error("siteConfig.baseUrl is not defined in main.js! Cannot load notifications.");
        if(notificationsList) notificationsList.innerHTML = '<li class="p-2 text-center text-danger small">Ошибка конфигурации клиента.</li>';
        return;
    }
    
    const apiUrl = `${siteConfig.baseUrl}api/get_notifications.php?limit=5`; 
    console.log("Fetching notifications from URL:", apiUrl);

    notificationsList.innerHTML = '<li class="loading-state p-2 text-center text-muted small">Загрузка...</li>';

    try {
        const response = await fetch(apiUrl); 

        if (!response.ok) {
            let errorText = `Ошибка сети: ${response.status} ${response.statusText}`;
            try {
                const errorData = await response.json();
                if (errorData && errorData.message) {
                    errorText = errorData.message;
                }
            } catch (e) { /* ignore json parsing error if not json */ }
            throw new Error(errorText);
        }
        const data = await response.json();
        console.log('API Data for notifications:', data); // Логируем полученные данные

        if (data.success && Array.isArray(data.notifications)) {
            notificationsList.innerHTML = ''; 
            if (data.notifications.length === 0) {
                 notificationsList.innerHTML = '<li class="p-2 text-center text-muted small">Нет новых уведомлений.</li>';
            } else {
                data.notifications.forEach(n => {
                    const li = document.createElement('li');
                    li.className = n.is_read ? '' : 'notification-unread';
                    let linkUrl = n.url ? ( (n.url.startsWith('http') ? '' : siteConfig.baseUrl) + n.url ) : '#';
                    if (linkUrl === '#' || !n.is_read) { 
                        linkUrl = `${siteConfig.baseUrl}pages/notifications.php${n.is_read ? '' : '?mark_read=' + n.id}#notification-${n.id}`;
                    }

                    li.innerHTML = `
                        <a href="${escapeHtml(linkUrl)}" class="dropdown-item">
                            <strong>${escapeHtml(n.title || 'Уведомление')}</strong>
                            <span class="notification-text">${escapeHtml(truncateText(n.message || '', 70))}</span>
                            <span class="notification-time">${formatRelativeTime(n.created_at)}</span>
                        </a>
                    `;
                    notificationsList.appendChild(li);
                });
            }

            console.log('Notifications data from API:', data.notifications);
            const unreadCount = data.notifications ? data.notifications.filter(n => !n.is_read).length : 0;
            console.log('Calculated unreadCount:', unreadCount);

            const bellIcon = document.getElementById('notificationsBell');
            if (bellIcon) {
                console.log('Bell icon found. Current classes:', bellIcon.className);
                if (unreadCount > 0) {
                    console.log('Adding has-unread class.');
                    bellIcon.classList.add('has-unread');
                } else {
                    console.log('Removing has-unread class.');
                    bellIcon.classList.remove('has-unread');
                }
                console.log('Bell icon classes after update:', bellIcon.className);
            } else {
                console.error('Bell icon #notificationsBell not found!');
            }

            const countBadge = document.getElementById('notification-count-badge');
            if(countBadge){
                if(unreadCount > 0){
                    countBadge.textContent = unreadCount > 9 ? '9+' : unreadCount.toString();
                    countBadge.style.display = 'inline-block';
                } else {
                    countBadge.style.display = 'none';
                }
            }

        } else {
            notificationsList.innerHTML = '<li class="p-2 text-center text-danger small">Ошибка формата данных.</li>';
            console.error("API Error loading notifications:", data.error || "Notifications data is not an array or success is false", data);
        }
    } catch (error) {
        console.error('Fetch Error loading notifications:', error.message ? error.message : error);
        notificationsList.innerHTML = `<li class="p-2 text-center text-danger small">Ошибка сети: ${escapeHtml(error.message || 'Неизвестная ошибка')}</li>`;
    }
}

// Обработчик клика на колокольчик
if (notificationBell && notificationsDropdown) {
    notificationBell.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        
        const isCurrentlyShown = notificationsDropdown.style.display === 'block';
        
        if (isCurrentlyShown) {
            notificationsDropdown.style.display = 'none';
            notificationBell.setAttribute('aria-expanded', 'false');
        } else {
            notificationsDropdown.style.display = 'block';
            notificationBell.setAttribute('aria-expanded', 'true');
            loadNotifications();
        }
    });
}

// Закрытие дропдауна по клику вне его
document.addEventListener('click', (event) => {
    if (notificationsDropdown && notificationsDropdown.style.display === 'block') {
        if (notificationBell && !notificationBell.contains(event.target) && !notificationsDropdown.contains(event.target)) {
            notificationsDropdown.style.display = 'none';
            if (notificationBell) {
                 notificationBell.setAttribute('aria-expanded', 'false');
            }
        }
    }
});

 // Обработчик кнопки "Отметить все прочитанными"
 if (markAllReadBtn) {
    markAllReadBtn.addEventListener('click', async () => {
         console.log('Attempting to mark all notifications as read...');
         if (typeof siteConfig === 'undefined' || !siteConfig.baseUrl) {
             console.error("siteConfig.baseUrl is not defined! Cannot mark all read.");
             alert('Ошибка конфигурации клиента для отметки уведомлений.');
             return;
         }
         const apiUrl = `${siteConfig.baseUrl}api/notifications_mark_all_read.php`;
         markAllReadBtn.disabled = true; 
         markAllReadBtn.textContent = 'Обработка...';

          try {
              const response = await fetch(apiUrl, {
                  method: 'POST',
                  headers: { 'X-Requested-With': 'XMLHttpRequest' } 
              });
              const data = await response.json();

              if (data.success) {
                  if (notificationItemInHeader) { 
                      notificationItemInHeader.classList.remove('has-unread');
                  }
                  const countBadge = document.getElementById('notification-count-badge');
                  if(countBadge) countBadge.style.display = 'none';

                  notificationsList.querySelectorAll('li.notification-unread').forEach(li => {
                      li.classList.remove('notification-unread');
                  });
                  loadNotifications(); 
              } else {
                  console.error('API error marking all read:', data.message);
                  alert(`Ошибка: ${data.message || 'Не удалось отметить все как прочитанные.'}`);
              }
          } catch (error) {
              console.error('Fetch error marking all read:', error);
              alert('Сетевая ошибка при попытке отметить все уведомления как прочитанные.');
          } finally {
              markAllReadBtn.disabled = false; 
              markAllReadBtn.textContent = 'Все прочитаны';
               
               // Закрываем дропдаун
               if(notificationsDropdown) notificationsDropdown.style.display = 'none';
               if(notificationBell) notificationBell.setAttribute('aria-expanded', 'false');
          }
     });
 }
});