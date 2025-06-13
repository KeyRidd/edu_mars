<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
if (!defined('BASE_URL')) {
    $script_path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    define('BASE_URL', rtrim(dirname($script_path), '/') . '/');
}
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__)); }
if (!defined('CONFIG_PATH')) { define('CONFIG_PATH', ROOT_PATH . '/config/'); }
if (!defined('INCLUDES_PATH')) { define('INCLUDES_PATH', ROOT_PATH . '/includes/'); }
if (!defined('LAYOUTS_PATH')) { define('LAYOUTS_PATH', ROOT_PATH . '/layouts/'); }

require_once CONFIG_PATH . 'database.php';
require_once INCLUDES_PATH . 'functions.php'; 
require_once INCLUDES_PATH . 'auth.php';
// require_once INCLUDES_PATH . 'pluralize.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. Эта страница только для студентов.'];
    header('Location: ' . BASE_URL . 'pages/login.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$student_group_id = $_SESSION['group_id'] ?? null;
$errors = [];
$success_message = ''; 
$db_error_message = '';
$available_teachers_subjects = [];
$student_consultation_requests = [];
$form_data = ['teacher_id' => '', 'subject_id' => '', 'student_message' => '', 'requested_period_preference' => ''];
$total_completed_consultation_hours = 0;
$completed_consultations_count = 0;
$standard_consultation_duration_minutes = 90;
$page_flash_message = null;
// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

try {
    $conn = getDbConnection();

    if (!$student_group_id) {
        throw new Exception("Информация о группе студента не найдена. Невозможно подать заявку на консультацию.");
    }

    // Подсчет завершенных консультаций
    $sql_completed_time = "SELECT COUNT(*) as completed_count 
                           FROM consultation_requests 
                           WHERE student_id = ? AND status = 'completed'";
    $stmt_completed_time = $conn->prepare($sql_completed_time);
    $stmt_completed_time->execute([$student_id]);
    $completed_data_from_db = $stmt_completed_time->fetch(PDO::FETCH_ASSOC); 

    if ($completed_data_from_db && $completed_data_from_db['completed_count'] > 0) {
        $completed_consultations_count = (int)$completed_data_from_db['completed_count'];
        $total_completed_consultation_hours = ($completed_consultations_count * $standard_consultation_duration_minutes) / 60;
    }

    // Получить преподавателей и их предметы для группы студента
    $sql_teachers_subjects = "
        SELECT DISTINCT
            ta.teacher_id,
            u.full_name AS teacher_name,
            s.id AS subject_id,
            s.name AS subject_name
        FROM teaching_assignments ta
        JOIN users u ON ta.teacher_id = u.id
        JOIN subjects s ON ta.subject_id = s.id
        WHERE ta.group_id = ? AND u.role = 'teacher'
        ORDER BY u.full_name, s.name
    ";
    $stmt_ts = $conn->prepare($sql_teachers_subjects);
    $stmt_ts->execute([$student_group_id]);
    $results = $stmt_ts->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $row) {
        if (!isset($available_teachers_subjects[$row['teacher_id']])) {
            $available_teachers_subjects[$row['teacher_id']] = [
                'name' => $row['teacher_name'],
                'subjects' => []
            ];
        }
        $available_teachers_subjects[$row['teacher_id']]['subjects'][$row['subject_id']] = $row['subject_name'];
    }

    // Обработка POST-запроса для создания заявки
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_consultation_request'])) {
        $form_data['teacher_id'] = $_POST['teacher_id'] ?? '';
        $form_data['subject_id'] = $_POST['subject_id'] ?? '';
        $form_data['student_message'] = trim($_POST['student_message'] ?? '');
        $form_data['requested_period_preference'] = trim($_POST['requested_period_preference'] ?? '');

        // Валидация
        if (empty($form_data['teacher_id'])) {
            $errors[] = 'Пожалуйста, выберите преподавателя.';
        }
        if (empty($form_data['subject_id'])) {
            $errors[] = 'Пожалуйста, выберите дисциплину.';
        }
        if (empty($form_data['student_message'])) {
            $errors[] = 'Пожалуйста, опишите ваш вопрос или тему консультации.';
        } elseif (mb_strlen($form_data['student_message']) < 10) {
            $errors[] = 'Сообщение должно содержать не менее 10 символов.';
        }

        // Проверка, что выбранный преподаватель и предмет существуют и связаны
        if (!empty($form_data['teacher_id']) && !empty($form_data['subject_id'])) {
            if (!isset($available_teachers_subjects[$form_data['teacher_id']]) ||
                !isset($available_teachers_subjects[$form_data['teacher_id']]['subjects'][$form_data['subject_id']])) {
                $errors[] = 'Выбран некорректный преподаватель или дисциплина.';
            }
        }
        
        if (empty($errors)) {
            $conn->beginTransaction();
            try {
                $sql_insert_request = "
                    INSERT INTO consultation_requests 
                        (student_id, teacher_id, subject_id, student_message, requested_period_preference, status, created_at, updated_at)
                    VALUES 
                        (?, ?, ?, ?, ?, 'pending_teacher_response', NOW(), NOW())
                ";
                $stmt_insert = $conn->prepare($sql_insert_request);
                $stmt_insert->execute([
                    $student_id,
                    (int)$form_data['teacher_id'],
                    (int)$form_data['subject_id'],
                    $form_data['student_message'],
                    $form_data['requested_period_preference'] ?: null
                ]);
                $new_request_id = $conn->lastInsertId();

                // Создание уведомления для преподавателя
                $teacher_user_id = (int)$form_data['teacher_id'];
                $student_full_name = $_SESSION['full_name'] ?? 'Студент';
                $subject_name = $available_teachers_subjects[$form_data['teacher_id']]['subjects'][$form_data['subject_id']] ?? 'дисциплине';
                
                $notification_title = "Новая заявка на консультацию";
                $notification_message = "Студент {$student_full_name} запросил консультацию по дисциплине \"{$subject_name}\".";
                $notification_url = BASE_URL . "pages/teacher_consultations.php"; 

                $sql_notify = "INSERT INTO notifications (user_id, sender_id, type, title, message, related_url) 
                               VALUES (?, ?, 'new_consultation_request', ?, ?, ?)";
                $stmt_notify = $conn->prepare($sql_notify);
                $stmt_notify->execute([$teacher_user_id, $student_id, $notification_title, $notification_message, $notification_url]);
                
                $conn->commit();
                $_SESSION['message'] = ['type' => 'success', 'text' => 'Ваша заявка на консультацию успешно отправлена!'];
                $form_data = array_fill_keys(array_keys($form_data), ''); 
                header('Location: ' . BASE_URL . 'pages/student_consultations.php');
                exit();

            } catch (PDOException $e) {
                $conn->rollBack();
                $errors[] = "Ошибка при отправке заявки: " . $e->getMessage();
                error_log("Consultation request submission error: " . $e->getMessage());
            }
        }
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_action'])) {
        $request_id_action = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $action_type = $_POST['student_action'];
    
        if ($request_id_action <= 0) {
            $errors[] = "Некорректный ID заявки для выполнения действия.";
        } else {
            // Получаем детали заявки, чтобы убедиться, что она принадлежит студенту и имеет правильный статус
            $sql_get_action_request = "SELECT cr.*, s.name as subject_name, u_teacher.full_name as teacher_name 
                                      FROM consultation_requests cr 
                                      JOIN subjects s ON cr.subject_id = s.id
                                      JOIN users u_teacher ON cr.teacher_id = u_teacher.id
                                      WHERE cr.id = ? AND cr.student_id = ?";
            $stmt_get_action_req = $conn->prepare($sql_get_action_request);
            $stmt_get_action_req->execute([$request_id_action, $student_id]);
            $request_for_action = $stmt_get_action_req->fetch(PDO::FETCH_ASSOC);
    
            if (!$request_for_action) {
                $errors[] = "Заявка #{$request_id_action} не найдена или не принадлежит вам.";
            } else {
                $conn->beginTransaction();
                try {
                    if ($action_type === 'confirm_consultation' && $request_for_action['status'] === 'teacher_responded_pending_student_confirmation') {
                        // Студент принимает предложение
                        $sql_update = "UPDATE consultation_requests 
                                       SET status = 'scheduled_confirmed', student_confirmed_at = NOW(), updated_at = NOW() 
                                       WHERE id = ?";
                        $stmt_update = $conn->prepare($sql_update);
                        $stmt_update->execute([$request_id_action]);
    
                        // Уведомление преподавателю
                        $notification_title_t = "Студент подтвердил консультацию";
                        $notification_message_t = "Студент {$_SESSION['full_name']} подтвердил вашу консультацию по дисциплине \"{$request_for_action['subject_name']}\" на " . format_ru_datetime($request_for_action['scheduled_datetime_start']) . ".";
                        $notification_url_t = BASE_URL . "pages/teacher_consultations.php#request-" . $request_id_action; // Уточнить, если нужна другая страница
    
                        $sql_notify_t = "INSERT INTO notifications (user_id, sender_id, type, title, message, related_url) 
                                        VALUES (?, ?, 'consultation_student_confirmed', ?, ?, ?)";
                        $stmt_notify_t = $conn->prepare($sql_notify_t);
                        $stmt_notify_t->execute([(int)$request_for_action['teacher_id'], $student_id, $notification_title_t, $notification_message_t, $notification_url_t]);
                        
                        $_SESSION['message'] = ['type' => 'success', 'text' => 'Вы успешно подтвердили консультацию!'];
    
                    } elseif ($action_type === 'reject_consultation' && $request_for_action['status'] === 'teacher_responded_pending_student_confirmation') {
                        // Студент отклоняет предложение
                        $student_rejection_comment = trim($_POST['student_rejection_comment'] ?? '');
                        $sql_update = "UPDATE consultation_requests 
                                       SET status = 'student_rejected_offer', student_rejection_comment = ?, updated_at = NOW() 
                                       WHERE id = ?";
                        $stmt_update = $conn->prepare($sql_update);
                        $stmt_update->execute([$student_rejection_comment ?: null, $request_id_action]);
    
                        // Уведомление преподавателю
                        $notification_title_t = "Студент отклонил предложение о консультации";
                        $notification_message_t = "Студент {$_SESSION['full_name']} отклонил ваше предложение о консультации по дисциплине \"{$request_for_action['subject_name']}\"." . (!empty($student_rejection_comment) ? " Комментарий: " . $student_rejection_comment : "");
                        $notification_url_t = BASE_URL . "pages/teacher_consultations.php#request-" . $request_id_action;
    
                        $sql_notify_t = "INSERT INTO notifications (user_id, sender_id, type, title, message, related_url) 
                                        VALUES (?, ?, 'consultation_student_rejected', ?, ?, ?)";
                        $stmt_notify_t = $conn->prepare($sql_notify_t);
                        $stmt_notify_t->execute([(int)$request_for_action['teacher_id'], $student_id, $notification_title_t, $notification_message_t, $notification_url_t]);
    
                        $_SESSION['message'] = ['type' => 'info', 'text' => 'Вы отклонили предложение о консультации.'];
                    
                    } elseif ($action_type === 'cancel_my_request') {
                        // Студент отменяет свою заявку
                        $new_status = '';
                        $can_cancel = false;
                        if ($request_for_action['status'] === 'pending_teacher_response') {
                            $new_status = 'cancelled_by_student_before_confirmation';
                            $can_cancel = true;
                        } elseif ($request_for_action['status'] === 'scheduled_confirmed') {
                            $new_status = 'cancelled_by_student_after_confirmation';
                            $can_cancel = true; 
                        }
    
                        if ($can_cancel && !empty($new_status)) {
                            $sql_update = "UPDATE consultation_requests SET status = ?, updated_at = NOW() WHERE id = ?";
                            $stmt_update = $conn->prepare($sql_update);
                            $stmt_update->execute([$new_status, $request_id_action]);
    
                            // Уведомление преподавателю, если консультация была уже назначена и студент ее отменил
                            if ($request_for_action['status'] === 'scheduled_confirmed') {
                                $notification_title_t = "Студент отменил запись на консультацию";
                                $notification_message_t = "Студент {$_SESSION['full_name']} отменил запись на консультацию по дисциплине \"{$request_for_action['subject_name']}\", которая была назначена на " . format_ru_datetime($request_for_action['scheduled_datetime_start']) . ".";
                                $notification_url_t = BASE_URL . "pages/teacher_consultations.php#request-" . $request_id_action;
                                
                                $sql_notify_t = "INSERT INTO notifications (user_id, sender_id, type, title, message, related_url) 
                                                VALUES (?, ?, 'consultation_cancelled_by_student', ?, ?, ?)"; 
                                $stmt_notify_t = $conn->prepare($sql_notify_t);
                                $stmt_notify_t->execute([(int)$request_for_action['teacher_id'], $student_id, $notification_title_t, $notification_message_t, $notification_url_t]);
                            }
                            $_SESSION['message'] = ['type' => 'success', 'text' => 'Заявка/запись на консультацию отменена.'];
                        } else {
                            $errors[] = "Эту заявку нельзя отменить на данном этапе или слишком поздно.";
                        }
                    } else {
                        $errors[] = "Недопустимое действие или статус заявки.";
                    }
    
                    if (empty($errors)) { 
                         $conn->commit();
                         header('Location: ' . BASE_URL . 'pages/student_consultations.php#request-' . $request_id_action);
                         exit();
                    } else {
                         $conn->rollBack();
                    }
    
                } catch (PDOException $e) {
                    $conn->rollBack();
                    $errors[] = "Ошибка базы данных при выполнении действия: " . $e->getMessage();
                    error_log("Student consultation action error: " . $e->getMessage());
                }
            }
        }
    } 
    
    // Получить список существующих заявок студента
    $sql_my_requests = "
        SELECT 
            cr.id, 
            cr.student_message, 
            cr.requested_period_preference,
            cr.teacher_response_message, 
            cr.scheduled_datetime_start,
            cr.scheduled_datetime_end, 
            cr.consultation_location_or_link,
            cr.status, 
            cr.created_at, 
            cr.teacher_responded_at,
            cr.student_confirmed_at, 
            cr.updated_at,
            u_teacher.full_name as teacher_name, 
            s.name as subject_name 
        FROM consultation_requests cr 
        JOIN users u_teacher ON cr.teacher_id = u_teacher.id
        JOIN subjects s ON cr.subject_id = s.id
        WHERE cr.student_id = ? 
          AND cr.status IN (
              'pending_teacher_response',
              'teacher_responded_pending_student_confirmation',
              'scheduled_confirmed',
              'student_rejected_offer' 
          )
        ORDER BY cr.created_at DESC
    ";
    $stmt_my_req = $conn->prepare($sql_my_requests);
    $stmt_my_req->execute([$student_id]);
    $student_consultation_requests = $stmt_my_req->fetchAll(PDO::FETCH_ASSOC);


} catch (PDOException $e) {
    error_log("Database Error in student_consultations.php: " . $e->getMessage());
    $db_error_message = "Произошла ошибка базы данных: " . $e->getMessage();
} catch (Exception $e) {
    error_log("General Error in student_consultations.php: " . $e->getMessage());
    $db_error_message = $e->getMessage();
} finally {
    $conn = null;
}
$teachers_subjects_json = json_encode($available_teachers_subjects);
$page_title = "Мои Консультации";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'student-consultations-page app-page';
$load_notifications_css = true;
$page_specific_js = '
    <script>
        const consultationStudentPageConfig = {
            teachersSubjectsData: ' . $teachers_subjects_json . ',
            initialFormData: ' . json_encode($form_data) . ',
            errorRejectFormRequestId: null,
            errorRejectComment: ""
        };
    </script>
    <script src="' . BASE_URL . 'assets/js/consultations_student.js?v=' . time() . '" defer></script>
';

// Если была ошибка при отклонении
$error_reject_request_id_js = 'null';
$error_reject_comment_js = '""';
if (!empty($errors) && isset($_POST['student_action']) && $_POST['student_action'] === 'reject_consultation') {
    $error_reject_request_id_js = json_encode($_POST['request_id'] ?? null);
    $error_reject_comment_js = json_encode($_POST['student_rejection_comment'] ?? '');
}
$page_specific_js .= "
    <script>
        consultationStudentPageConfig.errorRejectFormRequestId = {$error_reject_request_id_js};
        consultationStudentPageConfig.errorRejectComment = {$error_reject_comment_js};
    </script>
";

ob_start();
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-chalkboard-teacher me-2"></i>Мои Консультации</h1>
        <a href="<?php echo BASE_URL; ?>pages/student_consultations_archive.php" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
            <i class="fas fa-archive me-1"></i> Архив консультаций
        </a>
    </div>
    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($db_error_message)): ?>
        <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($db_error_message); ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <strong>Обнаружены ошибки:</strong>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <h5 class="card-title">Сводка по консультациям</h5>
            <p class="card-text mb-0">
                Общее время на завершенных консультациях:
                <strong><?php echo htmlspecialchars(format_hours($total_completed_consultation_hours)); ?></strong>
                (<?php echo $completed_consultations_count; ?> консультаций)
            </p>
            <?php if ($completed_consultations_count == 0): ?>
                <small class="text-muted">У вас пока нет завершенных консультаций.</small>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4 shadow-sm">
        <div class="card-header">
            <h2 class="h5 mb-0">Подать новую заявку на консультацию</h2>
        </div>
        <div class="card-body">
            <?php if (empty($available_teachers_subjects) && empty($db_error_message)): ?>
                <div class="alert alert-info mb-0">Для вашей группы пока не назначено преподавателей, к которым можно записаться на консультацию.</div>
            <?php elseif (empty($db_error_message)): ?>
                <button type="button" id="openNewRequestModalBtn" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Подать новую заявку</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <h2 class="h5 mb-0">Мои активные заявки и записи</h2>
        </div>
        <div class="card-body">
            <?php if (empty($student_consultation_requests) && empty($db_error_message) && empty($errors)): ?>
                <p class="text-muted text-center py-3">У вас пока нет активных заявок или записей на консультации.</p>
            <?php elseif (!empty($student_consultation_requests)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Преподаватель</th>
                                <th scope="col">Дисциплина</th>
                                <th scope="col">Ваш запрос</th>
                                <th scope="col">Статус</th>
                                <th scope="col">Назначено / Ответ</th>
                                <th scope="col" class="text-center">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($student_consultation_requests as $request): ?>
                                <tr id="request-row-<?php echo $request['id']; ?>">
                                    <td><?php echo $request['id']; ?></td>
                                    <td><?php echo htmlspecialchars($request['teacher_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['subject_name']); ?></td>
                                    <td>
                                        <small title="<?php echo htmlspecialchars($request['student_message']); ?>">
                                            <?php echo htmlspecialchars(truncate_text($request['student_message'], 50)); ?>
                                        </small>
                                        <?php if (!empty($request['requested_period_preference'])): ?>
                                            <br><small class="text-muted fst-italic" title="Пожелание по времени: <?php echo htmlspecialchars($request['requested_period_preference']); ?>">Пожелание: <?php echo htmlspecialchars(truncate_text($request['requested_period_preference'], 30)); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo htmlspecialchars(get_consultation_request_status_badge_class($request['status'])); ?>">
                                            <?php echo htmlspecialchars(get_consultation_request_status_text($request['status'])); ?>
                                        </span>
                                        <br><small class="text-muted" title="Дата создания: <?php echo htmlspecialchars($request['created_at']); ?>"><?php echo htmlspecialchars(format_ru_datetime_short($request['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($request['teacher_response_message'])): ?>
                                            <small class="d-block text-info" title="Ответ преподавателя: <?php echo htmlspecialchars($request['teacher_response_message']); ?>">
                                                <i class="fas fa-reply me-1"></i><?php echo htmlspecialchars(truncate_text($request['teacher_response_message'], 60)); ?>
                                            </small>
                                        <?php endif; ?>
                                        <?php if (isset($request['scheduled_datetime_start'])): ?>
                                            <small class="d-block mt-1">
                                                <strong><i class="fas fa-calendar-check me-1"></i></strong> <?php echo htmlspecialchars(format_ru_datetime($request['scheduled_datetime_start'])); ?>
                                                <?php if (isset($request['scheduled_datetime_end'])): ?>
                                                    - <?php echo htmlspecialchars(format_ru_time($request['scheduled_datetime_end'])); ?>
                                                <?php endif; ?>
                                                <?php if(!empty($request['consultation_location_or_link'])): ?>
                                                     <br><i class="fas fa-map-marker-alt me-1"></i><span class="text-muted"><?php echo htmlspecialchars($request['consultation_location_or_link']); ?></span>
                                                <?php endif; ?>
                                            </small>
                                        <?php elseif (empty($request['teacher_response_message']) && $request['status'] === 'pending_teacher_response') : ?>
                                            <small class="text-muted">Ожидается ответ преподавателя</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($request['status'] === 'teacher_responded_pending_student_confirmation'): ?>
                                            <form method="POST" action="<?php echo BASE_URL; ?>pages/student_consultations.php#request-row-<?php echo $request['id']; ?>" class="d-inline-block mb-1">
                                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                <button type="submit" name="student_action" value="confirm_consultation" class="btn btn-sm btn-success" title="Принять предложение"><i class="fas fa-check"></i></button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-danger open-reject-modal-btn" data-request-id="<?php echo $request['id']; ?>" data-request-details="<?php echo htmlspecialchars("Заявка #{$request['id']} с {$request['teacher_name']} по {$request['subject_name']}"); ?>" title="Отклонить предложение"><i class="fas fa-times"></i></button>
                                        <?php elseif ($request['status'] === 'pending_teacher_response' || $request['status'] === 'scheduled_confirmed'): ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif (empty($db_error_message) && empty($errors)): ?>
                 <p class="text-muted text-center py-3">У вас пока нет активных заявок или записей на консультации.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Модальное окно -->
<div id="newRequestModal" class="modal" style="display: <?php echo !empty($errors) && isset($_POST['submit_consultation_request']) ? 'block' : 'none'; ?>;">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="h5">Новая заявка на консультацию</h2>
            <button type="button" class="close-modal-btn" id="closeNewRequestModalBtn" aria-label="Закрыть">×</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="newConsultationRequestForm" action="<?php echo BASE_URL; ?>pages/student_consultations.php">
                <input type="hidden" name="submit_consultation_request" value="1">
                <div class="form-group mb-3">
                    <label for="modal_teacher_id" class="form-label">Преподаватель <span class="text-danger">*</span></label>
                    <select name="teacher_id" id="modal_teacher_id" class="form-control form-select" required>
                        <option value="">-- Выберите преподавателя --</option>
                        <?php foreach ($available_teachers_subjects as $teacher_id_opt => $teacher_data): ?>
                            <option value="<?php echo $teacher_id_opt; ?>" <?php echo ((string)($form_data['teacher_id'] ?? '') === (string)$teacher_id_opt) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($teacher_data['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mb-3">
                    <label for="modal_subject_id" class="form-label">Дисциплина <span class="text-danger">*</span></label>
                    <select name="subject_id" id="modal_subject_id" class="form-control form-select" required>
                        <option value="">-- Сначала выберите преподавателя --</option>
                    </select>
                    <small class="form-text text-muted">Список дисциплин обновится после выбора преподавателя.</small>
                </div>
                <div class="form-group mb-3">
                    <label for="modal_student_message" class="form-label">Ваш вопрос / Тема консультации (мин. 10 симв.) <span class="text-danger">*</span></label>
                    <textarea name="student_message" id="modal_student_message" class="form-control" rows="4" required minlength="10"><?php echo htmlspecialchars($form_data['student_message'] ?? ''); ?></textarea>
                </div>
                <div class="form-group mb-3">
                    <label for="modal_requested_period_preference" class="form-label">Предпочтительный период / Пожелания по времени (необязательно)</label>
                    <input type="text" name="requested_period_preference" id="modal_requested_period_preference" class="form-control" value="<?php echo htmlspecialchars($form_data['requested_period_preference'] ?? ''); ?>" placeholder="Например: Следующая неделя, вторая половина дня">
                </div>
                <div class="form-actions modal-footer mt-3">
                    <button type="button" id="cancelNewRequestModalBtn" class="btn btn-secondary close-modal-btn">Отмена</button>
                    <button type="submit" class="btn btn-primary">Отправить заявку</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Модальное окно для отклонения -->
<div id="rejectRequestModal" class="modal" style="display: <?php echo !empty($errors) && isset($_POST['student_action']) && $_POST['student_action'] === 'reject_consultation' ? 'block' : 'none'; ?>;">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="h5" id="rejectModalTitle">Отклонить предложение по заявке #<span id="rejectModalRequestIdSpan"></span></h2>
            <button type="button" class="close-modal-btn" id="closeRejectModalBtn" aria-label="Закрыть">×</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="rejectConsultationForm" action="<?php echo BASE_URL; ?>pages/student_consultations.php">
                <input type="hidden" name="request_id" id="modal_reject_request_id_input" value="">
                <input type="hidden" name="student_action" value="reject_consultation">
                <p id="rejectModalInfoText" class="mb-3">Вы уверены, что хотите отклонить это предложение?</p>
                <div class="form-group mb-3">
                    <label for="modal_rejection_comment" class="form-label">Причина отклонения (необязательно):</label>
                    <textarea name="student_rejection_comment" id="modal_rejection_comment_input" class="form-control" rows="3"></textarea>
                    <small class="form-text text-muted">Ваш комментарий будет виден преподавателю.</small>
                </div>
                <div class="form-actions modal-footer mt-3">
                    <button type="button" id="cancelRejectModalBtn" class="btn btn-secondary close-modal-btn">Отмена</button>
                    <button type="submit" class="btn btn-danger">Подтвердить отклонение</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>