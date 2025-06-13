<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!defined('BASE_URL')) { define('BASE_URL', '/project/'); }
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Инициализация ответа
$response = ['success' => false, 'message' => 'Неизвестная ошибка API.'];
$pdo = null;
$errors_api = [];

try {
    if (!is_logged_in()) {
        http_response_code(401); throw new Exception('Требуется авторизация.');
    }

    $current_user_id_api = $_SESSION['user_id'];
    $current_user_role_api = $_SESSION['role'] ?? null;
    $is_admin_api = ($current_user_role_api === 'admin');

    $pdo = getDbConnection();
    if (!$pdo) { http_response_code(503); throw new Exception('Ошибка подключения к БД.'); }

    $action = $_REQUEST['action'] ?? null;

    // Получить данные пользователя (для админки)
    if ($action === 'get_user' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: application/json');
        if (!$is_admin_api) { http_response_code(403); throw new Exception('Доступ запрещен.'); }

        $user_id_to_get = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($user_id_to_get <= 0) { http_response_code(400); throw new Exception('Не указан ID пользователя.'); }

        // Убрали username из SELECT
        $sql_get = "SELECT id, full_name, email, role, group_id FROM users WHERE id = ?";
        $stmt_get = $pdo->prepare($sql_get);
        $stmt_get->execute([$user_id_to_get]);
        $user_data_get = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if ($user_data_get) {
            $response = ['success' => true, 'user' => $user_data_get];
        } else {
            http_response_code(404); $response['message'] = 'Пользователь не найден.';
        }
        echo json_encode($response);
        exit;
    }
    // Сохранить пользователя (Админ: создание/редактирование)
    elseif ($action === 'admin_save_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        if (!$is_admin_api) { http_response_code(403); $response['message'] = 'Доступ запрещен.'; echo json_encode($response); exit; }

        // Проверка CSRF
        $submitted_csrf = $_POST['csrf_token'] ?? '';
        if (!validate_csrf_token($submitted_csrf)) {
            http_response_code(403); $response['message'] = 'Ошибка безопасности (CSRF). Пожалуйста, обновите страницу.';
            echo json_encode($response); exit;
        }

        $user_id_to_save = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $is_new_user_admin_save = ($user_id_to_save === 0);

        // Получение данных (username убран)
        $full_name_admin_save = trim($_POST['full_name'] ?? '');
        $email_admin_save = trim($_POST['email'] ?? '');
        $role_admin_save = $_POST['role'] ?? '';
        $password_admin_save = trim($_POST['password'] ?? '');
        $group_id_input_admin_save = (isset($_POST['group_id']) && $_POST['group_id'] !== '') ? (int)$_POST['group_id'] : null;
        $group_id_to_save_admin = ($role_admin_save === 'student') ? $group_id_input_admin_save : null;

        if (empty($full_name_admin_save)) { $errors_api[] = 'Полное имя обязательно.'; }
        elseif (mb_strlen($full_name_admin_save) > 50) { $errors_api[] = 'Полное имя не должно превышать 50 символов.'; }

        // Email
        if (empty($email_admin_save)) { $errors_api[] = 'Email обязателен.'; }
        elseif (!filter_var($email_admin_save, FILTER_VALIDATE_EMAIL)) { $errors_api[] = "Некорректный email."; }
        else {
            $sql_check_email_admin = "SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ?";
            $stmt_check_email_admin = $pdo->prepare($sql_check_email_admin);
            $stmt_check_email_admin->execute([mb_strtolower($email_admin_save), $user_id_to_save]);
            if ($stmt_check_email_admin->fetch()) { $errors_api[] = "Этот Email уже занят."; }
        }
        // Роль
        if (!in_array($role_admin_save, ['student', 'teacher', 'admin'])) { $errors_api[] = "Некорректная роль."; }
        // Группа для студента
        if ($role_admin_save === 'student' && $group_id_to_save_admin === null) { $errors_api[] = "Для студента необходимо выбрать группу."; }
        elseif ($role_admin_save === 'student' && $group_id_to_save_admin !== null) {
            $stmt_check_group_admin = $pdo->prepare("SELECT COUNT(*) FROM groups WHERE id = ?");
            $stmt_check_group_admin->execute([$group_id_to_save_admin]);
            if ($stmt_check_group_admin->fetchColumn() == 0) { $errors_api[] = "Выбранная группа не существует."; }
        }
        // Пароль
        if ($is_new_user_admin_save) { // Для нового пользователя
            if (empty($password_admin_save)) { $errors_api[] = "Пароль обязателен для нового пользователя."; }
            elseif (mb_strlen($password_admin_save) < 8) { $errors_api[] = "Пароль должен быть не менее 8 символов."; }
            elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password_admin_save)) { $errors_api[] = "Пароль должен содержать заглавную, строчную буквы и цифру.";}
        } elseif (!empty($password_admin_save)) { // Если пароль введен для существующего (т.е. меняется)
            if (mb_strlen($password_admin_save) < 8) { $errors_api[] = "Новый пароль должен быть не менее 8 символов."; }
            elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password_admin_save)) { $errors_api[] = "Новый пароль должен содержать заглавную, строчную буквы и цифру.";}
        }

        if (empty($errors_api)) {
            try {
                $pdo->beginTransaction();
                if ($user_id_to_save > 0) { // Обновление
                    $sql_update_user = "UPDATE users SET full_name = ?, email = LOWER(?), role = ?, group_id = ?";
                    $params_update_user = [clean_input($full_name_admin_save), mb_strtolower($email_admin_save), $role_admin_save, $group_id_to_save_admin];
                    if (!empty($password_admin_save)) {
                        $hashed_password_update = password_hash($password_admin_save, PASSWORD_DEFAULT);
                        if ($hashed_password_update === false) throw new Exception("Ошибка хеширования пароля.");
                        $sql_update_user .= ", password = ?";
                        $params_update_user[] = $hashed_password_update;
                    }
                    $sql_update_user .= " WHERE id = ?";
                    $params_update_user[] = $user_id_to_save;
                    $stmt_save_user = $pdo->prepare($sql_update_user);
                    if (!$stmt_save_user->execute($params_update_user)) throw new PDOException("Ошибка обновления пользователя в БД.");
                    // Формируем ответ
                    $success_message_text = 'Данные пользователя успешно обновлены.';
                    $_SESSION['message_flash'] = ['type' => 'success', 'text' => $success_message_text];
                    $response = ['success' => true, 'message' => 'Данные пользователя успешно обновлены.'];
                } else { // Создание
                    $hashed_password_create = password_hash($password_admin_save, PASSWORD_DEFAULT);
                    if ($hashed_password_create === false) throw new Exception("Ошибка хеширования пароля.");
                    $sql_insert_user_admin = "INSERT INTO users (full_name, email, role, group_id, password, created_at) VALUES (?, LOWER(?), ?, ?, ?, NOW())";
                    $params_insert_user = [clean_input($full_name_admin_save), mb_strtolower($email_admin_save), $role_admin_save, $group_id_to_save_admin, $hashed_password_create];
                    $stmt_save_user = $pdo->prepare($sql_insert_user_admin);
                    if (!$stmt_save_user->execute($params_insert_user)) throw new PDOException("Ошибка создания пользователя в БД.");
                    // Формируем ответ
                    $success_message_text = 'Пользователь успешно создан.';
                    $_SESSION['message_flash'] = ['type' => 'success', 'text' => $success_message_text];
                    $response = ['success' => true, 'message' => 'Пользователь успешно создан.', 'new_user_id' => $pdo->lastInsertId()];
                }
                $pdo->commit();
            } catch (PDOException $e_pdo_save) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("API admin_save_user PDO Error: " . $e_pdo_save->getMessage());
                http_response_code(500); $response['success'] = false; $response['message'] = 'Ошибка базы данных при сохранении пользователя.';
            } catch (Exception $e_gen_save) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("API admin_save_user General Error: " . $e_gen_save->getMessage());
                http_response_code(400); $response['success'] = false; $response['message'] = $e_gen_save->getMessage();

            }
        } else { // Есть ошибки валидации
            http_response_code(422); 
            $response['success'] = false; $response['message'] = 'Обнаружены ошибки валидации.'; $response['errors'] = $errors_api;
        }
        echo json_encode($response);
        exit;
    }
    // Обновить профиль (смена пароля пользователем, или админ меняет данные)
    elseif ($action === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');

        // Проверка CSRF
        $submitted_csrf_profile = $_POST['csrf_token'] ?? '';
        if (!validate_csrf_token($submitted_csrf_profile)) {
            http_response_code(403); $response['message'] = 'Ошибка безопасности (CSRF).'; echo json_encode($response); exit;
        }

        $user_id_to_update_profile = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $password_profile = trim($_POST['password'] ?? '');
        $password_confirm_profile = trim($_POST['password_confirm'] ?? '');

        if ($user_id_to_update_profile <= 0) { http_response_code(400); $response['message'] = "Не указан ID пользователя."; echo json_encode($response); exit; }
        if (!$is_admin_api && $user_id_to_update_profile !== $current_user_id_api) { http_response_code(403); $response['message'] = "У вас нет прав для изменения этого профиля."; echo json_encode($response); exit; }

        $update_fields_sql_parts_profile = [];
        $params_for_update_profile = [];

        // Админ может менять ФИО и Email
        if ($is_admin_api && isset($_POST['full_name']) && isset($_POST['email'])) {
            $full_name_profile_admin = trim($_POST['full_name'] ?? '');
            $email_profile_admin = trim($_POST['email'] ?? '');

            if (empty($full_name_profile_admin)) { $errors_api[] = "Полное имя обязательно."; }
            if (empty($email_profile_admin) || !filter_var($email_profile_admin, FILTER_VALIDATE_EMAIL)) { $errors_api[] = "Некорректный email."; }
            else {
                $sql_check_email_profile = "SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ?";
                $stmt_check_email_profile = $pdo->prepare($sql_check_email_profile);
                $stmt_check_email_profile->execute([mb_strtolower($email_profile_admin), $user_id_to_update_profile]);
                if ($stmt_check_email_profile->fetch()) { $errors_api[] = "Этот Email уже занят."; }
            }
            if (empty($errors_api)) {
                $update_fields_sql_parts_profile[] = "full_name = ?"; $params_for_update_profile[] = clean_input($full_name_profile_admin);
                $update_fields_sql_parts_profile[] = "email = LOWER(?)"; $params_for_update_profile[] = mb_strtolower($email_profile_admin);
            }
        }

        // Обновление пароля (только если поле нового пароля не пустое)
        if (!empty($password_profile)) {
            if (mb_strlen($password_profile) < 8) { $errors_api[] = "Новый пароль должен быть не менее 8 символов."; }
            elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password_profile)) { $errors_api[] = "Новый пароль должен содержать заглавную, строчную буквы и цифру."; }
            elseif ($password_profile !== $password_confirm_profile) { $errors_api[] = "Новый пароль и его подтверждение не совпадают."; }
            else {
                $hashed_password_new_profile = password_hash($password_profile, PASSWORD_DEFAULT);
                if ($hashed_password_new_profile === false) { error_log("Password hashing failed for user ID: " . $user_id_to_update_profile); http_response_code(500); $response['message'] = "Ошибка обработки пароля."; echo json_encode($response); exit; }
                $update_fields_sql_parts_profile[] = "password = ?"; $params_for_update_profile[] = $hashed_password_new_profile;
            }
        }

        if (!empty($errors_api)) {
            http_response_code(422); $response['success'] = false; $response['message'] = 'Обнаружены ошибки валидации.'; $response['errors'] = $errors_api;
        } elseif (empty($update_fields_sql_parts_profile)) {
            $response = ['success' => true, 'message' => 'Изменений для сохранения не было.'];
        } else {
            try {
                $pdo->beginTransaction();
                $sql_do_update_profile = "UPDATE users SET " . implode(", ", $update_fields_sql_parts_profile) . " WHERE id = ?";
                $params_for_update_profile[] = $user_id_to_update_profile;
                $stmt_do_update_profile = $pdo->prepare($sql_do_update_profile);
                if ($stmt_do_update_profile->execute($params_for_update_profile)) {
                    $pdo->commit();
                    $success_message_text_profile = 'Данные профиля успешно обновлены.';
                    if (in_array("password = ?", $update_fields_sql_parts_profile)) { 
                        $success_message_text_profile = 'Пароль успешно изменен.'; 
                    }
                    $_SESSION['message_flash'] = ['type' => 'success', 'text' => $success_message_text_profile];
                    $response = ['success' => true, 'message' => $success_message_text_profile];
                    if (in_array("password = ?", $update_fields_sql_parts_profile)) { $response['message'] = 'Пароль успешно изменен.'; }
                 } else { throw new PDOException("Ошибка обновления профиля в БД."); }
            } catch (PDOException $e_pdo_profile) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("API update_profile PDO Error: " . $e_pdo_profile->getMessage());
                http_response_code(500); $response['success'] = false; $response['message'] = "Ошибка базы данных при обновлении профиля.";
            }
        }
        echo json_encode($response); 
        exit;
    }
    else {
         http_response_code(400); throw new Exception('Неизвестное или неподдерживаемое действие API.');
    }

} catch (Exception $e) { 
    error_log("API Users General Uncaught Error: Action: {$action}, Message: " . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json');
        // Устанавливаем HTTP код из исключения, если он есть и валидный, иначе 400 или 500
        $http_code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
        if ($e instanceof PDOException) $http_code = 500; 
        elseif ($http_code === 0 && !($e instanceof PDOException)) $http_code = 400; 
        http_response_code($http_code);
    }
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    echo json_encode($response);
    exit;
} finally {
    $pdo = null;
}
?>