document.addEventListener('DOMContentLoaded', function() {
    const config = window.profilePageConfig || {};

    const modalElement = document.getElementById('edit-profile-modal');
    const openBtn = document.getElementById('edit-profile-btn');
    const profileForm = document.getElementById('profile-form');
    const modalFormErrorsPlaceholder = document.getElementById('modal-profile-form-errors'); 

    let bootstrapModalInstance = null;
    if (modalElement) {
    }

    // Элементы полей пароля
    const newPasswordInput = document.getElementById('profile_edit_password');
    const confirmNewPasswordInput = document.getElementById('profile_edit_password_confirm');
    const confirmFeedback = document.getElementById('confirm_password_feedback_profile_modal');

    // Функция переключения видимости пароля
    const togglePasswordVisibility = (inputId, iconWrapperId) => {
        const passwordInput = document.getElementById(inputId);
        const iconWrapper = document.getElementById(iconWrapperId);
        if (passwordInput && iconWrapper) {
            const icon = iconWrapper.querySelector("i");
            iconWrapper.addEventListener('click', function () {
                const type = passwordInput.getAttribute("type") === "password" ? "text" : "password";
                passwordInput.setAttribute("type", type);
                if (icon) {
                    icon.classList.toggle("fa-eye");
                    icon.classList.toggle("fa-eye-slash");
                    this.setAttribute("title", type === "password" ? "Показать пароль" : "Скрыть пароль");
                }
            });
        }
    };

    togglePasswordVisibility('profile_edit_password', 'toggle_profile_password_icon');
    togglePasswordVisibility('profile_edit_password_confirm', 'toggle_profile_confirm_password_icon');


    // Функция валидации совпадения паролей
    function validateProfilePasswordConfirmation() {
        if (!newPasswordInput || !confirmNewPasswordInput || !confirmFeedback) return;

        if (newPasswordInput.value !== "") {
            confirmNewPasswordInput.required = true; // Делаем обязательным, если новый пароль введен
            if (newPasswordInput.value !== confirmNewPasswordInput.value && confirmNewPasswordInput.value !== "") {
                confirmNewPasswordInput.setCustomValidity("Пароли не совпадают.");
                confirmFeedback.textContent = "Пароли не совпадают.";
                confirmNewPasswordInput.classList.add('is-invalid');
            } else {
                confirmNewPasswordInput.setCustomValidity("");
                confirmFeedback.textContent = "Пожалуйста, подтвердите новый пароль.";
                confirmNewPasswordInput.classList.remove('is-invalid');
            }
        } else {
            confirmNewPasswordInput.required = false; // Не обязательно, если новый пароль пуст
            confirmNewPasswordInput.setCustomValidity("");
            confirmFeedback.textContent = "Пожалуйста, подтвердите новый пароль.";
            confirmNewPasswordInput.classList.remove('is-invalid');
        }
         // Триггерим проверку валидности формы для обновления состояния
        if (profileForm && profileForm.classList.contains('was-validated')) {
            confirmNewPasswordInput.checkValidity();
        }
    }

    if (newPasswordInput) newPasswordInput.addEventListener('input', validateProfilePasswordConfirmation);
    if (confirmNewPasswordInput) confirmNewPasswordInput.addEventListener('input', validateProfilePasswordConfirmation);


    // Логика открытия/закрытия кастомного модального окна
    if (openBtn && modalElement) {
        openBtn.addEventListener('click', () => {
            if (profileForm) {
                profileForm.reset(); 
                profileForm.classList.remove('was-validated');
                if(modalFormErrorsPlaceholder) modalFormErrorsPlaceholder.innerHTML = '';
                // Сбрасываем setCustomValidity для полей пароля
                if(newPasswordInput) newPasswordInput.setCustomValidity("");
                if(confirmNewPasswordInput) confirmNewPasswordInput.setCustomValidity("");
                if(confirmFeedback) confirmFeedback.textContent = "Пожалуйста, подтвердите новый пароль.";
            }
            modalElement.style.display = 'block';
            if (newPasswordInput) newPasswordInput.focus();
        });
    }

    const closeBtnsModal = modalElement ? modalElement.querySelectorAll('.close-modal-btn') : [];
    closeBtnsModal.forEach(btn => {
        btn.addEventListener('click', () => {
            if (modalElement) modalElement.style.display = 'none';
        });
    });

    if (modalElement) {
        window.addEventListener('click', (event) => {
            if (event.target === modalElement) {
                modalElement.style.display = 'none';
            }
        });
    }

    // Обработка отправки формы AJAX
    if (profileForm && modalElement) {
        profileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            validateProfilePasswordConfirmation();

            if (!this.checkValidity()) { 
                this.classList.add('was-validated');
                // Ищем первое невалидное поле и фокусируемся на нем
                const firstInvalidField = this.querySelector(':invalid');
                if (firstInvalidField) firstInvalidField.focus();
                return;
            }
            this.classList.add('was-validated');


            const formData = new FormData(this);
            // Добавляем CSRF-токен в FormData, если он есть в конфиге
            if (config.csrfToken) {
                formData.append('csrf_token', config.csrfToken);
            }

            const submitButton = this.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Сохранение...';
            
            if (!config.apiUserUrl) {
                displayModalError('Ошибка конфигурации: URL для отправки формы не найден.');
                console.error('apiUserUrl is undefined in profilePageConfig.');
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonText;
                return;
            }

            fetch(config.apiUserUrl, { method: 'POST', body: formData })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(errData => {
                            throw { status: response.status, data: errData };
                        }).catch(() => { 
                            return response.text().then(text => { throw { status: response.status, text: text }; });
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        if (modalElement) modalElement.style.display = 'none';
                        try {
                             localStorage.setItem('flashMessageProfile', JSON.stringify({ type: 'success', text: data.message || 'Данные успешно обновлены!' }));
                        } catch(lsError) { console.warn("Не удалось сохранить flashMessageProfile в localStorage"); }
                        window.location.reload();
                    } else {
                        // Отображаем ошибки валидации в модалке
                        if (data.errors && Array.isArray(data.errors)) {
                            let errorsHtml = '<strong>Обнаружены ошибки:</strong><ul class="mb-0 ps-3">';
                            data.errors.forEach(err => { errorsHtml += `<li>${escapeHtmlForJS(err)}</li>`; });
                            errorsHtml += '</ul>';
                            displayModalError(errorsHtml, 'danger');
                        } else {
                            displayModalError(data.message || 'Неизвестная ошибка от сервера.', 'danger');
                        }
                    }
                })
                .catch(errObj => {
                    let errorTextToShow = 'Произошла ошибка при отправке данных.';
                    if (errObj && errObj.data && errObj.data.message) {
                        errorTextToShow = errObj.data.message;
                        if(errObj.data.errors) console.error('Server Validation Errors:', errObj.data.errors);
                    } else if (errObj && errObj.text) {
                        console.error('Server Error (Non-JSON):', errObj.text);
                        errorTextToShow = 'Ошибка сервера. Ответ не в формате JSON.';
                    } else if (errObj && errObj.message) {
                        console.error('Fetch/Network Error:', errObj.message);
                        errorTextToShow = errObj.message;
                    }
                    displayModalError(errorTextToShow, 'danger');
                })
                .finally(() => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonText;
                });
        });
    }

    // Функция для отображения ошибок внутри модального окна
    function displayModalError(message, type = 'danger') {
        if (modalFormErrorsPlaceholder) {
            modalFormErrorsPlaceholder.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>`;
        } else {
            alert(message.replace(/<[^>]*>?/gm, '')); 
        }
    }
    
    // Вспомогательная функция для экранирования HTML
function escapeHtmlForJS(unsafe) {
    if (typeof unsafe !== 'string' || unsafe === null || typeof unsafe === 'undefined') { 
        return '';
    }
    return unsafe
             .replace(/&/g, "&amp;")  // Амперсанд
             .replace(/</g, "&lt;")   // Меньше
             .replace(/>/g, "&gt;")   // Больше
             .replace(/"/g, "&quot;") // Двойная кавычка
             .replace(/'/g, "&#39;"); // Одинарная кавычка
}

    // Отображение модального окна при ошибках
    if (modalElement && config.showProfileModalOnError === true) {
        modalElement.style.display = 'block';
        if (newPasswordInput) newPasswordInput.focus(); 
    }

    // Отображение флеш-сообщения из localStorage
    try {
    const flashMessageJSON = localStorage.getItem('flashMessageProfile');
    if (flashMessageJSON) {
        const flashMessage = JSON.parse(flashMessageJSON);
        const mainContentArea = document.querySelector('.main-container-wrapper.container .content-area-wrapper'); 
        let alertContainer = document.querySelector('.profile-page > .container'); 

        if (mainContentArea) { 
            alertContainer = mainContentArea;
        }
        
        if (alertContainer) {
             const alertDiv = document.createElement('div');
             alertDiv.className = `alert alert-${flashMessage.type || 'info'} alert-dismissible fade show my-3`;
             alertDiv.setAttribute('role', 'alert');
             alertDiv.innerHTML = `${escapeHtmlForJS(flashMessage.text)} <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
             alertContainer.insertBefore(alertDiv, alertContainer.firstChild);
        }
        localStorage.removeItem('flashMessageProfile');
    }
} catch (e) { console.error("Error processing flash message from localStorage:", e); }

});