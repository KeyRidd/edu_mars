document.addEventListener('DOMContentLoaded', function() {
    const replyModalElement = document.getElementById('replyRequestModal');
    let bootstrapReplyModalInstance = null;
    if (replyModalElement) {
        bootstrapReplyModalInstance = new bootstrap.Modal(replyModalElement);
    }
    const modalReplyRequestIdInput = document.getElementById('modal_reply_request_id_input');
    const replyModalStudentName = document.getElementById('modalReplyStudentName');
    const replyModalSubjectName = document.getElementById('modalReplySubjectName');
    const replyModalStudentMessage = document.getElementById('modalReplyStudentMessage');
    const replyModalTitleSpan = document.getElementById('modalReplyRequestIdSpan');

    // Элементы формы
    const modalScheduledDate = document.getElementById('modal_scheduled_date');
    const modalScheduledTime = document.getElementById('modal_scheduled_time');
    const modalLocationLink = document.getElementById('modal_location_or_link');
    const modalResponseMessage = document.getElementById('modal_teacher_response_message');
    const modalErrorsContainer = document.getElementById('modalReplyErrorsContainer'); 

    function resetModalForm() {
        if(modalScheduledDate) modalScheduledDate.value = new Date(Date.now() + 86400000).toISOString().split('T')[0];
        if(modalScheduledTime) modalScheduledTime.value = '';
        if(modalLocationLink) modalLocationLink.value = '';
        if(modalResponseMessage) modalResponseMessage.value = '';
        if(modalReplyRequestIdInput) modalReplyRequestIdInput.value = '';
        if(modalErrorsContainer) {
            modalErrorsContainer.style.display = 'none';
            modalErrorsContainer.querySelector('ul').innerHTML = '';
        }
    }

    if (replyModalElement) {
        replyModalElement.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;

            const requestId = button.dataset.requestId;
            const studentName = button.dataset.studentName;
            const subjectName = button.dataset.subjectName;
            const studentMessage = button.dataset.studentMessage;

            resetModalForm();

            if (modalReplyRequestIdInput) modalReplyRequestIdInput.value = requestId;
            if (replyModalTitleSpan) replyModalTitleSpan.textContent = requestId;
            if (replyModalStudentName) replyModalStudentName.textContent = studentName;
            if (replyModalSubjectName) replyModalSubjectName.textContent = subjectName;
            if (replyModalStudentMessage) replyModalStudentMessage.textContent = studentMessage;
        });
    }

    const config = window.teacherConsultationsPageConfig || {};
    if (config.errorFormRequestId && bootstrapReplyModalInstance) {
        const requestId = config.errorFormRequestId;
        const errorData = config.errorFormDataReply && config.errorFormDataReply[requestId]
            ? config.errorFormDataReply[requestId]
            : {};

        // Заполняем поля данными, которые были при ошибке
        if (modalReplyRequestIdInput) modalReplyRequestIdInput.value = requestId;
        if (replyModalTitleSpan) replyModalTitleSpan.textContent = requestId;

        // Ищем данные студента и предмета
        const errorButton = document.querySelector(`.open-reply-modal-btn[data-request-id="${requestId}"]`);
        if (errorButton) {
            if (replyModalStudentName) replyModalStudentName.textContent = errorButton.dataset.studentName;
            if (replyModalSubjectName) replyModalSubjectName.textContent = errorButton.dataset.subjectName;
            if (replyModalStudentMessage) replyModalStudentMessage.textContent = errorButton.dataset.studentMessage;
        }

        if(modalScheduledDate && errorData.scheduled_date) modalScheduledDate.value = errorData.scheduled_date;
        if(modalScheduledTime && errorData.scheduled_time) modalScheduledTime.value = errorData.scheduled_time;
        if(modalLocationLink && errorData.location_or_link) modalLocationLink.value = errorData.location_or_link;
        if(modalResponseMessage && errorData.teacher_response_message) modalResponseMessage.value = errorData.teacher_response_message;

        // Показываем ошибки валидации, если они были переданы
        if (config.validationErrors && config.validationErrors[requestId] && modalErrorsContainer) {
            const errorsList = modalErrorsContainer.querySelector('ul');
            errorsList.innerHTML = '';
            config.validationErrors[requestId].forEach(errText => {
                const li = document.createElement('li');
                li.textContent = errText;
                errorsList.appendChild(li);
            });
            modalErrorsContainer.style.display = 'block';
        }
        bootstrapReplyModalInstance.show();
    }
});