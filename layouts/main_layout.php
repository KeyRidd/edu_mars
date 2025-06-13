<?php
declare(strict_types=1);
if (file_exists(dirname(__DIR__) . '/config/app_config.php')) {
    require_once dirname(__DIR__) . '/config/app_config.php';
} else {
    if (!defined('BASE_URL')) define('BASE_URL', '/project/');
    if (!defined('APP_NAME')) define('APP_NAME', 'Edu.MARS');
    if (!defined('INCLUDES_PATH')) define('INCLUDES_PATH', dirname(__DIR__) . '/includes/');
    if (!defined('LAYOUTS_PATH')) define('LAYOUTS_PATH', __DIR__ . '/');
    if (!defined('CONFIG_PATH')) define('CONFIG_PATH', dirname(__DIR__) . '/config/');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('getDbConnection')) {
    require_once CONFIG_PATH . 'database.php';
}
if (!class_exists('Auth')) { 
    require_once INCLUDES_PATH . 'auth.php';
}
if (!function_exists('is_active_page')) { 
    require_once INCLUDES_PATH . 'functions.php';
}

// Уведомления
$isLoggedInForLayout = is_logged_in();
$currentUserIdForLayout = $_SESSION['user_id'] ?? 0;
$hasUnreadNotificationsForLayout = false;

if ($isLoggedInForLayout && $currentUserIdForLayout > 0) {
    $connLayout = null; 
    try {
        $connLayout = getDbConnection();
        if (isset($_GET['mark_read']) && !headers_sent()) {
            $notification_id_to_mark = (int)$_GET['mark_read'];
            if ($notification_id_to_mark > 0) {
                 $stmt_mark = $connLayout->prepare("UPDATE notifications SET is_read = TRUE, read_at = NOW() WHERE id = :id AND user_id = :user_id AND is_read = FALSE");
                 $stmt_mark->execute([':id' => $notification_id_to_mark, ':user_id' => $currentUserIdForLayout]);
                 
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
        
        $sql_check_unread = "SELECT id, title FROM notifications WHERE user_id = ? AND is_read = FALSE ORDER BY created_at DESC LIMIT 1"; // Выберем id и title для лога
        $stmt_check_unread = $connLayout->prepare($sql_check_unread);
        $stmt_check_unread->execute([$currentUserIdForLayout]);

        $unread_notification_data = $stmt_check_unread->fetch(PDO::FETCH_ASSOC); 


        if ($unread_notification_data) { 
            $hasUnreadNotificationsForLayout = true;
    
        } else {
            $hasUnreadNotificationsForLayout = false;
        }
    } catch (Exception $e) {
        error_log("Layout Notification Check/Mark Error: " . $e->getMessage());
    } finally {
        $connLayout = null;
    }
}

// Переменные, устанавливаемые на конкретной странице
$page_title_from_page = $page_title ?? APP_NAME; 
$body_class_from_page = $body_class ?? '';
$show_sidebar_from_page = $show_sidebar ?? $isLoggedInForLayout; 
$container_class_main_from_page = $container_class_main ?? 'container';
$is_auth_page_from_page = $is_auth_page ?? false; 
$is_landing_page_from_page = $is_landing_page ?? false;

// Определяем тип отображаемого UI на основе флагов со страницы
$display_full_ui = !$is_auth_page_from_page && !$is_landing_page_from_page; // Обычная внутренняя страница
$display_landing_ui = $is_landing_page_from_page; // Лендинг или страницы с его фоном (login/register)

// Настройка финальных переменных для layout'а
$final_body_class = $body_class_from_page;
$final_show_sidebar = $show_sidebar_from_page;
$final_container_class_main = $container_class_main_from_page;

if ($display_landing_ui) {
    // Если класс landing-page-body еще не добавлен со страницы 
    if (strpos($final_body_class, 'landing-page-body') === false) {
        $final_body_class .= (empty($final_body_class) ? '' : ' ') . 'landing-page-body';
    }
    $final_show_sidebar = false;
    $final_container_class_main = ''; 
} elseif ($is_auth_page_from_page) { 
    if (strpos($final_body_class, 'auth-page-body') === false) {
         $final_body_class .= (empty($final_body_class) ? '' : ' ') . 'auth-page-body';
    }
    $final_show_sidebar = false;
    $final_container_class_main = '';
}

// Добавляем класс для управления сдвигом контента, если сайдбар будет показан
if ($final_show_sidebar && $isLoggedInForLayout) {
    $final_body_class .= (empty($final_body_class) ? '' : ' ') . 'sidebar-is-present';
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title_from_page); ?></title>
    
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=<?php echo time(); ?>">
    <?php if ($final_show_sidebar && $isLoggedInForLayout): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/sidebar.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    
    <?php // Подключение специфичных CSS файлов по флагам со страницы ?>
    <?php if (isset($load_news_css) && $load_news_css): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/news.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    <?php if (isset($load_notifications_css) && $load_notifications_css): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/notifications.css?v=<?php echo time(); ?>">
    <?php endif; ?>
      <?php if (isset($load_dashboard_styles_css) && $load_dashboard_styles_css):?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/dashboard_styles.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    <?php if (isset($load_profile_css) && $load_profile_css): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/profile_styles.css?v=<?php echo time(); ?>">
    <?php endif; ?>
     <?php if (isset($load_lesson_page_css) && $load_lesson_page_css): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/lesson_page.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    <?php if (isset($load_chat_css) && $load_chat_css): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/chat_styles.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    <?php if (isset($load_stud_css) && $load_stud_css): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/student_styles.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    <?php if (isset($load_teach_css) && $load_teach_css): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/teacher_styles.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    <?php if (isset($load_admin_css) && $load_admin_css): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/admin.css?v=<?php echo time(); ?>">
    <?php endif; ?>

    <?php echo $page_specific_css ?? ''; // Для inline <link> или <style> со страницы ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
</head>
<body class="<?php echo htmlspecialchars(trim($final_body_class)); ?>">
    <?php if ($display_landing_ui): // Для лендинга и страниц с его фоном (login, register) ?>
        <div class="landing-page-celestial-bodies">
            <div class="planet blue-planet"></div><div class="planet purple-planet"></div><div class="planet small-planet"></div>
            <div class="orbit orbit-1"></div><div class="orbit orbit-2"></div><div class="orbit orbit-3"></div><div class="orbit orbit-4"></div><div class="orbit orbit-5"></div>
        </div>
        <div class="space-bg" id="landing-space-bg">
            <div class="stars"></div><div class="aurora"></div>
        </div>
    <?php elseif ($display_full_ui): // Для обычных внутренних страниц ?>
        <div class="space-bg" id="global-space-bg">
            <div class="stars"></div><div class="aurora"></div>
        </div>
    <?php endif; ?>

    <!--Навбар -->
    <?php if ($display_full_ui):?>
        <header class="navbar" id="main-navbar">
            <a href="<?php echo BASE_URL; ?>index.php" class="logo">
                <svg width="40" height="40" viewBox="0 0 40 40" fill="none" style="margin-right: 10px;"><circle cx="20" cy="20" r="18" fill="var(--color-accent)"/><circle cx="20" cy="20" r="15" fill="#0097A7"/><ellipse cx="15" cy="15" rx="4" ry="6" fill="#E0F7FA" fill-opacity="0.6"/><path d="M10 26C12 28 16 29 20 29C24 29 28 28 30 26" stroke="#E0F7FA" stroke-width="1" stroke-linecap="round"/><path d="M8 18C10 20 14 21 20 21C26 21 30 20 32 18" stroke="#E0F7FA" stroke-width="1" stroke-linecap="round"/></svg>
                <?php echo APP_NAME; ?>
            </a>

            <?php if ($isLoggedInForLayout && $final_show_sidebar): // Кнопка бургер-меню ?>
            <button class="burger-menu-btn nav-link-icon" id="burger-menu-toggle-btn" aria-label="Открыть меню" aria-expanded="false" aria-controls="main-sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <?php endif; ?>

            <div class="nav-links-container">
                <?php if ($isLoggedInForLayout): ?>
                    <nav class="nav-links">
                        <?php $current_role_nav_layout = $_SESSION['role'] ?? null; ?>
                        <?php if ($current_role_nav_layout === 'student'): ?>
                        <?php elseif ($current_role_nav_layout === 'teacher'): ?>
                        <?php elseif ($current_role_nav_layout === 'admin'): ?>
                        <?php endif; ?>
                    </nav>
                    <div class="notification-wrapper position-relative">
                        <a href="#" id="notificationsBell" class="notification nav-link-icon <?php echo $hasUnreadNotificationsForLayout ? 'has-unread' : ''; ?>" title="Уведомления">
                            <i class="fas fa-bell"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end notification-dropdown" id="notificationsDropdown" style="display: none;">
                            <div class="dropdown-header d-flex justify-content-between align-items-center">
                                <span>Уведомления</span>
                                <button type="button" class="btn btn-sm btn-link text-primary py-0 px-1" id="markAllReadBtn" style="font-size: 0.75rem;">Все прочитаны</button>
                            </div>
                            <div id="notificationsList" class="dropdown-list-items">
                                <p class="text-center p-2 text-muted small">Загрузка...</p>
                            </div>
                            <div class="dropdown-footer text-center">
                                <a href="<?php echo BASE_URL; ?>pages/notifications.php">Все уведомления</a>
                            </div>
                        </div>
                    </div>
                    <div class="user-info">
                        <a href="<?php echo BASE_URL; ?>pages/profile.php" class="user-name-link">
                            <i class="fas fa-user-circle me-1"></i>
                            <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Профиль'); ?></span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>pages/logout.php" class="nav-link-icon" title="Выход">
                            <i class="fas fa-sign-out-alt"></i>
                        </a>
                    </div>
                <?php else:?>
                    <nav class="nav-links">
                        <a href="<?php echo BASE_URL; ?>pages/login.php">Войти</a>
                        <a href="<?php echo BASE_URL; ?>pages/register.php">Регистрация</a>
                    </nav>
                <?php endif; ?>
                <button class="theme-toggle nav-link-icon" id="theme-toggle-btn" title="Переключить тему">🌙</button>
            </div>
        </header>
     <?php elseif ($display_landing_ui): ?>
        <div class="theme-toggle-container-landing">
            <button class="theme-toggle" id="theme-toggle-btn-landing" title="Переключить тему">🌙</button>
        </div>
    <?php endif;?>
    

    <!-- Основной контент -->
    <?php if ($display_full_ui): ?>
        <div class="main-container-wrapper <?php echo htmlspecialchars(trim($final_container_class_main)); ?>">
            <?php if ($final_show_sidebar && $isLoggedInForLayout): ?>
                <?php include INCLUDES_PATH . 'sidebar.php'; ?>
            <?php endif; ?>
            <main class="content-area-wrapper <?php echo $content_area_class ?? ''; ?>">
                <?php echo $page_content ?? '<p class="text-center py-5">Контент страницы не найден.</p>'; ?>
            </main>
        </div>
    <?php else:?>
        <?php echo $page_content ?? '<p class="text-center py-5">Контент страницы не найден.</p>'; ?>
    <?php endif; ?>

    <!-- Подвал -->
    <?php if ($display_full_ui): ?>
        <footer class="site-footer" id="main-footer">
            <div class="container">
                © <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. Все права защищены.
            </div>
        </footer>
    <?php endif; ?>

     <script>
        const siteConfig = {
            baseUrl: "<?php echo BASE_URL; ?>",
            userId: <?php echo json_encode($isLoggedInForLayout ? $currentUserIdForLayout : 0); ?>,
            userRole: <?php echo json_encode($isLoggedInForLayout ? ($_SESSION['role'] ?? null) : null); ?>
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.X.X/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="<?php echo BASE_URL; ?>assets/js/main_ui.js?v=<?php echo time(); ?>" defer></script>
    <script src="<?php echo BASE_URL; ?>assets/js/main.js?v=<?php echo time(); ?>" defer></script>
    <?php echo $page_specific_js ?? ''; ?>
</body>
</html>