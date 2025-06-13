<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(0);

if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $baseUrlGuess = preg_replace('/\/api$/', '', $scriptDir);
    define('BASE_URL', rtrim($baseUrlGuess, '/') . '/');
}
require_once '../config/database.php';
require_once '../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$response = ['success' => false, 'message' => 'Неизвестная ошибка.'];
$http_code = 500;

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $http_code = 405;
    $response['message'] = 'Метод не разрешен. Ожидается POST.';
    http_response_code($http_code);
    echo json_encode($response);
    exit;
}

// Проверка авторизации
if (!is_logged_in() || !isset($_SESSION['user_id'])) {
    $http_code = 401;
    $response['message'] = 'Доступ запрещен. Требуется авторизация.';
    http_response_code($http_code);
    echo json_encode($response);
    exit;
}
$user_id = (int)$_SESSION['user_id'];

$conn = null;
try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQL-запрос для отметки всех непрочитанных уведомлений пользователя
    $sql_mark_all = "UPDATE notifications
                     SET is_read = TRUE, read_at = NOW()
                     WHERE user_id = :user_id AND is_read = FALSE";

    $stmt = $conn->prepare($sql_mark_all);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);

    if ($stmt->execute()) {
        $updated_count = $stmt->rowCount(); // Количество обновленных (прочитанных) строк
        $response['success'] = true;
        $response['message'] = "Все уведомления отмечены как прочитанные. Обновлено: {$updated_count}.";
        $response['updated_count'] = $updated_count;
        $http_code = 200;
    } else {
        $response['message'] = 'Ошибка базы данных при отметке уведомлений.';
        error_log("API notifications_mark_all_read: Failed to execute update for user_id={$user_id}");
    }

} catch (PDOException $e) {
    error_log("API notifications_mark_all_read DB Error (User ID: {$user_id}): " . $e->getMessage());
    $response['message'] = 'Ошибка базы данных.';
} catch (Exception $e) {
    error_log("API notifications_mark_all_read General Error (User ID: {$user_id}): " . $e->getMessage());
    $response['message'] = 'Внутренняя ошибка сервера.';
} finally {
    $conn = null;
}

// Отправка ответа
http_response_code($http_code);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>