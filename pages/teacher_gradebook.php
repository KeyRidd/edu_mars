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

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. Эта страница только для преподавателей.'];
    $redirect_url_auth = is_logged_in() ? BASE_URL . 'pages/dashboard.php' : BASE_URL . 'pages/login.php';
    header('Location: ' . $redirect_url_auth);
    exit();
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Преподаватель';

$errors = [];
$page_flash_message = null;

$selected_group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$selected_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

$teacher_groups = [];       // Группы, назначенные преподавателю
$subjects_in_selected_group = []; // Предметы в выбранной группе, которые ведет преподаватель
$students_in_selected_group = []; // Студенты в выбранной группе
$lesson_assignments_headers = [];   // Структура для заголовков таблицы (занятия и определения заданий)
$student_grades_data = [];      // Данные по оценкам студентов 

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

$conn = null;
try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Загрузка списка групп, назначенных преподавателю
    $sql_teacher_groups = "SELECT DISTINCT g.id, g.name
                           FROM groups g
                           JOIN teaching_assignments ta ON g.id = ta.group_id
                           WHERE ta.teacher_id = :teacher_id
                           ORDER BY g.name ASC";
    $stmt_teacher_groups = $conn->prepare($sql_teacher_groups);
    $stmt_teacher_groups->execute([':teacher_id' => $teacher_id]);
    $teacher_groups = $stmt_teacher_groups->fetchAll(PDO::FETCH_ASSOC);

    // Если группа выбрана, загружаем предметы для этой группы, которые ведет преподаватель
    if ($selected_group_id > 0) {
        $sql_subjects_in_group = "SELECT DISTINCT s.id, s.name
                                  FROM subjects s
                                  JOIN teaching_assignments ta ON s.id = ta.subject_id
                                  WHERE ta.teacher_id = :teacher_id AND ta.group_id = :group_id
                                  ORDER BY s.name ASC";
        $stmt_subjects_in_group = $conn->prepare($sql_subjects_in_group);
        $stmt_subjects_in_group->execute([':teacher_id' => $teacher_id, ':group_id' => $selected_group_id]);
        $subjects_in_selected_group = $stmt_subjects_in_group->fetchAll(PDO::FETCH_ASSOC);
    }

    // Если выбраны группа И предмет, загружаем данные для ведомости
    if ($selected_group_id > 0 && $selected_subject_id > 0) {
        // Получить список студентов выбранной группы
        $sql_students = "SELECT id, full_name FROM users WHERE role = 'student' AND group_id = :group_id ORDER BY full_name ASC";
        $stmt_students = $conn->prepare($sql_students);
        $stmt_students->execute([':group_id' => $selected_group_id]);
        $students_in_selected_group = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
        $sql_lessons_defs = "SELECT l.id as lesson_id, l.lesson_date, l.lesson_type,
                                    ad.id as ad_id, ad.title as ad_title, ad.max_grade as ad_max_grade
                             FROM lessons l
                             JOIN assignment_definitions ad ON l.id = ad.lesson_id
                             WHERE l.group_id = :group_id AND l.subject_id = :subject_id
                             ORDER BY l.lesson_date ASC, ad.created_at ASC";
        $stmt_lessons_defs = $conn->prepare($sql_lessons_defs);
        $stmt_lessons_defs->execute([':group_id' => $selected_group_id, ':subject_id' => $selected_subject_id]);
        while ($row = $stmt_lessons_defs->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($lesson_assignments_headers[$row['lesson_id']])) {
                $lesson_assignments_headers[$row['lesson_id']] = [
                    'lesson_date' => $row['lesson_date'],
                    'lesson_type' => $row['lesson_type'], 
                    'definitions' => []
                ];
            }
            $lesson_assignments_headers[$row['lesson_id']]['definitions'][] = [
                'ad_id' => $row['ad_id'],
                'ad_title' => $row['ad_title'],
                'ad_max_grade' => $row['ad_max_grade']
            ];
        }

                // Получить все сданные работы студентов этой группы по определениям заданий для выбранного предмета
        if (!empty($students_in_selected_group) && !empty($lesson_assignments_headers)) {
            $assignment_definition_ids = [];
            foreach ($lesson_assignments_headers as $lesson_info) {
                foreach ($lesson_info['definitions'] as $def) {
                    $assignment_definition_ids[] = $def['ad_id'];
                }
            }
            if (!empty($assignment_definition_ids)) {
                $placeholders_for_ad_ids = implode(',', array_fill(0, count($assignment_definition_ids), '?'));
                $sql_grades = "SELECT student_id, assignment_definition_id, grade, status
                               FROM assignments
                               WHERE student_id IN (SELECT id FROM users WHERE role = 'student' AND group_id = ?) 
                                 AND assignment_definition_id IN ($placeholders_for_ad_ids)"; 

                // Собираем параметры в правильном порядке для позиционных плейсхолдеров
                $params_for_grades = [];
                $params_for_grades[] = $selected_group_id; 
                foreach ($assignment_definition_ids as $ad_id) {
                    $params_for_grades[] = $ad_id; 
                }
                $stmt_grades = $conn->prepare($sql_grades);
                $stmt_grades->execute($params_for_grades); 
                while ($grade_row = $stmt_grades->fetch(PDO::FETCH_ASSOC)) {
                    $student_grades_data[$grade_row['student_id']][$grade_row['assignment_definition_id']] = [
                        'grade' => $grade_row['grade'],
                        'status' => $grade_row['status']
                    ];
                }
            }
        }
    }
    // Логика экспорта в CSV
    if (isset($_GET['export']) && $_GET['export'] === 'csv' && $selected_group_id > 0 && $selected_subject_id > 0 && !empty($students_in_selected_group) && !empty($lesson_assignments_headers)) {
        
        $group_name_for_file = 'group_' . $selected_group_id;
        $subject_name_for_file = 'subject_' . $selected_subject_id;
        $stmt_file_names = $conn->prepare("SELECT g.name as group_name, s.name as subject_name FROM groups g, subjects s WHERE g.id = :gid AND s.id = :sid");
        $stmt_file_names->execute([':gid' => $selected_group_id, ':sid' => $selected_subject_id]);
        $file_names_data = $stmt_file_names->fetch(PDO::FETCH_ASSOC);
        if ($file_names_data) {
            $group_name_for_file = preg_replace('/[^a-z0-9_]/i', '_', $file_names_data['group_name']);
            $subject_name_for_file = preg_replace('/[^a-z0-9_]/i', '_', $file_names_data['subject_name']);
        }
        $filename = "gradebook_" . $group_name_for_file . "_" . $subject_name_for_file . "_" . date('Ymd') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        $header_row1 = ['ФИО Студента'];
        $header_row2 = [''];
        $header_row3 = [''];
        foreach ($lesson_assignments_headers as $lesson_info) {
            $num_defs_in_lesson = count($lesson_info['definitions']);
            $header_row1[] = format_ru_datetime($lesson_info['lesson_date'], true, false); 
            for ($i = 1; $i < $num_defs_in_lesson; $i++) { $header_row1[] = ''; } 
            foreach ($lesson_info['definitions'] as $def) {
                $header_row2[] = $def['ad_title']; 
                $header_row3[] = get_lesson_type_text_short($lesson_info['lesson_type']) . ($def['ad_max_grade'] ? ' (макс. '.$def['ad_max_grade'].')' : '');
            }
        }
        fputcsv($output, $header_row1, ';');
        fputcsv($output, $header_row2, ';');
        fputcsv($output, $header_row3, ';');

        // Данные студентов
        foreach ($students_in_selected_group as $student) {
            $student_row_data = [htmlspecialchars_decode($student['full_name'])]; 
            foreach ($lesson_assignments_headers as $lesson_info) {
                foreach ($lesson_info['definitions'] as $def) {
                    $cell_value = '';
                    if (isset($student_grades_data[$student['id']][$def['ad_id']])) {
                        $grade_info = $student_grades_data[$student['id']][$def['ad_id']];
                        if ($grade_info['grade'] !== null) {
                            $cell_value = $grade_info['grade'];
                        } elseif ($grade_info['status'] === 'submitted') {
                            $cell_value = 'на проверке';
                        }
                    }
                    $student_row_data[] = $cell_value;
                }
            }
            fputcsv($output, $student_row_data, ';');
        }
        fclose($output);
        exit;
    }
} catch (PDOException $e) {
    error_log("Database Error on teacher_gradebook.php for teacher ID {$teacher_id}: " . $e->getMessage());
    $errors[] = "Произошла ошибка базы данных при загрузке данных: " . htmlspecialchars($e->getMessage());
} finally {
    if ($conn) { $conn = null; }
}

$page_title = "Ведомости успеваемости";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'teacher-page gradebook-page app-page';
$load_notifications_css = true;
$load_teach_css = true; 
ob_start();
?>
<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-book-reader me-2"></i>Ведомости успеваемости</h1>
        <?php if ($selected_group_id > 0 && $selected_subject_id > 0 && !empty($students_in_selected_group) && !empty($lesson_assignments_headers)): ?>
            <a href="?group_id=<?php echo $selected_group_id; ?>&subject_id=<?php echo $selected_subject_id; ?>&export=csv" class="btn btn-success btn-sm mt-2 mt-md-0">
                <i class="fas fa-download me-1"></i> Экспортировать
            </a>
        <?php endif; ?>
    </div>
    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_teacher.php">Главная</a></li>
            <li class="breadcrumb-item active" aria-current="page">Ведомости</li>
        </ol>
    </nav>
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
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label for="group_id_select" class="form-label">Выберите группу:</label>
                    <select name="group_id" id="group_id_select" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Все группы --</option>
                        <?php foreach ($teacher_groups as $group_item): ?>
                            <option value="<?php echo $group_item['id']; ?>" <?php echo ($selected_group_id === $group_item['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($group_item['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($selected_group_id > 0 && !empty($subjects_in_selected_group)): ?>
                <div class="col-md-5">
                    <label for="subject_id_select" class="form-label">Выберите дисциплину:</label>
                    <select name="subject_id" id="subject_id_select" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Все дисциплины --</option>
                        <?php foreach ($subjects_in_selected_group as $subject_item): ?>
                            <option value="<?php echo $subject_item['id']; ?>" <?php echo ($selected_subject_id === $subject_item['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject_item['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php elseif ($selected_group_id > 0 && empty($subjects_in_selected_group)): ?>
                <div class="col-md-5">
                    <label class="form-label text-muted">Дисциплины:</label>
                    <p class="form-control-plaintext text-muted">Для выбранной группы нет назначенных вам дисциплин.</p>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                </div>
            </form>
        </div>
    </div>
    <?php if ($selected_group_id > 0 && $selected_subject_id > 0): ?>
        <?php if (empty($students_in_selected_group)): ?>
            <div class="alert alert-info">В выбранной группе нет студентов.</div>
        <?php elseif (empty($lesson_assignments_headers)): ?>
            <div class="alert alert-info">Для выбранной дисциплины в этой группе нет определенных заданий.</div>
        <?php else: ?>
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">
                        Ведомость по группе: <?php echo htmlspecialchars(array_column($teacher_groups, 'name', 'id')[$selected_group_id] ?? ''); ?> /
                        Дисциплина: <?php echo htmlspecialchars(array_column($subjects_in_selected_group, 'name', 'id')[$selected_subject_id] ?? ''); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm text-center small gradebook-table">
                            <thead class="table-light">
                                <tr>
                                    <th rowspan="3" class="align-middle student-name-col">ФИО Студента</th>
                                    <?php foreach ($lesson_assignments_headers as $lesson_info): ?>
                                        <?php $colspan_count = count($lesson_info['definitions']); ?>
                                        <th colspan="<?php echo $colspan_count; ?>" class="lesson-date-header">
                                            <?php echo htmlspecialchars(format_ru_datetime($lesson_info['lesson_date'], true, false)); ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                                <tr>
                                    <?php foreach ($lesson_assignments_headers as $lesson_info): ?>
                                        <?php foreach ($lesson_info['definitions'] as $def): ?>
                                            <th class="assignment-title-header" title="<?php echo htmlspecialchars($def['ad_title']); ?>">
                                                <?php echo htmlspecialchars(truncate_text($def['ad_title'], 25)); ?>
                                            </th>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tr>
                                <tr>
                                    <?php foreach ($lesson_assignments_headers as $lesson_info): ?>
                                        <?php foreach ($lesson_info['definitions'] as $def): ?>
                                            <th class="lesson-type-header">
                                                <?php echo htmlspecialchars(get_lesson_type_text_short($lesson_info['lesson_type'])); ?>
                                                <?php echo $def['ad_max_grade'] ? '<br><small>(макс. ' . (int)$def['ad_max_grade'] . ')</small>' : ''; ?>
                                            </th>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students_in_selected_group as $student): ?>
                                    <tr>
                                        <td class="text-start student-name-cell"><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <?php foreach ($lesson_assignments_headers as $lesson_info): ?>
                                            <?php foreach ($lesson_info['definitions'] as $def): ?>
                                                <td class="grade-cell">
                                                    <?php
                                                    $cell_output = '';
                                                    if (isset($student_grades_data[$student['id']][$def['ad_id']])) {
                                                        $grade_data = $student_grades_data[$student['id']][$def['ad_id']];
                                                        if ($grade_data['grade'] !== null) {
                                                            $cell_output = htmlspecialchars((string)$grade_data['grade']);
                                                        } elseif ($grade_data['status'] === 'submitted') {
                                                            $cell_output = '<span class="badge bg-warning text-dark">На проверке</span>';
                                                        }
                                                    }
                                                    echo $cell_output;
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php elseif ($selected_group_id > 0 && $selected_subject_id == 0): ?>
        <div class="alert alert-info mt-4">Пожалуйста, выберите дисциплину для отображения ведомости.</div>
    <?php elseif ($selected_group_id == 0): ?>
         <div class="alert alert-info mt-4">Пожалуйста, выберите группу для просмотра ведомости.</div>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>
<style>