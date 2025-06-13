document.addEventListener('DOMContentLoaded', function() {
    // Проверяем наличие конфигурации
    if (typeof homeConfig === 'undefined' || !homeConfig || !homeConfig.apiMarkReadUrl || !homeConfig.userId) {
        console.error('Конфигурация (homeConfig) для news_read_marker.js не найдена или неполна.');
        return;
    }

    const newsItems = document.querySelectorAll('.news-item.news-unread'); // Находим только непрочитанные новости
    const newsIdsToMark = [];

    if (newsItems.length === 0) {
        return;
    }

    // Собираем ID непрочитанных новостей
    newsItems.forEach(item => {
        const newsId = item.dataset.newsId;
        if (newsId && !isNaN(parseInt(newsId))) {
            newsIdsToMark.push(parseInt(newsId));
        } else {
            console.warn('Найден элемент новости без валидного data-news-id:', item);
        }
    });

    // Если есть ID для отметки, отправляем запрос к API
    if (newsIdsToMark.length > 0) {
        markNewsAsRead(newsIdsToMark);
    }

    // Функция для отправки запроса к API
    function markNewsAsRead(ids) {
        fetch(homeConfig.apiMarkReadUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ newsIds: ids }) // Отправляем массив ID в JSON
        })
        .then(response => {
            if (!response.ok) {
                // Если ответ сервера не успешный
                console.error(`Ошибка сервера: ${response.status} ${response.statusText}`);
                return response.json().then(errData => {
                     throw new Error(errData.message || `Ошибка ${response.status}`);
                });
            }
            return response.json(); // Декодируем успешный JSON ответ
        })
        .then(data => {
            if (data.success && data.marked_ids && data.marked_ids.length > 0) {
                // Обновляем внешний вид отмеченных новостей на странице
                data.marked_ids.forEach(markedId => {
                    const newsElement = document.querySelector(`.news-item[data-news-id="${markedId}"]`);
                    if (newsElement) {
                        newsElement.classList.remove('news-unread');
                        newsElement.classList.add('news-read');
                        const badge = newsElement.querySelector('.news-badge');
                        if (badge) {
                            badge.remove();
                        }
                    }
                });
            } else if (!data.success) {
                console.warn('API вернуло ошибку:', data.message);
            } else {
            }
        })
        .catch(error => {
            console.error('Ошибка при отправке запроса на отметку новостей:', error.message || error);
        });
    }
});