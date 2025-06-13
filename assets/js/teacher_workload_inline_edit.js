document.addEventListener('DOMContentLoaded', function() {
    if (typeof teacherWorkloadConfig === 'undefined' || !teacherWorkloadConfig.apiUpdateLessonRowUrl) {
        console.error('Объект teacherWorkloadConfig или teacherWorkloadConfig.apiUpdateLessonRowUrl не определены! ' +
                      'Инлайн-редактирование уроков не будет работать.');
        return; 
    }
    const API_URL_FOR_LESSON_UPDATE = teacherWorkloadConfig.apiUpdateLessonRowUrl;
    const tableBody = document.querySelector('.table tbody');

    if (tableBody) {
        tableBody.addEventListener('click', function(event) {
            const target = event.target;
            const editButton = target.closest('.action-edit-inline');

            if (editButton) {
                const lessonId = editButton.dataset.lessonId;
                const row = editButton.closest('tr[data-lesson-row-id="' + lessonId + '"]');
                if (row && !row.classList.contains('is-editing')) {
                    enableRowEditing(row, lessonId);
                }
            }
        });
    }

    function enableRowEditing(rowElement, lessonId) {
        rowElement.classList.add('is-editing');

        const titleCell = rowElement.querySelector('.lesson-title-cell');
        const descriptionCell = rowElement.querySelector('.lesson-description-cell');
        const actionsCell = rowElement.querySelector('.lesson-actions-cell');

        const originalTitleHTML = titleCell.innerHTML;
        const originalDescriptionHTML = descriptionCell.innerHTML;
        const originalActionsHTML = actionsCell.innerHTML;

        const currentTitle = titleCell.querySelector('.lesson-title-text').textContent.trim();
        titleCell.innerHTML = `<input type="text" class="form-control form-control-sm editing-input lesson-title-input" value="${escapeHtml(currentTitle)}">`;

        let currentDescription = '';
        const fullDescriptionDiv = descriptionCell.querySelector('.full-description');
        if (fullDescriptionDiv) {
            currentDescription = fullDescriptionDiv.textContent.trim();
        } else {
            currentDescription = descriptionCell.querySelector('.lesson-description-text').textContent.trim();
        }
        descriptionCell.innerHTML = `<textarea class="form-control form-control-sm editing-textarea lesson-description-input" rows="3">${escapeHtml(currentDescription)}</textarea>`;

        actionsCell.innerHTML = `
            <button type="button" class="btn btn-sm btn-success action-save-row me-1" title="Сохранить изменения">
                <i class="fas fa-save"></i>
            </button>
            <button type="button" class="btn btn-sm btn-secondary action-cancel-row" title="Отменить редактирование">
                <i class="fas fa-times"></i>
            </button>
        `;

        actionsCell.querySelector('.action-save-row').addEventListener('click', function() {
            saveRowChanges(rowElement, lessonId, originalTitleHTML, originalDescriptionHTML, originalActionsHTML);
        });
        actionsCell.querySelector('.action-cancel-row').addEventListener('click', function() {
            cancelRowEditing(rowElement, originalTitleHTML, originalDescriptionHTML, originalActionsHTML);
        });

        const titleInput = titleCell.querySelector('.lesson-title-input');
        if (titleInput) titleInput.focus();
    }

    function cancelRowEditing(rowElement, originalTitleHTML, originalDescriptionHTML, originalActionsHTML) {
        rowElement.classList.remove('is-editing');
        rowElement.querySelector('.lesson-title-cell').innerHTML = originalTitleHTML;
        rowElement.querySelector('.lesson-description-cell').innerHTML = originalDescriptionHTML;
        rowElement.querySelector('.lesson-actions-cell').innerHTML = originalActionsHTML;
    }

    function saveRowChanges(rowElement, lessonId, originalTitleHTML, originalDescriptionHTML, originalActionsHTML) {
        const newTitle = rowElement.querySelector('.lesson-title-input').value.trim();
        const newDescription = rowElement.querySelector('.lesson-description-input').value.trim();

        if (newTitle === '') {
            alert('Название урока не может быть пустым.');
            rowElement.querySelector('.lesson-title-input').focus();
            return;
        }

        const actionsCell = rowElement.querySelector('.lesson-actions-cell');
        const originalButtonsHTML = actionsCell.innerHTML;
        actionsCell.innerHTML = '<small class="text-muted">Сохранение...</small>';
        fetch(API_URL_FOR_LESSON_UPDATE, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json' 
            },
            body: JSON.stringify({
                lesson_id: lessonId, 
                title: newTitle,
                description: newDescription
            })
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    let errorDetail = text ? `. Детали: ${text.substring(0, 200)}` : '';
                    throw new Error(`Ошибка сервера: ${response.status} ${response.statusText}${errorDetail}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.updated_lesson) { // Проверяем наличие updated_lesson
                rowElement.classList.remove('is-editing');
                const titleCell = rowElement.querySelector('.lesson-title-cell');
                const descriptionCell = rowElement.querySelector('.lesson-description-cell');

                titleCell.innerHTML = `<span class="lesson-title-text">${escapeHtml(data.updated_lesson.title)}</span>`;

                let truncatedDescription = data.updated_lesson.description;
                if (typeof truncateTextJS === 'function') { 
                    truncatedDescription = truncateTextJS(data.updated_lesson.description, 100);
                } else if (data.updated_lesson.description && data.updated_lesson.description.length > 100) {
                    truncatedDescription = data.updated_lesson.description.substring(0, 97) + "...";
                }

                descriptionCell.innerHTML = `
                    <span class="lesson-description-text">${escapeHtml(truncatedDescription).replace(/\n/g, '<br>')}</span>
                    <div class="full-description" style="display:none;">${escapeHtml(data.updated_lesson.description)}</div>
                `;

                actionsCell.innerHTML = originalActionsHTML;
            } else {
                alert('Ошибка сохранения: ' + (data.message || data.error || 'Неизвестная ошибка от сервера.'));
                actionsCell.innerHTML = originalButtonsHTML;
            }
        })
        .catch(error => {
            console.error('Ошибка при сохранении изменений урока:', error);
            alert('Произошла критическая ошибка при сохранении: ' + error.message + '. Попробуйте позже.');
            actionsCell.innerHTML = originalButtonsHTML;
        });
    }
    
    // Функция для экранирования
    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') return '';
        return unsafe
             .replace(/&/g, "&amp;")  // Амперсанд
             .replace(/</g, "&lt;")   // Меньше
             .replace(/>/g, "&gt;")   // Больше
             .replace(/"/g, "&quot;") // Двойная кавычка
             .replace(/'/g, "&#39;"); // Одинарная кавычка
    }

});