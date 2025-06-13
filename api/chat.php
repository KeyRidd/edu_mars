<?php
declare(strict_types=1);

ob_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

define('EDIT_DELETE_TIMELIMIT', 12 * 60 * 60);
if (!defined('BASE_URL')) { define('BASE_URL', '/project/'); }

require_once '../includes/functions.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

if (!is_logged_in()) {
    ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}

$pdo = getDbConnection();
if (!$pdo) {
     ob_end_clean();
     http_response_code(503);
     header('Content-Type: application/json');
     echo json_encode(['success' => false, 'error' => 'Ошибка подключения к базе данных.']);
     exit;
}

//  Инициализация 
$user_id = (int)$_SESSION['user_id'];
$response = ['success' => false];
$method = $_SERVER['REQUEST_METHOD'];

try {
    // Отправка нового сообщения
    if ($method === 'POST') {
        $lesson_id = isset($_POST['lesson_id']) ? (int)$_POST['lesson_id'] : 0;
        $message_text = isset($_POST['message']) ? trim($_POST['message']) : '';

        if ($lesson_id <= 0 || $user_id <= 0 || empty($message_text)) {
            http_response_code(400);
            throw new Exception('Отсутствуют обязательные параметры: lesson_id, message или user_id.');
        }

        // Проверка доступа пользователя к уроку
        if (!userHasAccessToLesson($pdo, $user_id, $lesson_id)) {
            http_response_code(403);
            throw new Exception('Нет доступа к чату этого урока.');
        }

        $message_sanitized = htmlspecialchars($message_text, ENT_QUOTES, 'UTF-8');

        // Сохранение сообщения
        $sql_insert = "INSERT INTO messages (lesson_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())";
        $stmt_insert = $pdo->prepare($sql_insert);

        if ($stmt_insert->execute([$lesson_id, $user_id, $message_sanitized])) {
            $message_id = $pdo->lastInsertId();

            // Получаем данные ТОЛЬКО ЧТО созданного сообщения
            $sql_get_new = "SELECT m.id, m.message, m.created_at, m.edited_at, u.id as user_id, u.full_name, u.role
                            FROM messages m JOIN users u ON m.user_id = u.id
                            WHERE m.id = ?";
            $stmt_get_new = $pdo->prepare($sql_get_new);
            $stmt_get_new->execute([(int)$message_id]); // Передаем ID нового сообщения
            $newMessageData = $stmt_get_new->fetch(PDO::FETCH_ASSOC);

            if ($newMessageData) {
                 $response['success'] = true;
                 $response['message'] = 'Сообщение отправлено.'; // Общее сообщение об успехе

                 // Формируем messageData для JS
                 $response['messageData'] = [
                     'id' => (int)$newMessageData['id'],
                     'message' => $newMessageData['message'],
                     // Форматируем дату в ISO 8601 для JS new Date()
                     'created_at_iso' => (new DateTime($newMessageData['created_at']))->format(DateTime::ATOM),
                     'edited_at' => $newMessageData['edited_at'] ? (new DateTime($newMessageData['edited_at']))->format(DateTime::ATOM) : null,
                     'user_id' => (int)$newMessageData['user_id'],
                     'display_name' => getShortName($newMessageData['full_name']),
                     'role' => $newMessageData['role'],
                     'isOwn' => ($newMessageData['user_id'] === $user_id)
                 ];
                 http_response_code(201);

            } else {
                 error_log("API Chat Error: Could not retrieve newly inserted message ID: $message_id");
                 http_response_code(500);
                 throw new Exception('Не удалось получить данные сохраненного сообщения.');
            }

        } else {
            throw new PDOException('Ошибка при сохранении сообщения в БД: ' . implode(" | ", $stmt_insert->errorInfo()));
        }
    }
    // Получение сообщений
    elseif ($method === 'GET') {
        $lesson_id = isset($_GET['lesson_id']) ? (int)$_GET['lesson_id'] : 0;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $since_id = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;

        if ($lesson_id <= 0) {
            http_response_code(400);
            throw new Exception('Не указан ID урока (lesson_id).');
        }
        if (!userHasAccessToLesson($pdo, $user_id, $lesson_id)) {
            http_response_code(403);
            throw new Exception('Нет доступа к чату этого урока.');
        }

        // Выбираем нужные поля
        $sql_get = "SELECT m.id, m.user_id, u.full_name, u.role, m.message, m.created_at, m.edited_at
                    FROM messages m
                    JOIN users u ON m.user_id = u.id
                    WHERE m.lesson_id = ? ";
        $params = [$lesson_id];

        if ($since_id > 0) {
             $sql_get .= " AND m.id > ? ORDER BY m.created_at ASC";
             $params[] = $since_id;
        } else {
             $sql_get .= " ORDER BY m.created_at DESC LIMIT ? OFFSET ?";
             $params[] = $limit;
             $params[] = $offset;
        }

        $stmt_get = $pdo->prepare($sql_get);
        $param_index = 1;
        foreach ($params as $param) {
             $stmt_get->bindValue($param_index++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt_get->execute();
        $messagesRaw = $stmt_get->fetchAll(PDO::FETCH_ASSOC);

        // Форматируем вывод
        $messages = [];
        foreach ($messagesRaw as $msg) {
            $msg['id'] = (int)$msg['id'];
            $msg['user_id'] = (int)$msg['user_id'];
            $msg['display_name'] = getShortName($msg['full_name']); // Форматируем имя
            $messages[] = $msg;
        }

        if ($since_id <= 0 && !empty($messages)) {
             $messages = array_reverse($messages); // Переворачиваем для правильного порядка старых сообщений
        }

        $response = [
            'success' => true,
            'messages' => $messages,
        ];
        http_response_code(200);
    }
    // Редактирование сообщения
    elseif ($method === 'PATCH') {
        $input = json_decode(file_get_contents('php://input'), true);
        $message_id = isset($input['message_id']) ? (int)$input['message_id'] : 0;
        $new_message_text = isset($input['message']) ? trim($input['message']) : '';

        if ($message_id <= 0 || empty($new_message_text)) {
            http_response_code(400);
            throw new Exception('Отсутствуют обязательные параметры: message_id и message.');
        }

        // Получаем информацию о сообщении для проверки прав
        $sql_check = "SELECT user_id, created_at, lesson_id FROM messages WHERE id = ?";
        $stmt_check = $pdo->prepare($sql_check);
        $stmt_check->execute([$message_id]);
        $message_data = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if (!$message_data) {
            http_response_code(404);
            throw new Exception('Сообщение не найдено.');
        }

        // Проверка прав на редактирование
        $is_author = ($message_data['user_id'] === $user_id);
        $is_within_timelimit = (strtotime($message_data['created_at']) + EDIT_DELETE_TIMELIMIT) > time();

        if (!$is_author) { http_response_code(403); throw new Exception('Вы можете редактировать только свои сообщения.'); }
        if (!$is_within_timelimit) { http_response_code(403); throw new Exception('Время для редактирования сообщения истекло (12 часов).'); }
        if (!userHasAccessToLesson($pdo, $user_id, (int)$message_data['lesson_id'])) { http_response_code(403); throw new Exception('Нет доступа к уроку этого сообщения.'); }

        $message_sanitized = htmlspecialchars($new_message_text, ENT_QUOTES, 'UTF-8');

        // Выполняем обновление
        $sql_update = "UPDATE messages SET message = ?, edited_at = NOW() WHERE id = ?";
        $stmt_update = $pdo->prepare($sql_update);

        if ($stmt_update->execute([$message_sanitized, $message_id])) {
             $edited_at_time = date('Y-m-d H:i:s'); // Время редактирования для ответа

             // Получаем данные пользователя для консистентности ответа
             $stmt_user = $pdo->prepare("SELECT u.full_name, u.role FROM users u WHERE id = ?");
             $stmt_user->execute([$message_data['user_id']]);
             $userData = $stmt_user->fetch(PDO::FETCH_ASSOC);
             $actual_full_name = $userData['full_name'] ?? null;
             $actual_role = $userData['role'] ?? 'unknown';
             $display_name = getShortName($actual_full_name);

             $response = [
                 'success' => true,
                 'message' => [ // Возвращаем обновленный объект сообщения
                     'id' => $message_id,
                     'message' => $message_sanitized,
                     'edited_at' => $edited_at_time,
                     // Добавляем прочие данные, если они нужны для обновления на клиенте
                     'user_id' => $message_data['user_id'],
                     'full_name' => $actual_full_name,
                     'display_name' => $display_name,
                     'role' => $actual_role
                 ]
             ];
             http_response_code(200); // OK
        } else {
             throw new PDOException('Ошибка при обновлении сообщения в БД.');
        }
    }
    // Удаление сообщения
    elseif ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        $message_id = isset($input['message_id']) ? (int)$input['message_id'] : 0;

        if ($message_id <= 0) { http_response_code(400); throw new Exception('Не указан ID сообщения (message_id).'); }

        // Получаем информацию о сообщении для проверки прав
        $sql_check = "SELECT user_id, created_at, lesson_id FROM messages WHERE id = ?";
        $stmt_check = $pdo->prepare($sql_check);
        $stmt_check->execute([$message_id]);
        $message_data = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if (!$message_data) { http_response_code(404); throw new Exception('Сообщение не найдено.'); }

        // Проверка прав на удаление
        $is_author = ($message_data['user_id'] === $user_id);
        $is_within_timelimit = (strtotime($message_data['created_at']) + EDIT_DELETE_TIMELIMIT) > time();

        if (!$is_author) { http_response_code(403); throw new Exception('Вы можете удалять только свои сообщения.'); }
        if (!$is_within_timelimit) { http_response_code(403); throw new Exception('Время для удаления сообщения истекло (12 часов).'); }
        if (!userHasAccessToLesson($pdo, $user_id, (int)$message_data['lesson_id'])) { http_response_code(403); throw new Exception('Нет доступа к уроку этого сообщения.'); }

        // Выполняем удаление
        $sql_delete = "DELETE FROM messages WHERE id = ?";
        $stmt_delete = $pdo->prepare($sql_delete);

        if ($stmt_delete->execute([$message_id])) {
             if ($stmt_delete->rowCount() > 0) {
                 $response = ['success' => true];
                 http_response_code(200);
             } else {
                 http_response_code(404);
                 throw new Exception('Сообщение не найдено во время удаления.');
             }
        } else {
             throw new PDOException('Ошибка при удалении сообщения из БД.');
        }
    }
    // Неподдерживаемый метод
    else {
        http_response_code(405);
        header('Allow: GET, POST, PATCH, DELETE');
        throw new Exception('Метод ' . $method . ' не поддерживается');
    }

} catch (PDOException $e) {
    // Логируем ошибку БД
    error_log("API Chat PDO Error (Method: $method): " . $e->getMessage() . " | Input: " . file_get_contents('php://input'));
    $response['error'] = 'Ошибка базы данных. Пожалуйста, попробуйте позже.'; // Общее сообщение для клиента
    if (!headers_sent()) http_response_code(500);
} catch (Exception $e) {
    // Логируем другие ошибки
    error_log("API Chat Error (Method: $method): " . $e->getMessage() . " | Input: " . file_get_contents('php://input'));
    $response['error'] = $e->getMessage();
     // Устанавливаем код ошибки, если он еще не установлен
     if (!headers_sent() && http_response_code() < 400) {
         http_response_code(400);
     }
}

// Очистка буфера
$debugOutput = ob_get_clean(); // Получаем содержимое буфера
if (!empty($debugOutput)) {
    error_log("API Chat Debug Output: " . $debugOutput);
    if (!headers_sent() && $response['success'] !== true && empty($response['error'])) {
         $response['error'] = 'Произошла внутренняя ошибка сервера при обработке запроса.';
          if(http_response_code() < 400) http_response_code(500);
    }
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;

// Функция проверки доступа
function userHasAccessToLesson(PDO $pdo, int $userId, int $lessonId): bool
{
    $sql_check = "SELECT u.role, l.group_id, l.subject_id
                  FROM users u, lessons l
                  WHERE u.id = ? AND l.id = ?";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([$userId, $lessonId]);
    $data = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$data) { return false; }

    $userRole = $data['role'];
    $groupId = $data['group_id'] ? (int)$data['group_id'] : null;
    $subjectId = $data['subject_id'] ? (int)$data['subject_id'] : null;

    if ($userRole === 'admin') { return true; }
    if (!$groupId) return false;

    if ($userRole === 'teacher') {
        if (!$subjectId) return false;
        $sql_access = "SELECT 1 FROM teaching_assignments
                       WHERE teacher_id = ? AND subject_id = ? AND group_id = ? LIMIT 1";
        $stmt_access = $pdo->prepare($sql_access);
        $stmt_access->execute([$userId, $subjectId, $groupId]);
        return (bool)$stmt_access->fetchColumn();
    }

    if ($userRole === 'student') {
        $sql_access = "SELECT 1 FROM users WHERE id = ? AND group_id = ? LIMIT 1";
        $stmt_access = $pdo->prepare($sql_access);
        $stmt_access->execute([$userId, $groupId]);
        return (bool)$stmt_access->fetchColumn();
    }

    return false;
}

?>