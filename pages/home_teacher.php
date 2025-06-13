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
require_once INCLUDES_PATH . 'pluralize.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. Эта страница только для преподавателей.'];
    $redirect_url = is_logged_in() ? BASE_URL . 'pages/dashboard.php' : BASE_URL . 'pages/login.php';
    if (($_SESSION['role'] ?? null) === 'student') $redirect_url = BASE_URL . 'pages/home_student.php';
    if (($_SESSION['role'] ?? null) === 'admin') $redirect_url = BASE_URL . 'pages/home_admin.php';
    header('Location: ' . $redirect_url);
    exit();
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'] ?? 'Преподаватель';

$new_consultation_requests_count = 0;
$upcoming_consultations_today_count = 0;
$pending_grading_count = 0;
$total_new_messages = 0;
$chats_with_new_messages = 0;
$db_error_message = '';
$page_flash_message = null;
$current_datetime_for_footer = date('d.m.Y H:i');
$has_notifications_teacher = false;

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

try {
    $conn = getDbConnection();

    // Количество новых заявок на консультации
    $sql_new_requests = "SELECT COUNT(*) FROM consultation_requests WHERE teacher_id = :teacher_id AND status = 'pending_teacher_response'";
    $stmt_new_consult = $conn->prepare($sql_new_requests);
    $stmt_new_consult->execute([':teacher_id' => $teacher_id]);
    $new_consultation_requests_count = (int)$stmt_new_consult->fetchColumn();

    // Количество запланированных консультаций на сегодня
    $today_start_str = date('Y-m-d 00:00:00');
    $today_end_str = date('Y-m-d 23:59:59');
    $sql_today_consultations = "SELECT COUNT(*) FROM consultation_requests WHERE teacher_id = :teacher_id AND status = 'scheduled_confirmed' AND scheduled_datetime_start BETWEEN :today_start AND :today_end";
    $stmt_today_consult = $conn->prepare($sql_today_consultations);
    $stmt_today_consult->execute([':teacher_id' => $teacher_id, ':today_start' => $today_start_str, ':today_end' => $today_end_str]);
    $upcoming_consultations_today_count = (int)$stmt_today_consult->fetchColumn();

    // Считаем работы на проверку
    $sql_grading = "SELECT COUNT(DISTINCT a.id) FROM assignments a JOIN assignment_definitions ad ON a.assignment_definition_id = ad.id JOIN lessons l ON ad.lesson_id = l.id JOIN teaching_assignments ta ON (l.subject_id = ta.subject_id AND l.group_id = ta.group_id) WHERE ta.teacher_id = :teacher_id AND a.status = 'submitted'";
    $stmt_grading = $conn->prepare($sql_grading);
    $stmt_grading->execute([':teacher_id' => $teacher_id]);
    $pending_grading_count = (int)$stmt_grading->fetchColumn();

    // Получение информации о новых сообщениях в чатах
    $sql_base_new_messages = "
        FROM messages m
        JOIN lessons l ON m.lesson_id = l.id
        JOIN teaching_assignments ta ON (l.subject_id = ta.subject_id AND l.group_id = ta.group_id)
        LEFT JOIN chat_read_status crs ON m.lesson_id = crs.lesson_id AND crs.user_id = :teacher_id
        WHERE ta.teacher_id = :teacher_id
          AND m.user_id != :teacher_id
          AND (crs.last_read_at IS NULL OR m.created_at > crs.last_read_at)
    ";
    $sql_total_count = "SELECT COUNT(m.id) " . $sql_base_new_messages;
    $stmt_total_count = $conn->prepare($sql_total_count);
    $stmt_total_count->execute([':teacher_id' => $teacher_id]);
    $total_new_messages = (int)$stmt_total_count->fetchColumn();

    $sql_chats_count = "SELECT COUNT(DISTINCT m.lesson_id) " . $sql_base_new_messages;
    $stmt_chats_count = $conn->prepare($sql_chats_count);
    $stmt_chats_count->execute([':teacher_id' => $teacher_id]);
    $chats_with_new_messages = (int)$stmt_chats_count->fetchColumn();

} catch (PDOException $e) {
    error_log("Database Error on home_teacher.php for teacher ID {$teacher_id}: " . $e->getMessage());
    $db_error_message = "Произошла ошибка при загрузке сводной информации.";
} finally {
    if ($conn) { $conn = null; }
}

$page_title = "Главная";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'teacher-home-page app-page dashboard-page';
$load_notifications_css = true;
$load_teach_css = true; 
$load_dashboard_styles_css = true; 
$load_news_css = true; 

ob_start();
?>

<div class="container py-4">
    <div class="page-header mb-4">
        <?php
        $teacher_first_name = $teacher_name; 
        if ($teacher_name !== 'Преподаватель' && !empty($teacher_name)) {
            $name_parts_teacher = explode(' ', $teacher_name);
            if (isset($name_parts_teacher[1]) && !empty($name_parts_teacher[1])) { 
                $teacher_first_name = $name_parts_teacher[1];
            } elseif (isset($name_parts_teacher[0]) && !empty($name_parts_teacher[0])) { 
                $teacher_first_name = $name_parts_teacher[0];
            }
        }
        ?>
        <h1 class="h2">Добро пожаловать, <?php echo htmlspecialchars($teacher_first_name); ?>!</h1>
        <p class="text-muted mb-0">Ваша актуальная сводка по учебному процессу.</p>
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

        <?php $has_any_info_blocks = false; ?>

        <div class="news-feed">

        <?php // Работы на проверку?>
        <?php if ($pending_grading_count > 0): $has_notifications_teacher = true; ?>
            <div class="card news-item mb-3 shadow-sm"> 
                <div class="card-header news-header d-flex justify-content-between align-items-center">
                    <h5 class="news-title mb-0">
                        <i class="fas fa-pencil-alt me-2 text-warning"></i>Работы на проверку
                    </h5>
                    <span class="badge bg-warning text-dark rounded-pill"><?php echo $pending_grading_count; ?></span>
                </div>
                <div class="card-body news-content">
                    <p>У вас <strong><?php echo $pending_grading_count; ?></strong> <?php echo pluralize($pending_grading_count, 'новая работа', 'новые работы', 'новых работ'); ?>, ожидающих проверки.</p>
                </div>
                <div class="card-footer news-footer text-muted small">
                <p class="footer-text mb-2"> 
                    Опубликовано: Система | Дата: <?php echo date('d.m.Y H:i'); ?>
                </p>
                <div class="text-end"> 
                    <a href="<?php echo BASE_URL; ?>pages/teacher_submissions_review.php" class="btn btn-sm btn-primary">Перейти к проверке</a>
                </div>
            </div>
            </div>
        <?php endif; ?>

         <?php // Новые Заявки на консультации?>
        <?php if ($new_consultation_requests_count > 0): $has_notifications_teacher = true; ?>
            <div class="card news-item mb-3 shadow-sm">
                 <div class="card-header news-header d-flex justify-content-between align-items-center">
                    <h5 class="news-title mb-0">
                        <i class="fas fa-envelope-open-text me-1 text-info"></i>Новые заявки на консультации
                    </h5>
                    <span class="badge bg-info text-dark rounded-pill news-badge"><?php echo $new_consultation_requests_count; ?></span>
                </div>
                <div class="card-body news-content">
                    <p>У вас <strong><?php echo $new_consultation_requests_count; ?></strong> <?php echo pluralize($new_consultation_requests_count, 'новая заявка', 'новые заявки', 'новых заявок'); ?> на консультацию, ожидающих вашего ответа.</p>
                </div>
                <div class="card-footer news-footer text-muted small">
                    <p class="footer-text mb-2">
                        Сформировано: Система | Дата: <?php echo $current_datetime_for_footer; ?>
                    </p>
                    <div class="text-end">
                        <a href="<?php echo BASE_URL; ?>pages/teacher_consultations.php" class="btn btn-sm btn-primary">Просмотреть заявки</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php // Консультации на сегодня?>
        <?php if ($upcoming_consultations_today_count > 0): $has_notifications_teacher = true; ?>
            <div class="card news-item mb-3 shadow-sm">
                 <div class="card-header news-header d-flex justify-content-between align-items-center">
                    <h5 class="news-title mb-0">
                        <i class="fas fa-calendar-check me-1 text-success"></i>Консультации сегодня
                    </h5>
                    <span class="badge bg-success rounded-pill news-badge"><?php echo $upcoming_consultations_today_count; ?></span>
                </div>
                <div class="card-body news-content">
                    <p>У вас запланировано <strong><?php echo $upcoming_consultations_today_count; ?></strong> <?php echo pluralize($upcoming_consultations_today_count, 'консультация', 'консультации', 'консультаций'); ?> на сегодня.</p>
                </div>
                <div class="card-footer news-footer text-muted small">
                    <p class="footer-text mb-2">
                        Сформировано: Система | Дата: <?php echo $current_datetime_for_footer; ?>
                    </p>
                    <div class="text-end">
                        <a href="<?php echo BASE_URL; ?>pages/teacher_consultations.php" class="btn btn-sm btn-primary">Посмотреть расписание</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php // Новые сообщения в чатах?>
        <?php if ($total_new_messages > 0): $has_notifications_teacher = true; ?>
            <div class="card news-item mb-3 shadow-sm">
                 <div class="card-header news-header d-flex justify-content-between align-items-center">
                    <h5 class="news-title mb-0">
                        <i class="fas fa-comments me-1 text-primary"></i>Новые сообщения в чатах
                    </h5>
                    <span class="badge bg-primary rounded-pill news-badge"><?php echo $total_new_messages; ?></span>
                </div>
                <div class="card-body news-content">
                    <p>
                        У вас <strong><?php echo $total_new_messages; ?></strong>
                        <?php echo pluralize($total_new_messages, 'новое сообщение', 'новых сообщения', 'новых сообщений'); ?>
                        <?php
                        $in_chats_text = '';
                        if ($chats_with_new_messages > 0) {
                            if ($chats_with_new_messages < $total_new_messages || $total_new_messages > 1) {
                                $in_chats_text = "в " . $chats_with_new_messages . " " . pluralize($chats_with_new_messages, 'чате', 'чатах', 'чатах');
                            } elseif ($chats_with_new_messages === 1 && $total_new_messages === 1) {
                                $in_chats_text = "в одном чате";
                            }
                        }
                        echo $in_chats_text;
                        ?>.
                    </p>
                </div>
                 <div class="card-footer news-footer text-muted small">
                    <p class="footer-text mb-2">
                        Сформировано: Система | Дата: <?php echo $current_datetime_for_footer; ?>
                    </p>
                    <div class="text-end">
                        <a href="<?php echo BASE_URL; ?>pages/teacher_dashboard.php" class="btn btn-sm btn-primary">Перейти к группам/занятиям</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
     <?php if (!$has_notifications_teacher && empty($db_error_message)): ?>
        <div class="card card-body text-center text-muted py-5 mt-4">
            <p class="mb-0 fs-5"><i class="fas fa-info-circle fa-2x mb-3 d-block"></i>На данный момент у вас нет актуальных уведомлений или задач.</p>
        </div>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>