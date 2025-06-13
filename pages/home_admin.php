<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (!defined('BASE_URL')) {
    $script_path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    define('BASE_URL', rtrim(dirname($script_path), '/') . '/');
}
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__)); }
if (!defined('CONFIG_PATH')) { define('CONFIG_PATH', ROOT_PATH . '/config/'); }
if (!defined('INCLUDES_PATH')) { define('INCLUDES_PATH', ROOT_PATH . '/includes/'); }
if (!defined('LAYOUTS_PATH')) { define('LAYOUTS_PATH', ROOT_PATH . '/layouts/'); }

// require_once CONFIG_PATH . 'database.php';
require_once INCLUDES_PATH . 'functions.php'; 
require_once INCLUDES_PATH . 'auth.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. Эта страница только для администраторов.'];
    $redirect_url = is_logged_in() ? BASE_URL . 'pages/dashboard.php' : BASE_URL . 'pages/login.php';
    if (($_SESSION['role'] ?? null) === 'student') $redirect_url = BASE_URL . 'pages/home_student.php';
    if (($_SESSION['role'] ?? null) === 'teacher') $redirect_url = BASE_URL . 'pages/home_teacher.php';
    header('Location: ' . $redirect_url);
    exit();
}

$admin_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Администратор'; // Используем полное имя, если есть
$page_flash_message = null;
$db_error_message = ''; 

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) { 
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

$page_title = "Главная";
$show_sidebar = true; 
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'admin-home-page app-page dashboard-page';
$load_notifications_css = true;
$load_admin_css = true;

ob_start();
?>

<div class="container py-4">
    <div class="page-header mb-4">
        <h1 class="h2"><i class="fas fa-user-shield me-2"></i>Добро пожаловать, <?php echo htmlspecialchars($admin_name); ?>!</h1>
        <p class="text-muted">Панель администратора системы.</p>
    </div>

    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($db_error_message)):?>
         <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($db_error_message); ?></div>
    <?php endif; ?>

    <?php // Основной контент админ-панели ?>
    <div class="row gy-4">
        <div class="col-12">
            <div class="card text-center shadow-sm">
                <div class="card-body py-5">
                    <i class="fas fa-info-circle fa-3x text-muted mb-3 d-block"></i>
                    <h5 class="card-title">Информация</h5>
                    <p class="card-text text-muted">На данный момент здесь нет специальной информации или сводки. <br>Используйте меню слева для доступа к разделам управления.</p>
                </div>
            </div>
        </div>
    </div> 
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>