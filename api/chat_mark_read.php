<?php
declare(strict_types=1);
header('Content-Type: application/json');

ini_set('display_errors', '0');
error_reporting(0);

require_once '../config/database.php';
require_once '../includes/auth.php';
// require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$response = ['success' => false, 'message' => 'Неизвестная ошибка.'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); 
    $response['message'] = 'Метод не разрешен.';
    echo json_encode($response);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    $response['message'] = 'Доступ запрещен. Требуется авторизация.';
    echo json_encode($response);
    exit;
}

// Получаем данные из запроса
$lesson_id = isset($_POST['lesson_id']) ? (int)$_POST['lesson_id'] : 0;
$user_id = $_SESSION['user_id'] ?? 0;

if ($lesson_id <= 0 || $user_id <= 0) {
    http_response_code(400);
    $response['message'] = 'Некорректные параметры запроса (lesson_id).';
    echo json_encode($response);
    exit;
}

$conn = null;
try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $role = $_SESSION['role'];
    $has_access = false;
    // Получаем group_id и subject_id урока
    $stmt_lesson_info = $conn->prepare("SELECT group_id, subject_id FROM lessons WHERE id = ?");
    $stmt_lesson_info->execute([$lesson_id]);
    $lesson_info = $stmt_lesson_info->fetch(PDO::FETCH_ASSOC);
    $stmt_lesson_info = null;

    if ($lesson_info) {
        $lesson_group_id = $lesson_info['group_id'];
        $lesson_subject_id = $lesson_info['subject_id'];

        if ($role === 'admin') {
            $has_access = true;
        } elseif ($role === 'teacher' && $lesson_subject_id) {
            $stmt_check = $conn->prepare("SELECT 1 FROM teaching_assignments WHERE teacher_id = ? AND subject_id = ? AND group_id = ? LIMIT 1");
            $stmt_check->execute([$user_id, $lesson_subject_id, $lesson_group_id]);
            $has_access = (bool)$stmt_check->fetchColumn();
        } elseif ($role === 'student') {
            $stmt_check = $conn->prepare("SELECT 1 FROM users WHERE id = ? AND group_id = ? LIMIT 1");
            $stmt_check->execute([$user_id, $lesson_group_id]);
            $has_access = (bool)$stmt_check->fetchColumn();
        }
    }

    if (!$has_access) {
        http_response_code(403);
        $response['message'] = 'У вас нет доступа к этому чату.';
        echo json_encode($response);
        exit;
    }

    // Обновление статуса прочтения
    $sql_upsert = "
        INSERT INTO chat_read_status (user_id, lesson_id, last_read_at)
        VALUES (:user_id, :lesson_id, NOW())
        ON CONFLICT (user_id, lesson_id)
        DO UPDATE SET last_read_at = NOW();
    ";
    $stmt_upsert = $conn->prepare($sql_upsert);
    $stmt_upsert->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt_upsert->bindParam(':lesson_id', $lesson_id, PDO::PARAM_INT);

    if ($stmt_upsert->execute()) {
        $response['success'] = true;
        $response['message'] = 'Статус прочтения обновлен.';
    } else {
         http_response_code(500); // Internal Server Error
         $response['message'] = 'Ошибка базы данных при обновлении статуса.';
         error_log("API chat_mark_read DB Error: Failed to execute upsert for user {$user_id}, lesson {$lesson_id}");
    }

} catch (PDOException $e) {
    http_response_code(500);
    $response['message'] = 'Ошибка базы данных.';
    error_log("API chat_mark_read PDOException: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    $response['message'] = 'Внутренняя ошибка сервера.';
    error_log("API chat_mark_read Exception: " . $e->getMessage());
} finally {
    $conn = null;
}

// Отправляем ответ
echo json_encode($response);
exit;
?>