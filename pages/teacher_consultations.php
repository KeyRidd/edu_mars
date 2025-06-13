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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. Эта страница только для преподавателей.'];
    header('Location: ' . BASE_URL . 'pages/login.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$errors = []; // Ошибки для конкретной формы ответа, ключ - ID заявки
$global_errors = []; // Общие ошибки, не связанные с конкретной формой
$success_message = ''; // Для сообщений об успехе (не из сессии)
$page_flash_message = null; // Для флеш-сообщений из сессии
$pending_teacher_response_requests = [];
$pending_student_confirmation_requests = [];
$scheduled_confirmed_requests = [];
$student_rejected_requests = [];
$form_data_reply = []; // Для предзаполнения формы ответа

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

try {
    $conn = getDbConnection();

    // Обработка POST-запроса для ответа на заявку
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_to_consultation_request'])) {
        $request_id_to_reply = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        
        // Сохраняем данные из формы для предзаполнения в случае ошибки
        $form_data_reply[$request_id_to_reply] = [
            'scheduled_date' => $_POST['scheduled_date'] ?? '',
            'scheduled_time' => $_POST['scheduled_time'] ?? '',
            'location_or_link' => trim($_POST['location_or_link'] ?? ''),
            'teacher_response_message' => trim($_POST['teacher_response_message'] ?? '')
        ];

        // Валидация
        if (empty($form_data_reply[$request_id_to_reply]['scheduled_date'])) {
            $errors[$request_id_to_reply][] = 'Пожалуйста, укажите дату консультации.';
        }
        if (empty($form_data_reply[$request_id_to_reply]['scheduled_time'])) {
            $errors[$request_id_to_reply][] = 'Пожалуйста, укажите время начала консультации.';
        }
        
        $scheduled_datetime_start_obj = null;
        if (!empty($form_data_reply[$request_id_to_reply]['scheduled_date']) && !empty($form_data_reply[$request_id_to_reply]['scheduled_time'])) {
            $scheduled_datetime_start_str = $form_data_reply[$request_id_to_reply]['scheduled_date'] . ' ' . $form_data_reply[$request_id_to_reply]['scheduled_time'];
            try {
                $scheduled_datetime_start_obj = new DateTime($scheduled_datetime_start_str);
                 if ($scheduled_datetime_start_obj < new DateTime("today")) { // Проверка, что дата не в прошлом (без учета времени)
                     $errors[$request_id_to_reply][] = 'Дата консультации не может быть в прошлом.';
                 }
            } catch (Exception $e) {
                $errors[$request_id_to_reply][] = 'Некорректный формат даты или времени.';
            }
        } else {
             if (empty($form_data_reply[$request_id_to_reply]['scheduled_date']) && !isset($errors[$request_id_to_reply])) { 
             }
             if (empty($form_data_reply[$request_id_to_reply]['scheduled_time']) && !isset($errors[$request_id_to_reply])) {
             }
             $scheduled_datetime_start_obj = null;
        }
        if (empty($errors[$request_id_to_reply]) && $scheduled_datetime_start_obj) {
            $sql_get_request_details = "SELECT cr.student_id, cr.subject_id, u_student.full_name as student_name, s.name as subject_name 
                                        FROM consultation_requests cr
                                        JOIN users u_student ON cr.student_id = u_student.id
                                        JOIN subjects s ON cr.subject_id = s.id
                                        WHERE cr.id = ? AND cr.teacher_id = ? AND cr.status = 'pending_teacher_response'";
            $stmt_details = $conn->prepare($sql_get_request_details);
            $stmt_details->execute([$request_id_to_reply, $teacher_id]);
            $request_details = $stmt_details->fetch(PDO::FETCH_ASSOC);
            if (!$request_details) {
                $global_errors[] = "Заявка #{$request_id_to_reply} не найдена, уже обработана или не принадлежит вам.";
            } else {
                $conn->beginTransaction();
                try {
                    $scheduled_datetime_start_sql = $scheduled_datetime_start_obj->format('Y-m-d H:i:s');
                    $duration_minutes_consultation = 90; 
                    $scheduled_datetime_end_obj = clone $scheduled_datetime_start_obj;
                    $scheduled_datetime_end_obj->add(new DateInterval("PT{$duration_minutes_consultation}M"));
                    $scheduled_datetime_end_sql = $scheduled_datetime_end_obj->format('Y-m-d H:i:s');

                    $sql_update_request = "
                        UPDATE consultation_requests 
                        SET status = 'teacher_responded_pending_student_confirmation',
                            teacher_response_message = ?,
                            scheduled_datetime_start = ?,
                            scheduled_datetime_end = ?,
                            consultation_location_or_link = ?,
                            teacher_responded_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ? AND teacher_id = ? AND status = 'pending_teacher_response' 
                    ";
                    $stmt_update = $conn->prepare($sql_update_request);
                    $stmt_update->execute([
                        $form_data_reply[$request_id_to_reply]['teacher_response_message'] ?: null,
                        $scheduled_datetime_start_sql,
                        $scheduled_datetime_end_sql,
                        $form_data_reply[$request_id_to_reply]['location_or_link'] ?: null,
                        $request_id_to_reply,
                        $teacher_id
                    ]);

                    if ($stmt_update->rowCount() > 0) {
                        $student_user_id = (int)$request_details['student_id'];
                        $teacher_full_name = $_SESSION['full_name'] ?? 'Преподаватель';
                        $subject_name_for_notification = $request_details['subject_name'] ?? 'дисциплине';
                        
                        $notification_title_s = "Ответ на заявку о консультации";
                        $notification_message_s = "Преподаватель {$teacher_full_name} ответил на ваш запрос о консультации по дисциплине \"{$subject_name_for_notification}\" и предложил время.";
                        $notification_url_s = BASE_URL . "pages/student_consultations.php#request-" . $request_id_to_reply;

                        $sql_notify_s = "INSERT INTO notifications (user_id, sender_id, type, title, message, related_url) 
                                       VALUES (?, ?, 'consultation_offer_received', ?, ?, ?)";
                        $stmt_notify_s = $conn->prepare($sql_notify_s);
                        $stmt_notify_s->execute([$student_user_id, $teacher_id, $notification_title_s, $notification_message_s, $notification_url_s]);
                        
                        $conn->commit();
                        $_SESSION['message'] = ['type' => 'success', 'text' => "Ваш ответ на заявку #{$request_id_to_reply} успешно отправлен студенту."];
                        header('Location: ' . BASE_URL . 'pages/teacher_consultations.php');
                        exit();
                    } else {
                        $conn->rollBack();
                        $global_errors[] = "Не удалось обновить заявку #{$request_id_to_reply}. Возможно, она уже была обработана или не принадлежит вам (проверка статуса не прошла).";
                    }
                } catch (PDOException $e) {
                    $conn->rollBack();
                    $global_errors[] = "Ошибка базы данных при обработке заявки #{$request_id_to_reply}: " . $e->getMessage();
                    error_log("Consultation reply PDO error for request ID {$request_id_to_reply}: " . $e->getMessage());
                } catch (Exception $e) { 
                    $conn->rollBack();
                    $global_errors[] = "Системная ошибка при обработке заявки #{$request_id_to_reply}: " . $e->getMessage();
                    error_log("Consultation reply general error for request ID {$request_id_to_reply}: " . $e->getMessage());
                }
            }
        }
    }
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['teacher_consultation_action'])) {
    $request_id_action = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $action_type = $_POST['teacher_consultation_action'];

    if ($request_id_action <= 0) {
        $global_errors[] = "Некорректный ID заявки для выполнения действия.";
    } else {
        // Получаем детали заявки для проверки и для уведомлений
        $sql_get_action_request_details = "SELECT cr.*, s.name as subject_name, u_student.full_name as student_name 
                                          FROM consultation_requests cr
                                          JOIN subjects s ON cr.subject_id = s.id
                                          JOIN users u_student ON cr.student_id = u_student.id
                                          WHERE cr.id = ? AND cr.teacher_id = ?";
        $stmt_get_action_details = $conn->prepare($sql_get_action_request_details);
        $stmt_get_action_details->execute([$request_id_action, $teacher_id]);
        $request_details_for_action = $stmt_get_action_details->fetch(PDO::FETCH_ASSOC);
        if (!$request_details_for_action) {
            $global_errors[] = "Заявка #{$request_id_action} не найдена или не принадлежит вам.";
        } else {
            $conn->beginTransaction();
            try {
                if ($action_type === 'mark_completed' && $request_details_for_action['status'] === 'scheduled_confirmed') {
                    $sql_update = "UPDATE consultation_requests SET status = 'completed', updated_at = NOW() WHERE id = ?";
                    $stmt_update = $conn->prepare($sql_update);
                    $stmt_update->execute([$request_id_action]);
                    $_SESSION['message'] = ['type' => 'success', 'text' => "Консультация #{$request_id_action} отмечена как проведенная."];

                } elseif ($action_type === 'cancel_by_teacher' && $request_details_for_action['status'] === 'scheduled_confirmed') {
                    $sql_update = "UPDATE consultation_requests SET status = 'cancelled_by_teacher', updated_at = NOW() WHERE id = ?";
                    $stmt_update = $conn->prepare($sql_update);
                    $stmt_update->execute([$request_id_action]);

                    // Уведомление студенту
                    $notification_title_s_cancel = "Консультация отменена преподавателем";
                    $notification_message_s_cancel = "Преподаватель {$_SESSION['full_name']} отменил консультацию по дисциплине \"{$request_details_for_action['subject_name']}\", которая была назначена на " . format_ru_datetime($request_details_for_action['scheduled_datetime_start']) . ". Свяжитесь с преподавателем для уточнения.";
                    $notification_url_s_cancel = BASE_URL . "pages/student_consultations.php#request-" . $request_id_action;

                    $sql_notify_s_cancel = "INSERT INTO notifications (user_id, sender_id, type, title, message, related_url) 
                                           VALUES (?, ?, 'consultation_cancelled_by_teacher', ?, ?, ?)";
                    $stmt_notify_s_cancel = $conn->prepare($sql_notify_s_cancel);
                    $stmt_notify_s_cancel->execute([(int)$request_details_for_action['student_id'], $teacher_id, $notification_title_s_cancel, $notification_message_s_cancel, $notification_url_s_cancel]);
                    
                    $_SESSION['message'] = ['type' => 'warning', 'text' => "Консультация #{$request_id_action} отменена. Студент будет уведомлен."];
                } else {
                    $global_errors[] = "Недопустимое действие или статус для заявки #{$request_id_action}.";
                }

                if (empty($global_errors) || !in_array("Недопустимое действие или статус для заявки #{$request_id_action}.", $global_errors)) { 
                    $conn->commit();
                    header('Location: ' . BASE_URL . 'pages/teacher_consultations.php');
                    exit();
                } else {
                    $conn->rollBack();
                }

            } catch (PDOException $e) {
                $conn->rollBack();
                $global_errors[] = "Ошибка базы данных при действии с заявкой #{$request_id_action}: " . $e->getMessage();
                error_log("Teacher consultation action DB error: " . $e->getMessage());
            }
        }
    }
}
    // Получить список заявок, ожидающих ответа преподавателя
    $sql_all_relevant_requests = "
    SELECT 
        cr.id, cr.student_id, cr.subject_id, cr.student_message, 
        cr.requested_period_preference, cr.created_at,
        cr.status, cr.teacher_response_message, cr.scheduled_datetime_start, 
        cr.scheduled_datetime_end, cr.consultation_location_or_link,
        cr.student_rejection_comment, 
        cr.teacher_responded_at, cr.student_confirmed_at, cr.updated_at,
        u_student.full_name as student_name, 
        g.name as student_group_name,
        s.name as subject_name 
    FROM consultation_requests cr
    JOIN users u_student ON cr.student_id = u_student.id
    LEFT JOIN groups g ON u_student.group_id = g.id
    JOIN subjects s ON cr.subject_id = s.id
    WHERE cr.teacher_id = ? 
      AND cr.status IN ( 
          'pending_teacher_response', 
          'teacher_responded_pending_student_confirmation', 
          'scheduled_confirmed',
          'student_rejected_offer' 
      )
    ORDER BY 
        CASE cr.status
            WHEN 'pending_teacher_response' THEN 1
            WHEN 'scheduled_confirmed' THEN 2
            WHEN 'teacher_responded_pending_student_confirmation' THEN 3
            WHEN 'student_rejected_offer' THEN 4
            ELSE 5 
        END,
        cr.scheduled_datetime_start ASC, 
        cr.created_at ASC
";
$stmt_all_req = $conn->prepare($sql_all_relevant_requests);
$stmt_all_req->execute([$teacher_id]);
$all_requests = $stmt_all_req->fetchAll(PDO::FETCH_ASSOC);

// Распределяем по категориям
foreach ($all_requests as $request) {
    switch ($request['status']) {
        case 'pending_teacher_response':
            $pending_teacher_response_requests[] = $request;
            break;
        case 'teacher_responded_pending_student_confirmation':
            $pending_student_confirmation_requests[] = $request;
            break;
        case 'scheduled_confirmed':
            $scheduled_confirmed_requests[] = $request;
            break;
        case 'student_rejected_offer':
            $student_rejected_requests[] = $request;
            break;
        }
    }

} catch (PDOException $e) {
    error_log("Database Error in teacher_consultations.php: " . $e->getMessage());
    $db_error_message = "Произошла ошибка базы данных: " . $e->getMessage();
} catch (Exception $e) {
    error_log("General Error in teacher_consultations.php: " . $e->getMessage());
    if (isset($request_id_to_reply) && $request_id_to_reply > 0 && isset($errors[$request_id_to_reply])) {
    } else {
        $db_error_message = "Произошла системная ошибка: " . $e->getMessage();
    }
} finally {
    $conn = null;
}
if (isset($_SESSION['message'])) {
    if ($_SESSION['message']['type'] === 'success') {
         $success_message = $_SESSION['message']['text'];
    } else {
         $global_errors[] = $_SESSION['message']['text'];
    }
    unset($_SESSION['message']);
}
$page_title = "Управление Консультациями";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'teacher-consultations-page app-page';
$load_notifications_css = true;
$load_consultations_css = true;  
$page_specific_js = '
    <script>
        const teacherConsultationsPageConfig = {
            // Передаем данные, если нужно предзаполнить форму ответа при ошибке
            errorFormRequestId: null, /* Будет установлено ниже, если есть ошибка */
            errorFormDataReply: {}
        };
    </script>
';
// Если была ошибка при ответе на заявку, передаем данные для предзаполнения модалки
$error_form_request_id_for_js_php = 'null';
$form_data_reply_for_js_json_php = '{}';
if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_to_consultation_request'])) {
    $error_form_request_id_php_val = $_POST['request_id'] ?? null;
    if ($error_form_request_id_php_val && isset($form_data_reply[$error_form_request_id_php_val])) {
        $error_form_request_id_for_js_php = json_encode($error_form_request_id_php_val);
        $form_data_reply_for_js_json_php = json_encode([$error_form_request_id_php_val => $form_data_reply[$error_form_request_id_php_val]]);
    }
}
$page_specific_js .= "
    <script>
        teacherConsultationsPageConfig.errorFormRequestId = {$error_form_request_id_for_js_php};
        teacherConsultationsPageConfig.errorFormDataReply = {$form_data_reply_for_js_json_php};
    </script>
    <script src=\"" . BASE_URL . "assets/js/consultations_teacher.js?v=" . time() . "\" defer></script>
";

ob_start();
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-calendar-check me-2"></i>Управление Консультациями</h1>
        <a href="<?php echo BASE_URL; ?>pages/teacher_consultations_archive.php" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
            <i class="fas fa-archive me-1"></i> Архив консультаций
        </a>
    </div>

    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($global_errors)):?>
        <div class="alert alert-danger mb-4">
            <strong>Возникли ошибки:</strong>
            <ul class="mb-0 ps-3"><?php foreach ($global_errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <!-- Секции для разных типов заявок -->
    <?php
    $sections = [
        'pending_teacher_response' => [
            'title' => 'Новые заявки от студентов',
            'requests' => $pending_teacher_response_requests,
            'badge_class' => 'bg-danger',
            'empty_message' => 'Нет новых заявок, ожидающих вашего ответа.'
        ],
        'scheduled_confirmed' => [
            'title' => 'Запланированные консультации',
            'requests' => $scheduled_confirmed_requests,
            'badge_class' => 'bg-success',
            'empty_message' => 'Нет запланированных консультаций.'
        ],
        'pending_student_confirmation' => [
            'title' => 'Ожидают подтверждения студента',
            'requests' => $pending_student_confirmation_requests,
            'badge_class' => 'bg-info text-dark',
            'empty_message' => 'Нет предложений, ожидающих подтверждения от студентов.'
        ],
        'student_rejected_offer' => [
            'title' => 'Отклоненные студентом предложения',
            'requests' => $student_rejected_requests,
            'badge_class' => 'bg-warning text-dark',
            'empty_message' => 'Нет предложений, отклоненных студентами.'
        ]
    ];
    ?>
    <?php foreach ($sections as $key => $section): ?>
        <section class="mb-5">
            <h2 class="h4 mb-3 border-bottom pb-2">
                <?php echo htmlspecialchars($section['title']); ?>
                <span class="badge rounded-pill <?php echo $section['badge_class']; ?> ms-2 fs-6 align-middle"><?php echo count($section['requests']); ?></span>
            </h2>
            <?php if (empty($section['requests']) && empty($db_error_message) && empty($global_errors) && empty($errors)): ?>
                <div class="alert alert-light text-center py-3"><?php echo htmlspecialchars($section['empty_message']); ?></div>
            <?php elseif (!empty($section['requests'])): ?>
                <div class="row row-cols-1 row-cols-lg-2 g-4">
                    <?php foreach ($section['requests'] as $request): ?>
                        <div class="col">
                            <div class="card h-100 shadow-sm consultation-request-card" id="request-card-<?php echo $request['id']; ?>">
                                <div class="card-header bg-light py-2">
                                    <h3 class="h6 mb-0">Заявка #<?php echo $request['id']; ?>
                                        <small class="text-muted fw-normal">- <?php echo htmlspecialchars(format_ru_datetime_short($request['created_at'])); ?></small>
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1"><strong>Студент:</strong> <a href="<?php echo BASE_URL; ?>pages/profile.php?id=<?php echo $request['student_id']; ?>"><?php echo htmlspecialchars($request['student_name']); ?></a>
                                        (Гр: <?php echo htmlspecialchars($request['student_group_name'] ?? 'N/A'); ?>)</p>
                                    <p class="mb-1"><strong>Дисциплина:</strong> <?php echo htmlspecialchars($request['subject_name']); ?></p>

                                    <?php if ($key === 'pending_teacher_response'): ?>
                                        <p class="mb-1"><strong>Запрос:</strong></p>
                                        <blockquote class="blockquote blockquote-sm bg-light p-2 rounded border-start border-primary border-3">
                                            <p class="mb-0 small fst-italic">"<?php echo nl2br(htmlspecialchars($request['student_message'])); ?>"</p>
                                        </blockquote>
                                        <?php if (!empty($request['requested_period_preference'])): ?>
                                            <p class="mb-1 mt-2 small"><strong>Пожелание по времени:</strong> "<?php echo htmlspecialchars($request['requested_period_preference']); ?>"</p>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($key === 'scheduled_confirmed' || $key === 'pending_student_confirmation'): ?>
                                        <p class="mb-1 mt-2"><strong>Назначено на:</strong> <?php echo htmlspecialchars(format_ru_datetime($request['scheduled_datetime_start'])); ?>
                                            <?php if(isset($request['scheduled_datetime_end'])) echo ' - ' . htmlspecialchars(format_ru_time($request['scheduled_datetime_end'])); ?>
                                        </p>
                                        <?php if(!empty($request['consultation_location_or_link'])): ?>
                                            <p class="mb-1 small"><strong>Место/Ссылка:</strong> <?php echo htmlspecialchars($request['consultation_location_or_link']); ?></p>
                                        <?php endif; ?>
                                        <?php if(!empty($request['teacher_response_message'])): ?>
                                             <p class="mb-1 mt-2 small"><strong>Ваш комментарий:</strong> "<?php echo htmlspecialchars($request['teacher_response_message']); ?>"</p>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                     <?php if ($key === 'student_rejected_offer'): ?>
                                        <p class="mb-1 mt-2"><strong>Ваше предложение было на:</strong> <?php echo htmlspecialchars(format_ru_datetime($request['scheduled_datetime_start'])); ?></p>
                                        <?php if(!empty($request['student_rejection_comment'])): ?>
                                            <p class="mb-1 small text-danger"><strong>Комментарий студента:</strong> "<?php echo htmlspecialchars($request['student_rejection_comment']); ?>"</p>
                                        <?php else: ?>
                                            <p class="mb-1 small text-muted"><em>Студент не оставил комментария при отклонении.</em></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer bg-light py-2 text-end">
                                    <?php if ($key === 'pending_teacher_response'): ?>
                                        <button type="button" class="btn btn-primary btn-sm open-reply-modal-btn"
                                            data-bs-toggle="modal" data-bs-target="#replyRequestModal"
                                            data-request-id="<?php echo $request['id']; ?>"
                                            data-student-name="<?php echo htmlspecialchars($request['student_name']); ?>"
                                            data-subject-name="<?php echo htmlspecialchars($request['subject_name']); ?>"
                                            data-student-message="<?php echo htmlspecialchars($request['student_message']); ?>">
                                        <i class="fas fa-reply me-1"></i>Ответить
                                    </button>
                                    <?php elseif ($key === 'scheduled_confirmed'): ?>
                                        <form method="POST" action="<?php echo BASE_URL; ?>pages/teacher_consultations.php#request-card-<?php echo $request['id']; ?>" class="d-inline-block me-1">
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                            <button type="submit" name="teacher_consultation_action" value="mark_completed" class="btn btn-success btn-sm" title="Отметить как проведенную"><i class="fas fa-check-circle me-1"></i>Проведена</button>
                                        </form>
                                        <form method="POST" action="<?php echo BASE_URL; ?>pages/teacher_consultations.php#request-card-<?php echo $request['id']; ?>" class="d-inline-block">
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                            <button type="submit" name="teacher_consultation_action" value="cancel_by_teacher" class="btn btn-warning btn-sm text-dark" title="Отменить консультацию" onclick="return confirm('Вы уверены, что хотите отменить эту консультацию? Студент будет уведомлен.');"><i class="fas fa-times-circle me-1"></i>Отменить</button>
                                        </form>
                                    <?php elseif ($key === 'pending_student_confirmation'): ?>
                                        <span class="text-muted fst-italic">Ожидается ответ студента...</span>
                                    <?php elseif ($key === 'student_rejected_offer'): ?>
                                        <span class="text-muted fst-italic">Предложение отклонено студентом.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php if (next($sections)):?>
            <hr class="my-5">
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<!-- Модальное окно для ответа преподавателя -->
<div class="modal fade" id="replyRequestModal" tabindex="-1" aria-labelledby="replyRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="replyConsultationRequestForm" action="<?php echo BASE_URL; ?>pages/teacher_consultations.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="replyRequestModalLabel">Ответ на заявку #<span id="modalReplyRequestIdSpan"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="modal_reply_request_id_input" value="">
                    <input type="hidden" name="reply_to_consultation_request" value="1">

                    <div id="modalReplyStudentInfo" class="alert alert-secondary mb-3">
                        <p class="mb-1"><strong>Студент:</strong> <span id="modalReplyStudentName"></span></p>
                        <p class="mb-1"><strong>Дисциплина:</strong> <span id="modalReplySubjectName"></span></p>
                        <p class="mb-0"><strong>Запрос студента:</strong> <em id="modalReplyStudentMessage"></em></p>
                    </div>
                    <div id="modalReplyErrorsContainer" class="alert alert-danger" style="display: none;">
                        <strong>При отправке ответа произошли ошибки:</strong>
                        <ul class="mb-0 ps-3"></ul>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="modal_scheduled_date" class="form-label">Дата консультации <span class="text-danger">*</span></label>
                            <input type="date" id="modal_scheduled_date" name="scheduled_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="modal_scheduled_time" class="form-label">Время начала <span class="text-danger">*</span></label>
                            <input type="time" id="modal_scheduled_time" name="scheduled_time" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="modal_location_or_link" class="form-label">Место проведения / Ссылка на онлайн-встречу</label>
                        <input type="text" id="modal_location_or_link" name="location_or_link" class="form-control" placeholder="Например: Каб. 305 или ссылка Zoom/Google Meet">
                    </div>
                    <div class="mb-3">
                        <label for="modal_teacher_response_message" class="form-label">Сообщение студенту (необязательно)</label>
                        <textarea id="modal_teacher_response_message" name="teacher_response_message" class="form-control" rows="3" placeholder="Любые дополнительные комментарии или инструкции..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">Отправить предложение студенту</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>