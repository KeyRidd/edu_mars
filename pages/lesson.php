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

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? null;
$lesson_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$fatal_error_message = '';
$lesson = null;
$materials = [];
$assignment_definitions = [];
$submissions_by_def_id = [];
$messages = [];
$conn = null;
$has_access = false;
$first_unread_message_id = null;
$unread_message_count = 0;
$page_flash_message = null; // Для флеш-сообщений из сессии

// Получение флеш-сообщения из сессии 
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

if ($lesson_id <= 0) {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Некорректный ID занятия.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit;
}

try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Получение информации о занятии
    $sql_lesson = "
        SELECT l.id, l.title, l.description, l.lesson_date, l.group_id, l.subject_id,
               g.name as group_name, s.name as subject_name
        FROM lessons l
        JOIN groups g ON l.group_id = g.id
        LEFT JOIN subjects s ON l.subject_id = s.id
        WHERE l.id = ?
    ";
    $stmt_lesson = $conn->prepare($sql_lesson);
    $stmt_lesson->execute([$lesson_id]);
    $lesson = $stmt_lesson->fetch(PDO::FETCH_ASSOC);
    $stmt_lesson = null;

    if (!$lesson) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Занятие не найдено.'];
        header('Location: ' . BASE_URL . 'pages/dashboard.php');
        exit;
    }
     if ($lesson['subject_id'] === null && $role === 'teacher') {
         if (!$page_message) { // Показываем только если нет другой ошибки/успеха
              $page_message = ['type' => 'warning', 'text' => 'Внимание: Занятие не привязано к дисциплине. Некоторые функции могут быть ограничены.'];
         }
    }

    // Проверка доступа пользователя
    $has_access = false; // Инициализируем по умолчанию
    if ($role === 'admin') {
        $has_access = true;
    } elseif ($role === 'teacher' && isset($lesson['subject_id']) && isset($lesson['group_id'])) {
        $sql_teacher_access_check = "SELECT 1 FROM teaching_assignments 
                                     WHERE teacher_id = ? AND subject_id = ? AND group_id = ? LIMIT 1";
        $stmt_teacher_access_check = $conn->prepare($sql_teacher_access_check);
        $stmt_teacher_access_check->execute([$user_id, $lesson['subject_id'], $lesson['group_id']]);
        if ($stmt_teacher_access_check->fetchColumn()) {
            $has_access = true;
        }
        $stmt_teacher_access_check = null; // Освобождаем ресурсы
    } elseif ($role === 'student' && isset($lesson['group_id'])) {
        $sql_student_access_check = "SELECT 1 FROM users WHERE id = ? AND group_id = ? AND role = 'student' LIMIT 1";
        $stmt_student_access_check = $conn->prepare($sql_student_access_check);
        $stmt_student_access_check->execute([$user_id, $lesson['group_id']]);
        if ($stmt_student_access_check->fetchColumn()) {
            $has_access = true;
        }
        $stmt_student_access_check = null; // Освобождаем ресурсы
    }

    if (!$has_access) {
        $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'У вас нет доступа к этому занятию.'];
        header('Location: ' . BASE_URL . 'pages/dashboard.php');
        exit;
    }

    // Определяем права на действия ДО обработки POST
    $can_upload_material = false;
    $can_review_assignment = false;
    $has_access_to_edit_lesson = false;        
    $has_access_to_manage_assignments = false; 

    if ($role === 'admin') {
        $can_upload_material = true;
        $can_review_assignment = true;
        $has_access_to_edit_lesson = true;
        $has_access_to_manage_assignments = true;
    } elseif ($role === 'teacher') {
        if ($has_access) {
            $can_upload_material = true;
            $can_review_assignment = true;
            //$has_access_to_edit_lesson = true;
            $has_access_to_manage_assignments = true;
        }
    }

    // Получение материалов
    $sql_materials = "
        SELECT m.id, m.title, m.description, m.file_path, m.created_at,
               u.full_name as uploaded_by_name, u.role as uploader_role,
               m.uploaded_by
        FROM materials m
        JOIN users u ON m.uploaded_by = u.id
        WHERE m.lesson_id = ? ORDER BY m.created_at DESC";
    $stmt_materials = $conn->prepare($sql_materials);
    $stmt_materials->execute([$lesson_id]);
    $materials = $stmt_materials->fetchAll(PDO::FETCH_ASSOC);
    $stmt_materials = null;

    // Получение всех определений заданий для этого урока
    $sql_definitions = "SELECT * FROM assignment_definitions WHERE lesson_id = ? ORDER BY created_at ASC";
    $stmt_definitions = $conn->prepare($sql_definitions);
    $stmt_definitions->execute([$lesson_id]);
    $assignment_definitions = $stmt_definitions->fetchAll(PDO::FETCH_ASSOC);
    $stmt_definitions = null;

    // Получение всех сданных работ для всех определений этого урока
    $sql_submissions_base = "
        SELECT
            a.*, -- Все поля из assignments (id, student_id, file_path, status, feedback, grade, submitted_at, student_comment, ...)
            ad.title AS definition_title,
            ad.deadline,
            u.full_name as student_name
        FROM assignments a
        JOIN assignment_definitions ad ON a.assignment_definition_id = ad.id
        JOIN users u ON a.student_id = u.id
        WHERE ad.lesson_id = ?
    ";

    if ($role === 'student') {
        // Студент видит только свои сданные работы
        $sql_submissions = $sql_submissions_base . " AND a.student_id = ? ORDER BY ad.created_at ASC, a.submitted_at DESC";
        $stmt_submissions = $conn->prepare($sql_submissions);
        $stmt_submissions->execute([$lesson_id, $user_id]);
    } else { // Преподаватель и админ видят все
        $sql_submissions = $sql_submissions_base . " ORDER BY ad.created_at ASC, u.full_name ASC, a.submitted_at DESC";
        $stmt_submissions = $conn->prepare($sql_submissions);
        $stmt_submissions->execute([$lesson_id]);
    }
    $submissions = $stmt_submissions->fetchAll(PDO::FETCH_ASSOC);
    $stmt_submissions = null;

    // Группируем сданные работы для легкого доступа в шаблоне
    foreach ($submissions as $sub) {
        $def_id = $sub['assignment_definition_id'];
        $stud_id = $sub['student_id'];
        if (!isset($submissions_by_def_id[$def_id])) {
            $submissions_by_def_id[$def_id] = [];
        }
        $submissions_by_def_id[$def_id][$stud_id] = $sub; // Сохраняем всю информацию о сдаче
    }

    // Обработка POST-запросов
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? null;

        if ($action && $action !== 'send_message') { 
            $conn->beginTransaction();
            try {
                // Загрузка материала
                if ($action === 'upload_material' && $can_upload_material) {
                    $title = trim($_POST['title'] ?? ''); $description = trim($_POST['description'] ?? '');
                    if (empty($title)) { throw new Exception('Укажите название материала'); }
                    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { throw new Exception('Ошибка при загрузке файла материала: ' . get_upload_error_message($_FILES['file']['error'])); }
                    $target_dir_rel = 'materials/'; 
                    $target_dir_abs = '../uploads/' . $target_dir_rel; 
                    $uploaded_filename = handleFileUpload($_FILES['file'], $target_dir_abs, 'mat_', ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'gif'], 10 * 1024 * 1024);
                    if ($uploaded_filename) {
                         $file_path_db = $target_dir_rel . $uploaded_filename; 
                         $sql_insert_material = "INSERT INTO materials (lesson_id, title, description, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?)";
                         $stmt_insert_material = $conn->prepare($sql_insert_material);
                         if (!$stmt_insert_material->execute([$lesson_id, $title, $description, $file_path_db, $user_id])) {
                              @unlink($target_dir_abs . $uploaded_filename); throw new PDOException("Ошибка сохранения данных материала.");
                         }
                         $conn->commit(); $_SESSION['message'] = ['type' => 'success', 'text' => 'Материал успешно загружен.']; header("Location: " . BASE_URL . "pages/lesson.php?id=$lesson_id&tab=materials"); exit;
                     } else { throw new Exception("Ошибка обработки файла материала."); }
                }
                // Загрузка работы студента
                elseif ($action === 'upload_assignment' && $role === 'student') {
                     $assignment_definition_id = isset($_POST['assignment_definition_id']) ? (int)$_POST['assignment_definition_id'] : 0;
                     $student_comment = trim($_POST['student_comment'] ?? '');

                     if ($assignment_definition_id <= 0) { throw new Exception('Ошибка: Не указано, к какому заданию относится работа.'); }

                     // Проверяем, существует ли такое определение задания и принадлежит ли оно этому уроку
                     $stmt_check_def = $conn->prepare("SELECT deadline, allow_late_submissions FROM assignment_definitions WHERE id = ? AND lesson_id = ?");
                     $stmt_check_def->execute([$assignment_definition_id, $lesson_id]);
                     $definition_info = $stmt_check_def->fetch(PDO::FETCH_ASSOC);
                     if (!$definition_info) { throw new Exception('Ошибка: Указанное задание не найдено или не относится к этому уроку.'); }
                     $stmt_check_def = null;

                     // Проверка дедлайна
                     $deadline = $definition_info['deadline'];
                     $allow_late = (bool)$definition_info['allow_late_submissions'];
                     if ($deadline !== null) {
                          $now = new DateTime();
                          $deadline_dt = new DateTime($deadline);
                          if ($now > $deadline_dt && !$allow_late) {
                               throw new Exception('Срок сдачи этого задания истек (' . $deadline_dt->format('d.m.Y H:i') . '). Загрузка невозможна.');
                          }
                     }

                     // Проверка файла
                     if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { throw new Exception('Ошибка при загрузке файла работы: ' . get_upload_error_message($_FILES['file']['error'])); }

                     // Загрузка файла
                     $target_dir_rel = 'assignments/'; 
                     $target_dir_abs = '../uploads/' . $target_dir_rel; 
                     $uploaded_filename = handleFileUpload($_FILES['file'], $target_dir_abs, 'asgmt_', ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'gif'], 10 * 1024 * 1024);

                     if ($uploaded_filename) {
                         $file_path_db = $target_dir_rel . $uploaded_filename; 

                         // Проверяем, есть ли уже сдача по этому определению от этого студента
                         $stmt_check_existing = $conn->prepare("SELECT id FROM assignments WHERE assignment_definition_id = ? AND student_id = ?");
                         $stmt_check_existing->execute([$assignment_definition_id, $user_id]);
                         $existing_assignment_id = $stmt_check_existing->fetchColumn();
                         $stmt_check_existing = null;

                         if ($existing_assignment_id) {
                              $stmt_get_old_file = $conn->prepare("SELECT file_path FROM assignments WHERE id = ?");
                              $stmt_get_old_file->execute([$existing_assignment_id]);
                              $old_file_path_rel = $stmt_get_old_file->fetchColumn();
                              $stmt_get_old_file = null;

                             $sql_update_assignment = "UPDATE assignments
                                                       SET file_path = ?, student_comment = ?, submitted_at = NOW(), status = 'submitted', feedback = NULL, grade = NULL
                                                       WHERE id = ?";
                              $stmt_update_assignment = $conn->prepare($sql_update_assignment);
                              if (!$stmt_update_assignment->execute([$file_path_db, $student_comment, $existing_assignment_id])) {
                                   @unlink($target_dir_abs . $uploaded_filename); throw new PDOException("Ошибка обновления данных работы.");
                              }
                              // Удаляем старый файл после успешного обновления БД
                              if ($old_file_path_rel && file_exists('../' . $old_file_path_rel)) {
                                   @unlink('../' . $old_file_path_rel);
                              }
                              $message_text = 'Работа успешно перезагружена.';

                         } else {
                             $sql_insert_assignment = "INSERT INTO assignments (assignment_definition_id, student_id, student_comment, file_path, submitted_at, status) VALUES (?, ?, ?, ?, NOW(), 'submitted')";
                             $stmt_insert_assignment = $conn->prepare($sql_insert_assignment);
                             if (!$stmt_insert_assignment->execute([$assignment_definition_id, $user_id, $student_comment, $file_path_db])) {
                                  @unlink($target_dir_abs . $uploaded_filename); throw new PDOException("Ошибка сохранения данных работы.");
                              }
                               $message_text = 'Работа успешно загружена.';
                         }

                         $conn->commit();
                         $_SESSION['message'] = ['type' => 'success', 'text' => $message_text];
                         header("Location: " . BASE_URL . "pages/lesson.php?id=$lesson_id&tab=assignments"); exit;
                     } else { throw new Exception("Ошибка обработки файла работы."); }
                }
                // Проверка работы
                elseif ($action === 'review_assignment' && $can_review_assignment) {
                     $assignment_id = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
                     $status = $_POST['status'] ?? '';
                     $feedback = trim($_POST['feedback'] ?? '');
                     $grade_input = $_POST['grade'] ?? null;
                     $grade = (is_numeric($grade_input) && $grade_input !== '') ? (float)$grade_input : null;

                     if ($assignment_id <= 0) { throw new Exception('Не указан ID работы'); }
                     if (!in_array($status, ['submitted', 'reviewed', 'approved'])) { throw new Exception('Некорректный статус'); } 

                     // Дополнительная проверка: может ли текущий пользователь проверять эту работу
                     $stmt_check_review_access = $conn->prepare("SELECT 1 FROM assignments a JOIN assignment_definitions ad ON a.assignment_definition_id = ad.id WHERE a.id = ? AND ad.lesson_id = ?");
                     $stmt_check_review_access->execute([$assignment_id, $lesson_id]);
                     if (!$stmt_check_review_access->fetchColumn()) { throw new Exception("Попытка проверить работу, не относящуюся к этому уроку."); }
                     $stmt_check_review_access = null;

                     // Обновляем + добавляем graded_by и graded_at
                     $sql_review = "UPDATE assignments SET status = ?, feedback = ?, grade = ?, graded_by = ?, graded_at = NOW() WHERE id = ?";
                     $stmt_review = $conn->prepare($sql_review);
                     if (!$stmt_review->execute([$status, $feedback, $grade, $user_id, $assignment_id])) {
                         throw new PDOException("Ошибка сохранения проверки.");
                     }

                     if ($stmt_review->rowCount() > 0) {
                         $conn->commit();
                         $_SESSION['message'] = ['type' => 'success', 'text' => 'Проверка работы сохранена.'];
                         header("Location: " . BASE_URL . "pages/lesson.php?id=$lesson_id&tab=assignments"); exit;
                     } else {
                         $conn->commit();
                         $_SESSION['message'] = ['type' => 'info', 'text' => 'Данные проверки не изменились.'];
                         header("Location: " . BASE_URL . "pages/lesson.php?id=$lesson_id&tab=assignments"); exit;
                     }
                } else {
                     if ($action) { throw new Exception('Недостаточно прав или неизвестное действие: ' . htmlspecialchars($action)); }
                }
                // Если не было commit/exit до этого места, откатываем транзакцию
                 if ($conn->inTransaction()) { $conn->rollBack(); }
            } catch (PDOException | Exception $e) {
                if ($conn && $conn->inTransaction()) { $conn->rollBack(); }
                error_log("Lesson Action Error (lesson_id: $lesson_id, user_id: $user_id, action: $action): " . $e->getMessage());
                $_SESSION['message'] = ['type' => 'error', 'text' => "Произошла ошибка: " . $e->getMessage()];
                $redirect_tab = '';
                if ($action === 'upload_material') $redirect_tab = 'materials';
                elseif ($action === 'upload_assignment' || $action === 'review_assignment') $redirect_tab = 'assignments';
                header("Location: " . BASE_URL . "pages/lesson.php?id=$lesson_id" . ($redirect_tab ? "&tab=$redirect_tab" : ''));
                exit;
            }
        }
    } 

    // Получение сообщений чата
    $sql_chat = "SELECT m.id, m.message, m.created_at, m.edited_at,
                        u.id as user_id, u.full_name, u.role
                 FROM messages m
                 JOIN users u ON m.user_id = u.id
                 WHERE m.lesson_id = ? ORDER BY m.created_at ASC";
    $stmt_chat = $conn->prepare($sql_chat);
    $stmt_chat->execute([$lesson_id]);
    $messagesRaw = $stmt_chat->fetchAll(PDO::FETCH_ASSOC);
    $stmt_chat = null;
    
    // Подсчет непрочитанных сообщений и форматирование для вывода
    $messages = [];
    if ($has_access && $lesson_id > 0 && $user_id > 0) {
         // Получаем время последнего прочтения
         $stmt_read = $conn->prepare("SELECT last_read_at FROM chat_read_status WHERE user_id = ? AND lesson_id = ?");
         $stmt_read->execute([$user_id, $lesson_id]);
         $last_read_time_str = $stmt_read->fetchColumn();
         $last_read_time = $last_read_time_str ? new DateTime($last_read_time_str) : null;
         $stmt_read = null;

         foreach ($messagesRaw as $msg) {
             $msg['display_name'] = getShortName($msg['full_name']);
             $is_unread = false; // Флаг для непрочитанных
             if ($msg['user_id'] != $user_id) { // Не свои сообщения
                 $message_time = new DateTime($msg['created_at']);
                 if (!$last_read_time || $message_time > $last_read_time) {
                      $unread_message_count++;
                      $is_unread = true;
                      if ($first_unread_message_id === null) {
                           $first_unread_message_id = $msg['id'];
                      }
                 }
             }
             $msg['is_unread'] = $is_unread; // Добавляем флаг к сообщению
             $messages[] = $msg; // Добавляем отформатированное сообщение в итоговый массив
         }
    } else { // Если нет доступа, просто форматируем
        foreach ($messagesRaw as $msg) {
            $msg['display_name'] = getShortName($msg['full_name']);
            $msg['is_unread'] = false;
            $messages[] = $msg;
        }
    }

} catch (PDOException $e) {
    error_log("Database Error on lesson page load (lesson_id: $lesson_id, user_id: $user_id): " . $e->getMessage());
    $fatal_error_message = "Произошла ошибка базы данных при загрузке данных занятия.";
    // Передаем сообщение через сессию для отображения
    $_SESSION['message'] = ['type' => 'error', 'text' => $fatal_error_message . " Занятие ID: " . $lesson_id];
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit();
} catch (Exception $e) { // Ловим другие возможные исключения
     error_log("General Error on lesson page load (lesson_id: $lesson_id, user_id: $user_id): " . $e->getMessage());
     $fatal_error_message = "Произошла внутренняя ошибка при загрузке страницы занятия.";
     $_SESSION['message'] = ['type' => 'error', 'text' => $fatal_error_message];
     header('Location: ' . BASE_URL . 'pages/dashboard.php');
     exit();
} finally {
    $conn = null;
}

// Получение имени текущего пользователя для JS 
$currentUserFullName = $_SESSION['full_name'] ?? 'Пользователь'; // Берем из сессии, если доступно

$page_title = ($lesson ? htmlspecialchars($lesson['title']) : 'Занятие') . " - Доска занятия";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'lesson-page app-page';
$load_notifications_css = true;
$load_lesson_page_css = true;
$load_chat_css = true;    

// JS для страницы урока
$page_specific_js = '
    <script>
        const lessonConfig = {
            lessonId: ' . json_encode($lesson_id) . ',
            userId: ' . json_encode($user_id) . ',
            userFullName: ' . json_encode($currentUserFullName) . ',
            userRole: ' . json_encode($role) . ',
            apiChatUrl: ' . json_encode(BASE_URL . 'api/chat.php') . ',
            baseUrl: ' . json_encode(BASE_URL) . ',
            canEditOwnMessages: true, canDeleteOwnMessages: true,
            canEditAnyMessage: ' . json_encode($role === 'admin' || $role === 'teacher') . ',
            canDeleteAnyMessage: ' . json_encode($role === 'admin' || $role === 'teacher') . ',
            unreadMessages: {
                count: ' . json_encode($unread_message_count) . ',
                firstId: ' . json_encode($first_unread_message_id) . '
            }
            // csrfToken: "..." 
        };
    </script>
    <script src="' . BASE_URL . 'assets/js/lesson.js?v=' . time() . '" defer></script>
    <script src="' . BASE_URL . 'assets/js/chat.js?v=' . time() . '" defer></script>
';

ob_start();
?>

<?php if ($lesson): // Только если урок успешно загружен, показываем основной контент ?>
    <div class="container lesson-page-content py-4">

        <!-- Шапка урока -->
        <div class="card lesson-header-card mb-4 shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start">
                    <div class="lesson-info mb-3 mb-md-0">
                        <h1 class="h2 card-title mb-1"><?php echo htmlspecialchars($lesson['title']); ?></h1>
                        <p class="lesson-meta text-muted mb-1">
                            <i class="fas fa-users me-1"></i>Группа:
                            <a href="<?php echo BASE_URL; ?>pages/teacher_group_view.php?group_id=<?php echo $lesson['group_id']; ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($lesson['group_name']); ?>
                            </a>
                            <?php if($lesson['subject_name']): ?>
                                <span class="mx-2">|</span><i class="fas fa-book me-1"></i>Дисциплина: <?php echo htmlspecialchars($lesson['subject_name']); ?>
                            <?php endif; ?>
                            <span class="mx-2">|</span><i class="far fa-calendar-alt me-1"></i>Дата: <?php echo htmlspecialchars(format_ru_datetime($lesson['lesson_date'])); ?>
                        </p>
                        <?php if (!empty($lesson['description'])): ?>
                            <p class="lesson-description small text-muted mb-0"><?php echo nl2br(htmlspecialchars($lesson['description'])); ?></p>
                        <?php endif; ?>
                    </div>
                   <div class="lesson-actions d-flex flex-column align-items-stretch align-items-md-end gap-2 ms-md-3 flex-shrink-0" style="min-width: 180px;">
                    <?php
                        // Общие значения по умолчанию
                        $final_back_link_url = BASE_URL . 'pages/dashboard.php';
                        $final_back_link_text = '← На дашборд';    

                        if ($role === 'student') {
                            $final_back_link_url = BASE_URL . 'pages/student_dashboard.php';
                            $final_back_link_text = '← К расписанию';

                            // Проверяем, был ли передан параметр для возврата на конкретную неделю
                            if (isset($_GET['return_week_start']) && !empty($_GET['return_week_start'])) {
                                // Простая валидация формата YYYY-MM-DD
                                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['return_week_start'])) {
                                    // Добавляем параметр start_date к URL
                                    $final_back_link_url .= '?start_date=' . htmlspecialchars($_GET['return_week_start']);
                                }
                            }

                        } elseif ($role === 'teacher') {
                            $final_back_link_url = BASE_URL . 'pages/dashboard.php';
                            $final_back_link_text = '← К расписанию'; 
                            if (isset($_GET['return_week_start']) && !empty($_GET['return_week_start'])) {
                                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['return_week_start'])) {
                                    $final_back_link_url .= '?start_date=' . htmlspecialchars($_GET['return_week_start']);
                                    
                                }
                            }

                        } elseif ($role === 'admin') {
                            if (isset($_GET['return_week_start']) && !empty($_GET['return_week_start']) && isset($_GET['return_group_id'])) {
                                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['return_week_start'])) {
                                    $final_back_link_url = BASE_URL . 'pages/dashboard.php?group_id=' . (int)$_GET['return_group_id'] . '&start_date=' . htmlspecialchars($_GET['return_week_start']);
                                    $final_back_link_text = '← К расписанию группы';
                                }
                            } elseif (isset($lesson_data['group_id']) && $lesson_data['group_id'] > 0) {
                                $final_back_link_url = BASE_URL . 'pages/dashboard.php?group_id=' . $lesson_data['group_id'];
                                $final_back_link_text = '← К расписанию группы урока';
                            } else {
                                $final_back_link_url = BASE_URL . 'pages/dashboard.php';
                                $final_back_link_text = '← В админ-панель';
                            }
                        }
                    ?>
                    <a href="<?php echo $final_back_link_url; ?>" class="btn btn-sm btn-outline-secondary w-100"><?php echo $final_back_link_text; ?></a>
                    <?php if ($has_access_to_edit_lesson):?>
                            <a href="<?php echo BASE_URL; ?>pages/edit_lesson.php?id=<?php echo $lesson_id; ?>" class="btn btn-sm btn-primary w-100"><i class="fas fa-edit me-1"></i>Редактировать урок</a>
                        <?php endif; ?>
                        <?php if ($has_access_to_manage_assignments):?>
                            <a href="<?php echo BASE_URL; ?>pages/manage_lesson_assignments.php?lesson_id=<?php echo $lesson_id; ?>" class="btn btn-sm btn-success w-100">
                                <i class="fas fa-tasks me-1"></i> Управлять заданиями
                            </a>
                        <?php endif; ?>
                </div>
                </div>
            </div>
        </div>

        <?php // Сообщения об ошибках/успехе ?>
        <?php if ($page_flash_message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo htmlspecialchars($page_flash_message['text']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Навигация по вкладкам -->
        <ul class="nav nav-tabs nav-fill mb-3" id="lessonTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="materials-tab" data-bs-toggle="tab" data-bs-target="#materials-pane" type="button" role="tab" aria-controls="materials-pane" aria-selected="true">
                    <i class="fas fa-folder-open me-1"></i>Материалы
                    <span class="badge rounded-pill bg-secondary ms-1"><?php echo count($materials); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="assignments-tab" data-bs-toggle="tab" data-bs-target="#assignments-pane" type="button" role="tab" aria-controls="assignments-pane" aria-selected="false">
                    <i class="fas fa-clipboard-list me-1"></i>Задания
                    <span class="badge rounded-pill bg-secondary ms-1"><?php echo count($assignment_definitions); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link position-relative" id="chat-tab" data-bs-toggle="tab" data-bs-target="#chat-pane" type="button" role="tab" aria-controls="chat-pane" aria-selected="false">
                    <i class="fas fa-comments me-1"></i>Чат
                    <span id="chat-tab-message-count" class="badge rounded-pill bg-primary ms-1 <?php echo $unread_message_count > 0 ? '' : 'd-none'; ?>">
                        <?php echo $unread_message_count; ?>
                    </span>
                </button>
            </li>
        </ul>

        <!-- Контент вкладок -->
        <div class="tab-content pt-3" id="lessonTabContent">

            <!-- Кладка материалы -->
            <div class="tab-pane fade show active" id="materials-pane" role="tabpanel" aria-labelledby="materials-tab" tabindex="0">
            <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h4 mb-0">Материалы занятия</h2>
                    <?php if ($can_upload_material): ?>
                        <button type="button" class="btn btn-primary" id="add-material-btn"><i class="fas fa-plus me-1"></i>Добавить материал</button>
                    <?php endif; ?>
                </div>

                <?php if ($can_upload_material): ?>
                    <div class="card mb-4 p-3" id="material-form" style="display: none; background-color: var(--bs-tertiary-bg, #f8f9fa);">
                        <h3 class="h5 mb-3">Загрузка нового материала</h3>
                        <form method="POST" enctype="multipart/form-data" action="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $lesson_id; ?>">
                            <input type="hidden" name="action" value="upload_material">
                            <div class="mb-3">
                                <label for="mat_title" class="form-label">Название <span class="text-danger">*</span></label>
                                <input type="text" id="mat_title" name="title" required class="form-control">
                            </div>
                            <div class="mb-3">
                                <label for="mat_description" class="form-label">Описание</label>
                                <textarea id="mat_description" name="description" rows="3" class="form-control"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="mat_file" class="form-label">Файл <span class="text-danger">*</span></label>
                                <input type="file" id="mat_file" name="file" required class="form-control">
                                <div class="form-text">Макс. размер: 10MB. Разрешенные типы: pdf, doc, docx, ppt, pptx, xls, xlsx, txt, zip, rar, jpg, jpeg, png, gif.</div>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="submit" class="btn btn-success">Загрузить</button>
                                <button type="button" class="btn btn-secondary" id="cancel-material-btn">Отмена</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (empty($materials)): ?>
                    <div class="card card-body text-center text-muted py-5">
                        <p class="mb-0 fs-5"><i class="fas fa-folder-minus fa-2x mb-3 d-block"></i>Материалы к этому занятию еще не добавлены.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($materials as $material): ?>
                            <div class="list-group-item list-group-item-action flex-column align-items-start">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1 h6"><i class="fas <?php echo get_file_icon_class($material['file_path']); ?> me-2"></i><?php echo htmlspecialchars($material['title']); ?></h5>
                                    <small class="text-muted" title="<?php echo htmlspecialchars($material['created_at']); ?>"><?php echo htmlspecialchars(format_ru_datetime_short($material['created_at'])); ?></small>
                                </div>
                                <?php if (!empty($material['description'])): ?>
                                    <p class="mb-1 small text-muted"><?php echo nl2br(htmlspecialchars($material['description'])); ?></p>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <small class="text-muted">Загрузил: <?php echo htmlspecialchars($material['uploaded_by_name']); ?></small>
                                    <div>
                                        <a href="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($material['file_path']); ?>" class="btn btn-sm btn-outline-primary me-2" target="_blank" download title="Скачать <?php echo htmlspecialchars(basename($material['file_path'])); ?>">
                                            <i class="fas fa-download me-1"></i>Скачать
                                        </a>
                                        <?php if ($role === 'admin' || ($role === 'teacher' && $has_access && $user_id === (int)$material['uploaded_by'])): ?>
                                            <a href="<?php echo BASE_URL; ?>actions/delete_item.php?type=material&id=<?php echo $material['id']; ?>&lesson_id=<?php echo $lesson_id; ?>&confirm=yes"
                                               class="btn btn-sm btn-outline-danger delete-item-btn"
                                               data-item-name="<?php echo htmlspecialchars($material['title']); ?>"
                                               title="Удалить материал">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Вкладка задания и работы -->
            <div class="tab-pane fade" id="assignments-pane" role="tabpanel" aria-labelledby="assignments-tab" tabindex="0">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h4 mb-0">Задания к уроку</h2>
                     <?php if ($role === 'admin' || ($role === 'teacher' && $has_access)): ?>
                         <a href="<?php echo BASE_URL; ?>pages/manage_lesson_assignments.php?lesson_id=<?php echo $lesson_id; ?>" class="btn btn-success"><i class="fas fa-plus me-1"></i> Добавить задание</a>
                     <?php endif; ?>
                </div>
                <?php if (empty($assignment_definitions)): ?>
                    <div class="card card-body text-center text-muted py-5">
                        <p class="mb-0 fs-5"><i class="fas fa-tasks fa-2x mb-3 d-block"></i>К этому уроку пока нет определенных заданий.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($assignment_definitions as $definition): ?>
                        <div class="card mb-4 assignment-definition-item shadow-sm" id="assignment-def-<?php echo $definition['id']; ?>">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h3 class="h5 mb-0"><?php echo htmlspecialchars($definition['title']); ?></h3>
                                <?php if ($role === 'admin' || ($role === 'teacher' && $has_access)):?>
                                     <div>
                                         <a href="<?php echo BASE_URL; ?>pages/manage_lesson_assignments.php?lesson_id=<?php echo $lesson_id; ?>&edit_definition_id=<?php echo $definition['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Редактировать определение">
                                              <i class="fas fa-edit"></i>
                                         </a>
                                     </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($definition['description'])): ?>
                                    <p class="mb-2"><?php echo nl2br(htmlspecialchars($definition['description'])); ?></p>
                                <?php endif; ?>
                                <p class="small text-muted">
                                    <?php if ($definition['deadline']): ?>
                                        <strong>Срок сдачи:</strong> <?php echo htmlspecialchars(format_ru_datetime($definition['deadline'])); ?>
                                        <span class="badge <?php echo $definition['allow_late_submissions'] ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis'; ?> ms-2">
                                            <?php echo $definition['allow_late_submissions'] ? 'Поздняя сдача разрешена' : 'Поздняя сдача запрещена'; ?>
                                        </span>
                                    <?php else: ?>
                                        Срок сдачи не установлен.
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($definition['file_path'])): ?>
                                    <p class="small">
                                        <a href="<?php echo BASE_URL . htmlspecialchars($definition['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-info" download>
                                            <i class="fas fa-file-download me-1"></i> Скачать файл задания
                                        </a>
                                    </p>
                                <?php endif; ?>
                                <hr>
                                <!-- Отображение для студента -->
                            <?php if ($role === 'student'): ?>
                                <?php
                                    // Ищем сдачу текущего студента для текущего определения
                                    $my_submission = $submissions_by_def_id[$definition['id']][$user_id] ?? null;
                                ?>
                                <?php if ($my_submission): // Если студент уже сдал работу ?>
                                    <div class="my-submission-info">
                                        <h4 class="h6 text-success"><i class="fas fa-check-circle"></i> Ваша работа сдана</h4>
                                         <p class="small mb-1">
                                             <i class="fas fa-file-alt"></i> Файл:
                                             <a href="<?php echo BASE_URL . htmlspecialchars($my_submission['file_path']); ?>" target="_blank" download>
                                                  <?php echo basename($my_submission['file_path']); ?>
                                             </a>
                                         </p>
                                         <p class="small mb-1">
                                              <i class="fas fa-clock"></i> Время сдачи: <?php echo format_ru_datetime($my_submission['submitted_at']); ?>
                                              <?php
                                                   $late_status_html = '';
                                                   if ($definition['deadline']) {
                                                       $submitted_dt = new DateTime($my_submission['submitted_at']);
                                                       $deadline_dt = new DateTime($definition['deadline']);
                                                       if ($submitted_dt > $deadline_dt) {
                                                           $late_status_html = '<span class="status-late ms-2">(С опозданием)</span>';
                                                       } else {
                                                            $late_status_html = '<span class="status-on-time ms-2">(Вовремя)</span>';
                                                       }
                                                   }
                                                   echo $late_status_html;
                                              ?>
                                         </p>
                                         <p class="small mb-1">
                                             <i class="fas fa-tasks"></i> Статус:
                                             <span class="status-<?php echo htmlspecialchars($my_submission['status']); ?>">
                                                <?php switch ($my_submission['status']) { case 'submitted': echo 'На проверке'; break; case 'reviewed': echo 'Проверено'; break; case 'approved': echo 'Одобрено'; break; default: echo htmlspecialchars($my_submission['status']); } ?>
                                             </span>
                                         </p>
                                         <?php if (!empty($my_submission['student_comment'])): ?>
                                              <p class="small mb-1 fst-italic">Ваш комментарий: "<?php echo htmlspecialchars($my_submission['student_comment']); ?>"</p>
                                         <?php endif; ?>
                                          <?php if ($my_submission['status'] !== 'submitted' && !empty($my_submission['feedback'])): ?>
                                               <div class="assignment-feedback small mt-2 p-2 bg-light border rounded">
                                                    <strong><i class="fas fa-comment-dots"></i> Отзыв преподавателя:</strong>
                                                    <p class="mb-0 mt-1"><?php echo nl2br(htmlspecialchars($my_submission['feedback'])); ?></p>
                                               </div>
                                          <?php endif; ?>
                                          <?php if ($my_submission['status'] !== 'submitted' && $my_submission['grade'] !== null): ?>
                                              <p class="small mb-1"><strong><i class="fas fa-graduation-cap"></i> Оценка:</strong> <?php echo htmlspecialchars((string)$my_submission['grade']); ?></p>
                                          <?php endif; ?>

                                          <!-- Возможность пересдать или удалить -->
                                          <div class="student-actions mt-2">
                                               <?php $can_resubmit = true;
                                                    if ($can_resubmit) {
                                                         echo '<button type="button" class="btn btn-sm btn-outline-secondary student-upload-btn" data-def-id="' . $definition['id'] . '"><i class="fas fa-redo"></i> Пересдать</button>';
                                                    }
                                               ?>
                                              <?php if ($my_submission['status'] === 'submitted'): ?>
                                                  <a href="<?php echo BASE_URL; ?>actions/delete_item.php?type=assignment&id=<?php echo $my_submission['id']; ?>&lesson_id=<?php echo $lesson_id; ?>&confirm=yes" class="btn btn-danger btn-sm ms-2" onclick="return confirm('Уверены, что хотите удалить эту работу?')"> <i class="fas fa-trash"></i> Удалить </a>
                                              <?php endif; ?>
                                          </div>
                                    </div>
                                <?php else: // Если студент еще не сдал работу по этому определению ?>
                                    <button type="button" class="btn btn-success student-upload-btn" data-def-id="<?php echo $definition['id']; ?>">
                                        <i class="fas fa-upload"></i> Сдать работу
                                    </button>
                                <?php endif; ?>

                                <!-- Форма загрузки для студента  -->
                                <div class="upload-form card mt-2 student-upload-form" id="student-upload-form-<?php echo $definition['id']; ?>" style="display: none; background-color:#e9f5ff;">
                                     <h4 class="h6">Загрузка работы для: "<?php echo htmlspecialchars($definition['title']); ?>"</h4>
                                     <form method="POST" enctype="multipart/form-data" action="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $lesson_id; ?>">
                                          <input type="hidden" name="action" value="upload_assignment">
                                          <input type="hidden" name="assignment_definition_id" value="<?php echo $definition['id']; ?>">
                                          <div class="mb-2">
                                               <label for="student_comment_<?php echo $definition['id']; ?>" class="form-label form-label-sm">Комментарий (опционально)</label>
                                               <textarea id="student_comment_<?php echo $definition['id']; ?>" name="student_comment" rows="2" class="form-control form-control-sm"></textarea>
                                          </div>
                                          <div class="mb-2">
                                               <label for="assign_file_<?php echo $definition['id']; ?>" class="form-label form-label-sm">Файл работы <span class="required">*</span></label>
                                               <input type="file" id="assign_file_<?php echo $definition['id']; ?>" name="file" required class="form-control form-control-sm">
                                               <small class="text-muted d-block">Макс. 10MB</small>
                                          </div>
                                          <div class="form-actions">
                                               <button type="submit" class="btn btn-primary btn-sm">Загрузить</button>
                                               <button type="button" class="btn btn-secondary btn-sm cancel-student-upload-btn" data-def-id="<?php echo $definition['id']; ?>">Отмена</button>
                                          </div>
                                     </form>
                                </div>

                            <!-- Отображение для преподавателя/админа -->
                            <?php elseif ($role === 'teacher' || $role === 'admin'): ?>
                                <div class="submissions-for-definition">
                                    <h4 class="h6 mt-2">Сданные работы студентов:</h4>
                                    <?php
                                        // Получаем все сдачи для текущего определения
                                        $current_definition_submissions = $submissions_by_def_id[$definition['id']] ?? [];
                                    ?>
                                    <?php if (empty($current_definition_submissions)): ?>
                                        <p class="text-muted small">По этому заданию пока нет сданных работ.</p>
                                    <?php else: ?>
                                        <?php foreach ($current_definition_submissions as $student_id => $submission): ?>
                                            <div class="submission-item card mb-2 p-2" id="submission-<?php echo $submission['id']; ?>">
                                                 <div class="d-flex justify-content-between align-items-center">
                                                      <div>
                                                          <p class="mb-0 small">
                                                               <i class="fas fa-user"></i> <strong>Студент:</strong> <a href="<?php echo BASE_URL; ?>pages/profile.php?id=<?php echo $student_id; ?>"><?php echo htmlspecialchars($submission['student_name']); ?></a>
                                                          </p>
                                                          <p class="mb-0 small text-muted">
                                                               <i class="fas fa-clock"></i> Сдано: <?php echo format_ru_datetime($submission['submitted_at']); ?>
                                                               <?php
                                                                   $late_status_html = '';
                                                                   if ($definition['deadline']) {
                                                                       $submitted_dt = new DateTime($submission['submitted_at']);
                                                                       $deadline_dt = new DateTime($definition['deadline']);
                                                                       if ($submitted_dt > $deadline_dt) { $late_status_html = '<span class="status-late ms-2">(С опозданием)</span>'; }
                                                                       else { $late_status_html = '<span class="status-on-time ms-2">(Вовремя)</span>'; }
                                                                   }
                                                                   echo $late_status_html;
                                                               ?>
                                                          </p>
                                                           <p class="mb-0 small">
                                                              <i class="fas fa-tasks"></i> Статус:
                                                              <span class="status-<?php echo htmlspecialchars($submission['status']); ?>">
                                                                <?php switch ($submission['status']) { case 'submitted': echo 'На проверке'; break; case 'reviewed': echo 'Проверено'; break; case 'approved': echo 'Одобрено'; break; default: echo htmlspecialchars($submission['status']); } ?>
                                                              </span>
                                                              <?php if ($submission['grade'] !== null): ?>
                                                                  <span class="ms-2"><i class="fas fa-graduation-cap"></i> Оценка: <?php echo htmlspecialchars((string)$submission['grade']); ?></span>
                                                              <?php endif; ?>
                                                           </p>
                                                            <?php if (!empty($submission['student_comment'])): ?>
                                                               <p class="small mb-0 fst-italic mt-1">Комментарий студента: "<?php echo htmlspecialchars($submission['student_comment']); ?>"</p>
                                                            <?php endif; ?>
                                                      </div>
                                                      <div class="submission-actions d-flex gap-2 align-items-center">
                                                          <a href="<?php echo BASE_URL . htmlspecialchars($submission['file_path']); ?>" class="btn btn-outline-primary btn-sm" target="_blank" download title="Скачать работу <?php echo basename($submission['file_path']);?>"> <i class="fas fa-download"></i> Скачать </a>
                                                          <?php if ($can_review_assignment): ?>
                                                               <button type="button" class="btn btn-outline-secondary btn-sm review-btn" data-id="<?php echo $submission['id']; ?>"> <i class="fas fa-edit"></i> Проверить </button>
                                                          <?php endif; ?>
                                                          <?php if ($role === 'admin'):?>
                                                               <a href="<?php echo BASE_URL; ?>actions/delete_item.php?type=assignment&id=<?php echo $submission['id']; ?>&lesson_id=<?php echo $lesson_id; ?>&confirm=yes" class="btn btn-outline-danger btn-sm" onclick="return confirm('АДМИН: Удалить работу студента <?php echo htmlspecialchars(addslashes($submission['student_name'])); ?> (ID: <?php echo $submission['id']; ?>)?');" title="Удалить работу (Админ)"> <i class="fas fa-trash-alt"></i></a>
                                                          <?php endif; ?>
                                                      </div>
                                                 </div>

                                                <?php // Форма проверки ?>
                                                <?php if ($can_review_assignment): ?>
                                                <div class="review-form card mt-2" id="review-form-<?php echo $submission['id']; ?>" style="display: none; padding: 1rem; border: 1px solid #eee; background-color:#f8f9fa;">
                                                      <h5 class="h6">Проверка работы: "<?php echo htmlspecialchars($submission['definition_title']); ?>" (<?php echo htmlspecialchars($submission['student_name']); ?>)</h5>
                                                      <form method="POST" action="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $lesson_id; ?>">
                                                           <input type="hidden" name="action" value="review_assignment">
                                                           <input type="hidden" name="assignment_id" value="<?php echo $submission['id']; ?>">
                                                           <div class="mb-2"> <label for="status-<?php echo $submission['id']; ?>" class="form-label form-label-sm">Статус</label> <select id="status-<?php echo $submission['id']; ?>" name="status" class="form-select form-select-sm"> <option value="submitted" <?php if ($submission['status'] === 'submitted') echo 'selected'; ?>>На проверке</option> <option value="reviewed" <?php if ($submission['status'] === 'reviewed') echo 'selected'; ?>>Проверено</option> <option value="approved" <?php if ($submission['status'] === 'approved') echo 'selected'; ?>>Одобрено</option> </select> </div>
                                                           <div class="mb-2"> <label for="feedback-<?php echo $submission['id']; ?>" class="form-label form-label-sm">Отзыв</label> <textarea id="feedback-<?php echo $submission['id']; ?>" name="feedback" rows="2" class="form-control form-control-sm"><?php echo htmlspecialchars($submission['feedback'] ?? ''); ?></textarea> </div>
                                                           <div class="mb-2"> <label for="grade-<?php echo $submission['id']; ?>" class="form-label form-label-sm">Оценка (0-100)</label> <input type="number" id="grade-<?php echo $submission['id']; ?>" name="grade" min="0" max="100" step="1" value="<?php echo htmlspecialchars((string)($submission['grade'] ?? '')); ?>" class="form-control form-control-sm"> </div>
                                                           <div class="form-actions"> <button type="submit" class="btn btn-primary btn-sm">Сохранить</button> <button type="button" class="btn btn-secondary btn-sm cancel-review-btn" data-id="<?php echo $submission['id']; ?>">Отмена</button> </div>
                                                      </form>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif;?>
                        </div> 
                    </div> 
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Вкладка чат -->
            <div class="tab-pane fade" id="chat-pane" role="tabpanel" aria-labelledby="chat-tab" tabindex="0">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h4 mb-0">Чат занятия</h2>
                </div>
                <div class="chat-container card shadow-sm">
                    <div id="unread-indicator" class="alert alert-info py-1 px-2 small rounded-0 border-0 border-bottom text-center" style="display: none; cursor: pointer;" role="button" title="Перейти к новым сообщениям">
                        <i class="fas fa-angles-down me-1"></i> <span id="unread-count-text">0 новых сообщений</span> <i class="fas fa-angles-down ms-1"></i>
                    </div>
                    <div class="list-group list-group-flush chat-messages p-3" id="chat-messages-list" style="min-height: 300px; max-height: 50vh; overflow-y: auto;">
                        <?php if (empty($messages)): ?>
                            <div class="list-group-item text-center text-muted border-0 py-5">
                                <i class="far fa-comments fa-3x mb-3 d-block"></i>Сообщений пока нет. Начните общение!
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): ?>
                                <div class="list-group-item message-item border-0 mb-2 p-2 rounded shadow-sm
                                    <?php echo ($msg['user_id'] === $user_id) ? 'message-own align-self-end text-end' : 'message-other align-self-start'; ?>"
                                     style="max-width: 75%; background-color: <?php echo ($msg['user_id'] === $user_id) ? 'var(--bs-primary-bg-subtle, #cfe2ff)' : 'var(--bs-light-bg-subtle, #f8f9fa)'; ?>;"
                                     data-message-id="<?php echo $msg['id']; ?>"
                                     data-author-id="<?php echo $msg['user_id']; ?>"
                                     data-created-at="<?php echo htmlspecialchars($msg['created_at']);?>"
                                     data-edited-at="<?php echo htmlspecialchars($msg['edited_at'] ?? ''); ?>">

                                    <div class="d-flex <?php echo ($msg['user_id'] === $user_id) ? 'flex-row-reverse' : ''; ?> justify-content-between small mb-1">
                                        <span class="message-author fw-bold <?php echo ($msg['user_id'] === $user_id) ? 'text-primary' : ''; ?>">
                                            <?php echo htmlspecialchars($msg['display_name']); ?>
                                            <?php if ($msg['role'] === 'teacher'): ?> <span class="badge bg-info-subtle text-info-emphasis rounded-pill fw-normal py-1">Преп.</span><?php endif; ?>
                                            <?php if ($msg['role'] === 'admin'): ?> <span class="badge bg-danger-subtle text-danger-emphasis rounded-pill fw-normal py-1">Админ</span><?php endif; ?>
                                        </span>
                                        <span class="message-time text-muted ms-2 me-2" title="<?php echo htmlspecialchars($msg['created_at']); ?>">
                                            <?php echo htmlspecialchars(format_ru_datetime_short($msg['created_at'])); ?>
                                        </span>
                                    </div>
                                    <div class="message-content">
                                        <p class="chat-message-text mb-0" style="white-space: pre-wrap; word-break: break-word;"><?php echo nl2br(htmlspecialchars($msg['message']));?></p>
                                        <?php if($msg['edited_at']): ?>
                                            <small class="chat-edited-indicator text-muted fst-italic d-block <?php echo ($msg['user_id'] === $user_id) ? '' : 'text-start'; ?>">(изменено)</small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="chat-message-actions <?php echo ($msg['user_id'] === $user_id) ? 'text-start' : 'text-end'; ?> mt-1" style="font-size: 0.8em; opacity: 0.7;">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div id="chat-end" style="height: 1px;"></div>
                    </div>
                    <div class="chat-form card-footer p-2 border-top-0">
                        <form id="chat-form" class="d-flex gap-2">
                            <textarea id="chat-message-input" name="message" placeholder="Введите сообщение..." required rows="1" class="form-control form-control-sm" style="resize: none;"></textarea>
                            <button type="submit" class="btn btn-primary btn-sm px-3" title="Отправить (Ctrl+Enter)">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div> 
    </div>
<?php else: ?>
    <?php if (empty($page_flash_message)): ?>
        <div class="container py-4">
            <div class="alert alert-danger">
                Не удалось загрузить данные занятия. Возможно, оно было удалено или у вас нет к нему доступа.
                <?php if (!empty($fatal_error_message)) echo "<br>Детали: " . htmlspecialchars($fatal_error_message); ?>
            </div>
            <a href="<?php echo BASE_URL; ?>pages/dashboard.php" class="btn btn-primary">Вернуться на дашборд</a>
        </div>
    <?php endif; ?>
<?php endif;?>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>