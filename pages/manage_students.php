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
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Пожалуйста, войдите в систему.'];
    header('Location: ' . BASE_URL . 'pages/login.php');
    exit;
}

$current_user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? null;
$is_admin = ($role === 'admin');
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$has_access = false;
$conn = null;
$group = null;
$students = [];
$errors = []; // Для ошибок, которые должны отображаться сразу на странице
$page_flash_message = null; // Для флеш-сообщений из сессии

// Получение флеш-сообщения из сессии 
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}
if (isset($_SESSION['message'])) {
    if (!$page_flash_message) { 
        $page_flash_message = $_SESSION['message'];
    }
    unset($_SESSION['message']);
}

if ($group_id <= 0) {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Некорректный ID группы.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit;
}

try {
    $conn = getDbConnection();

    // Получаем информацию о группе
    $sql_group = "SELECT name FROM groups WHERE id = ?";
    $stmt_group = $conn->prepare($sql_group);
    $stmt_group->execute([$group_id]);
    $group = $stmt_group->fetch(PDO::FETCH_ASSOC);
    $stmt_group = null;

    if (!$group) {
        $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Группа не найдена.'];
        header('Location: ' . BASE_URL . 'pages/dashboard.php');
        exit;
    }

    // Проверка доступа
    if ($role === 'admin') {
        $has_access = true;
    } elseif ($role === 'teacher') {
        $sql_teacher_access = "SELECT 1 FROM teaching_assignments WHERE teacher_id = ? AND group_id = ? LIMIT 1";
        $stmt_teacher_access = $conn->prepare($sql_teacher_access);
        $stmt_teacher_access->execute([$current_user_id, $group_id]);
        $has_access = (bool)$stmt_teacher_access->fetchColumn();
        $stmt_teacher_access = null;
    }

    if (!$has_access) {
        $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'У вас нет доступа к управлению студентами этой группы.'];
        header('Location: ' . BASE_URL . 'pages/dashboard.php');
        exit;
    }

    // Получение списка студентов этой группы
    $sql_students = "SELECT u.id, u.full_name, u.email, u.created_at
                     FROM users u
                     WHERE u.role = 'student' AND u.group_id = ?
                     ORDER BY u.full_name ASC";
    $stmt_students = $conn->prepare($sql_students);
    $stmt_students->execute([$group_id]);
    $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
    $stmt_students = null;

} catch (PDOException $e) {
    error_log("Database Error in manage_students.php: " . $e->getMessage());
    $errors[] = "Произошла ошибка базы данных при загрузке списка студентов.";
} finally {
    $conn = null;
}

$page_title = "Студенты Группы: " . htmlspecialchars($group['name'] ?? 'Группа не найдена');
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = ($is_admin ? 'admin-page' : 'teacher-page') . ' manage-students-page app-page';
$load_notifications_css = true;
if ($is_admin) {
    $load_admin_css = true;
} else {
    $load_teach_css = true; 
}

$page_specific_js = '
<script>
    function redirectToUserEdit(userId) {
        window.location.href = "' . BASE_URL . 'pages/admin_users.php?role=student&search=" + userId;
    }
</script>
';

ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-user-graduate me-2"></i>Студенты Группы "<?php echo htmlspecialchars($group['name'] ?? 'Ошибка'); ?>"</h1>
        <a href="<?php echo BASE_URL; ?>pages/dashboard.php?group_id=<?php echo $group_id; ?>" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
            <i class="fas fa-arrow-left me-1"></i> Вернуться к группе
        </a>
    </div>

    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
        <ol class="breadcrumb mb-0">
            <?php if ($is_admin): ?>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_admin.php">Админ-панель</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/admin_groups.php">Управление Группами</a></li>
            <?php else: ?>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/teacher_dashboard.php">Мои группы</a></li>
            <?php endif; ?>
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/dashboard.php?group_id=<?php echo $group_id; ?>">Группа: <?php echo htmlspecialchars($group['name'] ?? 'Ошибка'); ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Список студентов</li>
        </ol>
    </nav>

    <?php // Отображение флеш-сообщений ?>
    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php // Отображение ошибок, возникших на этой странице ?>
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
            <h5 class="mb-0">Список студентов</h5>
        </div>
        <div class="card-body">
            <p class="small text-muted">Управление добавлением/удалением студентов в группу осуществляется на странице <a href="<?php echo BASE_URL; ?>pages/admin_users.php">Управления Пользователями</a> (через редактирование профиля студента).</p>

            <?php if (empty($students) && empty($errors)): // Показываем, только если нет других ошибок ?>
                <div class="alert alert-info text-center py-4">
                    <i class="fas fa-info-circle fa-2x mb-3 d-block"></i>
                    В этой группе пока нет студентов.
                </div>
            <?php elseif (!empty($students)): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover users-table">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Полное имя</th>
                                <th>Email</th>
                                <th>Зарегистрирован</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                            <tr>
                                <td><?php echo $student['id']; ?></td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>pages/profile.php?id=<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['full_name']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($student['email']); ?></td>
                                <td><?php echo htmlspecialchars(format_ru_datetime($student['created_at'], false)); ?></td>
                                <td class="actions">
                                    <a href="<?php echo BASE_URL; ?>pages/profile.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-outline-info" title="Просмотр профиля">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($is_admin): ?>
                                         <button type="button" onclick="redirectToUserEdit(<?php echo $student['id']; ?>)" class="btn btn-sm btn-outline-primary ms-1" title="Редактировать пользователя (изменить группу)">
                                             <i class="fas fa-user-edit"></i>
                                         </button>
                                    <?php endif; ?>
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