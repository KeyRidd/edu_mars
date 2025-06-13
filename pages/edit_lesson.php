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

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. У вас нет прав администратора.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? 0;
$errors = [];
$page_flash_message = null; // Для флеш-сообщений из сессии
$lesson_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$lesson_data_for_form = null; // Данные урока для предзаполнения формы
$group_id_from_lesson = 0;
$subjects_in_group = [];

if ($lesson_id <= 0) {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Некорректный ID занятия.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit;
}

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}
// Старый механизм сообщений
if (isset($_SESSION['message'])) {
    if (!$page_flash_message && !empty($_SESSION['message']['text'])) {
         $page_flash_message = $_SESSION['message'];
    }
    unset($_SESSION['message']);
}

$conn = null;
try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Загрузка данных урока, если форма еще не была отправлена с ошибками
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($errors)) { 
        $sql_lesson_details = "SELECT l.id, l.title, l.description, l.lesson_date, l.group_id, l.subject_id, 
                                      l.lesson_type, l.duration_minutes, g.name as group_name
                               FROM lessons l
                               JOIN groups g ON l.group_id = g.id
                               WHERE l.id = ?";
        $stmt_lesson_details = $conn->prepare($sql_lesson_details);
        $stmt_lesson_details->execute([$lesson_id]);
        $lesson_data_for_form = $stmt_lesson_details->fetch(PDO::FETCH_ASSOC);
        $stmt_lesson_details = null;

        if (!$lesson_data_for_form) {
            $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Занятие не найдено.'];
            header('Location: ' . BASE_URL . 'pages/dashboard.php');
            exit;
        }
    }

    $group_id_from_lesson = (int)($lesson_data_for_form['group_id'] ?? 0); 

    // Получение предметов, доступных для группы урока
    if ($group_id_from_lesson > 0) {
        $sql_group_subjects = "SELECT DISTINCT s.id, s.name FROM subjects s
                               JOIN teaching_assignments ta ON s.id = ta.subject_id
                               WHERE ta.group_id = ? ORDER BY s.name ASC";
        $stmt_group_subjects = $conn->prepare($sql_group_subjects);
        $stmt_group_subjects->execute([$group_id_from_lesson]);
        $subjects_in_group = $stmt_group_subjects->fetchAll(PDO::FETCH_ASSOC);
        $stmt_group_subjects = null;
    }

    // Обработка POST-запроса на обновление
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action_from_post = $_POST['action'] ?? null;

        if ($action_from_post === 'update_lesson' || $action_from_post === null) { 
            // Заполняем $lesson_data_for_form данными из POST для предзаполнения и валидации
            $lesson_data_for_form['title'] = trim($_POST['title'] ?? '');
            $lesson_data_for_form['description'] = trim($_POST['description'] ?? '');
            $lesson_data_for_form['lesson_date'] = trim($_POST['lesson_date'] ?? ''); 
            $lesson_data_for_form['subject_id'] = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
            $lesson_data_for_form['lesson_type'] = trim($_POST['lesson_type'] ?? '');
            $lesson_data_for_form['duration_minutes'] = isset($_POST['duration_minutes']) ? (int)$_POST['duration_minutes'] : 0;

            $lesson_date_for_sql = null; // Для преобразования в SQL формат

            // Валидация
            if (empty($lesson_data_for_form['title'])) { $errors[] = 'Название занятия обязательно.'; }
            if (empty($lesson_data_for_form['lesson_date'])) { $errors[] = 'Дата и время занятия обязательны.'; }
            else {
                $dt_obj = DateTime::createFromFormat('Y-m-d\TH:i', $lesson_data_for_form['lesson_date']);
                if (!$dt_obj || $dt_obj->format('Y-m-d\TH:i') !== $lesson_data_for_form['lesson_date']) {
                    $errors[] = 'Некорректный формат даты занятия.';
                } else { $lesson_date_for_sql = $dt_obj->format('Y-m-d H:i:s'); } // Формат для SQL
            }
            if ($lesson_data_for_form['subject_id'] <= 0) { $errors[] = 'Необходимо выбрать дисциплину.'; }
            else {
                $is_subject_valid_post = false;
                foreach ($subjects_in_group as $subj) { if ($subj['id'] === $lesson_data_for_form['subject_id']) { $is_subject_valid_post = true; break; } }
                if (!$is_subject_valid_post) { $errors[] = 'Выбрана недопустимая дисциплина для этой группы.'; }
            }
            $allowed_lesson_types_post = ['lecture', 'practice', 'assessment', 'other'];
            if (empty($lesson_data_for_form['lesson_type'])) { $errors[] = 'Тип занятия обязателен.'; }
            elseif (!in_array($lesson_data_for_form['lesson_type'], $allowed_lesson_types_post)) { $errors[] = 'Недопустимый тип занятия.';}
            if ($lesson_data_for_form['duration_minutes'] <= 0) { $errors[] = 'Продолжительность должна быть > 0.'; }
            elseif ($lesson_data_for_form['duration_minutes'] < 15 || $lesson_data_for_form['duration_minutes'] > 480) { $errors[] = 'Продолжительность (15-480 мин).';}

            if (empty($errors)) {
                try {
                    $sql_update_query = "UPDATE lessons SET title = ?, description = ?, lesson_date = ?, subject_id = ?,
                                           lesson_type = ?::lesson_type_enum, duration_minutes = ?
                                       WHERE id = ?";
                    $stmt_update_query = $conn->prepare($sql_update_query);
                    if ($stmt_update_query->execute([
                        $lesson_data_for_form['title'], $lesson_data_for_form['description'], $lesson_date_for_sql,
                        $lesson_data_for_form['subject_id'], $lesson_data_for_form['lesson_type'],
                        $lesson_data_for_form['duration_minutes'], $lesson_id
                    ])) {
                        $updated_rows = $stmt_update_query->rowCount();
                        $_SESSION['message_flash'] = ['type' => $updated_rows > 0 ? 'success' : 'info',
                                                 'text' => $updated_rows > 0 ? 'Параметры занятия успешно обновлены!' : 'Изменений не было внесено.'];
                        header('Location: ' . BASE_URL . 'pages/lesson.php?id=' . $lesson_id); 
                        exit();
                    } else {
                         $errors[] = 'Не удалось обновить параметры занятия. Ошибка базы данных при сохранении.';
                         error_log("Lesson Update Error (PDO Execute): " . implode(" | ", $stmt_update_query->errorInfo()));
                    }
                } catch (PDOException $e) {
                    $errors[] = 'Произошла ошибка базы данных при обновлении параметров занятия.';
                    error_log("Lesson Update PDO Error: " . $e->getMessage());
                }
            }
        }
    }
} catch (PDOException $e) {
     error_log("Database Error on edit_lesson.php: " . $e->getMessage());
     $errors[] = "Произошла критическая ошибка базы данных: " . htmlspecialchars($e->getMessage());
     if (!$lesson_data_for_form && empty($page_flash_message)) { // Если урок не загрузился и нет другого сообщения
         $_SESSION['message_flash'] = ['type' => 'error', 'text' => "Ошибка загрузки данных урока."];
     }
} catch (Exception $e) { // Общие ошибки
     error_log("General Error on edit_lesson.php: " . $e->getMessage());
     $errors[] = "Произошла внутренняя ошибка: " . htmlspecialchars($e->getMessage());
} finally {
    $conn = null;
}

$page_title = "Редактирование занятия: " . htmlspecialchars($lesson_data_for_form['title'] ?? 'Занятие ID ' . $lesson_id);
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'admin-page edit-lesson-page app-page';
$load_notifications_css = true;
$load_admin_css = true;

// JavaScript для подтверждения удаления
$page_specific_js = '
<script>
document.addEventListener("DOMContentLoaded", function() {
    const deleteLessonButton = document.getElementById("deleteLessonBtn");
    if (deleteLessonButton) {
        deleteLessonButton.addEventListener("click", function() {
            const lessonId = this.dataset.lessonId;
            const groupId = this.dataset.groupId;
            const lessonTitle = this.dataset.lessonTitle || "это занятие";
            const message = `Вы уверены, что хотите удалить ${lessonTitle} (ID: ${lessonId})?
ВНИМАНИЕ: Все определения заданий, сданные работы, материалы и сообщения чата, связанные с этим занятием, будут УДАЛЕНЫ НАВСЕГДА!`;
            if (confirm(message)) {
                window.location.href = "' . BASE_URL . 'actions/delete_item.php?type=lesson&id=" + lessonId + "&group_id=" + groupId + "&confirm=yes";
            }
        });
    }
});
</script>
';

ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-edit me-2"></i>Редактирование занятия</h1>
        <?php if ($lesson_data_for_form): ?>
        <a href="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $lesson_id; ?>" class="btn btn-outline-info btn-sm mt-2 mt-md-0">
            <i class="fas fa-eye me-1"></i> Просмотр занятия
        </a>
        <?php endif; ?>
    </div>

    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_admin.php">Админ-панель</a></li>
            <?php if ($group_id_from_lesson > 0 && isset($lesson_data_for_form['group_name'])): ?>
                 <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/admin_groups.php?action=edit&id=<?php echo $group_id_from_lesson; ?>">Группа: <?php echo htmlspecialchars($lesson_data_for_form['group_name']); ?></a></li>
            <?php endif; ?>
            <?php if ($lesson_data_for_form): ?>
                 <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $lesson_id; ?>"><?php echo htmlspecialchars(truncate_text($lesson_data_for_form['title'], 30)); ?></a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page">Редактирование</li>
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
                <?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>


    <?php if ($lesson_data_for_form): // Показываем форму, только если данные урока успешно загружены ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <h5 class="mb-0">Параметры занятия (Группа: <?php echo htmlspecialchars($lesson_data_for_form['group_name']); ?>)</h5>
        </div>
        <div class="card-body p-4">
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $lesson_id; ?>" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="update_lesson"> 

                <div class="row g-3">
                    <div class="col-12">
                        <label for="title_input" class="form-label">Название занятия <span class="text-danger">*</span></label>
                        <input type="text" id="title_input" name="title" class="form-control" required maxlength="255" value="<?php echo htmlspecialchars($lesson_data_for_form['title'] ?? ''); ?>">
                        <div class="invalid-feedback">Пожалуйста, введите название.</div>
                    </div>

                    <div class="col-md-6">
                         <label for="subject_id_select" class="form-label">Дисциплина <span class="text-danger">*</span></label>
                         <select name="subject_id" id="subject_id_select" class="form-select" required>
                              <option value="">-- Выберите дисциплину --</option>
                              <?php if (empty($subjects_in_group)): ?>
                                  <option value="" disabled>В этой группе нет назначенных дисциплин.</option>
                              <?php else: ?>
                                   <?php foreach ($subjects_in_group as $subject_item): ?>
                                       <option value="<?php echo $subject_item['id']; ?>" <?php echo (isset($lesson_data_for_form['subject_id']) && (int)$lesson_data_for_form['subject_id'] === (int)$subject_item['id']) ? 'selected' : ''; ?>>
                                           <?php echo htmlspecialchars($subject_item['name']); ?>
                                       </option>
                                   <?php endforeach; ?>
                              <?php endif; ?>
                         </select>
                         <div class="invalid-feedback">Пожалуйста, выберите дисциплину.</div>
                         <?php if (empty($subjects_in_group)): ?>
                              <small class="text-danger d-block mt-1">Сначала <a href="<?php echo BASE_URL; ?>pages/edit_group.php?id=<?php echo $group_id_from_lesson; ?>">назначьте дисциплины</a> этой группе.</small>
                         <?php endif; ?>
                     </div>

                    <div class="col-md-6">
                        <label for="lesson_type_select" class="form-label">Тип занятия <span class="text-danger">*</span></label>
                        <select name="lesson_type" id="lesson_type_select" class="form-select" required>
                            <option value="">-- Выберите тип --</option>
                            <?php $allowed_lesson_types_display = ['lecture' => 'Лекция', 'practice' => 'Практика/Семинар', 'assessment' => 'Аттестация/Контроль', 'other' => 'Другое']; ?>
                            <?php foreach ($allowed_lesson_types_display as $type_key => $type_name): ?>
                                <option value="<?php echo $type_key; ?>" <?php echo (isset($lesson_data_for_form['lesson_type']) && $lesson_data_for_form['lesson_type'] === $type_key) ? 'selected' : ''; ?>><?php echo $type_name; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Пожалуйста, выберите тип занятия.</div>
                    </div>

                    <div class="col-md-6">
                        <label for="lesson_date_input" class="form-label">Дата и время занятия <span class="text-danger">*</span></label>
                        <input type="datetime-local" id="lesson_date_input" name="lesson_date" class="form-control" required value="<?php echo htmlspecialchars(format_datetime_local($lesson_data_for_form['lesson_date'] ?? '')); ?>">
                        <div class="invalid-feedback">Пожалуйста, укажите дату и время.</div>
                    </div>

                    <div class="col-md-6">
                        <label for="duration_minutes_input" class="form-label">Продолжительность (мин) <span class="text-danger">*</span></label>
                        <input type="number" id="duration_minutes_input" name="duration_minutes" class="form-control" required min="15" max="480" step="5" value="<?php echo htmlspecialchars((string)($lesson_data_for_form['duration_minutes'] ?? 90)); ?>">
                        <div class="form-text">Обычно 90 минут.</div>
                        <div class="invalid-feedback">Укажите корректную продолжительность (15-480 мин).</div>
                    </div>

                    <div class="col-12">
                        <label for="description_textarea" class="form-label">Описание занятия</label>
                        <textarea id="description_textarea" name="description" class="form-control" rows="4"><?php echo htmlspecialchars($lesson_data_for_form['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                    <a href="<?php echo BASE_URL; ?>pages/manage_lesson_assignments.php?lesson_id=<?php echo $lesson_id; ?>" class="btn btn-info">
                        <i class="fas fa-tasks me-1"></i> Управление заданиями к уроку
                    </a>
                    <div class="d-flex gap-2">
                        <a href="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $lesson_id; ?>" class="btn btn-secondary">Отмена</a>
                        <button type="submit" class="btn btn-primary" <?php echo empty($subjects_in_group) ? 'disabled' : '';?>>
                            <i class="fas fa-save me-1"></i>Сохранить изменения
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-danger mt-4">
        <div class="card-body">
             <p class="text-danger"><strong>Внимание!</strong> Это действие необратимо и удалит все связанные данные (включая все определения заданий к нему, сданные работы, материалы, чат).</p>
             <button type="button" class="btn btn-danger" id="deleteLessonBtn"
                     data-lesson-id="<?php echo $lesson_id; ?>"
                     data-group-id="<?php echo $group_id_from_lesson; ?>"
                     data-lesson-title="<?php echo htmlspecialchars(addslashes($lesson_data_for_form['title'] ?? '')); ?>">
                 <i class="fas fa-trash-alt me-1"></i> Удалить это занятие
             </button>
         </div>
    </div>

    <?php else: ?>
         <div class="alert alert-warning">
             Не удалось загрузить данные занятия для редактирования.
             <?php if (empty($errors)): ?>
                Пожалуйста, вернитесь на <a href="<?php echo BASE_URL; ?>pages/dashboard.php" class="alert-link">панель управления</a>.
             <?php endif; ?>
         </div>
    <?php endif; ?>
</div>

<?php
$page_content = ob_get_clean();

require_once LAYOUTS_PATH . 'main_layout.php';
?>