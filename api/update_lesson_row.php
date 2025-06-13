<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$response = ['success' => false, 'error' => 'Неизвестная ошибка.'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['error'] = 'Метод не разрешен.';
    echo json_encode($response);
    exit;
}

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    $response['error'] = 'Доступ запрещен.';
    echo json_encode($response);
    exit;
}

$teacher_id_session = $_SESSION['user_id'];
$input_data = json_decode(file_get_contents('php://input'), true);

if (!$input_data) {
    $response['error'] = 'Некорректные входные данные.';
    echo json_encode($response);
    exit;
}

$lesson_id = isset($input_data['lesson_id']) ? (int)$input_data['lesson_id'] : 0;
$new_title = isset($input_data['title']) ? trim((string)$input_data['title']) : null;
$new_description = isset($input_data['description']) ? trim((string)$input_data['description']) : ''; // Описание может быть пустым

if ($lesson_id <= 0 || $new_title === null) { // Описание может быть пустым, но название - нет
    $response['error'] = 'Отсутствуют необходимые параметры: ID урока или название.';
    echo json_encode($response);
    exit;
}

// Валидация
if (empty($new_title)) {
    $response['error'] = 'Название урока не может быть пустым.';
    echo json_encode($response);
    exit;
}
if (mb_strlen($new_title) > 100) {
    $response['error'] = 'Название урока слишком длинное (макс. 100 символов).';
    echo json_encode($response);
    exit;
}

$conn = null;
try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Проверка прав преподавателя на редактирование этого урока
    $sql_check_permission = "
        SELECT l.id 
        FROM lessons l
        JOIN teaching_assignments ta ON (l.subject_id = ta.subject_id AND l.group_id = ta.group_id)
        WHERE l.id = ? AND ta.teacher_id = ?
    ";
    $stmt_check = $conn->prepare($sql_check_permission);
    $stmt_check->execute([$lesson_id, $teacher_id_session]);
    if (!$stmt_check->fetch()) {
        $response['error'] = 'У вас нет прав на редактирование этого урока или урок не найден.';
        echo json_encode($response);
        exit;
    }

    // Обновление урока
    $sql_update = "UPDATE lessons SET title = :title, description = :description, updated_at = NOW() WHERE id = :lesson_id";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bindParam(':title', $new_title, PDO::PARAM_STR);
    $stmt_update->bindParam(':description', $new_description, PDO::PARAM_STR);
    $stmt_update->bindParam(':lesson_id', $lesson_id, PDO::PARAM_INT);
    
    if ($stmt_update->execute()) {
            $response['success'] = true;
            $response['updated_lesson'] = [ 
                'title' => htmlspecialchars($new_title),
                'description' => htmlspecialchars($new_description)
            ];
            unset($response['error']);
    } else {
        $response['error'] = 'Ошибка при обновлении данных в базе.';
    }

} catch (PDOException $e) {
    error_log("API update_lesson_row PDO Error: " . $e->getMessage());
    $response['error'] = 'Ошибка базы данных: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("API update_lesson_row General Error: " . $e->getMessage());
    $response['error'] = 'Общая ошибка сервера: ' . $e->getMessage();
} finally {
    $conn = null;
}

echo json_encode($response);
exit;
?>