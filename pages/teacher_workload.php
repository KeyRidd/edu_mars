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
    $redirect_url = is_logged_in() ? BASE_URL . 'pages/dashboard.php' : BASE_URL . 'pages/login.php';
    if (($_SESSION['role'] ?? null) === 'student') $redirect_url = BASE_URL . 'pages/home_student.php';
    if (($_SESSION['role'] ?? null) === 'admin')   $redirect_url = BASE_URL . 'pages/home_admin.php';
    header('Location: ' . $redirect_url);
    exit();
}

$teacher_id = $_SESSION['user_id'];
$teacher_groups = [];
$teacher_subjects_in_group = [];
$lessons_for_workload = [];
$workload_summary = ['lecture_hours' => 0, 'practice_hours' => 0, 'assessment_hours' => 0, 'lecture_count' => 0, 'practice_count' => 0, 'assessment_count' => 0, 'total_hours' => 0, 'total_lessons' => 0];
$scheduled_consultations_for_view = [];
$consultations_view_title = "Все мои предстоящие консультации";
$selected_group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;
$selected_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : null;
$current_group_name_for_title = null;
$current_subject_name_for_title = null;
$db_error_message = '';
$page_flash_message = null;

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

try {
    $conn = getDbConnection();

    // Получить группы, которые ведет преподаватель
    $sql_groups = "SELECT DISTINCT g.id, g.name
                   FROM groups g
                   JOIN teaching_assignments ta ON g.id = ta.group_id
                   WHERE ta.teacher_id = ?
                   ORDER BY g.name ASC";
    $stmt_groups = $conn->prepare($sql_groups);
    $stmt_groups->execute([$teacher_id]);
    $teacher_groups = $stmt_groups->fetchAll(PDO::FETCH_ASSOC);

    if ($selected_group_id) {
        foreach ($teacher_groups as $group_item) {
            if ($group_item['id'] === $selected_group_id) {
                $current_group_name_for_title = $group_item['name'];
                break;
            }
        }
    }

    // Если группа выбрана, получить предметы для этой группы
    if ($selected_group_id) {
        $sql_subjects = "SELECT DISTINCT s.id, s.name
                         FROM subjects s
                         JOIN teaching_assignments ta ON s.id = ta.subject_id
                         WHERE ta.teacher_id = ? AND ta.group_id = ?
                         ORDER BY s.name ASC";
        $stmt_subjects = $conn->prepare($sql_subjects);
        $stmt_subjects->execute([$teacher_id, $selected_group_id]);
        $teacher_subjects_in_group = $stmt_subjects->fetchAll(PDO::FETCH_ASSOC);

        if ($selected_subject_id) {
            foreach ($teacher_subjects_in_group as $subject_item) {
                if ($subject_item['id'] === $selected_subject_id) {
                    $current_subject_name_for_title = $subject_item['name'];
                    break;
                }
            }
        }
    }

    // Если выбраны группа и предмет, получить уроки и рассчитать нагрузку
    if ($selected_group_id && $selected_subject_id) {
        $sql_lessons = "SELECT id, title, description, lesson_date, lesson_type, duration_minutes
                        FROM lessons
                        WHERE group_id = ? AND subject_id = ?
                        ORDER BY lesson_date DESC";
        $stmt_lessons = $conn->prepare($sql_lessons);
        $stmt_lessons->execute([$selected_group_id, $selected_subject_id]);
        $lessons_for_workload = $stmt_lessons->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lessons_for_workload as $lesson) {
            $duration_hours = ($lesson['duration_minutes'] ?? 0) / 60;
            $workload_summary['total_lessons']++;
            $workload_summary['total_hours'] += $duration_hours;
            switch ($lesson['lesson_type']) {
                case 'lecture': $workload_summary['lecture_count']++; $workload_summary['lecture_hours'] += $duration_hours; break;
                case 'practice': $workload_summary['practice_count']++; $workload_summary['practice_hours'] += $duration_hours; break;
                case 'assessment': $workload_summary['assessment_count']++; $workload_summary['assessment_hours'] += $duration_hours; break;
            }
        }
    }

    // Получить предстоящие запланированные консультации
    $sql_base_consultations = "
        SELECT 
            cr.id as consultation_id, cr.scheduled_datetime_start, cr.scheduled_datetime_end,
            cr.consultation_location_or_link, u_student.full_name as student_name,
            s.id as subject_id_consult, s.name as subject_name_consult,
            g_student.id as student_group_id_consult, g_student.name as student_group_name_consult
        FROM consultation_requests cr
        JOIN users u_student ON cr.student_id = u_student.id
        JOIN subjects s ON cr.subject_id = s.id
        LEFT JOIN groups g_student ON u_student.group_id = g_student.id
        WHERE cr.teacher_id = :teacher_id 
          AND cr.status = 'scheduled_confirmed'
          AND cr.scheduled_datetime_start >= NOW() 
    ";
    $sql_conditions_consult = [];
    $sql_params_consult = [':teacher_id' => $teacher_id];
    if ($selected_group_id) {
        $sql_conditions_consult[] = "u_student.group_id = :group_id_consult_filter";
        $sql_params_consult[':group_id_consult_filter'] = $selected_group_id;
        $consultations_view_title = "Предстоящие консультации для группы \"" . htmlspecialchars($current_group_name_for_title ?? 'Выбранная группа') . "\"";
        if ($selected_subject_id) {
            $sql_conditions_consult[] = "cr.subject_id = :subject_id_consult_filter";
            $sql_params_consult[':subject_id_consult_filter'] = $selected_subject_id;
            $consultations_view_title .= " по дисциплине \"" . htmlspecialchars($current_subject_name_for_title ?? 'Выбранная дисциплина') . "\"";
        }
    }
    if (!empty($sql_conditions_consult)) {
        $sql_base_consultations .= " AND " . implode(" AND ", $sql_conditions_consult);
    }

    $sql_base_consultations .= " ORDER BY cr.scheduled_datetime_start ASC";

    $stmt_consultations = $conn->prepare($sql_base_consultations);
    $stmt_consultations->execute($sql_params_consult);
    $scheduled_consultations_for_view = $stmt_consultations->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database Error in teacher_workload.php: " . $e->getMessage());
    $db_error_message = "Произошла ошибка базы данных при загрузке данных нагрузки.";
} finally {
    $conn = null;
}
$page_title = "Моя Учебная Нагрузка";
if ($current_group_name_for_title) $page_title .= " - Группа: " . htmlspecialchars($current_group_name_for_title);
if ($current_subject_name_for_title) $page_title .= " (Дисциплина: " . htmlspecialchars($current_subject_name_for_title) . ")";

$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'teacher-workload-page app-page dashboard-page';
$load_notifications_css = true;
$load_teach_css = true;
$load_dashboard_styles_css = true;
$page_specific_js = '
    <script>
        const teacherWorkloadConfig = {
            apiUpdateLessonRowUrl: ' . json_encode(BASE_URL . 'api/update_lesson_row.php') . '
        };
    </script>
    <script src="' . BASE_URL . 'assets/js/teacher_workload_inline_edit.js?v=' . time() . '" defer></script>
';

ob_start();
?>
<div class="container py-4">
    <div class="page-header mb-4">
        <h1 class="h2"><i class="fas fa-briefcase me-2"></i>Моя Учебная Нагрузка</h1>
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
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="<?php echo BASE_URL; ?>pages/teacher_workload.php" class="row gx-3 gy-2 align-items-end">
                <div class="col-md-5">
                    <label for="group_id_filter" class="form-label">Группа:</label>
                    <select name="group_id" id="group_id_filter" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Все мои группы --</option>
                        <?php foreach ($teacher_groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>" <?php echo ($selected_group_id === (int)$group['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($group['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label for="subject_id_filter" class="form-label">Дисциплина:</label>
                    <select name="subject_id" id="subject_id_filter" class="form-select" onchange="this.form.submit()" <?php echo !$selected_group_id ? 'disabled' : ''; ?>>
                        <option value="">-- Все дисциплины в группе --</option>
                        <?php if ($selected_group_id && !empty($teacher_subjects_in_group)): ?>
                            <?php foreach ($teacher_subjects_in_group as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>" <?php echo ($selected_subject_id === (int)$subject['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php elseif ($selected_group_id): ?>
                            <option value="">Нет дисциплин для этой группы</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <a href="<?php echo BASE_URL; ?>pages/teacher_workload.php" class="btn btn-outline-secondary w-100">Сбросить</a>
                </div>
            </form>
        </div>
    </div>
    <?php if ($selected_group_id && $selected_subject_id): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h2 class="h5 mb-0">Нагрузка по дисциплинам "<?php echo htmlspecialchars($current_subject_name_for_title ?? ''); ?>" для группы "<?php echo htmlspecialchars($current_group_name_for_title ?? ''); ?>"</h2>
            </div>
            <div class="card-body">
                <ul class="list-unstyled">
                    <li>Всего занятий: <strong><?php echo $workload_summary['total_lessons']; ?></strong></li>
                    <li>Всего часов: <strong><?php echo htmlspecialchars(format_hours($workload_summary['total_hours'])); ?></strong></li>
                    <li>Лекции: <strong><?php echo $workload_summary['lecture_count']; ?></strong> (<?php echo htmlspecialchars(format_hours($workload_summary['lecture_hours'])); ?> ч.)</li>
                    <li>Практики/Семинары: <strong><?php echo $workload_summary['practice_count']; ?></strong> (<?php echo htmlspecialchars(format_hours($workload_summary['practice_hours'])); ?> ч.)</li>
                    <li>Аттестации: <strong><?php echo $workload_summary['assessment_count']; ?></strong> (<?php echo htmlspecialchars(format_hours($workload_summary['assessment_hours'])); ?> ч.)</li>
                </ul>
            </div>
            <?php if (!empty($lessons_for_workload)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Название/Тема</th>
                                <th scope="col">Тип</th>
                                <th scope="col">Дата</th>
                                <th scope="col">Время</th>
                                <th scope="col" class="text-center">Продолж. (мин)</th>
                                <th scope="col" class="text-center">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lessons_for_workload as $lesson): ?>
                            <?php
                                $lesson_start_datetime = null;
                                if (!empty($lesson['lesson_date'])) {
                                    try {
                                        $lesson_start_datetime = new DateTime($lesson['lesson_date']);
                                    } catch (Exception $e) {
                                        error_log("Error parsing lesson_date '{$lesson['lesson_date']}' for lesson ID {$lesson['id']}: " . $e->getMessage());
                                    }
                                }
                                $lesson_end_datetime_str = '-';
                                if ($lesson_start_datetime && isset($lesson['duration_minutes']) && is_numeric($lesson['duration_minutes'])) {
                                    $lesson_end_datetime = clone $lesson_start_datetime;
                                    $lesson_end_datetime->add(new DateInterval('PT' . (int)$lesson['duration_minutes'] . 'M'));
                                    $lesson_end_datetime_str = $lesson_end_datetime->format('H:i');
                                }
                                $lesson_date_str = $lesson_start_datetime ? $lesson_start_datetime->format('d.m.Y') : '-';
                                $lesson_start_time_str = $lesson_start_datetime ? $lesson_start_datetime->format('H:i') : '-';
                            ?>
                            <tr data-lesson-row-id="<?php echo $lesson['id']; ?>">
                                <td class="lesson-title-cell"> 
                                    <span class="lesson-title-text"><?php echo htmlspecialchars($lesson['title']); ?></span>
                                </td>
                                <td>
                                    <?php
                                    switch ($lesson['lesson_type']) {
                                        case 'lecture': echo 'Лекция'; break;
                                        case 'practice': echo 'Практика'; break;
                                        case 'assessment': echo 'Аттестация'; break;
                                        default: echo htmlspecialchars($lesson['lesson_type'] ?? 'Не указан');
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($lesson_date_str); ?></td>
                                <td><?php echo htmlspecialchars($lesson_start_time_str . ' - ' . $lesson_end_datetime_str); ?></td>
                                <td><?php echo htmlspecialchars((string)($lesson['duration_minutes'] ?? '-')); ?> мин.</td>
                                <td class="lesson-description-cell">
                                    <span class="lesson-description-text">
                                        <?php echo nl2br(htmlspecialchars(truncate_text($lesson['description'] ?? '', 100))); ?>
                                    </span>
                                    <div class="full-description" style="display:none;"><?php echo htmlspecialchars($lesson['description'] ?? ''); ?></div>
                                </td>
                                <td class="text-center lesson-actions-cell"> 
                                    <a href="<?php echo BASE_URL . 'pages/lesson.php?id=' . $lesson['id']; ?>" 
                                    class="btn btn-sm btn-info me-1 action-view" title="Просмотр занятия">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-warning action-edit-inline" 
                                            data-lesson-id="<?php echo $lesson['id']; ?>" title="Редактировать тему/описание">
                                        <i class="fas fa-pencil-alt"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="card-body text-muted text-center">По выбранной дисциплине и группе занятий пока не найдено.</div>
            <?php endif; ?>
        </div>
    <?php elseif ($selected_group_id && !$selected_subject_id): ?>
        <div class="alert alert-info">Пожалуйста, выберите дисциплину для отображения детальной нагрузки.</div>
    <?php elseif (empty($teacher_groups) && empty($db_error_message)): ?>
         <div class="card card-body text-center text-muted py-5">
            <p class="mb-0 fs-5"><i class="fas fa-info-circle fa-2x mb-3 d-block"></i>Вы пока не назначены ни на одну группу.</p>
        </div>
    <?php elseif (empty($db_error_message)):?>
    <?php endif; ?>
    <hr class="my-4">
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="h5 mb-0"><?php echo htmlspecialchars($consultations_view_title); ?></h3>
        </div>
        <div class="card-body">
            <?php if (!empty($scheduled_consultations_for_view)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Дата</th>
                                <th scope="col">Время</th>
                                <th scope="col">Студент</th>
                                <th scope="col">Группа студента</th>
                                <th scope="col">Дисциплина</th>
                                <th scope="col">Место/Ссылка</th>
                                <th scope="col" class="text-center">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scheduled_consultations_for_view as $consultation): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(format_ru_date($consultation['scheduled_datetime_start'])); ?></td>
                                    <td class="text-nowrap">
                                        <?php echo htmlspecialchars(format_ru_time($consultation['scheduled_datetime_start'])); ?>
                                        -
                                        <?php echo htmlspecialchars(format_ru_time($consultation['scheduled_datetime_end'])); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($consultation['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($consultation['student_group_name_consult'] ?? 'Не указана'); ?></td>
                                    <td><?php echo htmlspecialchars($consultation['subject_name_consult']); ?></td>
                                    <td>
                                        <small title="<?php echo htmlspecialchars($consultation['consultation_location_or_link'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(truncate_text($consultation['consultation_location_or_link'] ?? '-', 30)); ?>
                                        </small>
                                    </td>
                                    <td class="text-center">
                                        <a href="<?php echo BASE_URL . 'pages/teacher_consultations.php#request-sch-' . $consultation['consultation_id']; ?>"
                                           class="btn btn-xs btn-outline-primary" title="Управлять консультацией">
                                           <i class="fas fa-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted text-center py-3">
                    <em>
                        <?php
                        if ($selected_group_id && $selected_subject_id) {
                            echo "Нет запланированных консультаций по выбранной дисциплине для студентов этой группы.";
                        } elseif ($selected_group_id) {
                            echo "Нет запланированных консультаций для студентов выбранной группы.";
                        } else {
                            echo "У вас пока нет запланированных консультаций.";
                        }
                        ?>
                    </em>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>