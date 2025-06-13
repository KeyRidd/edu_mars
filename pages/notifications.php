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

require_once CONFIG_PATH . 'database.php';
require_once INCLUDES_PATH . 'functions.php'; 
require_once INCLUDES_PATH . 'auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in()) {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Пожалуйста, войдите в систему для просмотра уведомлений.'];
    header('Location: ' . BASE_URL . 'pages/login.php');
    exit();
}

$user_id_for_page = $_SESSION['user_id'];
$all_notifications_data = [];
$errors = []; // Для ошибок, отображаемых на странице
$page_flash_message = null; // Для флеш-сообщений из сессии
$unread_count_on_page_load = 0;

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}
if (isset($_SESSION['message'])) {
    if (!$page_flash_message && !empty($_SESSION['message']['text'])) {
        $page_flash_message = $_SESSION['message'];
    }
    unset($_SESSION['message']);
}

$conn = null;
try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Загружаем все уведомления пользователя
    $sql_load_all_notifications = "SELECT id, title, message, related_url, is_read, created_at, read_at, type, sender_id
                                   FROM notifications
                                   WHERE user_id = :user_id
                                   ORDER BY created_at DESC"; 
    $stmt_load_all = $conn->prepare($sql_load_all_notifications);
    $stmt_load_all->bindParam(':user_id', $user_id_for_page, PDO::PARAM_INT);
    $stmt_load_all->execute();
    $all_notifications_data = $stmt_load_all->fetchAll(PDO::FETCH_ASSOC);
    $stmt_load_all = null;

    foreach($all_notifications_data as $n_item) { if(!$n_item['is_read']) $unread_count_on_page_load++; }

} catch (PDOException $e) {
    error_log("DB Error notifications.php: " . $e->getMessage());
    $errors[] = "Ошибка загрузки уведомлений: " . htmlspecialchars($e->getMessage());
} finally {
    $conn = null;
}

$page_title = "Мои Уведомления";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'notifications-page app-page';
$load_notifications_css = true;
$page_specific_js = '
<script>
document.addEventListener("DOMContentLoaded", function() {
    const markAllButtonPage = document.getElementById("markAllReadPageBtn");
    if (markAllButtonPage) {
        markAllButtonPage.addEventListener("click", async () => {
            if (!confirm("Отметить все уведомления как прочитанные?")) return;
            markAllButtonPage.disabled = true;
            markAllButtonPage.innerHTML = \'<i class="fas fa-spinner fa-spin me-1"></i> Обработка...\';
            if (typeof siteConfig === "undefined" || !siteConfig.baseUrl) {
                console.error("siteConfig.baseUrl is not defined! Cannot mark all read.");
                alert("Ошибка конфигурации клиента.");
                markAllButtonPage.disabled = false;
                markAllButtonPage.innerHTML = \'<i class="fas fa-check-double me-1"></i> Отметить все как прочитанные\';
                return;
            }
            const apiUrl = `${siteConfig.baseUrl}api/notifications_mark_all_read.php`;

            try {
                const response = await fetch(apiUrl, { method: "POST" });
                const data = await response.json();
                if (data.success) {
                    document.querySelectorAll(".notification-page-item.unread").forEach(item => {
                        item.classList.remove("unread");
                        const metaElement = item.querySelector(".notification-page-meta");
                        if (metaElement && !metaElement.textContent.includes("Прочитано:")) {
                            metaElement.innerHTML += \' | <span class="text-success">Прочитано: только что</span>\';
                        }
                    });
                    markAllButtonPage.innerHTML = \'<i class="fas fa-check me-1"></i> Готово\';
                    markAllButtonPage.disabled = true; 
                    const bellIconGlobal = document.getElementById("notificationsBell");
                    if (bellIconGlobal) bellIconGlobal.classList.remove("has-unread");
                    const countBadgeGlobal = document.getElementById("notification-count-badge");
                    if (countBadgeGlobal) countBadgeGlobal.style.display = "none";
                } else {
                    alert(`Ошибка: ${data.message || "Не удалось отметить все."}`);
                    markAllButtonPage.innerHTML = \'<i class="fas fa-check-double me-1"></i> Отметить все как прочитанные\';
                    markAllButtonPage.disabled = false;
                }
            } catch (error) {
                console.error("Error marking all read:", error);
                alert("Сетевая ошибка при попытке отметить все уведомления.");
                markAllButtonPage.innerHTML = \'<i class="fas fa-check-double me-1"></i> Отметить все как прочитанные\';
                markAllButtonPage.disabled = false;
            }
        });
    }
    if (window.location.hash) {
        const elementToScroll = document.querySelector(window.location.hash);
        if (elementToScroll) {
            elementToScroll.scrollIntoView({ behavior: "smooth", block: "center" });
            // Временная подсветка
            elementToScroll.style.transition = "background-color 0.3s ease-in-out";
            elementToScroll.classList.add("highlighted-notification");
            setTimeout(() => {
                elementToScroll.classList.remove("highlighted-notification");
            }, 2500);
        }
    }
});
</script>
';
ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-3 page-actions-header flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-bell me-2"></i>Мои Уведомления</h1>
        <div class="page-actions">
            <button id="markAllReadPageBtn" class="btn btn-sm btn-outline-primary" <?php echo ($unread_count_on_page_load === 0) ? 'disabled' : ''; ?>>
                <i class="fas fa-check-double me-1"></i> Отметить все как прочитанные
            </button>
        </div>
    </div>

    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/dashboard.php">Панель управления</a></li>
            <li class="breadcrumb-item active" aria-current="page">Мои Уведомления</li>
        </ol>
    </nav>
    <?php // Отображение флеш-сообщений и ошибок ?>
    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <strong>Обнаружены ошибки:</strong>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?><li><?php echo $error; ?></li><?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($all_notifications_data) && empty($errors)): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-info-circle fa-3x text-muted mb-3 d-block"></i>
                <p class="mb-0">У вас пока нет уведомлений.</p>
            </div>
        </div>
    <?php elseif (!empty($all_notifications_data)): ?>
        <div class="notifications-list-page">
            <?php foreach ($all_notifications_data as $n_item): ?>
                <div class="card notification-page-item shadow-sm <?php echo !$n_item['is_read'] ? 'unread' : ''; ?>" id="notification-<?php echo $n_item['id']; ?>">
                    <div class="card-body">
                        <div class="d-flex">
                            <div class="flex-shrink-0 me-3">
                                <i class="fas <?php echo get_notification_icon($n_item['type'] ?? 'default'); ?> fa-2x text-muted"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h2 class="card-title h5"><?php echo htmlspecialchars($n_item['title'] ?: 'Уведомление'); ?></h2>
                                <p class="notification-full-message mb-2"><?php echo nl2br(htmlspecialchars($n_item['message'])); ?></p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <p class="notification-page-meta mb-0">
                                        Получено: <?php echo htmlspecialchars(format_ru_datetime($n_item['created_at'])); ?>
                                        <?php if ($n_item['is_read'] && $n_item['read_at']): ?>
                                            | <span class="text-success">Прочитано: <?php echo htmlspecialchars(format_ru_datetime($n_item['read_at'])); ?></span>
                                        <?php endif; ?>
                                    </p>
                                    <div class="actions">
                                        <?php if (!$n_item['is_read'] && (empty($n_item['related_url']) || !$final_url_page) ): // Кнопка "Отметить", если нет осмысленной ссылки "Перейти" ?>
                                           <a href="?mark_read=<?php echo $n_item['id']; ?>#notification-<?php echo $n_item['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Отметить прочитанным">
                                                <i class="fas fa-eye"></i>
                                           </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>