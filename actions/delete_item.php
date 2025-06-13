<?php
declare(strict_types=1);
ini_set('display_errors', '1'); 
error_reporting(E_ALL);

if (!defined('BASE_URL')) { define('BASE_URL', '/project/'); }
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Проверка авторизации и базовых параметров
if (!is_logged_in()) { header('Location: ' . BASE_URL . 'pages/login.php'); exit; }

$current_user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? null;
$item_type = $_GET['type'] ?? null;
$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$lesson_id = isset($_GET['lesson_id']) ? (int)$_GET['lesson_id'] : 0;
$confirm = $_GET['confirm'] ?? '';

// Проверка прав и параметров
$is_admin = ($role === 'admin');
$allowed_non_admin_delete = ['material', 'assignment', 'assignment_definition'];

if (!$is_admin && !in_array($item_type, $allowed_non_admin_delete)) {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'У вас нет прав для выполнения этого действия (общая проверка).']; // Используем message_flash
    header('Location: ' . BASE_URL . 'pages/dashboard.php'); exit;
}
if ($item_type === 'user' && !$is_admin) {
     $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Только администратор может удалять пользователей.'];
     header('Location: ' . BASE_URL . 'pages/dashboard.php'); exit;
}
if (empty($item_type) || $item_id <= 0 || $confirm !== 'yes') {
     $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Ошибка: Неверные параметры для удаления.'];
     $redirect_url = BASE_URL . 'pages/dashboard.php';
     if ($item_type === 'user') $redirect_url = BASE_URL . 'pages/admin_users.php';
     elseif ($lesson_id > 0) $redirect_url = BASE_URL . 'pages/lesson.php?id=' . $lesson_id;
     elseif ($group_id > 0) $redirect_url = BASE_URL . 'pages/admin_groups.php';
     header('Location: ' . $redirect_url); exit;
}

$conn = null;
$error_message_for_user = '';
$redirect_page = 'dashboard.php';

try {
    $conn = getDbConnection();
    $conn->beginTransaction();

    // Удаление Материала
    if ($item_type === 'material') {
        if ($lesson_id <= 0) throw new Exception("Не указан ID урока для редиректа.");
        $redirect_page = 'lesson.php?id=' . $lesson_id . '&tab=materials';
        $sql_get = "SELECT m.file_path, m.uploaded_by, l.group_id, l.subject_id FROM materials m JOIN lessons l ON m.lesson_id = l.id WHERE m.id=? AND m.lesson_id=?";
        $stmt_get = $conn->prepare($sql_get); $stmt_get->execute([$item_id, $lesson_id]); $item_data = $stmt_get->fetch(PDO::FETCH_ASSOC);
        if (!$item_data) throw new Exception('Материал не найден.'); $stmt_get = null;
        $can_delete = false; if ($is_admin) { $can_delete = true; }
        elseif ($role === 'teacher' && isset($item_data['subject_id'])) {
             $sql_check = "SELECT COUNT(*) FROM teaching_assignments WHERE teacher_id = ? AND subject_id = ? AND group_id = ?";
             $stmt_check = $conn->prepare($sql_check); $stmt_check->execute([$current_user_id, $item_data['subject_id'], $item_data['group_id']]);
             if ($stmt_check->fetchColumn() > 0 && isset($item_data['uploaded_by']) && $current_user_id === $item_data['uploaded_by']) { $can_delete = true; } $stmt_check = null; }
        if (!$can_delete) throw new Exception('У вас нет прав для удаления этого материала.');
        $stmt_delete = $conn->prepare("DELETE FROM materials WHERE id = ?"); if (!$stmt_delete->execute([$item_id])) throw new PDOException("Ошибка удаления материала из БД."); if ($stmt_delete->rowCount() === 0) throw new Exception("Материал для удаления не найден в БД."); $stmt_delete = null;
        $file_path_db = $item_data['file_path']; $file_to_delete = realpath(__DIR__ . '/../uploads/' . $file_path_db); $base_upload_dir = realpath(__DIR__ . '/../uploads/materials');
        if ($file_to_delete && $base_upload_dir && strpos($file_to_delete, $base_upload_dir) === 0 && file_exists($file_to_delete)) { if (!unlink($file_to_delete)) throw new Exception("Не удалось удалить файл материала."); }
        else { error_log("File not found for material ID={$item_id}, Path={$file_path_db}"); }
        $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Материал успешно удален.'];

    // Удаление Занятия
    } elseif ($item_type === 'lesson') {
         if (!$is_admin) throw new Exception("Только администратор может удалять занятия.");
         if ($group_id <= 0) throw new Exception("Не указан ID группы для редиректа.");
         $redirect_page = 'admin_groups.php?action=edit&id=' . $group_id; // Редирект на страницу редактирования группы
         $lesson_id_to_delete = $item_id;
         $conn->prepare("DELETE FROM messages WHERE lesson_id = ?")->execute([$lesson_id_to_delete]);
         $stmt_get_assign = $conn->prepare("SELECT id, file_path FROM assignments WHERE lesson_id = ?"); $stmt_get_assign->execute([$lesson_id_to_delete]); $assignments_to_delete = $stmt_get_assign->fetchAll(PDO::FETCH_ASSOC); $stmt_get_assign = null;
         foreach ($assignments_to_delete as $assign) { $file_to_delete = realpath(__DIR__ . '/../uploads/' . $assign['file_path']); $base_dir = realpath(__DIR__ . '/../uploads/assignments'); if ($file_to_delete && $base_dir && strpos($file_to_delete, $base_dir) === 0 && file_exists($file_to_delete)) { unlink($file_to_delete); } else { error_log("Assign file not found ID={$assign['id']}, Path={$assign['file_path']}"); } }
         $conn->prepare("DELETE FROM assignments WHERE lesson_id = ?")->execute([$lesson_id_to_delete]);
         $stmt_get_mat = $conn->prepare("SELECT id, file_path FROM materials WHERE lesson_id = ?"); $stmt_get_mat->execute([$lesson_id_to_delete]); $materials_to_delete = $stmt_get_mat->fetchAll(PDO::FETCH_ASSOC); $stmt_get_mat = null;
         foreach ($materials_to_delete as $mat) { $file_to_delete = realpath(__DIR__ . '/../uploads/' . $mat['file_path']); $base_dir = realpath(__DIR__ . '/../uploads/materials'); if ($file_to_delete && $base_dir && strpos($file_to_delete, $base_dir) === 0 && file_exists($file_to_delete)) { unlink($file_to_delete); } else { error_log("Material file not found ID={$mat['id']}, Path={$mat['file_path']}"); } }
         $conn->prepare("DELETE FROM materials WHERE lesson_id = ?")->execute([$lesson_id_to_delete]);
         $stmt_delete_lesson = $conn->prepare("DELETE FROM lessons WHERE id = ?");
         if (!$stmt_delete_lesson->execute([$lesson_id_to_delete])) throw new PDOException("Не удалось удалить занятие.");
         if ($stmt_delete_lesson->rowCount() === 0) throw new Exception("Занятие для удаления не найдено."); $stmt_delete_lesson = null;
         $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Занятие и все связанные данные успешно удалены.'];

    // Удаление Группы
    } elseif ($item_type === 'group') {
        if (!$is_admin) throw new Exception("Только администратор может удалять группы.");
        $redirect_page = 'admin_groups.php';
        $group_id_to_delete = $item_id;
        // Каскадное удаление уроков и всего связанного с ними
         $stmt_get_lessons = $conn->prepare("SELECT id FROM lessons WHERE group_id = ?"); $stmt_get_lessons->execute([$group_id_to_delete]); $lesson_ids_to_delete = $stmt_get_lessons->fetchAll(PDO::FETCH_COLUMN, 0); $stmt_get_lessons = null;
         if (!empty($lesson_ids_to_delete)) {
             $placeholders = implode(',', array_fill(0, count($lesson_ids_to_delete), '?'));
             $conn->prepare("DELETE FROM messages WHERE lesson_id IN ($placeholders)")->execute($lesson_ids_to_delete);
             $stmt_get_assign = $conn->prepare("SELECT id, file_path FROM assignments WHERE lesson_id IN ($placeholders)"); $stmt_get_assign->execute($lesson_ids_to_delete); $assignments_to_delete = $stmt_get_assign->fetchAll(PDO::FETCH_ASSOC); $stmt_get_assign = null;
             foreach ($assignments_to_delete as $assign) { $file_to_delete = realpath(__DIR__ . '/../uploads/' . $assign['file_path']); $base_dir = realpath(__DIR__ . '/../uploads/assignments'); if ($file_to_delete && $base_dir && strpos($file_to_delete, $base_dir) === 0 && file_exists($file_to_delete)) { unlink($file_to_delete); } else { error_log("Assign file not found ID={$assign['id']}, Path={$assign['file_path']}"); } }
             $conn->prepare("DELETE FROM assignments WHERE lesson_id IN ($placeholders)")->execute($lesson_ids_to_delete);
             $stmt_get_mat = $conn->prepare("SELECT id, file_path FROM materials WHERE lesson_id IN ($placeholders)"); $stmt_get_mat->execute($lesson_ids_to_delete); $materials_to_delete = $stmt_get_mat->fetchAll(PDO::FETCH_ASSOC); $stmt_get_mat = null;
             foreach ($materials_to_delete as $mat) { $file_to_delete = realpath(__DIR__ . '/../uploads/' . $mat['file_path']); $base_dir = realpath(__DIR__ . '/../uploads/materials'); if ($file_to_delete && $base_dir && strpos($file_to_delete, $base_dir) === 0 && file_exists($file_to_delete)) { unlink($file_to_delete); } else { error_log("Material file not found ID={$mat['id']}, Path={$mat['file_path']}"); } }
             $conn->prepare("DELETE FROM materials WHERE lesson_id IN ($placeholders)")->execute($lesson_ids_to_delete);
             $conn->prepare("DELETE FROM lessons WHERE group_id = ?")->execute([$group_id_to_delete]);
        }

        $conn->prepare("DELETE FROM teaching_assignments WHERE group_id = ?")->execute([$group_id_to_delete]);
        $stmt_delete_group = $conn->prepare("DELETE FROM groups WHERE id = ?");
         if (!$stmt_delete_group->execute([$group_id_to_delete])) throw new PDOException("Не удалось удалить группу.");
        if ($stmt_delete_group->rowCount() === 0) throw new Exception("Группа для удаления не найдена.");
        $stmt_delete_group = null;
        $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Группа и все связанные данные успешно удалены.'];

    // Удаление Задания
    } elseif ($item_type === 'assignment') {
         if ($lesson_id <= 0) throw new Exception("Не указан ID урока для редиректа.");
         $redirect_page = 'lesson.php?id=' . $lesson_id . '&tab=assignments';
         $sql_get = "SELECT a.file_path, a.student_id, l.group_id FROM assignments a JOIN lessons l ON a.lesson_id = l.id WHERE a.id=? AND a.lesson_id=?"; $stmt_get = $conn->prepare($sql_get); $stmt_get->execute([$item_id, $lesson_id]); $item_data = $stmt_get->fetch(PDO::FETCH_ASSOC); if (!$item_data) throw new Exception('Задание не найдено.'); $stmt_get = null;
         $can_delete = false; if ($is_admin) { $can_delete = true; } elseif ($role === 'student' && isset($item_data['student_id']) && $current_user_id === $item_data['student_id']) { $can_delete = true; } if (!$can_delete) throw new Exception('У вас нет прав для удаления этого задания.');
         $stmt_delete = $conn->prepare("DELETE FROM assignments WHERE id = ?"); if (!$stmt_delete->execute([$item_id])) throw new PDOException("Ошибка удаления задания из БД."); if ($stmt_delete->rowCount() === 0) throw new Exception("Задание для удаления не найдено в БД."); $stmt_delete = null;
         $file_path_db = $item_data['file_path']; $file_to_delete = realpath(__DIR__ . '/../uploads/' . $file_path_db); $base_upload_dir = realpath(__DIR__ . '/../uploads/assignments'); if ($file_to_delete && $base_upload_dir && strpos($file_to_delete, $base_upload_dir) === 0 && file_exists($file_to_delete)) { if (!unlink($file_to_delete)) throw new Exception("Не удалось удалить файл задания."); } else { error_log("File not found for assignment ID={$item_id}, Path={$file_path_db}"); }
         $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Задание успешно удалено.'];

    // Удаление Пользователя
    } elseif ($item_type === 'user') {
        if (!$is_admin) throw new Exception("Только администратор может удалять пользователей.");
        $redirect_page = 'admin_users.php';
        $user_id_to_delete = $item_id;

        // Защита от самоудаления
        if ($user_id_to_delete === $current_user_id) { throw new Exception("Вы не можете удалить свою учетную запись."); }

        // Получаем роль
        $stmt_get_role = $conn->prepare("SELECT role FROM users WHERE id = ?"); $stmt_get_role->execute([$user_id_to_delete]); $user_to_delete_role = $stmt_get_role->fetchColumn(); $stmt_get_role = null;
        if (!$user_to_delete_role) throw new Exception("Пользователь для удаления не найден.");

        // Проверка для преподавателя: нет ли назначений в teaching_assignments
        if ($user_to_delete_role === 'teacher') {
             $stmt_check_ta = $conn->prepare("SELECT COUNT(*) FROM teaching_assignments WHERE teacher_id = ?"); $stmt_check_ta->execute([$user_id_to_delete]);
              if ($stmt_check_ta->fetchColumn() > 0) { throw new Exception("Невозможно удалить преподавателя: он назначен на дисциплины в группах."); } $stmt_check_ta = null;
        }

        // Каскадное удаление связей и данных
             if ($user_to_delete_role === 'student') {
                  $stmt_get_assign = $conn->prepare("SELECT id, file_path FROM assignments WHERE student_id = ?"); $stmt_get_assign->execute([$user_id_to_delete]); $assignments_to_delete = $stmt_get_assign->fetchAll(PDO::FETCH_ASSOC); $stmt_get_assign = null;
                  foreach ($assignments_to_delete as $assign) { $file_to_delete = realpath(__DIR__ . '/../uploads/' . $assign['file_path']); $base_dir = realpath(__DIR__ . '/../uploads/assignments'); if ($file_to_delete && $base_dir && strpos($file_to_delete, $base_dir) === 0 && file_exists($file_to_delete)) { unlink($file_to_delete); } else { error_log("Assign file not found ID={$assign['id']}, Path={$assign['file_path']}"); } }
                  $conn->prepare("DELETE FROM assignments WHERE student_id = ?")->execute([$user_id_to_delete]);
             }
        //  Загруженные пользователем материалы (с файлами)
             $stmt_get_mat = $conn->prepare("SELECT id, file_path FROM materials WHERE uploaded_by = ?"); $stmt_get_mat->execute([$user_id_to_delete]); $materials_to_delete = $stmt_get_mat->fetchAll(PDO::FETCH_ASSOC); $stmt_get_mat = null;
             foreach ($materials_to_delete as $mat) { $file_to_delete = realpath(__DIR__ . '/../uploads/' . $mat['file_path']); $base_dir = realpath(__DIR__ . '/../uploads/materials'); if ($file_to_delete && $base_dir && strpos($file_to_delete, $base_dir) === 0 && file_exists($file_to_delete)) { unlink($file_to_delete); } else { error_log("Material file not found ID={$mat['id']}, Path={$mat['file_path']}"); } }
             $conn->prepare("DELETE FROM materials WHERE uploaded_by = ?")->execute([$user_id_to_delete]);
         // Удаляем самого пользователя
        $stmt_delete_user = $conn->prepare("DELETE FROM users WHERE id = ?");
        if (!$stmt_delete_user->execute([$user_id_to_delete])) throw new PDOException("Не удалось удалить пользователя.");
        if ($stmt_delete_user->rowCount() === 0) throw new Exception("Пользователь для удаления не найден.");
        $stmt_delete_user = null;

        $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Пользователь (ID: ' . $user_id_to_delete . ') и все связанные данные успешно удалены.'];
        
    } elseif ($item_type === 'assignment_definition') {
        if ($lesson_id > 0) {
            $redirect_page = 'manage_lesson_assignments.php?lesson_id=' . $lesson_id;
        } else {
            $stmt_get_lesson_val = $conn->prepare("SELECT lesson_id FROM assignment_definitions WHERE id = ?");
            $stmt_get_lesson_val->execute([$item_id]);
            $lesson_id_from_db_val = $stmt_get_lesson_val->fetchColumn();
            if ($lesson_id_from_db_val) {
                $lesson_id = (int)$lesson_id_from_db_val;
                $redirect_page = 'manage_lesson_assignments.php?lesson_id=' . $lesson_id;
            } else {
                $redirect_page = 'dashboard.php';
                error_log("Delete assignment_definition: Critical - lesson_id could not be determined for redirect for definition ID: " . $item_id);
                throw new Exception("Не удалось определить ID урока, к которому относится определение задания.");
            }
            $stmt_get_lesson_val = null;
        }

        $can_delete_definition = false;
        if ($is_admin) {
            $can_delete_definition = true;
        } else if ($role === 'teacher') {
            // Проверяем, ведет ли преподаватель этот урок
            if ($lesson_id > 0) { // lesson_id теперь должен быть определен
                 $stmt_get_lesson_details = $conn->prepare("SELECT subject_id, group_id FROM lessons WHERE id = ?");
                 $stmt_get_lesson_details->execute([$lesson_id]);
                 $lesson_details = $stmt_get_lesson_details->fetch(PDO::FETCH_ASSOC);
                 $stmt_get_lesson_details = null;
                 if ($lesson_details && isset($lesson_details['subject_id']) && isset($lesson_details['group_id'])) { // Проверяем наличие ключей
                     $stmt_check_teacher = $conn->prepare("SELECT 1 FROM teaching_assignments WHERE teacher_id = ? AND subject_id = ? AND group_id = ? LIMIT 1");
                     $stmt_check_teacher->execute([$current_user_id, $lesson_details['subject_id'], $lesson_details['group_id']]);
                     if ($stmt_check_teacher->fetchColumn()) {
                         $can_delete_definition = true;
                     }
                     $stmt_check_teacher = null;
                 } else {
                     error_log("Delete assignment_definition: Could not fetch lesson details for lesson_id: " . $lesson_id);
                 }
            }
        }

        if (!$can_delete_definition) {
            throw new Exception("У вас нет прав для удаления этого определения задания.");
        }

        // Нужен lesson_id для редиректа на edit_lesson.php
        if ($lesson_id <= 0) {
            $stmt_get_lesson = $conn->prepare("SELECT lesson_id FROM assignment_definitions WHERE id = ?");
            $stmt_get_lesson->execute([$item_id]);
            $lesson_id_from_db = $stmt_get_lesson->fetchColumn();
            if ($lesson_id_from_db) {
                $lesson_id = (int)$lesson_id_from_db;
            } else {
                throw new Exception("Не удалось определить ID урока для редиректа.");
            }
            $stmt_get_lesson = null;
        }
        $redirect_page = 'edit_lesson.php?id=' . $lesson_id . '#assignments-section';

        $definition_id_to_delete = $item_id;

        // Получаем путь к файлу описания (если есть) перед удалением записи
        $stmt_get_def = $conn->prepare("SELECT file_path FROM assignment_definitions WHERE id = ?");
        $stmt_get_def->execute([$definition_id_to_delete]);
        $definition_data = $stmt_get_def->fetch(PDO::FETCH_ASSOC);
        if (!$definition_data) {
            throw new Exception("Определение задания для удаления не найдено.");
        }
        $definition_file_path = $definition_data['file_path'];
        $stmt_get_def = null;

        // Удаляем связанные сданные работы студентов и их файлы
         $stmt_get_assign = $conn->prepare("SELECT id, file_path FROM assignments WHERE assignment_definition_id = ?");
         $stmt_get_assign->execute([$definition_id_to_delete]);
         $assignments_to_delete = $stmt_get_assign->fetchAll(PDO::FETCH_ASSOC);
         $stmt_get_assign = null;

         $base_dir_assignments = realpath(__DIR__ . '/../uploads/assignments');
         if (!$base_dir_assignments) { error_log("Warning: Base directory for student assignments not found or inaccessible."); }

         foreach ($assignments_to_delete as $assign) {
             if (!empty($assign['file_path'])) {
                // Добавим проверку пути для безопасности
                 $file_to_delete = realpath(__DIR__ . '/../' . $assign['file_path']);
                 if ($file_to_delete && $base_dir_assignments && strpos($file_to_delete, $base_dir_assignments) === 0 && file_exists($file_to_delete)) {
                     if (!@unlink($file_to_delete)) {
                         error_log("Warning: Could not delete student assignment file: " . $file_to_delete);
                     }
                 } else {
                      error_log("Assignment file not found or path invalid for assignment ID={$assign['id']}, Path={$assign['file_path']}");
                 }
             }
         }
         // Удаляем записи из assignments
         $stmt_delete_assignments = $conn->prepare("DELETE FROM assignments WHERE assignment_definition_id = ?");
         $stmt_delete_assignments->execute([$definition_id_to_delete]);

        // Удаляем само определение задания
        $stmt_delete_def = $conn->prepare("DELETE FROM assignment_definitions WHERE id = ?");
        if (!$stmt_delete_def->execute([$definition_id_to_delete])) {
            throw new PDOException("Не удалось удалить определение задания из БД.");
        }
        if ($stmt_delete_def->rowCount() === 0) {
            throw new Exception("Определение задания для удаления не найдено в БД (возможно, удалено параллельно).");
        }
        $stmt_delete_def = null;

        // Удаляем файл описания задания
        if (!empty($definition_file_path)) {
            $base_dir_definitions = realpath(__DIR__ . '/../uploads/assignment_definitions');
             if (!$base_dir_definitions) { error_log("Warning: Base directory for assignment definitions not found or inaccessible."); }

            $file_to_delete_def = realpath(__DIR__ . '/../' . $definition_file_path);
            if ($file_to_delete_def && $base_dir_definitions && strpos($file_to_delete_def, $base_dir_definitions) === 0 && file_exists($file_to_delete_def)) {
                if (!@unlink($file_to_delete_def)) {
                    error_log("Warning: Could not delete assignment definition file: " . $file_to_delete_def);
                    $_SESSION['message_warning'] = 'Запись определения удалена, но не удалось удалить связанный файл описания.';
                }
            } else {
                error_log("Definition file not found or path invalid for definition ID={$definition_id_to_delete}, Path={$definition_file_path}");
            }
        }

        // Устанавливаем сообщение об успехе
        $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Определение задания и все связанные сданные работы успешно удалены.'];
        if (isset($_SESSION['message_warning_flash'])) {
             $_SESSION['message_flash']['text'] .= ' ' . $_SESSION['message_warning_flash'];
             unset($_SESSION['message_warning_flash']);
        }
    } else {
        throw new Exception('Неизвестный тип объекта для удаления.');
    }

    $conn->commit();

} catch (PDOException | Exception $e) {
    if ($conn && $conn->inTransaction()) { $conn->rollBack(); }
    error_log("Delete Item Error (Type: {$item_type}, ID: {$item_id}): " . $e->getMessage());
    $error_message_for_user = ($e instanceof PDOException) ? 'Произошла ошибка базы данных при удалении.' : 'Ошибка: ' . htmlspecialchars($e->getMessage());
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => $error_message_for_user];

    // Восстанавливаем $redirect_page на основе $item_type и $lesson_id, если ошибка произошла
    if ($item_type === 'assignment_definition' && $lesson_id > 0) {
        $redirect_page = 'manage_lesson_assignments.php?lesson_id=' . $lesson_id;
    }

} finally {
    $conn = null;
}

// Но на всякий случай, как фоллбэк:
if ($item_type === 'user') $final_redirect = 'admin_users.php';
elseif ($item_type === 'material' && $lesson_id > 0) $final_redirect = 'lesson.php?id=' . $lesson_id . '&tab=materials';
elseif ($item_type === 'assignment' && $lesson_id > 0) $final_redirect = 'lesson.php?id=' . $lesson_id . '&tab=assignments';
elseif ($item_type === 'assignment_definition' && $lesson_id > 0) $final_redirect = 'manage_lesson_assignments.php?lesson_id=' . $lesson_id;
elseif ($item_type === 'lesson' && $group_id > 0) $final_redirect = 'dashboard.php?group_id=' . $group_id;
elseif ($item_type === 'group') $final_redirect = 'admin_groups.php';
else $final_redirect = $redirect_page;

header('Location: ' . BASE_URL . 'pages/' . $final_redirect);
exit;
?>