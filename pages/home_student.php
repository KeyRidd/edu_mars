<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1'); 
error_reporting(E_ALL);
if (!defined('BASE_URL')) {
    $script_path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    define('BASE_URL', rtrim(dirname($script_path), '/') . '/');           
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Edu.MARS');
}
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
if (!defined('LAYOUTS_PATH')) {
    define('LAYOUTS_PATH', ROOT_PATH . '/layouts/');
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', ROOT_PATH . '/includes/');
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', ROOT_PATH . '/config/');
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
if (!function_exists('format_ru_datetime')) {
    require_once INCLUDES_PATH . 'functions.php';
}
if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    $fallback_url = is_logged_in() ? BASE_URL . 'pages/dashboard.php' : BASE_URL . 'pages/login.php';
    header('Location: ' . $fallback_url);
    exit();
}

$student_id = $_SESSION['user_id'];
$news_items = [];
$group_id = null;
$group_name = null;
$db_error_message = '';

$conn = null;
try {
    $conn = getDbConnection();
    $stmt_group = $conn->prepare("SELECT group_id FROM users WHERE id = :student_id");
    $stmt_group->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt_group->execute();
    $group_id_result = $stmt_group->fetchColumn();

    if ($group_id_result !== false && $group_id_result !== null) {
        $group_id = (int)$group_id_result;
        $stmt_group_name = $conn->prepare("SELECT name FROM groups WHERE id = :group_id");
        $stmt_group_name->bindParam(':group_id', $group_id, PDO::PARAM_INT);
        $stmt_group_name->execute();
        $group_name = $stmt_group_name->fetchColumn();
        $sql_news = "
            SELECT
                n.id,
                n.title,
                n.content,
                n.created_at,
                u.full_name as author_name,
                EXISTS (SELECT 1 FROM news_read_status rs WHERE rs.news_id = n.id AND rs.user_id = :student_id) AS is_read
            FROM news n
            JOIN users u ON n.author_user_id = u.id
            WHERE n.group_id = :group_id AND n.is_published = TRUE
            ORDER BY n.created_at DESC
            LIMIT 5
        ";
        $stmt_news = $conn->prepare($sql_news);
        $stmt_news->bindParam(':student_id', $student_id, PDO::PARAM_INT);
        $stmt_news->bindParam(':group_id', $group_id, PDO::PARAM_INT);
        $stmt_news->execute();
        $news_items = $stmt_news->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $db_error_message = "Вы не привязаны к группе, новости недоступны.";
    }
} catch (PDOException $e) {
    error_log("DB Error on home_student.php for student ID {$student_id}: " . $e->getMessage());
    $db_error_message = "Произошла ошибка при загрузке данных страницы.";
} finally {
    $conn = null;
}
$page_title = "Мой дашборд - " . htmlspecialchars($_SESSION['full_name'] ?? '');
$show_sidebar = true; 
$is_auth_page = false; 
$is_landing_page = false; 
$body_class = 'student-home-page';
$load_news_css = true;
$load_notifications_css = true;
$page_specific_js = '';
if ($student_id > 0) {
    $page_specific_js = '<script>
        const homeConfig = { 
            apiMarkReadUrl: ' . json_encode(BASE_URL . 'api/news_mark_read.php') . ',
            userId: ' . json_encode($student_id) . ' // или $teacher_id
        };
    </script>
    <script src="' . BASE_URL . 'assets/js/news_read_marker.js?v=' . time() . '" defer></script>';
}
ob_start();
?>
<div class="container py-4"> 
    <?php
    if (isset($_SESSION['message_flash'])) { 
        $flash_msg = $_SESSION['message_flash'];
        echo '<div class="alert alert-' . htmlspecialchars($flash_msg['type']) . ' mb-3">' . htmlspecialchars($flash_msg['text']) . '</div>';
        unset($_SESSION['message_flash']);
    }
    if (!empty($db_error_message)): ?>
         <div class="alert alert-warning mb-3"><?php echo htmlspecialchars($db_error_message); ?></div>
    <?php endif; ?>
    
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
       <?php
            // Получаем полное имя из сессии
            $full_name_from_session = $_SESSION['full_name'] ?? 'студент';
            $student_first_name = $full_name_from_session; // Значение по умолчанию, если не удастся извлечь имя

            // Пытаемся извлечь только имя (второе слово, если ФИО разделено пробелами)
            if ($full_name_from_session !== 'студент') {
                $name_parts = explode(' ', $full_name_from_session);
                if (isset($name_parts[1]) && !empty($name_parts[1])) { // Проверяем, есть ли второе слово (имя)
                    $student_first_name = $name_parts[1];
                } elseif (isset($name_parts[0]) && !empty($name_parts[0])) { 
                    $student_first_name = $name_parts[0]; 
                }
            }
            ?>
            <h1 class="h2 mb-0">Добро пожаловать, <?php echo htmlspecialchars($student_first_name); ?>!</h1>
        <div class="color-legend small">
            <span data-legend-item="unread">
                <span class="legend-dot" style="background-color: var(--color-primary);"></span> - Новая новость
            </span>
            <span data-legend-item="read">
                <span class="legend-dot" style="background-color: var(--border-color);"></span> - Прочитанная новость
            </span>
        </div>
    </div>
     <?php if ($group_name): ?>
        <p class="text-muted group-news-intro mb-4">Последние новости вашей группы: <strong><?php echo htmlspecialchars($group_name); ?></strong></p>
     <?php elseif (!$group_id && empty($db_error_message)):?>
         <p class="text-muted group-news-intro mb-4">Вы не состоите в группе.</p>
     <?php else: ?>
         <p class="text-muted group-news-intro mb-4">Общие новости или информация.</p>
     <?php endif; ?>
    <div class="news-feed">
        <?php if ($group_id && empty($news_items) && empty($db_error_message)):?>
            <div class="card p-4 text-center">
                <p class="mb-0 text-muted">Пока нет новостей для вашей группы.</p>
            </div>
        <?php elseif (!empty($news_items)): ?>
            <?php foreach ($news_items as $news): ?>
                <div class="card news-item mb-3 <?php echo !$news['is_read'] ? 'news-unread' : 'news-read'; ?>" data-news-id="<?php echo (int)$news['id']; ?>">
                     <div class="card-header news-header d-flex justify-content-between align-items-center">
                        <h5 class="news-title mb-0"><?php echo htmlspecialchars($news['title']); ?></h5>
                         <?php if (!$news['is_read']): ?>
                             <span class="badge bg-primary news-badge">Новое</span>
                         <?php endif; ?>
                    </div>
                    <div class="card-body news-content">
                         <?php echo nl2br(htmlspecialchars($news['content'], ENT_QUOTES, 'UTF-8'));?>
                    </div>
                    <div class="card-footer news-footer text-muted small">
                         Опубликовано: <?php echo htmlspecialchars($news['author_name'] ?? 'Неизвестно'); ?> |
                         Дата: <?php echo htmlspecialchars(format_ru_datetime($news['created_at'])); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php elseif (!$group_id && !empty($db_error_message)):?>
        <?php elseif (empty($news_items) && empty($db_error_message)):?>
             <div class="card p-4 text-center">
                <p class="mb-0 text-muted">Новостей нет.</p>
            </div>
        <?php endif; ?>
    </div>

</div>
<?php
$page_content = ob_get_clean(); 
require_once LAYOUTS_PATH . 'main_layout.php'; 
?>
</html>