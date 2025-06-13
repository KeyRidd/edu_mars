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

if (!defined('UPLOAD_BASE_PATH_ABS')) {
    define('UPLOAD_BASE_PATH_ABS', ROOT_PATH . '/uploads/');
}
if (!defined('UPLOAD_REL_DIR_ASSIGNMENT_DEF')) {
    define('UPLOAD_REL_DIR_ASSIGNMENT_DEF', 'assignment_definitions/');
}

require_once CONFIG_PATH . 'database.php';
require_once INCLUDES_PATH . 'functions.php';
require_once INCLUDES_PATH . 'auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in()) {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Пожалуйста, войдите в систему.'];
    header('Location: ' . BASE_URL . 'pages/login.php');
    exit;
}

$current_user_id_for_script = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? null;
$lesson_id = isset($_GET['lesson_id']) ? (int)$_GET['lesson_id'] : 0;

$errors = [];
$lesson_data = null; 
$assignments_definitions_list = []; 
$assignment_definition_to_edit = null; 
$edit_assignment_definition_id = isset($_GET['edit_assignment']) ? (int)$_GET['edit_assignment'] : 0;
$has_access_to_lesson = false;
$page_flash_message = null;

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}
if (isset($_SESSION['message']) && !$page_flash_message && !empty($_SESSION['message']['text'])) {
    $page_flash_message = $_SESSION['message'];
    unset($_SESSION['message']);
}

if ($lesson_id <= 0) {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Некорректный ID занятия.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php'); exit;
}

$conn = null;
try { 
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Получение информации о занятии
    $sql_lesson_info = "SELECT l.id, l.title, l.group_id, l.subject_id, g.name as group_name
                        FROM lessons l JOIN groups g ON l.group_id = g.id WHERE l.id = ?";
    $stmt_lesson_info = $conn->prepare($sql_lesson_info);
    $stmt_lesson_info->execute([$lesson_id]);
    $lesson_data = $stmt_lesson_info->fetch(PDO::FETCH_ASSOC);
    $stmt_lesson_info = null;
    if (!$lesson_data) {
        $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Занятие не найдено.'];
        header('Location: ' . BASE_URL . 'pages/dashboard.php'); exit;
    }

    // Проверка прав доступа к уроку
    if ($role === 'admin') { $has_access_to_lesson = true; }
    elseif ($role === 'teacher' && $lesson_data['subject_id'] && $lesson_data['group_id']) {
        $sql_teacher_lesson_access = "SELECT 1 FROM teaching_assignments WHERE teacher_id = ? AND subject_id = ? AND group_id = ? LIMIT 1";
        $stmt_teacher_lesson_access = $conn->prepare($sql_teacher_lesson_access);
        $stmt_teacher_lesson_access->execute([$current_user_id_for_script, $lesson_data['subject_id'], $lesson_data['group_id']]);
        $has_access_to_lesson = (bool)$stmt_teacher_lesson_access->fetchColumn();
        $stmt_teacher_lesson_access = null;
    }
    if (!$has_access_to_lesson) {
        $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'У вас нет прав для управления заданиями этого урока.'];
        header('Location: ' . BASE_URL . 'pages/lesson.php?id=' . $lesson_id); exit;
    }

    // Обработка POST запросов
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action_from_post = $_POST['action'] ?? null;

        if ($action_from_post === 'add_assignment' || $action_from_post === 'update_assignment') {
             $assignment_definition_to_edit = [
                 'id' => isset($_POST['assignment_definition_id']) ? (int)$_POST['assignment_definition_id'] : 0,
                 'title' => trim($_POST['assignment_title'] ?? ''),
                 'description' => trim($_POST['assignment_description'] ?? ''),
                 'deadline' => trim($_POST['assignment_deadline'] ?? ''),
                 'allow_late_submissions' => isset($_POST['assignment_allow_late']) ? 1 : 0,
                 'file_path' => null
             ];
             $edit_assignment_definition_id = $assignment_definition_to_edit['id'];
             $delete_files_paths_from_form = $_POST['delete_files'] ?? [];

             if (empty($assignment_definition_to_edit['title'])) { $errors[] = 'Название задания обязательно.'; }
             $assignment_deadline_for_sql = null; $deadline_is_past_flag = false;
             if (!empty($assignment_definition_to_edit['deadline'])) {
                  $dt_deadline = DateTime::createFromFormat('Y-m-d\TH:i', $assignment_definition_to_edit['deadline']);
                  if ($dt_deadline && $dt_deadline->format('Y-m-d\TH:i') === $assignment_definition_to_edit['deadline']) {
                      $assignment_deadline_for_sql = $dt_deadline->format('Y-m-d H:i:s');
                      if ($dt_deadline < new DateTime("now", new DateTimeZone('UTC'))) $deadline_is_past_flag = true;
                  } else { $errors[] = 'Некорректный формат дедлайна.'; }
             }

            $newly_uploaded_relative_paths_for_db = [];
            $absolute_upload_path_for_definitions = UPLOAD_BASE_PATH_ABS . UPLOAD_REL_DIR_ASSIGNMENT_DEF;

            if (!is_dir($absolute_upload_path_for_definitions)) {
                if (!@mkdir($absolute_upload_path_for_definitions, 0775, true)) {
                     $errors[] = 'Не удалось создать директорию для загрузки файлов заданий: ' . htmlspecialchars($absolute_upload_path_for_definitions);
                }
            }
            if (is_dir($absolute_upload_path_for_definitions) && is_writable($absolute_upload_path_for_definitions)) {
                if (isset($_FILES['assignment_files']) && !empty($_FILES['assignment_files']['name'][0])) {

            // Обрабатываем файлы, только если не было критических ошибок с директорией
            if (empty($errors) && isset($_FILES['assignment_files']) && is_array($_FILES['assignment_files']['name'])) {
                $files_data_from_form = $_FILES['assignment_files'];
                        $num_uploaded_files = count($files_data_from_form['name']);
                        for ($i = 0; $i < $num_uploaded_files; $i++) {
                            if ($files_data_from_form['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                            if ($files_data_from_form['error'][$i] === UPLOAD_ERR_OK) {
                                $current_single_file_info = [
                                    'name' => $files_data_from_form['name'][$i], 'type' => $files_data_from_form['type'][$i],
                                    'tmp_name' => $files_data_from_form['tmp_name'][$i], 'error' => $files_data_from_form['error'][$i],
                                    'size' => $files_data_from_form['size'][$i]
                                ];
                                $file_prefix = 'assign_def_l' . $lesson_id . '_';
                                $new_generated_filename_only = handleFileUpload(
                                    $current_single_file_info, $absolute_upload_path_for_definitions, $file_prefix,
                                    ['pdf', 'doc', 'docx', 'txt', 'zip', 'rar', 'jpg', 'png', 'jpeg', 'xls', 'xlsx', 'ppt', 'pptx', 'md'], 20 * 1024 * 1024
                                );
                                if ($new_generated_filename_only) {
                                    $newly_uploaded_relative_paths_for_db[] = UPLOAD_REL_DIR_ASSIGNMENT_DEF . $new_generated_filename_only;
                                } else {
                                    $upload_error_detail = $_SESSION['upload_error_message'] ?? 'Проверьте тип и размер файла.';
                                    $errors[] = 'Ошибка при обработке файла "' . htmlspecialchars($files_data_from_form['name'][$i]) . '". ' . $upload_error_detail;
                                    if (isset($_SESSION['upload_error_message'])) unset($_SESSION['upload_error_message']);
                                }
                            } else {
                                $errors[] = 'Ошибка при загрузке файла "' . htmlspecialchars($files_data_from_form['name'][$i]) . '": ' . get_upload_error_message($files_data_from_form['error'][$i]);
                            }
                        }
                    }
                }
            } 

            if (empty($errors)) {
                if ($deadline_is_past_flag && !isset($_SESSION['message_warning_flash'])) {
                     $_SESSION['message_warning_flash'] = ['Внимание: Установленный дедлайн находится в прошлом.'];
                }
                $final_file_paths_json_for_db = null;
                try {
                    $conn->beginTransaction();

                    if ($action_from_post === 'update_assignment' && $edit_assignment_definition_id > 0) {
                        // Обновление
                        $stmt_get_old_files = $conn->prepare("SELECT file_path FROM assignment_definitions WHERE id = ? AND lesson_id = ?");
                        $stmt_get_old_files->execute([$edit_assignment_definition_id, $lesson_id]);
                        $old_db_file_path_json = $stmt_get_old_files->fetchColumn();
                        $stmt_get_old_files = null;
                        $current_db_relative_paths = [];
                        if ($old_db_file_path_json) {
                            $decoded_old_paths = json_decode($old_db_file_path_json, true);
                            if (is_array($decoded_old_paths)) $current_db_relative_paths = $decoded_old_paths;
                            elseif (is_string($old_db_file_path_json) && !empty($old_db_file_path_json)) $current_db_relative_paths = [$old_db_file_path_json];
                        }
                        $paths_remaining_after_deletion = [];
                        foreach ($current_db_relative_paths as $existing_relative_path) {
                            if (isset($delete_files_paths_from_form[$existing_relative_path])) {
                                $absolute_path_to_delete = UPLOAD_BASE_PATH_ABS . $existing_relative_path;
                                if (file_exists($absolute_path_to_delete)) if (!@unlink($absolute_path_to_delete)) error_log("Could not delete: " . $absolute_path_to_delete);
                            } else {
                                $paths_remaining_after_deletion[] = $existing_relative_path;
                            }
                        }
                        $all_final_relative_paths = array_values(array_unique(array_merge($paths_remaining_after_deletion, $newly_uploaded_relative_paths_for_db)));
                        $final_file_paths_json_for_db = !empty($all_final_relative_paths) ? json_encode($all_final_relative_paths) : null;

                        $sql_update_def_query = "UPDATE assignment_definitions SET title = ?, description = ?, deadline = ?, allow_late_submissions = ?, file_path = ? WHERE id = ? AND lesson_id = ?";
                        $params_for_update = [
                            $assignment_definition_to_edit['title'], $assignment_definition_to_edit['description'],
                            $assignment_deadline_for_sql, $assignment_definition_to_edit['allow_late_submissions'],
                            $final_file_paths_json_for_db, $edit_assignment_definition_id, $lesson_id
                        ];
                        $stmt_update_def_exec = $conn->prepare($sql_update_def_query);
                        if ($stmt_update_def_exec->execute($params_for_update)) {
                             $changes_were_made = $stmt_update_def_exec->rowCount() > 0 || !empty($newly_uploaded_relative_paths_for_db) || (count($current_db_relative_paths) !== count($paths_remaining_after_deletion));
                             $_SESSION['message_flash'] = ['type' => $changes_were_made ? 'success' : 'info', 'text' => $changes_were_made ? 'Задание успешно обновлено.' : 'Изменений не внесено.'];
                        } else {
                           foreach($newly_uploaded_relative_paths_for_db as $new_path_on_error) { if (file_exists(UPLOAD_BASE_PATH_ABS . $new_path_on_error)) @unlink(UPLOAD_BASE_PATH_ABS . $new_path_on_error); }
                           throw new PDOException('Ошибка БД при обновлении задания.');
                        }
                    } elseif ($action_from_post === 'add_assignment') {
                        // Добавление
                        if (!empty($newly_uploaded_relative_paths_for_db)) {
                            $final_file_paths_json_for_db = json_encode($newly_uploaded_relative_paths_for_db);
                        } else {
                            $final_file_paths_json_for_db = null;
                        }
                        $sql_insert_def_query = "INSERT INTO assignment_definitions (lesson_id, title, description, file_path, created_by, deadline, allow_late_submissions, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                        $params_for_insert = [
                            $lesson_id, $assignment_definition_to_edit['title'], $assignment_definition_to_edit['description'],
                            $final_file_paths_json_for_db, $current_user_id_for_script, $assignment_deadline_for_sql,
                            $assignment_definition_to_edit['allow_late_submissions']
                        ];
                        $stmt_insert_def_exec = $conn->prepare($sql_insert_def_query);
                        if (!$stmt_insert_def_exec->execute($params_for_insert)) {
                           foreach($newly_uploaded_relative_paths_for_db as $new_path_on_error) { if (file_exists(UPLOAD_BASE_PATH_ABS . $new_path_on_error)) @unlink(UPLOAD_BASE_PATH_ABS . $new_path_on_error); }
                           throw new PDOException('Ошибка БД при добавлении задания.');
                        }
                        $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Задание успешно добавлено.'];
                    }
                    $conn->commit();

                    if (isset($_SESSION['message_warning_flash']) && isset($_SESSION['message_flash']['text']) && ($_SESSION['message_flash']['type'] === 'success' || $_SESSION['message_flash']['type'] === 'info')) {
                        $_SESSION['message_flash']['text'] .= ' (' . implode(' ', $_SESSION['message_warning_flash']) . ')';
                    } elseif (isset($_SESSION['message_warning_flash']) && empty($errors) && !isset($_SESSION['message_flash'])) {
                         $_SESSION['message_flash'] = ['type' => 'warning', 'text' => implode(' ', $_SESSION['message_warning_flash'])];
                    }
                    if(isset($_SESSION['message_warning_flash'])) unset($_SESSION['message_warning_flash']);

                    header('Location: ' . BASE_URL . 'pages/manage_lesson_assignments.php?lesson_id=' . $lesson_id);
                    exit();

                } catch (PDOException $e) { 
                    if ($conn->inTransaction()) { $conn->rollBack(); }
                    $errors[] = 'Произошла ошибка базы данных при сохранении задания.';
                    error_log("Assignment Definition Save PDO Error: " . $e->getMessage());
                    if (!empty($newly_uploaded_relative_paths_for_db)) {
                        foreach($newly_uploaded_relative_paths_for_db as $new_path) {
                            if (file_exists(UPLOAD_BASE_PATH_ABS . $new_path)) @unlink(UPLOAD_BASE_PATH_ABS . $new_path);
                        }
                    }
                } 
            } 
        } 
    } 

    // Загрузка данных для отображения
  $sql_get_assignments = "SELECT ad.*, u.full_name as creator_name
                          FROM assignment_definitions ad
                          LEFT JOIN users u ON ad.created_by = u.id
                          WHERE ad.lesson_id = ? ORDER BY ad.created_at ASC";
  $stmt_get_assignments = $conn->prepare($sql_get_assignments);
  $stmt_get_assignments->execute([$lesson_id]);
  $assignments_definitions_list = $stmt_get_assignments->fetchAll(PDO::FETCH_ASSOC);
  $stmt_get_assignments = null;

  if ($edit_assignment_definition_id > 0 && !($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors))) {
      $sql_get_edit_def = "SELECT * FROM assignment_definitions WHERE id = ? AND lesson_id = ?";
      $stmt_get_edit_def = $conn->prepare($sql_get_edit_def);
      $stmt_get_edit_def->execute([$edit_assignment_definition_id, $lesson_id]);
      $assignment_definition_to_edit = $stmt_get_edit_def->fetch(PDO::FETCH_ASSOC);
      if (!$assignment_definition_to_edit) {
          $_SESSION['message_flash'] = ['type' => 'warning', 'text' => 'Задание для редактирования не найдено или не относится к этому уроку. Показана форма добавления нового.'];
          $edit_assignment_definition_id = 0;
          $assignment_definition_to_edit = null;
      }
      $stmt_get_edit_def = null;
  } elseif ($edit_assignment_definition_id === 0 && !($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors))) {
      $assignment_definition_to_edit = null;
  }

} catch (PDOException $e) {
    $errors[] = "Ошибка базы данных при загрузке страницы: " . htmlspecialchars($e->getMessage());
    error_log("DB Error manage_lesson_assignments: " . $e->getMessage());
} catch (Exception $e) { 
    $errors[] = "Произошла ошибка: " . htmlspecialchars($e->getMessage());
    error_log("General Error manage_lesson_assignments: " . $e->getMessage());
} finally {
    if ($conn && $conn->inTransaction()) { $conn->rollBack(); } 
    $conn = null;
}

$page_title = "Управление заданиями" . ($lesson_data ? " - " . htmlspecialchars($lesson_data['title']) : '');
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = ($role === 'admin' ? 'admin-page' : 'teacher-page') . ' manage-assignments-page app-page';
$load_notifications_css = true;

$page_specific_js = '
<script>
function confirmAssignmentDefinitionDelete(definitionId, lessonId) {
    const definitionTitle = document.querySelector(`.delete-item-btn[onclick*="confirmAssignmentDefinitionDelete(${definitionId},"]`)?.dataset.itemName || "это определение задания";
    if (confirm(`Вы уверены, что хотите удалить ${definitionTitle}?\n\nВНИМАНИЕ: Все сданные студентами работы по этому заданию также будут УДАЛЕНЫ НАВСЕГДА!`)) {
        window.location.href = "' . BASE_URL . 'actions/delete_item.php?type=assignment_definition&id=" + definitionId + "&lesson_id=" + lessonId + "&confirm=yes";
    }
}
document.addEventListener("DOMContentLoaded", function() {
    const assignmentFilesInput = document.getElementById("assignment_files");
    const selectedFilesPreviewContainer = document.getElementById("selected-files-preview");
    if (assignmentFilesInput && selectedFilesPreviewContainer) {
        assignmentFilesInput.addEventListener("change", function(event) {
            selectedFilesPreviewContainer.innerHTML = ""; 
            if (event.target.files && event.target.files.length > 0) {
                const ul = document.createElement("ul");
                ul.className = "list-unstyled mb-0 ps-0"; 
                for (let i = 0; i < event.target.files.length; i++) {
                    const file = event.target.files[i];
                    const li = document.createElement("li");
                    li.textContent = file.name + " (" + formatFileSizeForJS(file.size) + ")";
                    ul.appendChild(li);
                }
                selectedFilesPreviewContainer.appendChild(document.createTextNode("Выбранные новые файлы:"));
                selectedFilesPreviewContainer.appendChild(ul);
            }
        });
    }
    if (window.location.search.includes("edit_assignment=") && window.location.hash !== "#assignment-form-section") {
        const element = document.getElementById("assignment-form-section-heading"); 
        if (element) {
            element.scrollIntoView({ behavior: "smooth", block: "start" });
            const formCard = element.closest(".card");
            if(formCard) {
                formCard.classList.add("highlight-form");
                setTimeout(() => formCard.classList.remove("highlight-form"), 2500);
            }
        }
    }
});
function formatFileSizeForJS(bytes) {
    if (bytes === 0) return "0 Байт";
    const k = 1024;
    const sizes = ["Байт", "КБ", "МБ", "ГБ", "ТБ"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
}
</script>
<style>
.highlight-form {
    border: 2px solid var(--bs-primary) !important;
    box-shadow: 0 0 15px rgba(var(--bs-primary-rgb), 0.3) !important;
}
</style>
';

ob_start();
?>

<div class="container py-4">
    <?php if ($lesson_data): ?>
        <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
             <h1 class="h2 mb-0 me-3"><i class="fas fa-tasks me-2"></i>Управление заданиями</h1>
             <a href="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $lesson_id; ?>" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
                <i class="fas fa-arrow-left me-1"></i> Вернуться к занятию
            </a>
        </div>
        <h2 class="h4 mb-3 text-muted">Занятие: "<?php echo htmlspecialchars($lesson_data['title']); ?>" <small>(Группа: <?php echo htmlspecialchars($lesson_data['group_name']); ?>)</small></h2>

        <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item">
                    <a href="<?php echo BASE_URL . ($role === 'admin' ? 'pages/home_admin.php' : 'pages/teacher_dashboard.php'); ?>">
                        <?php echo ($role === 'admin' ? 'Админ-панель' : 'Мой дашборд'); ?>
                    </a>
                </li>
                <li class="breadcrumb-item">
                    <a href="<?php echo BASE_URL; ?>pages/dashboard.php?group_id=<?php echo $lesson_data['group_id']; ?>">
                        Группа: <?php echo htmlspecialchars($lesson_data['group_name']); ?>
                    </a>
                </li>
                <li class="breadcrumb-item">
                    <a href="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $lesson_id; ?>">
                        <?php echo htmlspecialchars(truncate_text($lesson_data['title'], 30)); ?>
                    </a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">Управление заданиями</li>
            </ol>
        </nav>
    <?php else: ?>
        <h1 class="h2">Ошибка</h1>
        <div class="alert alert-danger">Не удалось загрузить информацию о занятии.</div>
        <a href="<?php echo BASE_URL; ?>pages/dashboard.php" class="btn btn-primary">На дашборд</a>
        <?php
            $page_content = ob_get_clean();
            require_once LAYOUTS_PATH . 'main_layout.php';
            exit();
        ?>
    <?php endif; ?>

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

    <div class="row g-4">
        <div class="col-lg-7 mb-4 mb-lg-0">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h3 class="h5 mb-0"><i class="fas fa-list-ul me-2"></i>Существующие определения заданий</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($assignments_definitions_list)): ?>
                         <p class="text-muted text-center py-3">К этому уроку пока не добавлено ни одного определения задания.</p>
                    <?php else: ?>
                         <div class="list-group">
                             <?php foreach ($assignments_definitions_list as $def): ?>
                                 <div class="list-group-item list-group-item-action flex-column align-items-start p-3">
                                     <div class="d-flex w-100 justify-content-between">
                                         <h5 class="mb-1 h6">
                                             <a href="?lesson_id=<?php echo $lesson_id; ?>&edit_assignment=<?php echo $def['id']; ?>#assignment-form-section" class="text-decoration-none">
                                                <?php echo htmlspecialchars($def['title']); ?>
                                             </a>
                                         </h5>
                                         <small class="text-muted text-nowrap">
                                             <?php if ($def['deadline']): ?>
                                                 Дедлайн: <?php echo htmlspecialchars(format_ru_datetime($def['deadline'], true, false)); ?>
                                             <?php else: ?>
                                                 Без дедлайна
                                             <?php endif; ?>
                                         </small>
                                     </div>
                                     <?php if(!empty($def['description'])): ?>
                                        <p class="mb-1 small text-muted"><?php echo nl2br(htmlspecialchars(truncate_text($def['description'], 100))); ?></p>
                                     <?php endif; ?>
                                     <?php
                                        $assignment_files_arr = [];
                                        if (!empty($def['file_path'])) {
                                            $decoded_paths = json_decode($def['file_path'], true);
                                            if (is_array($decoded_paths)) {
                                                $assignment_files_arr = $decoded_paths;
                                            } elseif (is_string($def['file_path'])) { 
                                                $assignment_files_arr = [$def['file_path']];
                                            }
                                        }
                                     ?>
                                     <?php if (!empty($assignment_files_arr)): ?>
                                         <div class="mt-2">
                                             <?php foreach ($assignment_files_arr as $idx => $file_rel_path): ?>
                                                <a href="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($file_rel_path); ?>"
                                                   class="btn btn-sm btn-outline-secondary me-1 mb-1" target="_blank" download
                                                   title="Скачать <?php echo htmlspecialchars(basename($file_rel_path)); ?>">
                                                    <i class="fas fa-file-download me-1"></i> <?php echo htmlspecialchars(truncate_text(basename($file_rel_path), 20)); ?>
                                                </a>
                                             <?php endforeach; ?>
                                         </div>
                                     <?php endif; ?>
                                     <div class="mt-2 text-end">
                                         <a href="?lesson_id=<?php echo $lesson_id; ?>&edit_assignment=<?php echo $def['id']; ?>#assignment-form-section" class="btn btn-outline-primary btn-sm me-1" title="Редактировать">
                                             <i class="fas fa-edit"></i> Редактировать
                                         </a>
                                         <button type="button" class="btn btn-outline-danger btn-sm delete-item-btn"
                                                 data-item-name="определение задания '<?php echo htmlspecialchars(addslashes($def['title'])); ?>'"
                                                 onclick="confirmAssignmentDefinitionDelete(<?php echo $def['id']; ?>, <?php echo $lesson_id; ?>)" title="Удалить">
                                             <i class="fas fa-trash"></i> Удалить
                                         </button>
                                     </div>
                                 </div>
                             <?php endforeach; ?>
                         </div>
                     <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card shadow-sm sticky-lg-top" style="top: calc(var(--navbar-height, 70px) + 1rem);">
                <div class="card-header">
                    <h3 class="h5 mb-0" id="assignment-form-section-heading">
                        <i class="fas <?php echo $edit_assignment_definition_id > 0 ? 'fa-edit' : 'fa-plus-circle'; ?> me-2"></i>
                        <?php echo $edit_assignment_definition_id > 0 ? 'Редактирование определения задания' : 'Добавить новое определение задания'; ?>
                    </h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?lesson_id=' . $lesson_id . ($edit_assignment_definition_id > 0 ? '&edit_assignment=' . $edit_assignment_definition_id : ''); ?>#assignment-form-section-heading" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="<?php echo $edit_assignment_definition_id > 0 ? 'update_assignment' : 'add_assignment'; ?>">
                        <input type="hidden" name="assignment_definition_id" value="<?php echo $edit_assignment_definition_id; ?>">

                        <div class="mb-3">
                            <label for="assignment_title" class="form-label">Название <span class="text-danger">*</span></label>
                            <input type="text" id="assignment_title" name="assignment_title" class="form-control" required value="<?php echo htmlspecialchars($assignment_definition_to_edit['title'] ?? ''); ?>">
                            <div class="invalid-feedback">Пожалуйста, введите название.</div>
                        </div>
                        <div class="mb-3">
                            <label for="assignment_description" class="form-label">Описание</label>
                            <textarea id="assignment_description" name="assignment_description" class="form-control" rows="4"><?php echo htmlspecialchars($assignment_definition_to_edit['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="assignment_files" class="form-label">Файлы к определению (можно несколько)</label>
                            <input type="file" id="assignment_files" name="assignment_files[]" class="form-control form-control-sm" multiple>
                            <div id="selected-files-preview" class="mt-2 small"></div> 

                            <?php if ($edit_assignment_definition_id > 0 && !empty($assignment_definition_to_edit['file_path'])):
                                $existing_files_edit = [];
                                $decoded_paths_edit = json_decode($assignment_definition_to_edit['file_path'], true);
                                if (is_array($decoded_paths_edit)) $existing_files_edit = $decoded_paths_edit;
                                elseif (is_string($assignment_definition_to_edit['file_path'])) $existing_files_edit = [$assignment_definition_to_edit['file_path']];
                            ?>
                                <?php if(!empty($existing_files_edit)): ?>
                                <fieldset class="mt-2 border p-2 pt-0 rounded">
                                    <legend class="small w-auto px-2">Существующие файлы (отметьте для удаления):</legend>
                                    <?php foreach ($existing_files_edit as $file_path_item): ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" name="delete_files[<?php echo htmlspecialchars($file_path_item); ?>]" id="delete_file_<?php echo md5($file_path_item); ?>" value="1">
                                        <label class="form-check-label small" for="delete_file_<?php echo md5($file_path_item); ?>">
                                            <a href="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($file_path_item); ?>" target="_blank" title="Скачать <?php echo htmlspecialchars(basename($file_path_item)); ?>">
                                                <i class="fas fa-file-alt me-1"></i><?php echo htmlspecialchars(truncate_text(basename($file_path_item), 25)); ?>
                                            </a>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </fieldset>
                                <?php endif; ?>
                                <small class="form-text text-muted d-block mt-1">Новые файлы будут добавлены к существующим (если не отмечены для удаления).</small>
                            <?php endif; ?>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-7">
                                <label for="assignment_deadline" class="form-label">Дедлайн</label>
                                <input type="datetime-local" id="assignment_deadline" name="assignment_deadline" class="form-control" value="<?php echo htmlspecialchars(format_datetime_local($assignment_definition_to_edit['deadline'] ?? '')); ?>">
                            </div>
                            <div class="col-md-5 d-flex align-items-end pb-1">
                                <div class="form-check">
                                    <input type="checkbox" id="assignment_allow_late" name="assignment_allow_late" class="form-check-input" value="1" <?php echo !empty($assignment_definition_to_edit['allow_late_submissions']) ? 'checked' : ''; ?>>
                                    <label for="assignment_allow_late" class="form-check-label">Разрешить позднюю сдачу</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <?php if ($edit_assignment_definition_id > 0): ?>
                                <a href="<?php echo BASE_URL; ?>pages/manage_lesson_assignments.php?lesson_id=<?php echo $lesson_id; ?>" class="btn btn-secondary">Отмена</a>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas <?php echo $edit_assignment_definition_id > 0 ? 'fa-save' : 'fa-plus-circle'; ?> me-1"></i>
                                <?php echo $edit_assignment_definition_id > 0 ? 'Сохранить изменения' : 'Добавить определение'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>
