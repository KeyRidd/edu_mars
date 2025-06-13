<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Базовые подключения
if (!defined('BASE_URL')) {
     $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
     $baseUrlGuess = preg_replace('/\/api$/', '', $scriptDir);
     define('BASE_URL', rtrim($baseUrlGuess, '/') . '/');
}
require_once '../config/database.php';
require_once '../includes/auth.php';

// Запускаем сессию для проверки авторизации
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ответ по умолчанию
$response = ['success' => false, 'message' => 'Произошла ошибка.', 'marked_ids' => []];
$http_code = 400;

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Метод не разрешен. Ожидается POST.';
    $http_code = 405;
    http_response_code($http_code);
    echo json_encode($response);
    exit;
}

// Проверка авторизации пользователя
if (!is_logged_in() || !isset($_SESSION['user_id'])) {
    $response['message'] = 'Доступ запрещен. Требуется авторизация.';
    $http_code = 401;
    http_response_code($http_code);
    echo json_encode($response);
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// Получение и декодирование данных из тела запроса (ожидаем JSON)
$data = json_decode(file_get_contents("php://input"));

if (json_last_error() !== JSON_ERROR_NONE || !isset($data->newsIds) || !is_array($data->newsIds)) {
    $response['message'] = 'Ошибка в формате запроса. Ожидается JSON с массивом newsIds.';
    $http_code = 400;
    http_response_code($http_code);
    echo json_encode($response);
    exit;
}

// Валидация и очистка ID новостей
$news_ids_raw = $data->newsIds;
$news_ids_to_mark = [];
foreach ($news_ids_raw as $id) {
    if (is_numeric($id) && (int)$id > 0) {
        $news_ids_to_mark[] = (int)$id; // Собираем только валидные положительные ID
    }
}

if (empty($news_ids_to_mark)) {
    $response['message'] = 'Не переданы или некорректные ID новостей для отметки.';
    $http_code = 400;
    http_response_code($http_code);
    echo json_encode($response);
    exit;
}

// Работа с базой данных
$conn = null;
try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->beginTransaction();

    $marked_count = 0;
    $already_marked_ids = [];
    $successfully_marked_ids = [];

    // Готовим запрос для вставки
    $sql_mark = "INSERT INTO news_read_status (news_id, user_id) VALUES (:news_id, :user_id) ON CONFLICT (news_id, user_id) DO NOTHING";
    $stmt_mark = $conn->prepare($sql_mark);
    $stmt_mark->bindParam(':user_id', $user_id, PDO::PARAM_INT);

    foreach ($news_ids_to_mark as $news_id) {
        $stmt_mark->bindParam(':news_id', $news_id, PDO::PARAM_INT);
        if ($stmt_mark->execute()) {
            if ($stmt_mark->rowCount() > 0) {
                // Если rowCount > 0, значит запись была успешно вставлена (новость НЕ была прочитана ранее)
                $marked_count++;
                $successfully_marked_ids[] = $news_id;
            } else {
                 // Если rowCount = 0 и ON CONFLICT сработал, значит новость УЖЕ была прочитана
                 $already_marked_ids[] = $news_id;
            }
        } else {
            // Если execute вернул false - произошла ошибка при выполнении запроса для этого ID
             error_log("API news_mark_read: Failed to execute insert for news_id=$news_id, user_id=$user_id");
        }
    }

    $conn->commit();

    // Формируем успешный ответ
    $response['success'] = true;
    if ($marked_count > 0) {
        $response['message'] = "Успешно отмечено {$marked_count} новостей как прочитанные.";
    } elseif (!empty($already_marked_ids)) {
         $response['message'] = "Все переданные новости уже были отмечены как прочитанные ранее.";
    } else {
         $response['message'] = "Не удалось отметить новости (возможно, из-за ошибки или они уже прочитаны).";
    }
    $response['marked_ids'] = $successfully_marked_ids; // Возвращаем ID фактически отмеченных сейчас
    $http_code = 200; // OK

} catch (PDOException $e) {
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("API news_mark_read DB Error: " . $e->getMessage());
    $response['message'] = 'Ошибка базы данных при отметке новостей.';
    $http_code = 500;
} catch (Exception $e) {
     error_log("API news_mark_read General Error: " . $e->getMessage());
     $response['message'] = 'Внутренняя ошибка сервера.';
     $http_code = 500;
} finally {
    $conn = null;
}

// Отправка ответа
http_response_code($http_code);
echo json_encode($response);
exit;

?>