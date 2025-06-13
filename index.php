<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (file_exists(__DIR__ . '/config/app_config.php')) {
    require_once __DIR__ . '/config/app_config.php';
} else {
    if (!defined('BASE_URL')) define('BASE_URL', '/project/');
    if (!defined('APP_NAME')) define('APP_NAME', 'Edu.MARS');
    if (!defined('LAYOUTS_PATH')) define('LAYOUTS_PATH', __DIR__ . '/layouts/'); 
    if (!defined('INCLUDES_PATH')) define('INCLUDES_PATH', __DIR__ . '/includes/');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once INCLUDES_PATH . 'functions.php';
require_once INCLUDES_PATH . 'auth.php';

if (is_logged_in()) {
    if (isset($_SESSION['role'])) {
        $role = $_SESSION['role'];
        $redirect_url = BASE_URL . 'pages/dashboard.php'; 
        switch ($role) {
            case 'student': $redirect_url = BASE_URL . 'pages/home_student.php'; break;
            case 'teacher': $redirect_url = BASE_URL . 'pages/home_teacher.php'; break;
            case 'admin':   $redirect_url = BASE_URL . 'pages/home_admin.php';   break;
        }
        header('Location: ' . $redirect_url);
        exit();
    } else {
        error_log("User is logged in (ID: " . ($_SESSION['user_id'] ?? 'N/A') . ") but role is not set. Forcing logout.");
        logout_user();
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Ошибка сессии. Пожалуйста, войдите снова.'];
        header('Location: ' . BASE_URL . 'pages/login.php');
        exit();
    }
}

// Установка переменных для layout
$page_title = 'Добро пожаловать - ' . APP_NAME;
$body_class = 'landing-page-body'; 
$show_sidebar = false;            
$is_auth_page = true;            
$container_class_main = '';     
$is_auth_page = true;
$is_landing_page = true; 
$body_class = 'landing-page-body';
ob_start();
?>

<!-- Уникальный HTML для index.php -->
<div class="welcome-container-wrapper">
<div class="welcome-content-pane">
    <div class="welcome-section">
        <h1>Добро пожаловать в систему<br>управления учебным процессом<br><?php echo APP_NAME; ?></h1>
        <p>Эффективное приложение для взаимодействия преподавателей и студентов</p>
        
        <div class="auth-buttons">
            <a href="<?php echo BASE_URL; ?>pages/login.php" class="btn btn-primary btn-lg">Войти</a>
            <a href="<?php echo BASE_URL; ?>pages/register.php" class="btn btn-secondary btn-lg">Зарегистрироваться</a>
        </div>
    </div>
    </div>
</div>
<?php
$page_content = ob_get_clean(); 
require_once LAYOUTS_PATH . 'main_layout.php'; 
?>