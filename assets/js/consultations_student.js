document.addEventListener('DOMContentLoaded', function() {
    if (typeof consultationStudentPageConfig === 'undefined' || !consultationStudentPageConfig) {
        console.error('Объект конфигурации "consultationStudentPageConfig" не найден или некорректен!');
        return;
    }

    const localTeachersSubjectsData = consultationStudentPageConfig.teachersSubjectsData || {};
    const initialPhpFormData = consultationStudentPageConfig.initialFormData || {};

    // Модальное окно для новой заявки
    const newRequestModal = document.getElementById('newRequestModal');
    const openNewRequestModalBtn = document.getElementById('openNewRequestModalBtn');
    const closeNewRequestModalBtn = document.getElementById('closeNewRequestModalBtn');
    const cancelNewRequestModalBtn = document.getElementById('cancelNewRequestModalBtn');
    
    const modalTeacherSelect = document.getElementById('modal_teacher_id');
    const modalSubjectSelect = document.getElementById('modal_subject_id');

    if (openNewRequestModalBtn && newRequestModal) {
        openNewRequestModalBtn.onclick = function() {
            if (modalTeacherSelect) modalTeacherSelect.value = initialPhpFormData.teacher_id || '';
            updateModalSubjectOptions(); 
            if (modalSubjectSelect && initialPhpFormData.subject_id && modalTeacherSelect.value === initialPhpFormData.teacher_id) {
                modalSubjectSelect.value = initialPhpFormData.subject_id;
            }
            const messageTextarea = document.getElementById('modal_student_message');
            if(messageTextarea) messageTextarea.value = initialPhpFormData.student_message || '';
            const periodInput = document.getElementById('modal_requested_period_preference');
            if(periodInput) periodInput.value = initialPhpFormData.requested_period_preference || '';
            newRequestModal.style.display = 'block';
        }
    }
    function closeNewModal() {
        if (newRequestModal) newRequestModal.style.display = 'none';
    }
    if (closeNewRequestModalBtn) closeNewRequestModalBtn.onclick = closeNewModal;
    if (cancelNewRequestModalBtn) cancelNewRequestModalBtn.onclick = closeNewModal;
    function updateModalSubjectOptions() {
        if (!modalTeacherSelect || !modalSubjectSelect) return;

        const selectedTeacherId = modalTeacherSelect.value;
        modalSubjectSelect.innerHTML = '<option value="">-- Выберите дисциплину --</option>';

        if (selectedTeacherId && localTeachersSubjectsData[selectedTeacherId]) {
            const subjects = localTeachersSubjectsData[selectedTeacherId].subjects;
            for (const subjectId in subjects) {
                if (subjects.hasOwnProperty(subjectId)) {
                    const option = document.createElement('option');
                    option.value = subjectId;
                    option.textContent = subjects[subjectId];
                    // Восстановление выбранного предмета при ошибке или инициализации
                    if (initialPhpFormData.subject_id && 
                        subjectId === initialPhpFormData.subject_id && 
                        selectedTeacherId === initialPhpFormData.teacher_id) {
                         option.selected = true;
                    }
                    modalSubjectSelect.appendChild(option);
                }
            }
        }
    }

    if (modalTeacherSelect) {
        modalTeacherSelect.addEventListener('change', updateModalSubjectOptions);
        
        // Первоначальное заполнение для модального окна при загрузке страницы
        if (initialPhpFormData.teacher_id) {
            modalTeacherSelect.value = initialPhpFormData.teacher_id;
        }
        updateModalSubjectOptions(); 
        if (initialPhpFormData.subject_id && modalTeacherSelect.value === initialPhpFormData.teacher_id) {
             // Дополнительная проверка, существует ли такая опция после updateModalSubjectOptions
             if (Array.from(modalSubjectSelect.options).some(opt => opt.value === initialPhpFormData.subject_id)) {
                modalSubjectSelect.value = initialPhpFormData.subject_id;
             }
        }
    }

    // Модальное окно для отклонения заявки
    const rejectRequestModal = document.getElementById('rejectRequestModal');
    const openRejectModalBtns = document.querySelectorAll('.open-reject-modal-btn');
    const closeRejectModalBtn = document.getElementById('closeRejectModalBtn');
    const cancelRejectModalBtn = document.getElementById('cancelRejectModalBtn'); 
    const modalRejectRequestIdInput = document.getElementById('modal_reject_request_id_input');
    const rejectModalTitleSpan = document.getElementById('rejectModalRequestIdSpan');
    const rejectModalInfoText = document.getElementById('rejectModalInfoText'); 
    const modalRejectionCommentTextarea = document.getElementById('modal_rejection_comment_input');
    openRejectModalBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            const requestDetails = this.dataset.requestDetails || `заявке #${requestId}`; 
            if (modalRejectRequestIdInput) modalRejectRequestIdInput.value = requestId;
            if (rejectModalTitleSpan) rejectModalTitleSpan.textContent = requestId;
            if (modalRejectionCommentTextarea) modalRejectionCommentTextarea.value = '';

            if (rejectRequestModal) rejectRequestModal.style.display = 'block';
        });
    });

    function closeRejectModal() {
        if (rejectRequestModal) rejectRequestModal.style.display = 'none';
    }
    if (closeRejectModalBtn) closeRejectModalBtn.onclick = closeRejectModal;
    if (cancelRejectModalBtn) cancelRejectModalBtn.onclick = closeRejectModal;


    window.addEventListener('click', function(event) {
        if (newRequestModal && event.target == newRequestModal) {
            closeNewModal();
        }
        if (rejectRequestModal && event.target == rejectRequestModal) {
            closeRejectModal();
        }
    });
    
    // Автооткрытие модалки отклонения при ошибках
    if (consultationStudentPageConfig.errorRejectFormRequestId && rejectRequestModal && modalRejectRequestIdInput) {
        modalRejectRequestIdInput.value = consultationStudentPageConfig.errorRejectFormRequestId;
        if (rejectModalTitleSpan) { 
            rejectModalTitleSpan.textContent = consultationStudentPageConfig.errorRejectFormRequestId;
        }
        if (modalRejectionCommentTextarea) {
            modalRejectionCommentTextarea.value = consultationStudentPageConfig.errorRejectComment || '';
        }
        rejectRequestModal.style.display = 'block';
    }
});