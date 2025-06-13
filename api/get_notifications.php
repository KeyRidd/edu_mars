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
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$response = ['success' => false, 'notifications' => [], 'error' => 'Неизвестная ошибка.'];
$http_code = 500;

if (!is_logged_in() || !isset($_SESSION['user_id'])) {
    $http_code = 401; 
    $response['error'] = 'Доступ запрещен. Требуется авторизация.';
    http_response_code($http_code);
    echo json_encode($response);
    exit;
}
$user_id = (int)$_SESSION['user_id'];

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 3;
if ($limit <= 0 || $limit > 10) { 
    $limit = 3;
}

$conn = null;
try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQL-запрос для получения уведомлений
    $sql = "SELECT
                id,
                type,
                title,
                message,
                related_url,
                is_read,
                created_at,
                read_at,
                sender_id 
            FROM notifications
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT :limit";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $notifications_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $notifications_formatted = [];

    // Форматирование данных для ответа
    foreach ($notifications_raw as $n_raw) { 
        $n_formatted = $n_raw;
        $n_formatted['is_read'] = (bool)$n_raw['is_read'];

        // Форматируем даты в ISO 8601
        try {
            $n_formatted['created_at'] = (new DateTime($n_raw['created_at']))->format(DateTime::ATOM);
            if ($n_raw['read_at']) {
                $n_formatted['read_at'] = (new DateTime($n_raw['read_at']))->format(DateTime::ATOM);
            }
        } catch (Exception $e) {
            // Если дата некорректна, оставляем как есть или ставим null
            error_log("API get_notifications: Invalid date format for notification ID {$n_raw['id']}: " . $n_raw['created_at']);
            $n_formatted['created_at'] = $n_raw['created_at'];
            if ($n_raw['read_at']) $n_formatted['read_at'] = $n_raw['read_at'];
        }

        $notifications_formatted[] = $n_formatted;
    }

    $response['success'] = true;
    $response['notifications'] = $notifications_formatted;
    unset($response['error']);
    $http_code = 200;

} catch (PDOException $e) {
    error_log("API get_notifications DB Error (User ID: {$user_id}): " . $e->getMessage());
    $response['error'] = 'Ошибка базы данных при получении уведомлений.';
    $http_code = 500;
} catch (Exception $e) {
    error_log("API get_notifications General Error (User ID: {$user_id}): " . $e->getMessage());
    $response['error'] = 'Внутренняя ошибка сервера при получении уведомлений.';
    $http_code = 500;
} finally {
    $conn = null;
}

// Отправка ответа
http_response_code($http_code);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>