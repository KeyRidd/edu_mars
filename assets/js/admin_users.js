document.addEventListener('DOMContentLoaded', function() {
    if (typeof pageConfig === 'undefined' || !pageConfig || !pageConfig.apiUserUrl) {
         console.error('Объект конфигурации "pageConfig" или "pageConfig.apiUserUrl" не найдены или некорректны!');
         return;
    }

    const userModalElement = document.getElementById('userFormModal');
    if (!userModalElement) {
        console.error("Модальное окно Bootstrap #userFormModal не найдено!");
        return;
    }
    const userModal = new bootstrap.Modal(userModalElement);

    const addUserBtn = document.getElementById('add-user-btn-main');
    const userForm = userModalElement.querySelector('#user-form-modal');
    const userModalTitle = userModalElement.querySelector('#userModalLabel');
    const userIdInput = userModalElement.querySelector('#modal_edit_user_id');
    const passwordInput = userModalElement.querySelector('#modal_edit_password');
    const passwordHelp = userModalElement.querySelector('#modal-password-help');
    const fullNameInput = userModalElement.querySelector('#modal_edit_full_name');
    const emailInput = userModalElement.querySelector('#modal_edit_email');
    const roleInput = userModalElement.querySelector('#modal_edit_role');
    const groupSection = userModalElement.querySelector('#modal-group-assignment-section');
    const groupSelect = userModalElement.querySelector('#modal_edit_group_id');
    const modalFormErrorsPlaceholder = userModalElement.querySelector('#modal-form-errors-placeholder');

    function toggleGroupSection(show) {
        if (groupSection && groupSelect) {
            groupSection.style.display = show ? 'block' : 'none';
            groupSelect.required = show;
            if (!show) groupSelect.value = "";
        }
    }

    if (roleInput) {
        roleInput.addEventListener('change', function() {
            toggleGroupSection(this.value === 'student');
        });
    }

    if (addUserBtn) {
        addUserBtn.addEventListener('click', () => {
            // Проверка без usernameInput
            if (!userForm || !userModalTitle || !userIdInput || !passwordInput || !passwordHelp || !roleInput || !fullNameInput || !emailInput || !groupSelect) {
                console.error("Не все элементы формы найдены для 'Добавить пользователя'");
                return;
            }
            userModalTitle.textContent = 'Добавление пользователя';
            userForm.reset();
            if(modalFormErrorsPlaceholder) modalFormErrorsPlaceholder.innerHTML = '';
            userIdInput.value = '0';
            passwordInput.required = true; // Пароль обязателен при создании
            if(passwordHelp) passwordHelp.textContent = 'Обязателен при создании (мин. 8 символов, включая заглавную, строчную буквы и цифру).';
            roleInput.value = 'student'; // Роль по умолчанию
            toggleGroupSection(true); // Группа для студента по умолчанию видима
            userModal.show();
        });
    }

    document.querySelectorAll('.edit-user-btn-table').forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.getAttribute('data-id');
            const apiGetUserUrl = `${pageConfig.apiUserUrl}?action=get_user&id=${userId}`;
            console.log("Запрос GET на URL:", apiGetUserUrl);

            fetch(apiGetUserUrl)
                .then(response => {
                    if (!response.ok) { return Promise.reject(`Ошибка ${response.status}: ${response.statusText}`); }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.user) {
                        // Проверка без usernameInput
                        if (!userForm || !userModalTitle || !userIdInput || !passwordInput || !passwordHelp || !fullNameInput || !emailInput || !roleInput || !groupSelect) {
                             console.error("Не все элементы формы найдены для 'Редактировать пользователя'");
                             return;
                        }
                        userModalTitle.textContent = 'Редактирование пользователя';
                        userForm.reset();
                        if(modalFormErrorsPlaceholder) modalFormErrorsPlaceholder.innerHTML = '';

                        userIdInput.value = data.user.id;
                        fullNameInput.value = data.user.full_name || '';
                        emailInput.value = data.user.email || '';
                        roleInput.value = data.user.role || 'student';

                        const isStudent = data.user.role === 'student';
                        toggleGroupSection(isStudent);
                        if (isStudent) {
                            groupSelect.value = data.user.group_id || "";
                        }

                        passwordInput.value = '';
                        passwordInput.required = false; // Пароль не обязателен при редактировании
                        if(passwordHelp) passwordHelp.textContent = 'Оставьте пустым, чтобы не менять пароль.';
                        userModal.show();
                    } else {
                        alert('Ошибка получения данных: ' + (data.message || 'Нет данных от сервера'));
                    }
                })
                .catch(error => {
                    console.error('Fetch Error (GET User):', error);
                    alert('Произошла ошибка при загрузке данных пользователя. Подробнее в консоли.');
                });
        });
    });

    if (userForm) {
        userForm.addEventListener('submit', function(event) {
            event.preventDefault();
            if(modalFormErrorsPlaceholder) modalFormErrorsPlaceholder.innerHTML = '';

            const formData = new FormData(this);
            const submitButton = userForm.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Сохранение...';

            fetch(pageConfig.apiUserUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    userModal.hide();
                    window.location.reload(); // Перезагрузка для обновления таблицы и флеш-сообщения от PHP
                } else {
                    if (modalFormErrorsPlaceholder && data.errors && Array.isArray(data.errors)) {
                        let errorsHtml = '<div class="alert alert-danger mb-3"><strong>Ошибка валидации:</strong><ul>';
                        data.errors.forEach(err => { errorsHtml += `<li>${escapeHtml(err)}</li>`; });
                        errorsHtml += '</ul></div>';
                        modalFormErrorsPlaceholder.innerHTML = errorsHtml;
                    } else {
                        // Отображаем общее сообщение об ошибке, если нет специфичных ошибок валидации
                         if (modalFormErrorsPlaceholder) {
                             modalFormErrorsPlaceholder.innerHTML = `<div class="alert alert-danger mb-3">${escapeHtml(data.message || 'Неизвестная ошибка.')}</div>`;
                         } else {
                            alert('Ошибка сохранения: ' + (data.message || 'Неизвестная ошибка.'));
                         }
                    }
                }
            })
            .catch(error => {
                console.error('Fetch Error (Save User):', error);
                if (modalFormErrorsPlaceholder) {
                     modalFormErrorsPlaceholder.innerHTML = '<div class="alert alert-danger">Сетевая ошибка или ошибка сервера. Попробуйте позже.</div>';
                } else {
                    alert('Произошла ошибка при сохранении данных. Подробнее в консоли.');
                }
            })
            .finally(() => {
                if(submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonText;
                }
            });
        });
    }

    if (pageConfig.showModalOnLoad) {
        // Проверка без usernameInput
        if (!userForm || !userModalTitle || !userIdInput || !passwordInput || !passwordHelp || !roleInput || !groupSelect || !fullNameInput || !emailInput) {
             console.error("Не все элементы формы найдены для предзаполнения из pageConfig.formData");
        } else {
            userModal.show();
            const loadedUserId = parseInt(pageConfig.formData?.user_id || '0');
            userModalTitle.textContent = (loadedUserId > 0) ? 'Редактирование (проверьте ошибки)' : 'Добавление (проверьте ошибки)';

            passwordInput.required = (loadedUserId === 0);
            if(passwordHelp) passwordHelp.textContent = (loadedUserId > 0) ? 'Оставьте пустым, чтобы не менять пароль.' : 'Обязателен при создании (мин. 8 символов, включая заглавную, строчную буквы и цифру).';
            if (roleInput.value) { 
                toggleGroupSection(roleInput.value === 'student');
            } else { 
                toggleGroupSection(false);
            }
        }
    }

    const deleteUserButtons = document.querySelectorAll('.delete-item-btn');
    deleteUserButtons.forEach(button => {
        button.addEventListener('click', function(event) {
        });
    });
});

// Вспомогательная функция для экранирования HTML
function escapeHtml(unsafe) {
    if (typeof unsafe !== 'string') return '';
    return unsafe
         .replace(/&/g, "&amp;")  // Амперсанд
         .replace(/</g, "&lt;")   // Меньше
         .replace(/>/g, "&gt;")   // Больше
         .replace(/"/g, "&quot;") // Двойная кавычка
         .replace(/'/g, "&#39;"); // Одинарная кавычка
}