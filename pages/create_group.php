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
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. У вас нет прав для создания групп.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit();
}

$errors = [];
$group_name_value = '';
$description_value = '';
$page_flash_message = null; // Для флеш-сообщений из сессии 

// Получение флеш-сообщения из сессии 
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

// Обработка Post-запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $group_name_value = $name;
    $description_value = $description;

    // Валидация
    if (empty($name)) {
        $errors[] = 'Название группы обязательно для заполнения.';
    }
    if (mb_strlen($name) > 100) { 
        $errors[] = 'Название группы слишком длинное (макс. 100 символов).';
    }
    if (mb_strlen($description) > 1000) { 
        $errors[] = 'Описание группы слишком длинное (макс. 1000 символов).';
    }

    if (empty($errors)) {
        $conn = null;
        try {
            $conn = getDbConnection();
            $conn->beginTransaction();

            $sql_check = "SELECT id FROM groups WHERE LOWER(name) = LOWER(?)";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([mb_strtolower($name)]); // Сравнение без учета регистра
            if ($stmt_check->fetch()) {
                $errors[] = 'Группа с названием "' . htmlspecialchars($name) . '" уже существует.';
            } else {
                $sql_insert = "INSERT INTO groups (name, description, created_at) VALUES (?, ?, NOW())";
                $stmt_insert = $conn->prepare($sql_insert);
                if ($stmt_insert->execute([$name, $description])) {
                    $group_id = $conn->lastInsertId();
                    $conn->commit();
                    $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Группа "' . htmlspecialchars($name) . '" успешно создана. Теперь вы можете назначить ей дисциплину, преподавателей и студентов.'];
                    header('Location: ' . BASE_URL . 'pages/edit_group.php?id=' . $group_id);
                    exit;
                } else {
                    $conn->rollBack();
                    $errors[] = 'Не удалось создать группу. Ошибка базы данных при вставке.';
                }
            }
        } catch (PDOException $e) {
            if ($conn && $conn->inTransaction()) { $conn->rollBack(); }
            if ($e->getCode() == '23000' || $e->getCode() == '23505') { 
                 $errors[] = 'Группа с названием "' . htmlspecialchars($name) . '" уже существует (ошибка БД).';
            } else {
                 $errors[] = 'Произошла ошибка базы данных при создании группы: ' . $e->getMessage();
            }
            error_log("Group Creation PDO Error: " . $e->getMessage());
        } finally {
            if ($conn) { $conn = null; }
        }
    }
}

$page_title = "Создание новой группы";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'admin-page create-group-page app-page';
$load_notifications_css = true;
$load_admin_css = true;

ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-plus-circle me-2"></i>Создание новой группы</h1>
        <a href="<?php echo BASE_URL; ?>pages/admin_groups.php" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
            <i class="fas fa-arrow-left me-1"></i> К списку групп
        </a>
    </div>

    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_admin.php">Админ-панель</a></li>
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/admin_groups.php">Управление Группами</a></li>
            <li class="breadcrumb-item active" aria-current="page">Создание группы</li>
        </ol>
    </nav>

    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($errors)): // Ошибки валидации текущей формы ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <strong>Обнаружены ошибки:</strong>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="mb-3">
                    <label for="group_name_input" class="form-label">Название группы <span class="text-danger">*</span></label>
                    <input type="text" id="group_name_input" name="name" class="form-control <?php echo (isset($errors) && (in_array_str_contains('Название', $errors) || in_array_str_contains('названием', $errors))) ? 'is-invalid' : ''; ?>" required maxlength="100" value="<?php echo htmlspecialchars($group_name_value); ?>">
                </div>
                <div class="mb-3">
                    <label for="group_description_input" class="form-label">Описание группы</label>
                    <textarea id="group_description_input" name="description" class="form-control" rows="4"><?php echo htmlspecialchars($description_value); ?></textarea>
                    <div class="form-text">Краткое описание назначения или особенностей группы (необязательно).</div>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="<?php echo BASE_URL; ?>pages/admin_groups.php" class="btn btn-secondary">Отмена</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Создать группу</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
if (!function_exists('in_array_str_contains')) {
    function in_array_str_contains(string $needle, array $haystack): bool {
        foreach ($haystack as $item) {
            if (is_string($item) && stripos($item, $needle) !== false) { return true; }
        }
        return false;
    }
}
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>