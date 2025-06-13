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

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. У вас нет прав администратора.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit();
}

$conn = null;
$subjects = [];
$errors = []; // Для ошибок загрузки списка
$page_flash_message = null;

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


try {
    $conn = getDbConnection();

    // Получение списка всех предметов для отображения
    $sql_list_subjects = "SELECT s.id, s.name, s.description, s.min_passing_grade, s.created_at, COUNT(DISTINCT ta.teacher_id) as teacher_count
                          FROM subjects s
                          LEFT JOIN teaching_assignments ta ON s.id = ta.subject_id
                          GROUP BY s.id, s.name, s.description, s.min_passing_grade, s.created_at
                          ORDER BY s.name ASC";
    $stmt_list_subjects = $conn->query($sql_list_subjects);
    $subjects = $stmt_list_subjects->fetchAll(PDO::FETCH_ASSOC);
    $stmt_list_subjects = null;

} catch (PDOException $e) {
    error_log("Database Error in admin_subjects.php (listing): " . $e->getMessage());
    $errors[] = "Произошла ошибка базы данных при загрузке списка дисциплин.";
} finally {
    $conn = null;
}

$page_title = "Управление Дисциплинами";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'admin-page manage-subjects-page app-page'; 
$load_notifications_css = true;
$load_admin_css = true;

// Для подтверждения удаления
$page_specific_js = '
<script>
document.addEventListener("DOMContentLoaded", function() {
    const deleteButtons = document.querySelectorAll(".delete-item-btn");
    deleteButtons.forEach(function(button) {
        button.addEventListener("click", function(event) {
            const itemName = this.dataset.itemName || "этот элемент";
            const message = `Вы уверены, что хотите удалить ${itemName}? Это действие необратимо и может повлиять на связанные данные (занятия, назначения).`;
            if (!confirm(message)) {
                event.preventDefault();
            }
        });
    });
});
</script>
';

ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-book me-2"></i>Управление Дисциплинами</h1>
        <a href="<?php echo BASE_URL; ?>pages/create_subject.php" class="btn btn-success btn-sm mt-2 mt-md-0">
            <i class="fas fa-plus-circle me-1"></i> Создать новую дисциплину
        </a>
    </div>

    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_admin.php">Админ-панель</a></li>
            <li class="breadcrumb-item active" aria-current="page">Управление дисциплинами</li>
        </ol>
    </nav>

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
                <?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Список Дисциплин</h5>
        </div>
        <div class="card-body">
            <?php if (empty($subjects) && empty($errors)): ?>
                <div class="alert alert-info text-center py-4">
                    <i class="fas fa-info-circle fa-2x mb-3 d-block"></i>
                    Дисциплины еще не добавлены. Вы можете <a href="<?php echo BASE_URL; ?>pages/create_subject.php" class="alert-link">создать первую дисциплину</a>.
                </div>
            <?php elseif (!empty($subjects)): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th class="text-center">Прох. балл</th>
                                <th>Описание</th>
                                <th class="text-center">Назначено преп.</th>
                                <th>Дата создания</th>
                                <th class="text-end">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subject_item): ?>
                                <tr>
                                    <td><?php echo $subject_item['id']; ?></td>
                                    <td>
                                        <a href="<?php echo BASE_URL; ?>pages/edit_subject.php?id=<?php echo $subject_item['id']; ?>">
                                            <?php echo htmlspecialchars($subject_item['name']); ?>
                                        </a>
                                    </td>
                                    <td class="text-center"><?php echo htmlspecialchars((string)($subject_item['min_passing_grade'] ?? '-')); ?></td>
                                    <td><?php echo nl2br(htmlspecialchars(truncate_text($subject_item['description'] ?? '', 70))); ?></td>
                                    <td class="text-center"><?php echo $subject_item['teacher_count']; ?></td>
                                    <td><?php echo htmlspecialchars(format_ru_datetime($subject_item['created_at'], false)); ?></td>
                                    <td class="text-end actions-cell">
                                        <a href="<?php echo BASE_URL; ?>pages/edit_subject.php?id=<?php echo $subject_item['id']; ?>" class="btn btn-sm btn-outline-primary" title="Редактировать">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>pages/admin_users.php?role=teacher&filter_subject=<?php echo $subject_item['id']; ?>" class="btn btn-sm btn-outline-info ms-1" title="Список преподавателей этой дисциплины">
                                            <i class="fas fa-chalkboard-teacher"></i>
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>actions/delete_item.php?type=subject&id=<?php echo $subject_item['id']; ?>&confirm=yes"
                                           class="btn btn-sm btn-outline-danger ms-1 delete-item-btn"
                                           data-item-name="дисциплина '<?php echo htmlspecialchars(addslashes($subject_item['name'])); ?>'"
                                           title="Удалить дисциплину">
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

<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>