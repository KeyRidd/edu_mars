document.addEventListener('DOMContentLoaded', function() {
    console.log('Teacher Debtors JS Initialized (with Modal Logic)');
    const filterForm = document.getElementById('debtorsFilterForm');
    const groupSelect = document.getElementById('group_id_filter');
    const subjectSelect = document.getElementById('subject_id_filter');
    const showButton = filterForm ? filterForm.querySelector('button[type="submit"]') : null;

    // Элементы DOM для списка задолжников
    const debtorsListForm = document.getElementById('debtorsNotificationForm'); // Основная форма со списком
    const selectAllCheckbox = document.getElementById('select-all-debtors-checkbox');
    const debtorCheckboxes = document.querySelectorAll('.debtor-checkbox');
    const openNotificationModalButton = document.getElementById('openNotificationModalBtn'); // Кнопка открытия модалки

    // Элементы DOM для модалки
    const notificationModalElement = document.getElementById('notificationModal');
    let bootstrapNotificationModal = null; 
    if (notificationModalElement) {
        bootstrapNotificationModal = new bootstrap.Modal(notificationModalElement);
    }
    const notificationModalForm = document.getElementById('sendSystemNotificationModalForm');
    const selectedDebtorsCountModalSpan = document.getElementById('selectedDebtorsCountModal');
    const notificationTitleInput = document.getElementById('notificationTitleModal');
    const notificationMessageInput = document.getElementById('notificationMessageModal');
    const sendSystemNotificationModalSubmitBtn = document.getElementById('sendSystemNotificationModalSubmitBtn');


    // Логика для фильтров
    function updateShowButtonState() {
        if (groupSelect && subjectSelect && showButton) {
            const groupSelectedValue = groupSelect.value;
            const subjectSelectedValue = subjectSelect.value;
            const isGroupSelected = groupSelectedValue && groupSelectedValue !== '0' && groupSelectedValue !== '';
            const isSubjectSelected = subjectSelectedValue && subjectSelectedValue !== '0' && subjectSelectedValue !== '';
            showButton.disabled = !(isGroupSelected && isSubjectSelected);
        }
    }
    if (groupSelect && filterForm) {
        groupSelect.addEventListener('change', function() {
            if (subjectSelect) subjectSelect.value = '';
            filterForm.submit();
        });
    }
    if (subjectSelect) {
        subjectSelect.addEventListener('change', updateShowButtonState);
    }
    if (subjectSelect && groupSelect) {
        subjectSelect.disabled = !(groupSelect.value && groupSelect.value !== '0' && groupSelect.value !== '');
    }
    updateShowButtonState();


    // Логика для чекбоксов и открытия модалки
    function toggleOpenModalButtonState() {
        if (openNotificationModalButton && debtorCheckboxes) {
            const anyChecked = Array.from(debtorCheckboxes).some(cb => cb.checked);
            openNotificationModalButton.disabled = !anyChecked;
        }
    }

    if (selectAllCheckbox && debtorCheckboxes.length > 0) {
        selectAllCheckbox.addEventListener('change', function() {
            debtorCheckboxes.forEach(checkbox => checkbox.checked = selectAllCheckbox.checked);
            toggleOpenModalButtonState();
        });
    }
    debtorCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            if (selectAllCheckbox) {
                if (!this.checked) selectAllCheckbox.checked = false;
                else selectAllCheckbox.checked = Array.from(debtorCheckboxes).every(cb => cb.checked);
            }
            toggleOpenModalButtonState();
        });
    });
    toggleOpenModalButtonState();

    // При открытии модального окна, обновляем счетчик
    if (notificationModalElement) {
        notificationModalElement.addEventListener('show.bs.modal', function () {
            const checkedCount = Array.from(debtorCheckboxes).filter(cb => cb.checked).length;
            if (selectedDebtorsCountModalSpan) {
                selectedDebtorsCountModalSpan.textContent = checkedCount;
            }
            if(notificationTitleInput) notificationTitleInput.value = "Уведомление о задолженности";
            if(notificationMessageInput) notificationMessageInput.value = "Введите текст сообщения.";
        });
    }


    // Логика отправки уведомлений
    if (notificationModalForm && debtorsListForm && sendSystemNotificationModalSubmitBtn && bootstrapNotificationModal) {
        notificationModalForm.addEventListener('submit', function(event) { 
            event.preventDefault();

            // Показываем индикатор загрузки
            sendSystemNotificationModalSubmitBtn.disabled = true;
            sendSystemNotificationModalSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';

            const title = notificationTitleInput.value;
            const messageTemplate = notificationMessageInput.value;
            const checkedCheckboxes = debtorsListForm.querySelectorAll('.debtor-checkbox:checked');
            const studentIds = Array.from(checkedCheckboxes).map(cb => cb.value);

            const groupId = debtorsListForm.querySelector('input[name="group_id_form"]')?.value;
            const subjectId = debtorsListForm.querySelector('input[name="subject_id_form"]')?.value;

            if (studentIds.length === 0) {
                alert('Ошибка: Студенты не выбраны.');
                sendSystemNotificationModalSubmitBtn.disabled = false;
                sendSystemNotificationModalSubmitBtn.innerHTML = 'Отправить уведомления';
                return;
            }
            if (!messageTemplate.trim()) {
                alert('Введите текст уведомления.');
                notificationMessageInput.focus();
                sendSystemNotificationModalSubmitBtn.disabled = false;
                sendSystemNotificationModalSubmitBtn.innerHTML = 'Отправить уведомления';
                return;
            }
            if (!groupId || !subjectId) {
                alert('Ошибка: Не удалось определить группу или дисциплину для контекста уведомления.');
                sendSystemNotificationModalSubmitBtn.disabled = false;
                sendSystemNotificationModalSubmitBtn.innerHTML = 'Отправить уведомления';
                return;
            }

            const config = window.teacherDebtorsPageConfig || {};
            const apiUrl = config.sendNotificationUrl;
            if (!apiUrl) {
                alert('Ошибка конфигурации: URL для отправки уведомлений не найден.');
                sendSystemNotificationModalSubmitBtn.disabled = false;
                sendSystemNotificationModalSubmitBtn.innerHTML = 'Отправить уведомления';
                return;
            }

            // Создаем временную форму для отправки данных методом POST
            const tempForm = document.createElement('form');
            tempForm.method = 'POST';
            tempForm.action = apiUrl;

            // Добавляем поля во временную форму
            studentIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = 'student_ids[]'; input.value = id;
                tempForm.appendChild(input);
            });

            const fieldsToSubmit = {
                'group_id': groupId, 
                'subject_id': subjectId, 
                'notification_title': title,
                'notification_message_template': messageTemplate,
                'action_type': 'send_debtor_notification'
            };

            for (const key in fieldsToSubmit) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = fieldsToSubmit[key];
                tempForm.appendChild(input);
            }

            document.body.appendChild(tempForm);
            tempForm.submit(); 
        });
    }
});