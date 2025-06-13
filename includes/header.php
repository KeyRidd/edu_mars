<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BASE_URL')) {
    $script_path = dirname($_SERVER['SCRIPT_NAME'], 2); 
    define('BASE_URL', rtrim($script_path, '/') . '/');
}
if (!defined('APP_NAME')) define('APP_NAME', 'Edu.MARS');
if (!defined('INCLUDES_PATH')) define('INCLUDES_PATH', __DIR__ . '/'); 

$isLoggedIn = isset($_SESSION['user_id']);
$currentUserRoleHeader = $_SESSION['role'] ?? null;
$currentUserIdHeader = $_SESSION['user_id'] ?? 0;
$hasUnreadNotifications = false;

if (!function_exists('getDbConnection')) {
    require_once dirname(__DIR__) . '/config/database.php';
}
if (!function_exists('is_active_page')) { 
    require_once INCLUDES_PATH . 'functions.php';
}

// Механизм отметки о прочтении и проверки непрочитанных
if ($isLoggedIn && $currentUserIdHeader > 0) {
    $connHeader = null; // Используем отдельное имя для соединения
    try {
        $connHeader = getDbConnection(); // Получаем соединение

        // Обработка ?mark_read=ID
        if (isset($_GET['mark_read'])) {
            $notification_id_to_mark = (int)$_GET['mark_read'];
            if ($notification_id_to_mark > 0) {
                 $stmt_mark = $connHeader->prepare("UPDATE notifications SET is_read = TRUE, read_at = NOW() WHERE id = :id AND user_id = :user_id AND is_read = FALSE");
                 $stmt_mark->execute([':id' => $notification_id_to_mark, ':user_id' => $currentUserIdHeader]);
                 $stmt_mark = null;

                 // Убираем параметр mark_read из URL и делаем редирект
                 $current_url_params = $_GET;
                 unset($current_url_params['mark_read']);
                 $redirect_url = strtok($_SERVER["REQUEST_URI"], '?');
                 if (!empty($current_url_params)) {
                     $redirect_url .= '?' . http_build_query($current_url_params);
                 }
                 header("Location: " . $redirect_url);
                 exit;
            }
        }

        // Проверка наличия непрочитанных уведомлений для индикатора
        $sql_check_unread = "SELECT 1 FROM notifications WHERE user_id = ? AND is_read = FALSE LIMIT 1";
        $stmt_check_unread = $connHeader->prepare($sql_check_unread);
        $stmt_check_unread->execute([$currentUserIdHeader]);
        if ($stmt_check_unread->fetchColumn()) {
            $hasUnreadNotifications = true; // Устанавливаем флаг
        }
        $stmt_check_unread = null;

    } catch (Exception $e) {
        error_log("Header Notification Check/Mark Error: " . $e->getMessage());
    } finally {
        $connHeader = null; 
    }
}

$page_title_for_header = $page_title_for_header ?? APP_NAME; 
$body_class_for_header = $body_class_for_header ?? ''; 
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title_for_header); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/main.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/notifications.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="<?php echo htmlspecialchars(trim($body_class_for_header)); ?>">
    <header class="navbar" id="main-navbar-in-header">
        <a href="<?php echo BASE_URL; ?>index.php" class="logo">
            <svg width="40" height="40" viewBox="0 0 40 40" fill="none" style="margin-right: 10px;">
                 <circle cx="20" cy="20" r="18" fill="var(--accent, #00BCD4)"/>
                 <circle cx="20" cy="20" r="15" fill="#0097A7"/>
                 <ellipse cx="15" cy="15" rx="4" ry="6" fill="#E0F7FA" fill-opacity="0.6"/>
                 <path d="M10 26C12 28 16 29 20 29C24 29 28 28 30 26" stroke="#E0F7FA" stroke-width="1" stroke-linecap="round"/>
                 <path d="M8 18C10 20 14 21 20 21C26 21 30 20 32 18" stroke="#E0F7FA" stroke-width="1" stroke-linecap="round"/>
            </svg>
            <?php echo APP_NAME; ?>
        </a>
        <?php if ($isLoggedIn): ?>
        <button class="burger-menu-btn" id="burger-menu-toggle-btn-in-header" aria-label="Открыть меню">
            <i class="fas fa-bars"></i>
        </button>
        <?php endif; ?>
        <div class="nav-links-container ms-auto">
            <?php if ($isLoggedIn): ?>
                <nav class="nav-links">
                    <?php $current_role = $currentUserRoleHeader; ?>
                    <?php if ($current_role === 'student'): ?>
                        <a href="<?php echo BASE_URL; ?>pages/student_dashboard.php">Дашборд</a>
                    <?php elseif ($current_role === 'teacher'): ?>
                        <a href="<?php echo BASE_URL; ?>pages/dashboard.php">Группы</a>
                    <?php elseif ($current_role === 'admin'): ?>
                        <a href="<?php echo BASE_URL; ?>pages/home_admin.php">Админ-панель</a>
                    <?php endif; ?>
                    <a href="#" id="notificationsBell-in-header" class="notification nav-link-icon" title="Уведомления">
                        <i class="fas fa-bell"></i>
                        <span class="notification-indicator <?php echo $hasUnreadNotifications ? '' : 'd-none'; ?>" style="position: absolute; top: 0px; right: 0px;"></span>
                    </a>
                    <div class="notifications-dropdown" id="notificationsDropdown-in-header">
                        <div class="dropdown-header">
                            <span>Уведомления</span>
                            <button id="markAllReadBtn-in-header" class="btn-link text-primary small" type="button">Отметить все</button>
                        </div>
                        <ul class="dropdown-list" id="notificationsList-in-header">
                            <li class="loading-state p-2 text-center text-muted small">Загрузка...</li>
                        </ul>
                        <div class="dropdown-footer">
                            <a href="<?php echo BASE_URL; ?>pages/notifications.php">Все уведомления</a>
                        </div>
                    </div>
                </nav>
                <div class="user-info">
                    <a href="<?php echo BASE_URL; ?>pages/profile.php" class="user-name-link">
                        <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Профиль'); ?></span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>actions/logout.php" class="btn btn-sm btn-outline-light ms-2 nav-link-icon" title="Выход">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                    <button class="theme-toggle nav-link-icon" id="theme-toggle-btn-in-header" title="Переключить тему">🌙</button>
                </div>
            <?php else: 
                $current_page_for_nav_header = basename($_SERVER['PHP_SELF']);
                if ($current_page_for_nav_header !== 'login.php' && $current_page_for_nav_header !== 'register.php'):
            ?>
                <nav class="nav-links">
                    <a href="<?php echo BASE_URL; ?>pages/login.php">Войти</a>
                    <a href="<?php echo BASE_URL; ?>pages/register.php">Регистрация</a>
                </nav>
            <?php endif; ?>
            <button class="theme-toggle nav-link-icon" id="theme-toggle-btn-in-header" title="Переключить тему">🌙</button>
            <?php endif; ?>
        </div>
    </header>
    <?php
    if ($isLoggedIn && (!isset($hide_sidebar_for_page) || $hide_sidebar_for_page !== true) ) {
        include __DIR__ . '/sidebar.php'; 
    }
    ?>
    <main class="content-area">
    <?php 
        if (function_exists('display_message')) {
            display_message();
        }
    ?>