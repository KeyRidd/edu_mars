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

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен.']; 
    header('Location: ' . BASE_URL . 'pages/dashboard.php'); 
    exit();
}

$conn = null;
$stats = []; // Массив для хранения всех статистических данных
$errors = []; // Для ошибок, отображаемых на странице (например, ошибка БД)
$page_flash_message = null; // Для флеш-сообщений из сессии

// Получение флеш-сообщения из сессии 
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}
if (isset($_SESSION['message'])) {
    if (!$page_flash_message && !empty($_SESSION['message']['text'])) {
        $page_flash_message = $_SESSION['message'];
    }
    unset($_SESSION['message']);
}

try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Общая статистика по пользователям
    $stats['total_users'] = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['users_by_role'] = $conn->query("SELECT role, COUNT(*) as count FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR); // роль => количество
    $stats['new_users_30_days'] = $conn->query("SELECT COUNT(*) FROM users WHERE created_at >= CURRENT_DATE - INTERVAL '30 days'")->fetchColumn();
    $stats['active_users_7_days'] = $conn->query("SELECT COUNT(*) FROM users WHERE last_login >= CURRENT_DATE - INTERVAL '7 days'")->fetchColumn();

    // Статистика по учебным сущностям
    $stats['total_groups'] = $conn->query("SELECT COUNT(*) FROM groups")->fetchColumn();
    $stats['total_subjects'] = $conn->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
    $stats['total_lessons'] = $conn->query("SELECT COUNT(*) FROM lessons")->fetchColumn();
    $stats['lessons_by_type'] = $conn->query("SELECT lesson_type, COUNT(*) as count FROM lessons GROUP BY lesson_type")->fetchAll(PDO::FETCH_KEY_PAIR); // тип => количество
    $stats['lessons_past'] = $conn->query("SELECT COUNT(*) FROM lessons WHERE DATE(lesson_date) < CURRENT_DATE")->fetchColumn();
    $stats['lessons_today'] = $conn->query("SELECT COUNT(*) FROM lessons WHERE DATE(lesson_date) = CURRENT_DATE")->fetchColumn();
    $stats['lessons_next_7_days'] = $conn->query("SELECT COUNT(*) FROM lessons WHERE DATE(lesson_date) > CURRENT_DATE AND DATE(lesson_date) <= CURRENT_DATE + INTERVAL '7 days'")->fetchColumn();
    
    // Статистика по учебным материалам и заданиям
    $stats['total_materials'] = $conn->query("SELECT COUNT(*) FROM materials")->fetchColumn();
    $stats['total_assignment_definitions'] = $conn->query("SELECT COUNT(*) FROM assignment_definitions")->fetchColumn();
    $stats['total_assignments_submitted'] = $conn->query("SELECT COUNT(*) FROM assignments")->fetchColumn(); // Всего сдано (не уникальных студентов, а фактов сдачи)
    $stats['assignments_by_status'] = $conn->query("SELECT status, COUNT(*) as count FROM assignments GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Статистика по коммуникациям
    $stats['total_messages'] = $conn->query("SELECT COUNT(*) FROM messages")->fetchColumn();
    $stats['active_chats_7_days'] = $conn->query("SELECT COUNT(DISTINCT lesson_id) FROM messages WHERE created_at >= CURRENT_DATE - INTERVAL '7 days'")->fetchColumn();
    $stats['consultations_total'] = $conn->query("SELECT COUNT(*) FROM consultation_requests")->fetchColumn();
    $stats['consultations_by_status'] = $conn->query("SELECT status, COUNT(*) as count FROM consultation_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['consultations_pending_teacher'] = $stats['consultations_by_status']['pending_teacher_response'] ?? 0;
    $stats['consultations_pending_student'] = $stats['consultations_by_status']['teacher_responded_pending_student_confirmation'] ?? 0;
    $stats['consultations_scheduled_future'] = $conn->query("SELECT COUNT(*) FROM consultation_requests WHERE status = 'scheduled_confirmed' AND scheduled_datetime_start >= NOW()")->fetchColumn();
    $stats['consultations_completed'] = $stats['consultations_by_status']['completed'] ?? 0;


} catch (PDOException $e) {
    error_log("DB Error admin_statistics.php: " . $e->getMessage());
    $db_error_message = "Ошибка загрузки статистических данных: " . $e->getMessage();
} finally {
    $conn = null;
}

// Вспомогательные функции для отображения 
function get_role_name_stat(string $role_key): string {
    $roles = ['admin' => 'Администраторы', 'teacher' => 'Преподаватели', 'student' => 'Студенты'];
    return $roles[$role_key] ?? ucfirst($role_key);
}

function get_lesson_type_name_stat(string $type_key): string {
    $types = ['lecture' => 'Лекции', 'practice' => 'Практики', 'assessment' => 'Аттестации'];
    return $types[$type_key] ?? ucfirst($type_key);
}

function get_assignment_status_name_stat(string $status_key): string {
    $statuses = [
        'submitted' => 'Сдано (на проверке)', 
        'reviewed' => 'Проверено (ожидает оценки/зачета)', 
        'approved' => 'Зачтено/Одобрено',
        'graded' => 'Оценено' 
    ];
    return $statuses[$status_key] ?? ucfirst(str_replace('_', ' ', $status_key));
}

$page_title = "Статистика Системы";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'admin-page statistics-page app-page';
$load_notifications_css = true;
$load_admin_css = true;

ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0"><i class="fas fa-chart-line me-2"></i>Статистика Системы</h1>
    </div>

    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_admin.php">Админ-панель</a></li>
            <li class="breadcrumb-item active" aria-current="page">Статистика Системы</li>
        </ol>
    </nav>

    <?php // Отображение флеш-сообщений и ошибок ?>
    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <strong>Обнаружены ошибки:</strong>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?><li><?php echo $error; ?></li><?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>


    <?php if (empty($errors)): // Показываем статистику, только если не было критических ошибок загрузки ?>
    <div class="row gy-4">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-users me-2"></i>Пользователи</h5></div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($stats['users_by_role'] ?? [] as $role_key => $count): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo htmlspecialchars(get_role_name_stat((string)$role_key)); ?>
                                <span class="badge bg-primary rounded-pill"><?php echo (int)$count; ?></span>
                            </li>
                        <?php endforeach; ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center list-group-item-secondary">
                            Всего пользователей: <span class="fw-bold"><?php echo (int)($stats['total_users'] ?? 0); ?></span>
                        </li>
                        <li class="list-group-item">Новых за 30 дней: <strong><?php echo (int)($stats['new_users_30_days'] ?? 0); ?></strong></li>
                        <li class="list-group-item">Активных за 7 дней: <strong><?php echo (int)($stats['active_users_7_days'] ?? 0); ?></strong></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-book-reader me-2"></i>Учебный Процесс</h5></div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                         <li class="list-group-item d-flex justify-content-between align-items-center">Групп: <span class="badge bg-info rounded-pill"><?php echo (int)($stats['total_groups'] ?? 0); ?></span></li>
                         <li class="list-group-item d-flex justify-content-between align-items-center">Дисциплин: <span class="badge bg-info rounded-pill"><?php echo (int)($stats['total_subjects'] ?? 0); ?></span></li>
                         <li class="list-group-item d-flex justify-content-between align-items-center list-group-item-secondary">
                            Всего уроков: <span class="fw-bold"><?php echo (int)($stats['total_lessons'] ?? 0); ?></span>
                         </li>
                        <?php foreach ($stats['lessons_by_type'] ?? [] as $type => $count): ?>
                            <li class="list-group-item"><?php echo htmlspecialchars(get_lesson_type_name_stat((string)$type)); ?>: <strong><?php echo (int)$count; ?></strong></li>
                        <?php endforeach; ?>
                        <li class="list-group-item">Уроков проведено: <strong><?php echo (int)($stats['lessons_past'] ?? 0); ?></strong></li>
                        <li class="list-group-item">Уроков сегодня: <strong><?php echo (int)($stats['lessons_today'] ?? 0); ?></strong></li>
                        <li class="list-group-item">Уроков на след. 7 дней: <strong><?php echo (int)($stats['lessons_next_7_days'] ?? 0); ?></strong></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-folder-open me-2"></i>Материалы и Задания</h5></div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">Загружено материалов: <strong><?php echo (int)($stats['total_materials'] ?? 0); ?></strong></li>
                        <li class="list-group-item">Определений заданий: <strong><?php echo (int)($stats['total_assignment_definitions'] ?? 0); ?></strong></li>
                        <li class="list-group-item">Всего сдано работ: <strong><?php echo (int)($stats['total_assignments_submitted'] ?? 0); ?></strong></li>
                        <?php if (!empty($stats['assignments_by_status'])): ?>
                            <li class="list-group-item pt-2 text-muted small"><em>Работы по статусам:</em></li>
                            <?php foreach ($stats['assignments_by_status'] as $status => $count): ?>
                                <li class="list-group-item ps-4"><?php echo htmlspecialchars(get_assignment_status_name_stat((string)$status)); ?>: <strong><?php echo (int)$count; ?></strong></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-comments me-2"></i>Коммуникации</h5></div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">Сообщений в чатах: <strong><?php echo (int)($stats['total_messages'] ?? 0); ?></strong></li>
                        <li class="list-group-item">Активных чатов (за 7 дней): <strong><?php echo (int)($stats['active_chats_7_days'] ?? 0); ?></strong></li>
                        <li class="list-group-item pt-2 text-muted small"><em>Консультации:</em></li>
                        <li class="list-group-item ps-4">Всего заявок: <strong><?php echo (int)($stats['consultations_total'] ?? 0); ?></strong></li>
                        <li class="list-group-item ps-4">Ожидают ответа преподавателя: <strong class="text-warning"><?php echo (int)($stats['consultations_pending_teacher'] ?? 0); ?></strong></li>
                        <li class="list-group-item ps-4">Ожидают ответа студента: <strong class="text-warning"><?php echo (int)($stats['consultations_pending_student'] ?? 0); ?></strong></li>
                        <li class="list-group-item ps-4">Запланировано (будущие): <strong class="text-info"><?php echo (int)($stats['consultations_scheduled_future'] ?? 0); ?></strong></li>
                        <li class="list-group-item ps-4">Проведено: <strong class="text-success"><?php echo (int)($stats['consultations_completed'] ?? 0); ?></strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>