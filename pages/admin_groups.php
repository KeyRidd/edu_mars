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

// Проверка аутентификации и роли
if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. У вас нет прав администратора.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit();
}
// Инициализация переменных
$groups = [];
$db_error_message = '';
$page_flash_message = null;

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

// Загрузка данных
try {
    $conn = getDbConnection();
    $sql_list = "SELECT id, name, description, created_at FROM groups ORDER BY name ASC";
    $stmt_list = $conn->query($sql_list);
    $groups = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("DB Error admin_groups.php: " . $e->getMessage());
    $db_error_message = "Произошла ошибка базы данных при загрузке списка групп.";
} finally {
    if ($conn) { $conn = null; }
}

$page_title = "Управление Группами";
$show_sidebar = true; 
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'admin-page admin-groups-page app-page'; 
$load_notifications_css = true;
$load_admin_css = true; 

ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-users-cog me-2"></i>Управление Группами</h1>
        <a href="<?php echo BASE_URL; ?>pages/create_group.php" class="btn btn-success mt-2 mt-md-0">
            <i class="fas fa-plus me-1"></i> Создать новую группу
        </a>
    </div>

    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($db_error_message)): ?>
        <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($db_error_message); ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header">
            <h2 class="h5 mb-0">Список Групп</h2>
        </div>
        <div class="card-body p-0">
            <?php if (empty($groups) && empty($db_error_message)): ?>
                <div class="text-center text-muted p-4">
                    <p class="mb-2 fs-5"><i class="fas fa-info-circle fa-2x mb-3 d-block"></i>Группы еще не созданы.</p>
                    <a href="<?php echo BASE_URL; ?>pages/create_group.php" class="btn btn-primary">Создать первую группу</a>
                </div>
            <?php elseif (!empty($groups)): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width: 5%;">ID</th>
                                <th scope="col">Название</th>
                                <th scope="col">Описание</th>
                                <th scope="col">Дата создания</th>
                                <th scope="col" class="text-end" style="width: 15%;">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $group): ?>
                                <tr>
                                    <td><?php echo $group['id']; ?></td>
                                    <td>
                                        <a href="<?php echo BASE_URL; ?>pages/edit_group.php?id=<?php echo $group['id']; ?>" title="Просмотреть информацию о группе">
                                            <?php echo htmlspecialchars($group['name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo nl2br(htmlspecialchars(truncate_text($group['description'] ?? '', 80)));?></td>
                                    <td class="text-nowrap"><?php echo htmlspecialchars(format_ru_datetime_short($group['created_at'])); ?></td>
                                    <td class="text-end">
                                        <a href="<?php echo BASE_URL; ?>pages/edit_group.php?id=<?php echo $group['id']; ?>"
                                           class="btn btn-sm btn-outline-primary me-1" title="Редактировать группу и назначения">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>pages/manage_students.php?group_id=<?php echo $group['id']; ?>"
                                           class="btn btn-sm btn-outline-info me-1" title="Управление студентами группы">
                                            <i class="fas fa-user-friends"></i>
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>actions/delete_item.php?type=group&id=<?php echo $group['id']; ?>&confirm=yes"
                                           class="btn btn-sm btn-outline-danger delete-item-btn"
                                           data-item-name="<?php echo htmlspecialchars($group['name']); ?>"
                                           title="Удалить группу">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const deleteButtons = document.querySelectorAll('.delete-item-btn');
        deleteButtons.forEach(button => {
            button.addEventListener('click', function(event) {
                const itemName = this.dataset.itemName || 'этот элемент';
                const message = `ВНИМАНИЕ! Вы уверены, что хотите удалить группу '${itemName}'?\n\nЭто действие также удалит ВСЕ связанные данные: занятия, материалы, задания, сдачи студентов и назначения преподавателей для этой группы.\n\nЭто действие НЕОБРАТИМО!`;
                if (!confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    });
</script>

<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>