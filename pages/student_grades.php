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
    $redirect_url = is_logged_in() ? BASE_URL . 'pages/dashboard.php' : BASE_URL . 'pages/login.php';
    header('Location: ' . $redirect_url);
    exit();
}

$student_id = $_SESSION['user_id'];
$subject_summary = [];
$db_error_message = '';
$student_group_id = null;

$conn = null;
try {
    $conn = getDbConnection();

    $stmt_group = $conn->prepare("SELECT group_id FROM users WHERE id = :student_id");
    $stmt_group->execute([':student_id' => $student_id]);
    $student_group_id_result = $stmt_group->fetchColumn();

    if ($student_group_id_result === false || $student_group_id_result === null) {
        $db_error_message = "Вы не привязаны к учебной группе. Информация об оценках недоступна.";
    } else {
        $student_group_id = (int)$student_group_id_result;

        $sql_grades_summary = "
            SELECT
                s.id AS subject_id,
                s.name AS subject_name,
                s.min_passing_grade,
                MAX(ta.final_assessment_date) AS final_assessment_date,
                COALESCE(SUM(CASE WHEN a.status IN ('approved', 'graded', 'reviewed') THEN a.grade ELSE 0 END), 0) AS current_total_grade
            FROM subjects s
            JOIN lessons l ON s.id = l.subject_id AND l.group_id = :student_group_id
            LEFT JOIN teaching_assignments ta ON s.id = ta.subject_id AND l.group_id = ta.group_id
            LEFT JOIN assignment_definitions ad ON l.id = ad.lesson_id
            LEFT JOIN assignments a ON ad.id = a.assignment_definition_id AND a.student_id = :student_id
            GROUP BY s.id, s.name, s.min_passing_grade
            ORDER BY s.name ASC;
        ";

        $stmt_grades = $conn->prepare($sql_grades_summary);
        $stmt_grades->execute([':student_id' => $student_id, ':student_group_id' => $student_group_id]);
        $subject_summary = $stmt_grades->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("DB Error on student_grades.php (Student ID: {$student_id}): " . $e->getMessage());
    $db_error_message = "Произошла ошибка базы данных при загрузке итоговых оценок.";
} finally {
    $conn = null;
}

// Флеш-сообщение
$page_flash_message = null;
if (isset($_SESSION['message_flash'])) { 
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

$page_title = "Мои Оценки - Сводка";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'student-grades-page app-page';
$load_notifications_css = true;
$load_stud_css = true; 

ob_start();
?>

<div class="container py-4">
    <div class="page-header mb-4">
        <h1 class="h2"><i class="fas fa-clipboard-check me-2"></i>Мои Оценки (Сводка по дисциплинам)</h1>
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
    <?php if (!$student_group_id && empty($db_error_message)): ?>
    <?php elseif (empty($subject_summary) && empty($db_error_message)): ?>
        <div class="card text-center p-4">
            <p class="mb-0 text-muted">Пока нет данных об успеваемости по дисциплинам вашей группы.</p>
        </div>
    <?php elseif (!empty($subject_summary)): ?>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered grades-summary-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Дисциплина</th>
                                <th scope="col" class="text-center" title="Сумма баллов за засчитанные работы">Ваш балл</th>
                                <th scope="col" class="text-center" title="Минимальный балл для зачета/сдачи">Проходной</th>
                                <th scope="col" class="text-center">Оценка (2-5)</th>
                                <th scope="col" class="text-center">Статус</th>
                                <th scope="col" class="text-center" title="Дата итоговой аттестации">Дата ИА</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subject_summary as $summary): ?>
                                <?php
                                    $current_grade_val = (float)($summary['current_total_grade'] ?? 0);
                                    $min_passing_val = isset($summary['min_passing_grade']) ? (float)$summary['min_passing_grade'] : null;
                                    $final_date_str = $summary['final_assessment_date'];
                                    $final_assessment_date_obj = null;
                                    if ($final_date_str) {
                                        try { $final_assessment_date_obj = new DateTimeImmutable($final_date_str); }
                                        catch (Exception $e) {  }
                                    }
                                    $now_dt = new DateTimeImmutable();

                                    $final_mark_text = '-';
                                    $final_mark_class = '';
                                    $pass_status_html = '<span class="text-muted">Нет данных</span>';

                                    if ($min_passing_val !== null) {
                                        if ($current_grade_val >= 91) { $final_mark_text = '5'; $final_mark_class = 'grade-excellent'; }
                                        elseif ($current_grade_val >= 71) { $final_mark_text = '4'; $final_mark_class = 'grade-good'; } 
                                        elseif ($current_grade_val >= $min_passing_val && $current_grade_val >= 51) { $final_mark_text = '3'; $final_mark_class = 'grade-satisfactory'; } 
                                        elseif ($final_assessment_date_obj && $now_dt > $final_assessment_date_obj) {
                                            $final_mark_text = '2'; $final_mark_class = 'grade-fail';
                                        }
                                        if ($current_grade_val >= $min_passing_val) {
                                            $pass_status_html = '<span class="badge bg-success">Зачтено</span>';
                                        } elseif ($final_assessment_date_obj && $now_dt > $final_assessment_date_obj) {
                                            $pass_status_html = '<span class="badge bg-danger">Долг</span>';
                                        } else {
                                            $pass_status_html = '<span class="badge bg-warning text-dark">Незачет</span>';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td class="align-middle"><?php echo htmlspecialchars($summary['subject_name'] ?? 'Неизвестная дисциплина'); ?></td>
                                    <td class="text-center align-middle fw-bold"><?php echo htmlspecialchars(number_format($current_grade_val, 1)); ?></td>
                                    <td class="text-center align-middle"><?php echo $min_passing_val !== null ? htmlspecialchars(number_format($min_passing_val, 0)) : '-'; ?></td>
                                    <td class="text-center align-middle <?php echo $final_mark_class; ?>">
                                        <span class="badge fs-6 <?php
                                            if ($final_mark_text === '5') echo 'bg-success';
                                            elseif ($final_mark_text === '4') echo 'bg-primary';
                                            elseif ($final_mark_text === '3') echo 'bg-warning text-dark';
                                            elseif ($final_mark_text === '2') echo 'bg-danger';
                                            else echo 'bg-light text-dark';
                                        ?>"><?php echo $final_mark_text; ?></span>
                                    </td>
                                    <td class="text-center align-middle"><?php echo $pass_status_html; ?></td>
                                    <td class="text-center align-middle">
                                        <?php echo $final_assessment_date_obj ? $final_assessment_date_obj->format('d.m.Y') : '<span class="text-muted">Не уст.</span>'; ?>
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