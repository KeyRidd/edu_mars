<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Проверка, авторизован ли пользователь
function is_logged_in() {
    return isset($_SESSION['user_id']);
}
// Проверка авторизации для защищенных страниц
function require_login() {
    if (!is_logged_in()) {
        if (!defined('BASE_URL')) { define('BASE_URL', '/'); }
        redirect_with_message(BASE_URL . 'pages/login.php', 'Пожалуйста, авторизуйтесь', 'error');
    }
}
//Авторизация пользователя
function login_user($user) {
    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role']
    ];
    
    // Обновление времени последнего входа
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$user['id']]);
}
//Выход пользователя
function logout_user() {
    session_unset();
    session_destroy();
}
//Внедрение CSRF-токенов
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validate_csrf_token')) {
    function validate_csrf_token(string $token_from_form): bool {
        if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token_from_form)) {
            return true;
        }
        return false;
    }
}
?>