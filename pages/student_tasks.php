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

// Подключение основных файлов
require_once CONFIG_PATH . 'database.php';
require_once INCLUDES_PATH . 'functions.php';
require_once INCLUDES_PATH . 'auth.php';  
require_once INCLUDES_PATH . 'pluralize.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. Эта страница только для студентов.']; 
    $redirect_url = is_logged_in() ? BASE_URL . 'pages/dashboard.php' : BASE_URL . 'pages/login.php';
    header('Location: ' . $redirect_url);
    exit();
}

$student_id = $_SESSION['user_id'];
$tasks = [];
$db_error_message = '';
$student_group_id = null;
$selected_subject_id = null;
$selected_subject_name = null;

$conn = null;
try {
    $conn = getDbConnection();
    // Получаем ID группы студента
    $stmt_group = $conn->prepare("SELECT group_id FROM users WHERE id = :student_id");
    $stmt_group->execute([':student_id' => $student_id]);
    $student_group_id_result = $stmt_group->fetchColumn();
    if ($student_group_id_result === false || $student_group_id_result === null) {
        $db_error_message = "Вы не привязаны к учебной группе. Список заданий недоступен.";
    } else {
        $student_group_id = (int)$student_group_id_result;

        if (isset($_GET['subject_id']) && filter_var($_GET['subject_id'], FILTER_VALIDATE_INT)) {
            $selected_subject_id = (int)$_GET['subject_id'];
            $stmt_subject_check = $conn->prepare("SELECT name FROM subjects WHERE id = :subject_id");
            $stmt_subject_check->execute([':subject_id' => $selected_subject_id]);
            $selected_subject_name = $stmt_subject_check->fetchColumn();
            if (!$selected_subject_name) {
                $db_error_message = "Дисциплина с ID {$selected_subject_id} не найден. Отображаются все задания.";
                $selected_subject_id = null;
            }
        }
        // Загружаем задания
        $sql_tasks_base = "
            SELECT
                ad.id as definition_id, ad.title as assignment_title, ad.description as assignment_description,
                ad.deadline, ad.lesson_id,
                l.title as lesson_title, l.lesson_date,
                s.id as subject_id_from_lesson, s.name as subject_name,
                a.id as submission_id, a.submitted_at, a.grade, a.status as submission_status
            FROM assignment_definitions ad
            JOIN lessons l ON ad.lesson_id = l.id
            LEFT JOIN subjects s ON l.subject_id = s.id
            LEFT JOIN assignments a ON a.assignment_definition_id = ad.id AND a.student_id = :student_id
            WHERE l.group_id = :student_group_id
        ";
        $params_tasks = [':student_id' => $student_id, ':student_group_id' => $student_group_id];
        if ($selected_subject_id !== null) {
            $sql_tasks_base .= " AND l.subject_id = :subject_id_filter ";
            $params_tasks[':subject_id_filter'] = $selected_subject_id;
        }
        $sql_tasks_order = " ORDER BY s.name ASC, l.lesson_date DESC, ad.created_at DESC";
        $sql_tasks = $sql_tasks_base . $sql_tasks_order;
        $stmt_tasks = $conn->prepare($sql_tasks);
        $stmt_tasks->execute($params_tasks);
        $tasks = $stmt_tasks->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("DB Error on student_tasks.php (Student ID: {$student_id}): " . $e->getMessage());
    $db_error_message = "Произошла ошибка при загрузке списка заданий.";
} finally {
    $conn = null;
}
// Флеш-сообщение
$page_flash_message = null;
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

// Вспомогательная функция для определения статуса и класса дедлайна
function getDeadlineStatusStudentTasks(?string $deadline_str, bool $is_submitted): array {
    $status = ['text' => '-', 'class' => 'deadline-none'];
    if (empty($deadline_str)) {
        return $status;
    }
    try {
        $deadline_dt = new DateTimeImmutable($deadline_str);
        $now = new DateTimeImmutable();
        $diff_interval = $now->diff($deadline_dt);
        $days_left = (int)$diff_interval->format('%r%a');
        $hours_left = (int)$diff_interval->format('%r%h'); 
        $total_hours_left = $days_left * 24 + $hours_left;
        $formatted_deadline = $deadline_dt->format('d.m.Y H:i');

        if ($now > $deadline_dt) {
            $status['text'] = $formatted_deadline . ' (Просрочено)';
            $status['class'] = $is_submitted ? 'deadline-submitted-late text-warning' : 'deadline-critical text-danger';
        } else {
            $status['text'] = $formatted_deadline;
            if ($days_left > 7) {
                $status['text'] .= " (Осталось > 7 дней)";
                $status['class'] = 'deadline-normal text-success';
            } elseif ($days_left >= 1) { 
                $status['text'] .= " (Осталось " . $days_left . " " . pluralize($days_left, 'день', 'дня', 'дней') . ")";
                $status['class'] = 'deadline-warning text-warning';
            } elseif ($total_hours_left > 0) {
                 $status['text'] .= " (Менее 24 ч)";
                 $status['class'] = 'deadline-critical text-danger';
            } else {
                 $status['text'] .= " (Срок истекает!)";
                 $status['class'] = 'deadline-critical text-danger';
            }
        }
    } catch (Exception $e) {
        error_log("Error parsing deadline in getDeadlineStatusStudentTasks: " . $e->getMessage());
        $status['text'] = !empty($deadline_str) ? $deadline_str . ' (Ошибка даты)' : '-';
        $status['class'] = 'deadline-error';
    }
    return $status;
}

$page_title = "Мои Задания" . ($selected_subject_name ? ' - ' . htmlspecialchars($selected_subject_name) : '');
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'student-tasks-page app-page';
$load_notifications_css = true;
$load_stud_css = true; 
ob_start();
?>

<div class="container py-4">
    <div class="page-header mb-3">
        <h1 class="h2"><i class="fas fa-tasks me-2"></i>Мои Задания</h1>
    </div>

    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($db_error_message)): ?>
        <div class="alert alert-warning mb-4"><?php echo htmlspecialchars($db_error_message); ?></div>
    <?php endif; ?>


    <?php if ($selected_subject_id && $selected_subject_name && empty($db_error_message)): ?>
        <div class="alert alert-info mb-4">
            Задания по дисциплине: <strong><?php echo htmlspecialchars($selected_subject_name); ?></strong>.
            <a href="<?php echo BASE_URL; ?>pages/student_tasks.php" class="ms-2 fw-normal">Показать все задания</a>
        </div>
    <?php endif; ?>
    <?php if (!$student_group_id && empty($db_error_message)): ?>
        <div class="alert alert-info">Чтобы видеть задания, вы должны быть зачислены в группу.</div>
    <?php elseif (empty($tasks) && !$selected_subject_id && empty($db_error_message)): ?>
        <div class="card text-center p-4">
            <p class="mb-0 text-muted">Пока нет назначенных заданий для вашей группы.</p>
        </div>
    <?php elseif (empty($tasks) && $selected_subject_id && $selected_subject_name && empty($db_error_message)): ?>
        <div class="card text-center p-4">
            <p class="mb-0 text-muted">По дисциплине "<?php echo htmlspecialchars($selected_subject_name); ?>" пока нет назначенных заданий.</p>
        </div>
    <?php elseif (!empty($tasks)): ?>
        <div class="card">
            <div class="card-body p-0"> 
                <div class="table-responsive">
                    <table class="table table-hover table-striped tasks-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" class="text-center" style="width: 80px;">Статус</th>
                                <?php if (!$selected_subject_id): ?>
                                    <th scope="col">Дисциплина</th>
                                <?php endif; ?>
                                <th scope="col">Урок</th>
                                <th scope="col">Задание</th>
                                <th scope="col">Срок сдачи</th>
                                <th scope="col" class="text-center" style="width: 90px;">Оценка</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task): ?>
                                <?php
                                    $is_submitted = ($task['submission_id'] !== null);
                                    $deadline_info = getDeadlineStatusStudentTasks($task['deadline'], $is_submitted); 
                                ?>
                                <tr>
                                    <td class="text-center align-middle">
                                        <?php if ($is_submitted): ?>
                                            <?php if ($task['submission_status'] === 'approved'): ?>
                                                  <i class="fas fa-check-double fa-lg text-success status-icon" title="Одобрено (<?php echo $task['submitted_at'] ? htmlspecialchars(format_ru_datetime_short($task['submitted_at'])) : ''; ?>)"></i>
                                            <?php elseif ($task['submission_status'] === 'reviewed'): ?>
                                                   <i class="fas fa-check fa-lg text-info status-icon" title="Проверено (<?php echo $task['submitted_at'] ? htmlspecialchars(format_ru_datetime_short($task['submitted_at'])) : ''; ?>)"></i>
                                            <?php else: ?>
                                                   <i class="fas fa-paper-plane fa-lg text-primary status-icon" title="Сдано, на проверке (<?php echo $task['submitted_at'] ? htmlspecialchars(format_ru_datetime_short($task['submitted_at'])) : ''; ?>)"></i>
                                            <?php endif; ?>
                                        <?php elseif (strpos($deadline_info['class'], 'deadline-critical') !== false): ?>
                                            <i class="fas fa-exclamation-triangle fa-lg text-danger status-icon" title="Срок истек или истекает!"></i>
                                        <?php elseif (strpos($deadline_info['class'], 'deadline-warning') !== false): ?>
                                             <i class="fas fa-hourglass-half fa-lg text-warning status-icon" title="Скоро срок сдачи"></i>
                                        <?php else: ?>
                                            <i class="far fa-circle fa-lg text-muted status-icon" title="Не сдано"></i>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!$selected_subject_id): ?>
                                        <td class="align-middle"><?php echo htmlspecialchars($task['subject_name'] ?? 'Н/Д'); ?></td>
                                    <?php endif; ?>
                                    <td class="align-middle">
                                        <a href="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $task['lesson_id']; ?>" title="Перейти к уроку">
                                            <?php echo htmlspecialchars($task['lesson_title']); ?>
                                        </a>
                                    </td>
                                    <td class="align-middle">
                                        <a href="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $task['lesson_id']; ?>&tab=assignments#assignment-def-<?php echo $task['definition_id']; ?>" title="Перейти к заданию на странице урока">
                                            <?php echo htmlspecialchars($task['assignment_title']); ?>
                                        </a>
                                        <?php if (!empty($task['assignment_description'])): ?>
                                            <small class="d-block text-muted"><?php echo htmlspecialchars(mb_substr($task['assignment_description'], 0, 70)) . (mb_strlen($task['assignment_description']) > 70 ? '...' : ''); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle <?php echo htmlspecialchars($deadline_info['class']); ?>">
                                        <?php echo htmlspecialchars($deadline_info['text']); ?>
                                    </td>
                                    <td class="text-center align-middle">
                                        <?php if ($is_submitted && $task['grade'] !== null): ?>
                                            <?php
                                                 $grade = (float)$task['grade'];
                                                 $grade_class = 'bg-secondary';
                                                 if ($grade >= 85) $grade_class = 'bg-success';
                                                 else if ($grade >= 71) $grade_class = 'bg-primary';
                                                 else if ($grade >= 61) $grade_class = 'bg-warning text-dark';
                                                 else $grade_class = 'bg-danger';
                                            ?>
                                            <span class="badge <?php echo $grade_class; ?>">
                                                <?php echo htmlspecialchars(number_format($grade, 1)); ?>
                                            </span>
                                        <?php elseif ($is_submitted): ?>
                                             <em class="text-muted small">На проверке</em>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>