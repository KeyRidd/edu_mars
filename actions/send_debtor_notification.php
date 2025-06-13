<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!defined('BASE_URL')) define('BASE_URL', '/project/');
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
if (!defined('CONFIG_PATH')) define('CONFIG_PATH', ROOT_PATH . '/config/');
if (!defined('INCLUDES_PATH')) define('INCLUDES_PATH', ROOT_PATH . '/includes/');

require_once CONFIG_PATH . 'database.php';
require_once INCLUDES_PATH . 'functions.php';
require_once INCLUDES_PATH . 'auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Проверка метода и действия
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action_type']) || $_POST['action_type'] !== 'send_debtor_notification') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Некорректный запрос.'];
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? BASE_URL . 'pages/teacher_debtors.php'));
    exit;
}

// Проверка авторизации и роли преподавателя
if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен.'];
    $fallback_url = is_logged_in() ? BASE_URL . 'pages/dashboard.php' : BASE_URL . 'pages/login.php';
    header('Location: ' . $fallback_url);
    exit;
}
$teacher_id = $_SESSION['user_id'];

// Получение и валидация данных из POST
$student_ids_raw = $_POST['student_ids'] ?? [];
$subject_id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
$group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
$notification_title = trim($_POST['notification_title'] ?? 'Уведомление о задолженности');
$notification_message = trim($_POST['notification_message_template'] ?? '');

$student_ids = [];
if (is_array($student_ids_raw)) {
    foreach ($student_ids_raw as $id) {
        if (filter_var($id, FILTER_VALIDATE_INT) && (int)$id > 0) {
            $student_ids[] = (int)$id;
        }
    }
}

$errors_for_flash = [];
if (empty($student_ids)) {
    $errors_for_flash[] = 'Не выбраны студенты для отправки уведомления.';
}
if ($subject_id <= 0) { 
    $errors_for_flash[] = 'Не указана дисциплина для контекста уведомления.';
}

if (empty($notification_title)) {
    $errors_for_flash[] = 'Заголовок уведомления не может быть пустым.';
}
if (empty($notification_message)) { 
    $errors_for_flash[] = 'Текст сообщения не может быть пустым.';
}

$redirect_url_with_params = BASE_URL . 'pages/teacher_debtors.php' . ($group_id > 0 ? '?group_id='.$group_id : '') . ($subject_id > 0 ? ($group_id > 0 ? '&' : '?').'subject_id='.$subject_id : '');

if (!empty($errors_for_flash)) {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => implode("<br>", $errors_for_flash)];
    $_SESSION['form_data_debtor_notification'] = $_POST;
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? $redirect_url_with_params));
    exit;
}

$conn = null;
$notifications_sent_count = 0;

try {
    $conn = getDbConnection();

    // Получаем название предмета 
    $subject_name_for_context = "[Дисциплина ID:{$subject_id}]"; // Значение по умолчанию
    if ($subject_id > 0) {
        $stmt_subj_name = $conn->prepare("SELECT name FROM subjects WHERE id = ?");
        $stmt_subj_name->execute([$subject_id]);
        $subj_name_res = $stmt_subj_name->fetchColumn();
        if ($subj_name_res) {
            $subject_name_for_context = htmlspecialchars($subj_name_res);
        }
    }

    $sql_insert_notify = "INSERT INTO notifications (user_id, sender_id, type, title, message, related_url, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
    $stmt_notify = $conn->prepare($sql_insert_notify);
    // Ссылка может вести на страницу заданий студента или на страницу предмета
    $related_url = BASE_URL . "pages/student_tasks.php?subject_id=" . $subject_id;
    $notification_type = 'debt_notification_custom';

    $conn->beginTransaction();
    foreach ($student_ids as $student_id_to_notify) {
        if ($stmt_notify->execute([$student_id_to_notify, $teacher_id, $notification_type, $notification_title, $notification_message, $related_url])) {
            $notifications_sent_count++;
        } else {
            error_log("Failed to insert notification for student ID: {$student_id_to_notify}. Error: " . implode(";", $stmt_notify->errorInfo()));
        }
    }
    $conn->commit();

    if ($notifications_sent_count > 0) {
        $_SESSION['message_flash'] = ['type' => 'success', 'text' => "Уведомления успешно отправлены {$notifications_sent_count} студентам."];
    } else {
        $_SESSION['message_flash'] = ['type' => 'info', 'text' => "Не удалось отправить уведомления или не было выбрано студентов."];
    }

} catch (Exception $e) {
    if ($conn && $conn->inTransaction()) { $conn->rollBack(); }
    error_log("Error sending debtor notifications: " . $e->getMessage() . " \nTrace: " . $e->getTraceAsString());
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Произошла ошибка при отправке уведомлений: ' . $e->getMessage()];
} finally {
    if ($conn) $conn = null;
}

header('Location: ' . $redirect_url_with_params);
exit;
?>