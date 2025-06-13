// Проверка базового конфига
if (typeof lessonConfig === 'undefined' || !lessonConfig.lessonId || !lessonConfig.userId || !lessonConfig.apiChatUrl) {
    console.error("Chat module cannot initialize: lessonConfig or required fields are missing.");
} else {
    console.log("Chat module initializing..."); 

    // DOM Elements
    const chatMessagesList = document.getElementById('chat-messages-list');
    const chatEndAnchor = document.getElementById('chat-end');
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-message-input');
    const unreadIndicator = document.getElementById('unread-indicator');
    const unreadCountSpan = document.getElementById('unread-count');
    const chatTabButton = document.getElementById('chat-tab');

    // Константы чата
    const EDIT_DELETE_TIMELIMIT_SECONDS = 12 * 60 * 60; // 12 часов
    const CHAT_EDIT_FORM_CLASS = 'chat-edit-form';
    const CHAT_MESSAGE_TEXT_CLASS = 'chat-message-text';
    const CHAT_MESSAGE_ACTIONS_CLASS = 'chat-message-actions';
    const CHAT_EDIT_BTN_CLASS = 'chat-edit-btn';
    const CHAT_DELETE_BTN_CLASS = 'chat-delete-btn';
    const CHAT_EDIT_INDICATOR_CLASS = 'chat-edited-indicator';

    // Переменные состояния чата
    let firstUnreadElement = null;

    // Вспомогательные функции
    function escapeHTML(str) {
      if (!str) return '';
      const div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    }
    function nl2br(str) {
        if (!str) return '';
        return str.replace(/(\r\n|\n|\r)/g, '<br>');
    }
    function formatShortDateTime(dateString) {
        if (!dateString) return '';
        try {
            const date = new Date(dateString.replace(' ', 'T'));
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            return `${day}.${month} ${hours}:${minutes}`;
        } catch (e) { return dateString; }
    }

    // Основные функции чата
    // Определяет, можно ли редактировать/удалять сообщение по времени
    function isEditable(createdAtISO) {
         if (!createdAtISO) return false;
         try {
             const compatibleDateString = createdAtISO.replace(' ', 'T');
             const createdAtTime = new Date(compatibleDateString).getTime();
             if (isNaN(createdAtTime)) { console.error("Невалидный формат даты создания:", createdAtISO); return false; }
             const now = Date.now();
             return (now - createdAtTime) / 1000 < EDIT_DELETE_TIMELIMIT_SECONDS;
         } catch (e) { console.error("Ошибка парсинга даты создания:", createdAtISO, e); return false; }
    }

    // Добавляет контролы к сообщению
    function addMessageControls(messageElement) {
        if (!(messageElement instanceof HTMLElement)) return;
        const authorId = parseInt(messageElement.dataset.authorId, 10);
        const messageId = messageElement.dataset.messageId;
        const createdAt = messageElement.dataset.createdAt;
        const editedAt = messageElement.dataset.editedAt;

        if (!messageId || isNaN(authorId) || !createdAt) return;

        const textElement = messageElement.querySelector(`.${CHAT_MESSAGE_TEXT_CLASS}`);
        let indicator = messageElement.querySelector(`.${CHAT_EDIT_INDICATOR_CLASS}`);

        // Обновляем/добавляем/удаляем метку "изменено"
        if (editedAt && textElement) {
            if (!indicator) {
                indicator = document.createElement('span');
                indicator.className = CHAT_EDIT_INDICATOR_CLASS;
                indicator.textContent = ' (изменено)';
                textElement.appendChild(indicator);
            }
            indicator.title = `Отредактировано: ${new Date(editedAt.replace(' ', 'T')).toLocaleString()}`;
        } else if (indicator) {
            indicator.remove();
        }

        // Обновляем/добавляем/удаляем кнопки действий
        let actionsContainer = messageElement.querySelector(`.${CHAT_MESSAGE_ACTIONS_CLASS}`);
        actionsContainer?.remove(); // Удаляем старые

        const canEditThis = lessonConfig.userId === authorId && isEditable(createdAt);
        const canDeleteThis = (lessonConfig.userId === authorId && isEditable(createdAt)) || lessonConfig.canDeleteAnyMessage;
        const canEditAny = lessonConfig.canEditAnyMessage && lessonConfig.userId !== authorId;

        if (canEditThis || canDeleteThis || canEditAny) {
            actionsContainer = document.createElement('div');
            actionsContainer.className = CHAT_MESSAGE_ACTIONS_CLASS + ' ms-auto';

            if (canEditThis || canEditAny) {
                const editBtn = document.createElement('button');
                editBtn.innerHTML = '✏️';
                editBtn.className = `btn btn-sm btn-link p-0 text-secondary ${CHAT_EDIT_BTN_CLASS}`;
                editBtn.title = 'Редактировать';
                editBtn.dataset.messageId = messageId;
                actionsContainer.appendChild(editBtn);
            }
            if (canDeleteThis) {
                const deleteBtn = document.createElement('button');
                deleteBtn.innerHTML = '🗑️';
                deleteBtn.className = `btn btn-sm btn-link p-0 text-danger ms-1 ${CHAT_DELETE_BTN_CLASS}`;
                deleteBtn.title = 'Удалить';
                deleteBtn.dataset.messageId = messageId;
                actionsContainer.appendChild(deleteBtn);
            }
            const header = messageElement.querySelector('.message-header');
            if(header) { header.appendChild(actionsContainer); }
            else { messageElement.appendChild(actionsContainer); }
        }
    }

    // Инициализирует контролы для всех существующих сообщений
    function initializeMessageControls() {
        if (!chatMessagesList || !lessonConfig.userId) return;
        const messages = chatMessagesList.querySelectorAll('li[data-message-id]');
        messages.forEach(addMessageControls);
    }

    //  Отправляет запрос к API чата
    async function fetchChatApi(method = 'GET', body = null) {
         const url = lessonConfig.apiChatUrl;
         const options = { method: method.toUpperCase(), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
         if (body !== null && ['POST', 'PATCH', 'PUT', 'DELETE'].includes(method.toUpperCase())) {
             options.headers['Content-Type'] = 'application/json';
             options.body = JSON.stringify(body);
         }
         try {
             const response = await fetch(url, options);
             if (response.status === 204) { return { success: true }; }
             const data = await response.json().catch(() => ({})); // Возвращаем {} если не JSON
             if (!response.ok) {
                 const errorMessage = data.error || data.message || `HTTP ошибка! Статус: ${response.status}`;
                 throw new Error(errorMessage);
             }
             // Если нет поля success, но статус ok - считаем успехом
             if (typeof data.success === 'undefined') { return { success: true, ...data }; }
             return data;
         } catch (error) {
             console.error(`Ошибка API чата (${method} ${url}):`, error);
             const errorMessage = error instanceof TypeError ? 'Ошибка сети или CORS' : (error.message || 'Неизвестная ошибка API');
             return { success: false, error: errorMessage };
         }
    }

    // Обработчик удаления сообщения
    async function handleDeleteMessage(messageId) {
         if (!confirm('Вы уверены, что хотите удалить это сообщение?')) return;
         const response = await fetchChatApi('DELETE', { message_id: parseInt(messageId, 10) });
         if (response.success) {
             const messageElement = chatMessagesList?.querySelector(`li[data-message-id="${messageId}"]`);
             messageElement?.remove();
         } else {
             alert(`Не удалось удалить сообщение: ${response.error || 'Ошибка'}`);
         }
    }

    // Показывает форму для редактирования сообщения
    function showEditForm(messageId) {
         const messageElement = chatMessagesList?.querySelector(`li[data-message-id="${messageId}"]`);
         if (!messageElement) return;
         hideEditForm(); // Скрываем другие формы
         const textElement = messageElement.querySelector(`.${CHAT_MESSAGE_TEXT_CLASS}`);
         const actionsContainer = messageElement.querySelector(`.${CHAT_MESSAGE_ACTIONS_CLASS}`);
         if (!textElement) return;
         const currentText = Array.from(textElement.childNodes).filter(node => node.nodeType === Node.TEXT_NODE).map(node => node.textContent).join('').trim();
         const form = document.createElement('form');
         form.className = CHAT_EDIT_FORM_CLASS;
         form.dataset.messageId = messageId;
         form.innerHTML = `<textarea class="form-control form-control-sm mb-1" rows="2" required>${escapeHTML(currentText)}</textarea><div class="d-flex justify-content-end"><button type="button" class="btn btn-sm btn-secondary cancel-edit-btn me-1">Отмена</button><button type="submit" class="btn btn-sm btn-success">Сохранить</button></div>`;
         if (textElement) textElement.style.display = 'none';
         if (actionsContainer) actionsContainer.style.display = 'none';
         const contentDiv = messageElement.querySelector('.message-content');
         (contentDiv || messageElement).appendChild(form); // Добавляем форму
         const textarea = form.querySelector('textarea');
         if (textarea) textarea.focus();
         form.addEventListener('submit', handleSaveEdit);
         form.querySelector('.cancel-edit-btn').addEventListener('click', () => hideEditForm(messageId));
     }

    // Скрывает текущую открытую форму редактирования
    function hideEditForm(messageId = null) {
         const formSelector = messageId ? `form.${CHAT_EDIT_FORM_CLASS}[data-message-id="${messageId}"]` : `form.${CHAT_EDIT_FORM_CLASS}`;
         const form = chatMessagesList?.querySelector(formSelector);
         if (form) {
             const actualMessageId = form.dataset.messageId;
             const messageElement = chatMessagesList.querySelector(`li[data-message-id="${actualMessageId}"]`);
             if (messageElement) {
                 const textElement = messageElement.querySelector(`.${CHAT_MESSAGE_TEXT_CLASS}`);
                 const actionsContainer = messageElement.querySelector(`.${CHAT_MESSAGE_ACTIONS_CLASS}`);
                 if (textElement) textElement.style.display = '';
                 if (actionsContainer) actionsContainer.style.display = '';
             }
             form.remove();
         }
     }

    // Обработчик сохранения отредактированного сообщения
    async function handleSaveEdit(event) {
         event.preventDefault();
         const form = event.target;
         const messageId = form.dataset.messageId;
         const textarea = form.querySelector('textarea');
         const newText = textarea.value.trim();
         const submitButton = form.querySelector('button[type="submit"]');
         if (!newText || !messageId) return;
         form.querySelectorAll('button, textarea').forEach(el => el.disabled = true);
         submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; // Индикатор

         const response = await fetchChatApi('PATCH', { message_id: parseInt(messageId, 10), message: newText });

         if (response.success && response.message) {
             const messageElement = chatMessagesList?.querySelector(`li[data-message-id="${messageId}"]`);
             if (messageElement) {
                 const textElement = messageElement.querySelector(`.${CHAT_MESSAGE_TEXT_CLASS}`);
                 if (textElement) {
                     // Обновляем текст через textContent для безопасности
                     textElement.textContent = response.message.message;
                     messageElement.dataset.editedAt = response.message.edited_at;
                     addMessageControls(messageElement); // Обновляем метку и кнопки
                 }
             }
             hideEditForm(messageId); // Скрываем форму
         } else {
             alert(`Не удалось сохранить изменения: ${response.error || 'Ошибка'}`);
             form.querySelectorAll('button, textarea').forEach(el => el.disabled = false);
             submitButton.innerHTML = 'Сохранить';
         }
     }

    // Рендерит одно новое сообщение в чате
    function renderNewMessage(msgData) {
        if (!chatMessagesList || !msgData) { return; }
        let displayName = msgData.display_name || 'Пользователь';
        if (msgData.user_id === lessonConfig.userId && lessonConfig.userFullName) {
             try { 
                const parts = lessonConfig.userFullName.trim().split(/\s+/); 
                if (parts.length >= 2) { 
                    displayName = `${parts[0]} ${parts[1]}`; 
                } else if (parts.length === 1 && parts[0]) { 
                    displayName = lessonConfig.userFullName; 
                } 
            } catch (e) { 
                console.error("Ошибка:", e, lessonConfig.userFullName); 
            }
        }
        const messageElement = document.createElement('li');
        const isOwn = lessonConfig.userId === msgData.user_id;
        messageElement.className = `list-group-item message-item border-0 mb-2 p-2 rounded ${isOwn ? 'message-own' : ''}`;
        messageElement.dataset.messageId = msgData.id;
        messageElement.dataset.authorId = msgData.user_id;
        messageElement.dataset.createdAt = msgData.created_at_iso || msgData.created_at;
        if(msgData.edited_at) messageElement.dataset.editedAt = msgData.edited_at;
        let authorBadge = '';
        if (msgData.role === 'teacher') authorBadge = '<span class="badge role-teacher">Преподаватель</span>';
        else if (msgData.role === 'admin') authorBadge = '<span class="badge role-admin">Админ</span>';

        messageElement.innerHTML = `
            <div class="message-header">
                 <span class="message-author" title="ID: ${msgData.user_id || '?'}">${escapeHTML(displayName)} ${authorBadge}</span>
                 <small class="message-time text-muted" title="${msgData.created_at_iso || msgData.created_at}">${formatShortDateTime(msgData.created_at_iso || msgData.created_at)}</small>
                 <div class="chat-message-actions ms-auto"></div>
             </div>
             <div class="message-content mt-1">
                 <p class="chat-message-text mb-0">${nl2br(escapeHTML(msgData.message || ''))}</p>
                 ${msgData.edited_at ? '<span class="chat-edited-indicator">(изменено)</span>' : ''}
             </div>`;
        // Вставляем сообщение перед якорем #chat-end
    if (chatEndAnchor) {
        chatMessagesList.insertBefore(messageElement, chatEndAnchor);
    } else {
        chatMessagesList.appendChild(messageElement);
    }

    addMessageControls(messageElement);
    // Если сообщение от текущего пользователя, всегда скроллим в конец
    if (msgData.user_id === lessonConfig.userId && chatEndAnchor) {
         console.log('Own message rendered, scrolling to end');
         // Используем auto для мгновенной прокрутки
         chatEndAnchor.scrollIntoView({ behavior: 'auto', block: 'end' });
    }
    }

    // Обработчик отправки нового сообщения
    async function handleSendMessage(event) {
        event.preventDefault();
        if (!chatInput || !chatForm || !lessonConfig.lessonId || !lessonConfig.apiChatUrl) return;
        const messageText = chatInput.value.trim();
        if (!messageText) return;
        const submitButton = chatForm.querySelector('button[type="submit"]');
        const originalButtonHtml = submitButton ? submitButton.innerHTML : '<i class="fas fa-paper-plane"></i>';
        chatInput.disabled = true;
        if (submitButton) { submitButton.disabled = true; submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

        const formData = new FormData();
        formData.append('action', 'send_message'); // Убедимся, что API ожидает action
        formData.append('lesson_id', lessonConfig.lessonId);
        formData.append('message', messageText);

        // Прямой fetch с FormData
        let responseData = { success: false, error: 'Ошибка отправки' };
        try {
            const response = await fetch(lessonConfig.apiChatUrl, { method: 'POST', body: formData });
            if (!response.ok) {
                 const errData = await response.json().catch(() => ({}));
                 throw new Error(errData.message || `HTTP ошибка: ${response.status}`);
            }
            responseData = await response.json();
        } catch (error) {
             console.error('Fetch error in handleSendMessage:', error);
             responseData.error = error.message || 'Ошибка сети';
        } finally {
             chatInput.disabled = false;
             if (submitButton) { submitButton.disabled = false; submitButton.innerHTML = originalButtonHtml; }
        }

        // Обрабатываем ответ
        if (responseData.success && responseData.messageData) {
            chatInput.value = '';
            renderNewMessage(responseData.messageData);
        } else {
            alert(`Ошибка отправки сообщения: ${responseData.error || 'Неизвестная ошибка'}`);
        }
        chatInput.focus();
    }

     // Обработчик нажатия Enter
     function handleKeyDown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            const submitButton = chatForm.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.click();
            } else {
                 chatForm.requestSubmit ? chatForm.requestSubmit() : chatForm.submit();
            }
        }
     }

    // Делегированный обработчик кликов по списку сообщений
    function handleChatListClick(event) {
        const target = event.target;
        const editButton = target.closest(`.${CHAT_EDIT_BTN_CLASS}`);
        const deleteButton = target.closest(`.${CHAT_DELETE_BTN_CLASS}`);
        const cancelEditButton = target.closest('.cancel-edit-btn');

        if (editButton) { showEditForm(editButton.dataset.messageId); return; }
        if (deleteButton) { handleDeleteMessage(deleteButton.dataset.messageId); return; }
        if (cancelEditButton) {
            const form = target.closest(`.${CHAT_EDIT_FORM_CLASS}`);
            if (form && form.dataset.messageId) hideEditForm(form.dataset.messageId);
            return;
        }
    }

     // Проверяет видимость непрочитанных и обновляет индикатор
     function checkUnreadVisibility() {
          if (!unreadIndicator || !firstUnreadElement || !lessonConfig.unreadMessages || lessonConfig.unreadMessages.count <= 0) {
              unreadIndicator?.classList.remove('visible'); return;
          }
          const chatRect = chatMessagesList.getBoundingClientRect();
          const unreadRect = firstUnreadElement.getBoundingClientRect();
          if (unreadRect.top > chatRect.bottom - 10) {
              if(unreadCountSpan) unreadCountSpan.textContent = lessonConfig.unreadMessages.count;
              unreadIndicator.classList.add('visible');
          } else {
              unreadIndicator.classList.remove('visible');
          }
     }

     // Отправляет запрос на отметку о прочтении
     function markChatAsRead() {
          const lessonId = lessonConfig.lessonId;
          const apiUrl = lessonConfig.baseUrl + 'api/chat_mark_read.php';
          // Отправляем как x-www-form-urlencoded для совместимости с $_POST в API
          fetch(apiUrl, {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
              body: `lesson_id=${encodeURIComponent(lessonId)}`
          })
          .then(response => { if (!response.ok) { return response.json().then(err => { throw new Error(err.message || response.statusText) }).catch(()=> {throw new Error(response.statusText)}) } return response.json(); })
          .then(data => { if (data.success) { console.log('Чат отмечен прочитанным.'); if (chatTabButton) chatTabButton.classList.remove('has-unread'); } else { console.error('Ошибка отметки (сервер):', data.message); } })
          .catch(error => { console.error('Сетевая ошибка отметки чата:', error); });
     }

    // Прокрутка чата к концу
    window.scrollToChatEnd = function() {
        if (chatMessagesList && chatEndAnchor) {
             chatEndAnchor.scrollIntoView({ behavior: 'auto', block: 'end' });
        } else if (chatMessagesList) {
             chatMessagesList.scrollTop = chatMessagesList.scrollHeight;
        }
    }

    // Инициализация для списка сообщений
    if (chatMessagesList) {
        if (lessonConfig.unreadMessages && lessonConfig.unreadMessages.firstId) {
             firstUnreadElement = chatMessagesList.querySelector(`li[data-message-id="${lessonConfig.unreadMessages.firstId}"]`);
        }
        initializeMessageControls();
        chatMessagesList.addEventListener('scroll', checkUnreadVisibility); 
        chatMessagesList.addEventListener('click', handleChatListClick);
        setTimeout(checkUnreadVisibility, 500);
    }

    // Инициализация для формы отправки
    if (chatForm && chatInput) { 
        console.log('Adding chat form listeners (submit and keydown)...');
        chatForm.addEventListener('submit', handleSendMessage);
        chatInput.addEventListener('keydown', handleKeyDown);
    } else {
        console.warn("Форма чата (#chat-form) или поле ввода (#chat-message-input) не найдены. Отправка сообщений не будет работать.");
    }

    // Инициализация для индикатора непрочитанных 
     if (unreadIndicator) { 
         unreadIndicator.addEventListener('click', () => {
              const firstUnreadId = lessonConfig.unreadMessages?.firstId;
              const firstUnreadOnClick = firstUnreadId
                   ? chatMessagesList?.querySelector(`li[data-message-id="${firstUnreadId}"]`)
                   : null;
             if (firstUnreadOnClick) {
                 console.log('Unread indicator clicked, scrolling to:', firstUnreadOnClick);
                 firstUnreadOnClick.scrollIntoView({ behavior: 'smooth', block: 'start' });
             } else { console.warn('Could not find first unread element on indicator click.'); }
         });
     }
     markChatAsRead();
}