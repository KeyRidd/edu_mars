document.addEventListener('DOMContentLoaded', function() {
    // Конфигурация и проверка
    console.log('Lesson Main JS Initializing...');
    if (typeof lessonConfig === 'undefined' || !lessonConfig || !lessonConfig.lessonId) {
        console.error('lessonConfig or lessonConfig.lessonId is not defined!');
        return;
    }

    // Функция активации вкладки
    function activateTab(tabId) {
         const tabButton = document.getElementById(`${tabId}-tab`);
         if (tabButton) {
              try { 
                  const tab = new bootstrap.Tab(tabButton);
                  tab.show();
              } catch (e) { console.error("Ошибка активации вкладки Bootstrap:", e); }
         } else { console.warn(`Кнопка для вкладки #${tabId}-tab не найдена`); }
     }

     // Функция для показа/скрытия форм 
     function setupFormToggle(addButton, cancelButton, formElement) {
         if (addButton && cancelButton && formElement) {
             addButton.addEventListener('click', function() {
                 formElement.style.display = 'block';
                 this.style.display = 'none';
             });
             cancelButton.addEventListener('click', function() {
                 formElement.style.display = 'none';
                 formElement.querySelector('form')?.reset();
                 addButton.style.display = 'inline-block';
             });
         } else {
              if (!addButton && formElement) console.warn(`Кнопка добавления для формы ${formElement.id} не найдена.`);
         }
     }
    // Для вкладки материалы
    const addMaterialBtn = document.getElementById('add-material-btn');
    const cancelMaterialBtn = document.getElementById('cancel-material-btn');
    const materialForm = document.getElementById('material-form');
    setupFormToggle(addMaterialBtn, cancelMaterialBtn, materialForm);

    // Показать/скрыть форму загрузки студента
    document.querySelectorAll('.student-upload-btn').forEach(button => {
         button.addEventListener('click', (event) => {
              const defId = event.currentTarget.dataset.defId;
              const form = document.getElementById(`student-upload-form-${defId}`);
              if (form) { form.style.display = 'block'; event.currentTarget.style.display = 'none'; }
         });
    });
    document.querySelectorAll('.cancel-student-upload-btn').forEach(button => {
          button.addEventListener('click', (event) => {
               const defId = event.currentTarget.dataset.defId;
               const form = document.getElementById(`student-upload-form-${defId}`);
               const uploadButton = document.querySelector(`.student-upload-btn[data-def-id="${defId}"]`);
               if (form) form.style.display = 'none';
               if (uploadButton) uploadButton.style.display = 'inline-block';
          });
      });
    // Показать/скрыть форму проверки преподавателя
    document.querySelectorAll('.review-btn').forEach(button => {
         button.addEventListener('click', (event) => {
              const assignmentId = event.currentTarget.dataset.id;
              const form = document.getElementById(`review-form-${assignmentId}`);
              if (form) {
                  document.querySelectorAll('.review-form').forEach(f => { if (f !== form) f.style.display = 'none'; });
                   document.querySelectorAll('.review-btn').forEach(b => { if(b !== event.currentTarget) b.style.display = 'inline-block'; });
                  form.style.display = 'block'; event.currentTarget.style.display = 'none';
              }
         });
    });
    document.querySelectorAll('.cancel-review-btn').forEach(button => {
          button.addEventListener('click', (event) => {
               const assignmentId = event.currentTarget.dataset.id;
               const form = document.getElementById(`review-form-${assignmentId}`);
               const reviewButton = document.querySelector(`.review-btn[data-id="${assignmentId}"]`);
               if (form) form.style.display = 'none';
               if (reviewButton) reviewButton.style.display = 'inline-block';
          });
      });
    // Функция подтверждения удаления определения задания
    window.confirmAssignmentDelete = function(assignmentDefId, lessonId) {
         if (confirm('Удалить это определение задания и все сданные по нему работы?')) {
             window.location.href = `${lessonConfig.baseUrl}actions/delete_item.php?type=assignment_definition&id=${assignmentDefId}&lesson_id=${lessonId}&confirm=yes`;
         }
     }

    const storageKey = `activeLessonTab_${lessonConfig.lessonId}`;
    let initialActiveTabId = 'materials';

    // Определяем начальную вкладку
    const urlParams = new URLSearchParams(window.location.search);
    const urlTab = urlParams.get('tab');
    const savedTab = localStorage.getItem(storageKey);

    if (urlTab && document.getElementById(`${urlTab}-pane`)) { initialActiveTabId = urlTab; }
    else if (savedTab && document.getElementById(`${savedTab}-pane`)) { initialActiveTabId = savedTab; }
    else if (document.querySelector('.nav-tabs .nav-link')) { initialActiveTabId = document.querySelector('.nav-tabs .nav-link').id.replace('-tab',''); }

   // Функция умной прокрутки
   function smartScrollChat(isInitialLoad = false) {
       let firstUnreadEl = null;
       if (lessonConfig.unreadMessages && lessonConfig.unreadMessages.count > 0 && lessonConfig.unreadMessages.firstId) {
           firstUnreadEl = document.getElementById('chat-messages-list')?.querySelector(`li[data-message-id="${lessonConfig.unreadMessages.firstId}"]`);
       }
       const chatEndEl = document.getElementById('chat-end');

       if (firstUnreadEl) {
           // Если есть непрочитанные, скроллим к первому непрочитанному
           console.log('Scrolling to first unread message:', firstUnreadEl);
           firstUnreadEl.scrollIntoView({ behavior: isInitialLoad ? 'auto' : 'smooth', block: 'start' });
       } else if (chatEndEl) {
           // Если нет непрочитанных, скроллим в конец
           console.log('Scrolling to chat end.');
           chatEndEl.scrollIntoView({ behavior: isInitialLoad ? 'auto' : 'smooth', block: 'end' });
       }
   }
    // Логика прокрутки чата при загрузке
    if (initialActiveTabId === 'chat' && typeof window.scrollToChatEnd === 'function') { 
         const initialChatTabButton = document.getElementById('chat-tab');
         if (initialChatTabButton) {
              initialChatTabButton.addEventListener('shown.bs.tab', function() {
                   console.log('Chat tab shown on load, smart scrolling...');
                   smartScrollChat(true);
              }, { once: true });
         } else {
               console.log('Chat tab is initial, smart scrolling with timeout...');
               setTimeout(() => smartScrollChat(true), 300);
         }
    }
    // Активируем начальную вкладку
    if (initialActiveTabId) {
     const initialTabButton = document.getElementById(`${initialActiveTabId}-tab`);
     if (initialTabButton) {
          try {
              const tab = new bootstrap.Tab(initialTabButton);
              tab.show();
          } catch(e) { console.error("Ошибка при попытке показать начальную вкладку:", e, initialTabButton); }
     }
}

    // Обработчики кликов по вкладкам для сохранения состояния и умной прокрутки
     document.querySelectorAll('#lessonTab .nav-link').forEach(tabButton => {
         // Сохраняем активную вкладку (этот обработчик оставляем)
         tabButton.addEventListener('click', function(event) {
              const tabId = this.getAttribute('data-bs-target').substring(1).replace('-pane','');
              try { localStorage.setItem(storageKey, tabId); } catch (e) {}
         });

          // Добавляем обработчик после показа вкладки чата
          if (tabButton.id === 'chat-tab') {
               tabButton.addEventListener('shown.bs.tab', function () {
                    console.log('Chat tab shown on click, smart scrolling...');
                    smartScrollChat(false);
               });
          }
     });

   console.log('Lesson Main JS Initialized.');
});