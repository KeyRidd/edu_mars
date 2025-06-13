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

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен.'];
    $redirect_url_auth_student = BASE_URL . 'pages/login.php';
    if (is_logged_in() && isset($_SESSION['role'])) {
        if ($_SESSION['role'] === 'admin') $redirect_url_auth_student = BASE_URL . 'pages/home_admin.php';
        elseif ($_SESSION['role'] === 'teacher') $redirect_url_auth_student = BASE_URL . 'pages/home_teacher.php';
        else $redirect_url_auth_student = BASE_URL . 'pages/dashboard.php';
    }
    header('Location: ' . $redirect_url_auth_student);
    exit();
}

$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Студент';
$student_group_id = $_SESSION['group_id'] ?? 0;
$student_group_name = '';

$errors = [];
$page_flash_message = null;
$schedule_by_day = []; 
$week_dates_details_obj = null; 
$current_start_date_for_input = '';

// Получение флеш-сообщения
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

// Определяем текущую неделю для отображения
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
        $errors[] = "Критическая ошибка: Необходимая функция для расчета дат недели отсутствует.";
    }
}

$conn = null;
if ($student_group_id > 0 && empty($errors)) { 
    try {
        $conn = getDbConnection();

        // Получаем название группы студента
        $stmt_group_name = $conn->prepare("SELECT name FROM groups WHERE id = :group_id");
        $stmt_group_name->execute([':group_id' => $student_group_id]);
        $student_group_name_result = $stmt_group_name->fetchColumn();
        if ($student_group_name_result) {
            $student_group_name = $student_group_name_result;
        } else {
            $errors[] = "Не удалось найти вашу группу. Обратитесь к администратору.";
        }

        if (empty($errors)) { 
            // Загрузка занятий для группы студента на выбранную неделю
            $sql_student_schedule = "
                SELECT l.id, l.title, l.lesson_date, l.duration_minutes, l.lesson_type,
                       s.name as subject_name,
                       u.full_name as teacher_name  
                FROM lessons l
                LEFT JOIN subjects s ON l.subject_id = s.id
                LEFT JOIN teaching_assignments ta ON (l.subject_id = ta.subject_id AND l.group_id = ta.group_id) 
                LEFT JOIN users u ON ta.teacher_id = u.id 
                WHERE l.group_id = :group_id
                  AND l.lesson_date BETWEEN :start_date AND :end_date
                ORDER BY l.lesson_date ASC
            ";
            $stmt_schedule = $conn->prepare($sql_student_schedule);
            $stmt_schedule->execute([
                ':group_id' => $student_group_id,
                ':start_date' => $start_date_for_sql,
                ':end_date' => $end_date_for_sql
            ]);
            $lessons_for_week = $stmt_schedule->fetchAll(PDO::FETCH_ASSOC);

            // Группируем по дням
            foreach ($lessons_for_week as $lesson_item) {
                $day_key = date('Y-m-d', strtotime($lesson_item['lesson_date']));
                if (!isset($schedule_by_day[$day_key])) {
                    $schedule_by_day[$day_key] = [];
                }
                $schedule_by_day[$day_key][] = $lesson_item;
            }
        }

    } catch (PDOException $e) {
        error_log("DB Error student_home.php (student ID {$student_id}): " . $e->getMessage());
        $errors[] = "Произошла ошибка при загрузке вашего расписания.";
    } finally {
        $conn = null;
    }
} elseif ($student_group_id <= 0 && empty($errors)) {
    $errors[] = "Вы не привязаны к учебной группе. Расписание не может быть отображено.";
}

$page_title = "Мое расписание";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'student-home-page student-schedule-page app-page';
$load_notifications_css = true;
$load_schedule_styles_css = true; 

ob_start();
?>

<div class="container py-4">
    <div class="page-header mb-3">
        <h1 class="h2">Мое расписание</h1>
        <?php if ($student_group_name): ?>
            <p class="text-muted lead">Группа: <strong><?php echo htmlspecialchars($student_group_name); ?></strong></p>
        <?php endif; ?>
    </div>

    <?php if ($page_flash_message): endif; ?>
    <?php if (!empty($errors)): endif; ?>

    <?php // Блок фильтров и навигации по неделям?>
    <div class="card shadow-sm mb-4 schedule-controls">
        <div class="card-body">
            <form method="GET" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="row gx-2 gy-3 align-items-center">
                <div class="col-md-4 col-lg-3">
                    <label for="week_start_date_picker" class="form-label visually-hidden">Показать неделю с:</label>
                    <input type="date" class="form-control" id="week_start_date_picker" name="start_date"
                           value="<?php echo htmlspecialchars($current_start_date_for_input); ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Показать</button>
                </div>
                <div class="col-auto">
                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn btn-outline-secondary" title="Показать текущую неделю">
                        <i class="fas fa-calendar-day"></i> Текущая неделя
                    </a>
                </div>
                <?php if ($week_dates_details_obj):
                    $prev_week_start = (clone $week_dates_details_obj['display_start'])->modify('-7 days')->format('Y-m-d');
                    $next_week_start = (clone $week_dates_details_obj['display_start'])->modify('+7 days')->format('Y-m-d');
                ?>
                <div class="col-auto ms-md-auto"> 
                    <div class="btn-group" role="group" aria-label="Weekly navigation">
                        <a href="?start_date=<?php echo $prev_week_start; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-chevron-left"></i> Пред.
                        </a>
                        <a href="?start_date=<?php echo $next_week_start; ?>" class="btn btn-outline-secondary">
                            След. <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </form>
        </div>
        <?php if ($week_dates_details_obj): ?>
        <div class="card-footer text-center bg-light">
            <small class="text-muted">
                Расписание на неделю:
                <strong><?php echo $week_dates_details_obj['display_start']->format('d.m.Y'); ?></strong> -
                <strong><?php echo $week_dates_details_obj['display_end']->format('d.m.Y'); ?></strong>
                (Неделя № <?php echo $week_dates_details_obj['display_start']->format('W'); ?>)
            </small>
        </div>
        <?php endif; ?>
    </div>

    <?php // Блок отображения расписания ?>
    <?php if (empty($errors) && $student_group_id > 0 && $student_group_name): ?>
        <?php if (empty($schedule_by_day) && !empty($week_dates_details_obj)): ?>
            <div class="alert alert-info text-center py-4">
                 <i class="fas fa-calendar-alt fa-2x mb-3 d-block"></i>
                На выбранную неделю (<?php echo $week_dates_details_obj['display_start']->format('d.m') . ' - ' . $week_dates_details_obj['display_end']->format('d.m'); ?>) нет запланированных занятий.
            </div>
        <?php elseif (!empty($schedule_by_day)):
            $current_loop_date = clone $week_dates_details_obj['display_start'];
        $end_display_date_loop = clone $week_dates_details_obj['display_end'];
        $end_display_date_loop->modify('+1 day');
        $has_lessons_this_week = false;

        while ($current_loop_date < $end_display_date_loop) {
            $day_key_for_loop = $current_loop_date->format('Y-m-d');
            $lessons_on_this_day = $schedule_by_day[$day_key_for_loop] ?? [];

            // Выводим блок дня только если есть занятия в этот день
            if (!empty($lessons_on_this_day)) {
                $has_lessons_this_week = true; 
            ?>
            <div class="card shadow-sm mb-3 schedule-day-card <?php echo $current_loop_date->format('Y-m-d') === date('Y-m-d') ? 'today-highlight' : ''; ?>">
                <div class="card-header bg-primary-subtle"> 
                    <h3 class="h5 mb-0 schedule-day-title">
                        <?php echo htmlspecialchars(format_ru_weekday($current_loop_date->format('N'), false)); ?>,
                        <?php echo $current_loop_date->format('d.m.Y'); ?>
                    </h3>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($lessons_on_this_day as $lesson_item_display):
                        $base_lesson_url = BASE_URL . 'pages/lesson.php?id=' . $lesson_item_display['id'];
                        $lesson_url_with_return_param = $base_lesson_url . '&return_week_start=' . urlencode($current_start_date_for_input);
                    ?>
                        <li class="list-group-item schedule-lesson-item">
                            <div class="row g-2 align-items-center">
                                <div class="col-auto lesson-time text-primary fw-bold" style="min-width: 120px;">
                                    <i class="far fa-clock me-1"></i>
                                    <?php echo date('H:i', strtotime($lesson_item_display['lesson_date'])); ?> -
                                    <?php echo date('H:i', strtotime($lesson_item_display['lesson_date'] . ' +' . (int)($lesson_item_display['duration_minutes'] ?? 90) . ' minutes')); ?>
                                </div>
                                <div class="col">
                                    <h4 class="h6 mb-1 lesson-title">
                                        <a href="<?php echo htmlspecialchars($lesson_url_with_return_param); ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($lesson_item_display['title']); ?>
                                        </a>
                                    </h4>
                                    <small class="d-block text-muted lesson-meta">
                                        <i class="fas fa-book me-1"></i><?php echo htmlspecialchars($lesson_item_display['subject_name'] ?? 'Дисциплина не указана'); ?>
                                        <?php if (!empty($lesson_item_display['teacher_name'])): ?>
                                            <span class="mx-1">|</span> <i class="fas fa-chalkboard-teacher me-1"></i><?php echo htmlspecialchars($lesson_item_display['teacher_name']); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="col-auto lesson-actions">
                                    <a href="<?php echo htmlspecialchars($lesson_url_with_return_param); ?>" class="btn btn-sm btn-outline-primary" title="Перейти к занятию">
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
            } 
            $current_loop_date->modify('+1 day');
        } 
        // Если за всю неделю не было найдено ни одного занятия, показываем общее сообщение
        if (!$has_lessons_this_week && !empty($week_dates_details_obj) && empty($errors) && $student_group_id > 0 && $student_group_name) {
        ?>
            <div class="alert alert-info text-center py-4 mt-3">
                 <i class="fas fa-calendar-alt fa-2x mb-3 d-block"></i>
                На выбранную неделю (<?php echo $week_dates_details_obj['display_start']->format('d.m') . ' - ' . $week_dates_details_obj['display_end']->format('d.m'); ?>) нет запланированных занятий.
            </div>
        <?php
        }
        ?>
        <?php endif ?>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>