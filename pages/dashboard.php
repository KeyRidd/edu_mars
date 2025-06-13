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

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . 'pages/login.php');
    exit;
}
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? null;
$current_user_name = $_SESSION['full_name'] ?? 'Пользователь';

if ($current_user_role === 'student') {
    header('Location: ' . BASE_URL . 'pages/student_dashboard.php');
    exit;
}
if ($current_user_role !== 'admin' && $current_user_role !== 'teacher') {
     $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Неизвестная роль пользователя. Доступ запрещен.'];
     logout_user();
     header('Location: ' . BASE_URL . 'pages/login.php');
     exit;
}

$errors = [];
$page_flash_message = null;
$schedule_by_day = [];
$week_dates_details_obj = null;
$current_start_date_for_input = '';
$selected_group_id_filter = null;
$available_groups_for_filter = [];

if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

$selected_start_date_param = $_GET['start_date'] ?? 'now';
try {
    if (!function_exists('getWeekDates')) { throw new Exception("Функция getWeekDates не определена."); }
    $week_dates_details_obj = getWeekDates($selected_start_date_param);
    $start_date_for_sql = $week_dates_details_obj['start'];
    $end_date_for_sql = $week_dates_details_obj['end'];
    $current_start_date_for_input = $week_dates_details_obj['display_start']->format('Y-m-d');
} catch (Exception $e) {
    $errors[] = "Некорректная дата для отображения недели: " . htmlspecialchars($e->getMessage());
    if (function_exists('getWeekDates')) {
        $week_dates_details_obj = getWeekDates('now');
        $start_date_for_sql = $week_dates_details_obj['start'];
        $end_date_for_sql = $week_dates_details_obj['end'];
        $current_start_date_for_input = $week_dates_details_obj['display_start']->format('Y-m-d');
    } else {
        $errors[] = "Критическая ошибка: Функция для расчета дат недели отсутствует.";
    }
}

$conn = null;
if (empty($errors)) {
    try {
        $conn = getDbConnection();
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($current_user_role === 'admin') {
            $stmt_groups = $conn->query("SELECT id, name FROM groups ORDER BY name ASC");
            $available_groups_for_filter = $stmt_groups->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($current_user_role === 'teacher') {
            $sql_teacher_groups = "SELECT DISTINCT g.id, g.name FROM groups g JOIN teaching_assignments ta ON g.id = ta.group_id WHERE ta.teacher_id = :teacher_id ORDER BY g.name ASC";
            $stmt_groups = $conn->prepare($sql_teacher_groups);
            $stmt_groups->execute([':teacher_id' => $current_user_id]);
            $available_groups_for_filter = $stmt_groups->fetchAll(PDO::FETCH_ASSOC);
        }

        $selected_group_id_filter = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;

        $sql_lessons_base = "
            SELECT l.id, l.title, l.lesson_date, l.duration_minutes, l.lesson_type,
                   s.name as subject_name,
                   g.name as group_name,
                   u.full_name as teacher_name 
            FROM lessons l
            LEFT JOIN subjects s ON l.subject_id = s.id
            LEFT JOIN groups g ON l.group_id = g.id
            LEFT JOIN teaching_assignments ta ON (l.subject_id = ta.subject_id AND l.group_id = ta.group_id)
            LEFT JOIN users u ON ta.teacher_id = u.id
            WHERE l.lesson_date BETWEEN :start_date AND :end_date
        ";
        $params_lessons = [
            ':start_date' => $start_date_for_sql,
            ':end_date' => $end_date_for_sql
        ];
        $can_load_schedule = false;

        if ($current_user_role === 'admin') {
            if ($selected_group_id_filter > 0) {
                $sql_lessons_base .= " AND l.group_id = :group_id_filter";
                $params_lessons[':group_id_filter'] = $selected_group_id_filter;
                $can_load_schedule = true;
            }
        } elseif ($current_user_role === 'teacher') {
            $sql_lessons_base .= " AND ta.teacher_id = :teacher_id";
            $params_lessons[':teacher_id'] = $current_user_id;
            if ($selected_group_id_filter > 0) {
                $sql_lessons_base .= " AND l.group_id = :group_id_filter";
                $params_lessons[':group_id_filter'] = $selected_group_id_filter;
            }
            $can_load_schedule = true;
        }

        if ($can_load_schedule) {
            $sql_lessons_base .= " ORDER BY l.lesson_date ASC"; // Сортируем по времени начала урока
            $stmt_schedule = $conn->prepare($sql_lessons_base);
            $stmt_schedule->execute($params_lessons);
            $lessons_for_week = $stmt_schedule->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lessons_for_week as $lesson_item) {
                $day_key = date('Y-m-d', strtotime($lesson_item['lesson_date']));
                if (!isset($schedule_by_day[$day_key])) { $schedule_by_day[$day_key] = []; }
                $schedule_by_day[$day_key][] = $lesson_item;
            }
        }
    } catch (PDOException $e) {
        error_log("Dashboard PDO Error (Role: $current_user_role): " . $e->getMessage());
        $errors[] = "Ошибка базы данных при загрузке расписания.";
    } finally {
        $conn = null;
    }
}

$page_title = "Расписание занятий";
if ($current_user_role === 'teacher') {
    $page_title = "Мое расписание";
    if ($selected_group_id_filter > 0) {
        $group_name_for_title = '';
        foreach ($available_groups_for_filter as $g) { if ($g['id'] == $selected_group_id_filter) {$group_name_for_title = $g['name']; break;} }
        if ($group_name_for_title) $page_title .= " (Группа: " . htmlspecialchars($group_name_for_title) . ")";
    }
} elseif ($current_user_role === 'admin') {
    $page_title = "Расписание групп";
    if ($selected_group_id_filter > 0) {
        $group_name_for_title = '';
        foreach ($available_groups_for_filter as $g) { if ($g['id'] == $selected_group_id_filter) {$group_name_for_title = $g['name']; break;} }
        if ($group_name_for_title) $page_title = "Расписание группы: " . htmlspecialchars($group_name_for_title);
    }
}

$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = $current_user_role . '-dashboard schedule-page app-page';
$load_notifications_css = true;

ob_start();
?>

<div class="container py-4">
    <div class="page-header mb-3">
        <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
    </div>

    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($errors) && in_array("Критическая ошибка: Функция для расчета дат недели отсутствует.", $errors)): ?>
        <div class="alert alert-danger">Критическая ошибка конфигурации страницы. Обратитесь к администратору.</div>
    <?php else: ?>
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <strong>Обнаружены ошибки:</strong>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error_item): ?><li><?php echo htmlspecialchars($error_item); ?></li><?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4 schedule-controls">
            <div class="card-body">
                <form method="GET" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="row gx-3 gy-2 align-items-end">
                    <div class="col-md-4 col-lg-3">
                        <label for="week_start_date_picker" class="form-label small mb-1">Неделя:</label>
                        <input type="date" class="form-control form-control-sm" id="week_start_date_picker" name="start_date"
                               value="<?php echo htmlspecialchars($current_start_date_for_input); ?>" onchange="this.form.submit()">
                    </div>

                    <?php if (!empty($available_groups_for_filter)): ?>
                        <div class="col-md-4 col-lg-3">
                            <label for="group_filter_select" class="form-label small mb-1">Группа:</label>
                            <select name="group_id" id="group_filter_select" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0"><?php echo ($current_user_role === 'teacher' ? '-- Все мои группы --' : '-- Выберите группу --'); ?></option>
                                <?php foreach ($available_groups_for_filter as $group_filter_item): ?>
                                    <option value="<?php echo $group_filter_item['id']; ?>" <?php echo ($selected_group_id_filter === (int)$group_filter_item['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($group_filter_item['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php elseif ($current_user_role === 'admin'): ?>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label small mb-1 text-muted">Группа:</label>
                            <div class="form-control form-control-sm disabled bg-light" style="line-height: 1.5;">Нет доступных групп</div>
                        </div>
                    <?php endif; ?>

                    <div class="col-auto">
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn btn-outline-secondary btn-sm" title="Показать текущую неделю">
                            <i class="fas fa-calendar-day"></i> Текущая
                        </a>
                    </div>
                    <?php if ($week_dates_details_obj):
                        $prev_week_start_nav = (clone $week_dates_details_obj['display_start'])->modify('-7 days')->format('Y-m-d');
                        $next_week_start_nav = (clone $week_dates_details_obj['display_start'])->modify('+7 days')->format('Y-m-d');
                        $nav_params_query_string = $selected_group_id_filter > 0 ? '&group_id=' . $selected_group_id_filter : '';
                    ?>
                    <div class="col-auto ms-md-auto">
                        <div class="btn-group" role="group">
                            <a href="?start_date=<?php echo $prev_week_start_nav . $nav_params_query_string; ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-chevron-left"></i> Пред.
                            </a>
                            <a href="?start_date=<?php echo $next_week_start_nav . $nav_params_query_string; ?>" class="btn btn-outline-secondary btn-sm">
                                След. <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <?php if ($week_dates_details_obj): ?>
            <div class="card-footer text-center bg-light-subtle py-2">
                <small class="text-muted">
                    Выбрана неделя:
                    <strong><?php echo $week_dates_details_obj['display_start']->format('d.m.Y'); ?></strong> -
                    <strong><?php echo $week_dates_details_obj['display_end']->format('d.m.Y'); ?></strong>
                    (Неделя № <?php echo $week_dates_details_obj['display_start']->format('W'); ?>)
                </small>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($current_user_role === 'admin' && $selected_group_id_filter > 0 && !empty($available_groups_for_filter)): ?>
            <?php
                $group_name_for_create_button = '';
                foreach ($available_groups_for_filter as $g_item) { if ($g_item['id'] == $selected_group_id_filter) {$group_name_for_create_button = $g_item['name']; break;} }
            ?>
            <?php if ($group_name_for_create_button): ?>
            <div class="mb-3 text-end">
                <a href="<?php echo BASE_URL; ?>pages/create_lesson.php?group_id=<?php echo $selected_group_id_filter; ?>&return_week_start=<?php echo urlencode($current_start_date_for_input); ?>" class="btn btn-success">
                    <i class="fas fa-plus me-1"></i> Добавить урок для "<?php echo htmlspecialchars($group_name_for_create_button); ?>"
                </a>
            </div>
            <?php endif; ?>
        <?php endif; ?>


        <?php if ($current_user_role === 'admin' && $selected_group_id_filter <= 0 && !empty($available_groups_for_filter)): ?>
            <div class="alert alert-info text-center">Пожалуйста, выберите группу для отображения расписания и управления уроками.</div>
        <?php elseif ($current_user_role === 'admin' && empty($available_groups_for_filter) && empty($errors)): ?>
            <div class="alert alert-warning text-center">Нет созданных групп. <a href="<?php echo BASE_URL; ?>pages/admin_groups.php" class="alert-link">Создайте группу</a>.</div>
        <?php elseif (empty($schedule_by_day) && ($current_user_role === 'teacher' || ($current_user_role === 'admin' && $selected_group_id_filter > 0)) && empty($errors) && !empty($week_dates_details_obj)): ?>
             <div class="alert alert-light text-center border py-4">
                 <i class="far fa-calendar-times fa-2x mb-3 d-block text-muted"></i>
                На выбранную неделю нет запланированных занятий
                <?php
                    if ($selected_group_id_filter > 0) { 
                        $filtered_group_name = '';
                        foreach($available_groups_for_filter as $gf_item) { if($gf_item['id'] == $selected_group_id_filter) {$filtered_group_name = $gf_item['name']; break;}}
                        if ($filtered_group_name) echo " для группы \"" . htmlspecialchars($filtered_group_name) . "\"";
                    }
                ?>.
            </div>
        <?php elseif (!empty($schedule_by_day)):
            $current_display_date_schedule = clone $week_dates_details_obj['display_start'];
            $end_display_date_schedule = clone $week_dates_details_obj['display_end'];
            $end_display_date_schedule->modify('+1 day'); // Чтобы включить последний день недели

            while ($current_display_date_schedule < $end_display_date_schedule) {
                $day_key_schedule = $current_display_date_schedule->format('Y-m-d');
                $lessons_for_day_schedule = $schedule_by_day[$day_key_schedule] ?? [];

                if (!empty($lessons_for_day_schedule)) {
            ?>
                <div class="card shadow-sm mb-3 schedule-day-card <?php echo $current_display_date_schedule->format('Y-m-d') === date('Y-m-d') ? 'today-highlight' : ''; ?>">
                    <div class="card-header <?php echo $current_user_role === 'admin' ? 'bg-admin-subtle' : 'bg-teacher-subtle'; ?>">
                        <h3 class="h5 mb-0 schedule-day-title">
                            <?php echo htmlspecialchars(format_ru_weekday($current_display_date_schedule->format('N'))); ?>, 
                            <?php echo $current_display_date_schedule->format('d.m.Y'); ?>
                        </h3>
                    </div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($lessons_for_day_schedule as $lesson_item_schedule):
                            $lesson_link_url = BASE_URL . 'pages/lesson.php?id=' . $lesson_item_schedule['id'];
                            $lesson_link_url .= '&return_week_start=' . urlencode($current_start_date_for_input);
                            if ($selected_group_id_filter > 0) {
                                $lesson_link_url .= '&return_group_id=' . $selected_group_id_filter;
                            }
                        ?>
                            <li class="list-group-item schedule-lesson-item">
                                <div class="row g-2 align-items-center">
                                    <div class="col-auto lesson-time text-primary fw-bold" style="min-width: 120px;">
                                        <i class="far fa-clock me-1"></i>
                                        <?php echo date('H:i', strtotime($lesson_item_schedule['lesson_date'])); ?> -
                                        <?php echo date('H:i', strtotime($lesson_item_schedule['lesson_date'] . ' +' . (int)($lesson_item_schedule['duration_minutes'] ?? 90) . ' minutes')); ?>
                                    </div>
                                    <div class="col">
                                        <h4 class="h6 mb-1 lesson-title">
                                            <a href="<?php echo htmlspecialchars($lesson_link_url); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($lesson_item_schedule['title']); ?>
                                            </a>
                                        </h4>
                                        <small class="d-block text-muted lesson-meta">
                                            <i class="fas fa-book me-1"></i><?php echo htmlspecialchars($lesson_item_schedule['subject_name'] ?? 'Дисциплина'); ?>
                                            <?php if ($current_user_role === 'teacher' && $selected_group_id_filter <= 0 && $lesson_item_schedule['group_name']): ?>
                                                <span class="mx-1">|</span> <i class="fas fa-users me-1"></i><?php echo htmlspecialchars($lesson_item_schedule['group_name']); ?>
                                            <?php elseif ($current_user_role === 'admin' && $lesson_item_schedule['group_name']): ?>
                                                <span class="mx-1">|</span> <i class="fas fa-users me-1"></i><?php echo htmlspecialchars($lesson_item_schedule['group_name']); ?>
                                            <?php endif; ?>
                                            <?php if ($current_user_role === 'admin' && !empty($lesson_item_schedule['teacher_name'])): ?>
                                                <span class="mx-1">|</span> <i class="fas fa-chalkboard-teacher me-1"></i><?php echo htmlspecialchars($lesson_item_schedule['teacher_name']); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="col-auto lesson-actions">
                                         <a href="<?php echo htmlspecialchars($lesson_link_url); ?>" class="btn btn-sm btn-outline-primary" title="Перейти к занятию">
                                            <i class="fas fa-arrow-right"></i>
                                         </a>
                                        <?php if ($current_user_role === 'admin'): ?>
                                            <a href="<?php echo BASE_URL; ?>pages/edit_lesson.php?id=<?php echo $lesson_item_schedule['id']; ?>&return_week_start=<?php echo urlencode($current_start_date_for_input); ?>&return_group_id=<?php echo $selected_group_id_filter > 0 ? $selected_group_id_filter : ''; ?>" class="btn btn-sm btn-outline-secondary ms-1" title="Редактировать урок">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>actions/delete_item.php?type=lesson&id=<?php echo $lesson_item_schedule['id']; ?>&confirm=yes&return_to=dashboard&week_start=<?php echo urlencode($current_start_date_for_input); ?>&group_id=<?php echo $selected_group_id_filter > 0 ? $selected_group_id_filter : ''; ?>" 
                                               class="btn btn-sm btn-outline-danger ms-1" 
                                               title="Удалить урок"
                                               onclick="return confirm('Вы уверены, что хотите удалить урок \'<?php echo htmlspecialchars(addslashes($lesson_item_schedule['title'])); ?>\'? Это действие необратимо.');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php
                }
                $current_display_date_schedule->modify('+1 day');
            }
        endif;
    ?>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>