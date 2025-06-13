<?php
declare(strict_types=1);
ini_set('display_errors', '1'); 
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (!defined('BASE_URL')) {
    $script_path_for_base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])); 
    define('BASE_URL', rtrim(dirname($script_path_for_base_url), '/') . '/');    
}
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', ROOT_PATH . '/config/');
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', ROOT_PATH . '/includes/');
}
if (!defined('LAYOUTS_PATH')) {   
    define('LAYOUTS_PATH', ROOT_PATH . '/layouts/');
}

require_once CONFIG_PATH . 'database.php';
require_once INCLUDES_PATH . 'functions.php';
require_once INCLUDES_PATH . 'auth.php';


if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Доступ запрещен. У вас нет прав администратора.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit();
}

// Получение ID группы и инициализация
$group_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($group_id <= 0) {
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Некорректный ID группы.'];
    header('Location: ' . BASE_URL . 'pages/admin_groups.php');
    exit();
}

$group = null;
$all_subjects = [];
$all_teachers = [];
$current_assignments = [];
$students_in_group = [];
$errors_page = []; 
$db_error_message = '';
$page_flash_message = null;

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) { 
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}
// Ошибки валидации из сессии для формы данных группы
if (isset($_SESSION['form_errors_details'])) {
    $errors_page = array_merge($errors_page, $_SESSION['form_errors_details']);
    unset($_SESSION['form_errors_details']);
}
// Предзаполнение формы данных группы при ошибке из сессии
$group_data_from_session = null;
if (isset($_SESSION['form_data_details'])) {
    $group_data_from_session = $_SESSION['form_data_details'];
    unset($_SESSION['form_data_details']);
}
// Ошибки валидации формы добавления назначения из сессии
if (isset($_SESSION['form_errors_assign'])) {
    $errors_page = array_merge($errors_page, $_SESSION['form_errors_assign']);
    unset($_SESSION['form_errors_assign']);
}
// Ошибки валидации формы дат ИА из сессии
if (isset($_SESSION['form_errors_dates'])) {
    $errors_page = array_merge($errors_page, $_SESSION['form_errors_dates']);
    unset($_SESSION['form_errors_dates']);
}

try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Включаем исключения

    // Обработка POST-запросов
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

        // Обновление данных группы
        if ($_POST['action'] === 'update_group_details') {
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $form_errors = []; // Локальные ошибки для этой формы

            if (empty($name)) { $form_errors[] = 'Название группы не может быть пустым.'; }

            if (empty($form_errors)) {
                $sql_check = "SELECT id FROM groups WHERE LOWER(name) = LOWER(?) AND id != ?";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([$name, $group_id]);
                if ($stmt_check->fetch()) { $form_errors[] = 'Группа с таким названием уже существует.'; }
                $stmt_check = null;
            }

            if (empty($form_errors)) {
                 try {
                     $sql_update = "UPDATE groups SET name = ?, description = ? WHERE id = ?";
                     $stmt_update = $conn->prepare($sql_update);
                     if ($stmt_update->execute([$name, $description, $group_id])) {
                         $_SESSION['message'] = ['type' => 'success', 'text' => 'Данные группы успешно обновлены.'];
                     } else {
                          $_SESSION['message'] = ['type' => 'error', 'text' => 'Не удалось обновить данные группы (ошибка БД).'];
                     }
                 } catch (PDOException $e) {
                     $_SESSION['message'] = ['type' => 'error', 'text' => 'Ошибка базы данных при обновлении группы.'];
                     error_log("Group Update PDO Error (ID: $group_id): " . $e->getMessage());
                 }
            } else {
                 $_SESSION['form_errors_details'] = $form_errors; // Сохраняем ошибки валидации
                 $_SESSION['form_data_details'] = $_POST; // Сохраняем введенные данные
            }
            // Редирект после попытки обновления
            header('Location: ' . BASE_URL . 'pages/edit_group.php?id=' . $group_id);
            exit();
        }

        // Добавление нового назначения
        elseif ($_POST['action'] === 'add_assignment') {
            $subject_id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
            $teacher_id = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
            $add_assign_errors = [];

            if ($subject_id <= 0) { $add_assign_errors[] = 'Выберите дисциплину.'; }
            if ($teacher_id <= 0) { $add_assign_errors[] = 'Выберите преподавателя.'; }

            if(empty($add_assign_errors)) {
                 $sql_check_assign = "SELECT id FROM teaching_assignments WHERE group_id = ? AND subject_id = ? AND teacher_id = ?";
                 $stmt_check_assign = $conn->prepare($sql_check_assign);
                 $stmt_check_assign->execute([$group_id, $subject_id, $teacher_id]);
                 if ($stmt_check_assign->fetch()) {
                      $add_assign_errors[] = 'Такое назначение уже существует.';
                 } else {
                      $sql_insert_assign = "INSERT INTO teaching_assignments (group_id, subject_id, teacher_id) VALUES (?, ?, ?)";
                      $stmt_insert_assign = $conn->prepare($sql_insert_assign);
                      if ($stmt_insert_assign->execute([$group_id, $subject_id, $teacher_id])) {
                            $_SESSION['message'] = ['type' => 'success', 'text' => 'Назначение успешно добавлено.'];
                      } else {
                           $_SESSION['message'] = ['type' => 'error', 'text' => 'Ошибка при добавлении назначения.'];
                           error_log("Add Teaching Assignment Error: " . implode(" | ", $stmt_insert_assign->errorInfo()));
                      }
                 }
                 $stmt_check_assign = null;
            }
            if (!empty($add_assign_errors)) {
                 $_SESSION['form_errors_assign'] = $add_assign_errors;
            }
            header('Location: ' . BASE_URL . 'pages/edit_group.php?id=' . $group_id);
            exit();
        }

        // Обновление дат ИА для назначений
        elseif ($_POST['action'] === 'update_assignments_dates') {
            $final_dates = $_POST['final_date'] ?? [];
            $update_count = 0;
            $update_errors = 0;

            $sql_update_date = "UPDATE teaching_assignments SET final_assessment_date = ? WHERE id = ? AND group_id = ?";
            $stmt_update_date = $conn->prepare($sql_update_date);

            $conn->beginTransaction(); // Начинаем транзакцию для массового обновления

            foreach ($final_dates as $assignment_id => $date_str) {
                 $assign_id_int = (int)$assignment_id;
                 // Валидируем дату: пустая строка или корректный формат YYYY-MM-DD
                 $date_to_save = null; // По умолчанию NULL
                 if (!empty($date_str)) {
                      $d = DateTime::createFromFormat('Y-m-d', $date_str);
                      if ($d && $d->format('Y-m-d') === $date_str) {
                          $date_to_save = $date_str; // Сохраняем в формате YYYY-MM-DD
                      } else {
                           // Ошибка формата даты для этого назначения, пропускаем или добавляем в $errors
                           $errors[] = "Некорректный формат даты для назначения ID {$assign_id_int}.";
                           $update_errors++;
                           continue; // Пропускаем это обновление
                      }
                 }
                 // Выполняем обновление
                  if (!$stmt_update_date->execute([$date_to_save, $assign_id_int, $group_id])) {
                      $errors[] = "Ошибка при обновлении даты для назначения ID {$assign_id_int}.";
                      $update_errors++;
                       error_log("Update final_assessment_date Error: AssignID={$assign_id_int} " . implode(" | ", $stmt_update_date->errorInfo()));
                  } else {
                       if ($stmt_update_date->rowCount() > 0) {
                            $update_count++; // Считаем обновленные строки
                       }
                  }
            }
            $stmt_update_date = null;

            if ($update_errors > 0) {
                 $conn->rollBack(); // Откатываем все, если были ошибки
                 $_SESSION['message'] = ['type' => 'error', 'text' => 'Обнаружены ошибки при сохранении дат аттестации. Изменения не сохранены.'];
            } else {
                 $conn->commit(); // Фиксируем изменения
                 if ($update_count > 0) {
                      $_SESSION['message'] = ['type' => 'success', 'text' => "Успешно обновлено дат аттестации: {$update_count}."];
                 } else {
                       $_SESSION['message'] = ['type' => 'info', 'text' => "Изменений в датах аттестации не было внесено."];
                 }
            }

            // Перезаписываем ошибки валидации, если они были
            if (!empty($errors)) {
                 $_SESSION['form_errors_dates'] = $errors; // Сохраняем ошибки для показа
            }

            header('Location: ' . BASE_URL . 'pages/edit_group.php?id=' . $group_id . '#assignments-section');
            exit();
       }
    }

     // Обработка GET-запроса (Удаление назначения)
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'delete_assignment') {
        $assignment_id_to_delete = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
        $confirm_delete = $_GET['confirm'] ?? '';

        if ($assignment_id_to_delete > 0 && $confirm_delete === 'yes') {
             try {
                 $sql_delete_assign = "DELETE FROM teaching_assignments WHERE id = ? AND group_id = ?";
                 $stmt_delete_assign = $conn->prepare($sql_delete_assign);
                 if ($stmt_delete_assign->execute([$assignment_id_to_delete, $group_id])) {
                      if ($stmt_delete_assign->rowCount() > 0) { $_SESSION['message'] = ['type' => 'success', 'text' => 'Назначение успешно удалено.']; }
                      else { $_SESSION['message'] = ['type' => 'warning', 'text' => 'Назначение для удаления не найдено.']; }
                 } else { throw new PDOException("Ошибка при удалении назначения."); }
             } catch (PDOException $e) {
                  $_SESSION['message'] = ['type' => 'error', 'text' => 'Ошибка базы данных при удалении назначения.'];
                  error_log("Delete Teaching Assignment Error: " . $e->getMessage());
             }
             header('Location: ' . BASE_URL . 'pages/edit_group.php?id=' . $group_id);
             exit();
        }
    }

    // Получение данных
    // Данные самой группы
    $sql_group = "SELECT id, name, description FROM groups WHERE id = ?";
    $stmt_group = $conn->prepare($sql_group);
    $stmt_group->execute([$group_id]);
    $group = $stmt_group->fetch(PDO::FETCH_ASSOC);
    $stmt_group = null;
    if (!$group) { throw new Exception("Группа с ID $group_id не найдена."); }

    // Все предметы для выпадающего списка
    $stmt_all_subj = $conn->query("SELECT id, name FROM subjects ORDER BY name ASC");
    $all_subjects = $stmt_all_subj->fetchAll(PDO::FETCH_ASSOC);
    $stmt_all_subj = null;

    // Все преподаватели для выпадающего списка
    $stmt_all_teach = $conn->query("SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name ASC");
    $all_teachers = $stmt_all_teach->fetchAll(PDO::FETCH_ASSOC);
    $stmt_all_teach = null;

    // Текущие назначения 
    $sql_current_assign = "
        SELECT ta.id as assignment_id, s.id as subject_id, s.name as subject_name,
            t.id as teacher_id, t.full_name as teacher_name,
            ta.final_assessment_date -- <-- ДОБАВЛЕНО
        FROM teaching_assignments ta
        JOIN subjects s ON ta.subject_id = s.id
        JOIN users t ON ta.teacher_id = t.id
        WHERE ta.group_id = ?
        ORDER BY s.name, t.full_name
    ";
    $stmt_current_assign = $conn->prepare($sql_current_assign);
    $stmt_current_assign->execute([$group_id]);
    $current_assignments = $stmt_current_assign->fetchAll(PDO::FETCH_ASSOC);
    $stmt_current_assign = null;

    // Список студентов в этой группе
    $sql_students = "SELECT u.id, u.full_name, u.email FROM users u WHERE u.role = 'student' AND u.group_id = ? ORDER BY u.full_name";
    $stmt_students = $conn->prepare($sql_students);
    $stmt_students->execute([$group_id]);
    $students_in_group = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
    $stmt_students = null;

} catch (PDOException | Exception $e) {
    error_log("Error loading/processing edit_group.php (ID: $group_id): " . $e->getMessage());
    $db_error_message = "Произошла ошибка: " . $e->getMessage();
    if (!$group && $group_id > 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $db_error_message];
        header('Location: ' . BASE_URL . 'pages/admin_groups.php');
        exit();
    }
} finally {
    $conn = null;
}

$page_title = "Редактирование Группы: " . ($group ? htmlspecialchars($group['name']) : 'Группа не найдена');
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'admin-page edit-group-page app-page';
$load_notifications_css = true;
$load_admin_css = true;

ob_start();
?>

<div class="container py-4">
    <?php if ($group): // Показываем контент, только если группа загружена ?>
        <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
            <h1 class="h2 mb-0 me-3"><i class="fas fa-edit me-2"></i>Редактирование Группы: <?php echo htmlspecialchars($group['name']); ?></h1>
            <a href="<?php echo BASE_URL; ?>pages/admin_groups.php" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
                <i class="fas fa-arrow-left me-1"></i> К списку групп
            </a>
        </div>

        <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_admin.php">Админ-панель</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/admin_groups.php">Управление Группами</a></li>
                <li class="breadcrumb-item active" aria-current="page">Редактирование: <?php echo htmlspecialchars($group['name']); ?></li>
            </ol>
        </nav>

        <?php if ($page_flash_message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo htmlspecialchars($page_flash_message['text']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors_page)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <strong>Обнаружены ошибки:</strong>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors_page as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($db_error_message)): ?>
            <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($db_error_message); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card shadow-sm mb-4">
                    <div class="card-header"><h3 class="h5 mb-0"><i class="fas fa-info-circle me-2"></i>Основные данные</h3></div>
                    <div class="card-body">
                        <form method="POST" action="<?php echo BASE_URL; ?>pages/edit_group.php?id=<?php echo $group_id; ?>">
                            <input type="hidden" name="action" value="update_group_details">
                            <div class="mb-3">
                                <label for="group_name_edit" class="form-label">Название группы <span class="text-danger">*</span></label>
                                <input type="text" id="group_name_edit" name="name" class="form-control" required
                                       value="<?php echo htmlspecialchars($group['name'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label for="group_description_edit" class="form-label">Описание группы</label>
                                <textarea id="group_description_edit" name="description" class="form-control" rows="4"><?php echo htmlspecialchars($group['description'] ?? ''); ?></textarea>
                            </div>
                             <div class="text-end">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Сохранить данные</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header"><h3 class="h5 mb-0"><i class="fas fa-user-graduate me-2"></i>Студенты в группе (<?php echo count($students_in_group); ?>)</h3></div>
                    <div class="card-body" style="max-height: 250px; overflow-y: auto;">
                        <?php if (empty($students_in_group)): ?>
                             <p class="text-muted text-center py-3">В этой группе пока нет студентов.</p>
                        <?php else: ?>
                             <ul class="list-unstyled mb-0">
                                 <?php foreach ($students_in_group as $student): ?>
                                    <li class="mb-1">
                                        <a href="<?php echo BASE_URL; ?>pages/profile.php?id=<?php echo $student['id']; ?>" class="text-decoration-none" title="Email: <?php echo htmlspecialchars($student['email']); ?>">
                                            <i class="fas fa-user me-1 text-muted"></i><?php echo htmlspecialchars($student['full_name']); ?>
                                        </a>
                                    </li>
                                 <?php endforeach; ?>
                             </ul>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-center">
                        <a href="<?php echo BASE_URL; ?>pages/manage_students.php?group_id=<?php echo $group_id; ?>" class="btn btn-outline-primary btn-sm w-100">
                            <i class="fas fa-users-cog me-1"></i>Управлять составом группы
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-header"><h3 class="h5 mb-0"><i class="fas fa-chalkboard-teacher me-2"></i>Назначения дисциплин, преподавателей и даты ИА</h3></div>
                    <div class="card-body">
                        <form method="POST" action="<?php echo BASE_URL; ?>pages/edit_group.php?id=<?php echo $group_id; ?>#assignments-section" id="assignments-section">
                            <input type="hidden" name="action" value="update_assignments_dates">
                            <h4 class="h6 mb-3">Текущие назначения и даты итоговой аттестации:</h4>
                             <?php if (empty($current_assignments)): ?>
                                 <p class="text-muted">Нет назначенных дисциплин/преподавателей для этой группы.</p>
                             <?php else: ?>
                                 <div class="table-responsive mb-3">
                                     <table class="table table-sm table-striped table-hover align-middle">
                                         <thead class="table-light">
                                              <tr>
                                                  <th>Дисциплина</th>
                                                  <th>Преподаватель</th>
                                                  <th>Дата ИА <i class="fas fa-info-circle text-muted" title="Дата итоговой аттестации (зачета/экзамена). Оставьте пустым, если не применимо."></i></th>
                                                  <th class="text-center">Удалить</th>
                                              </tr>
                                         </thead>
                                         <tbody>
                                             <?php foreach ($current_assignments as $assignment): ?>
                                                 <tr>
                                                     <td><?php echo htmlspecialchars($assignment['subject_name']); ?></td>
                                                     <td>
                                                         <a href="<?php echo BASE_URL; ?>pages/profile.php?id=<?php echo $assignment['teacher_id']; ?>">
                                                             <?php echo htmlspecialchars($assignment['teacher_name']); ?>
                                                         </a>
                                                     </td>
                                                     <td>
                                                         <input type="date"
                                                                class="form-control form-control-sm"
                                                                name="final_date[<?php echo $assignment['assignment_id']; ?>]"
                                                                value="<?php echo htmlspecialchars($assignment['final_assessment_date'] ?? ''); ?>"
                                                                style="max-width: 160px;">
                                                     </td>
                                                     <td class="text-center">
                                                         <a href="?id=<?php echo $group_id; ?>&action=delete_assignment&assignment_id=<?php echo $assignment['assignment_id']; ?>&confirm=yes#assignments-section"
                                                            class="btn btn-xs btn-outline-danger"
                                                            onclick="return confirm('Вы уверены, что хотите удалить это назначение?');"
                                                            title="Удалить назначение">
                                                            <i class="fas fa-times"></i>
                                                         </a>
                                                     </td>
                                                 </tr>
                                             <?php endforeach; ?>
                                         </tbody>
                                     </table>
                                 </div>
                                 <div class="text-end mb-4">
                                      <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Сохранить даты аттестации</button>
                                 </div>
                             <?php endif; ?>
                        </form>

                        <hr class="my-4">

                        <h4 class="h6 mb-3">Добавить новое назначение (Дисциплина + Преподаватель):</h4>
                        <form method="POST" action="<?php echo BASE_URL; ?>pages/edit_group.php?id=<?php echo $group_id; ?>#assignments-section" class="add-assignment-form">
                           <input type="hidden" name="action" value="add_assignment">
                           <div class="row g-3">
                               <div class="col-md-6 mb-3">
                                   <label for="subject_id_assign" class="form-label">Дисциплина:</label>
                                   <select name="subject_id" id="subject_id_assign" class="form-select" required>
                                       <option value="">-- Выберите дисциплину --</option>
                                        <?php if (!empty($all_subjects)): ?>
                                           <?php foreach($all_subjects as $subject): ?>
                                           <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['name']); ?></option>
                                           <?php endforeach; ?>
                                        <?php else: ?>
                                              <option value="" disabled>Нет доступных дисциплин для назначения</option>
                                        <?php endif; ?>
                                   </select>
                               </div>
                               <div class="col-md-6 mb-3">
                                   <label for="teacher_id_assign" class="form-label">Преподаватель:</label>
                                   <select name="teacher_id" id="teacher_id_assign" class="form-select" required>
                                        <option value="">-- Выберите преподавателя --</option>
                                         <?php if (!empty($all_teachers)): ?>
                                            <?php foreach($all_teachers as $teacher): ?>
                                               <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['full_name']); ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                              <option value="" disabled>Нет доступных преподавателей для назначения</option>
                                        <?php endif; ?>
                                   </select>
                               </div>
                           </div>
                           <div class="text-end">
                               <button type="submit" class="btn btn-primary" <?php echo (empty($all_subjects) || empty($all_teachers)) ? 'disabled' : ''; ?>>
                                   <i class="fas fa-plus me-1"></i>Назначить дисциплину преподавателю
                               </button>
                           </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    <?php else: // Если $group не загружена ?>
        <div class="alert alert-danger">Не удалось загрузить данные группы. Возможно, она была удалена или указан неверный ID.</div>
        <a href="<?php echo BASE_URL; ?>pages/admin_groups.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>К списку групп</a>
    <?php endif; ?>
</div>

<?php
$page_content = ob_get_clean();

require_once LAYOUTS_PATH . 'main_layout.php';
?>