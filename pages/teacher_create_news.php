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

if (!is_logged_in() || !in_array($_SESSION['role'] ?? null, ['teacher', 'admin'])) {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен.'];
    header('Location: ' . BASE_URL . 'pages/teacher_create_news.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$errors = [];
$assigned_groups = [];
$form_data = ['group_id' => '', 'title' => '', 'content' => ''];
$page_flash_message = null;

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Получаем список групп, к которым назначен преподаватель 
    if ($role === 'teacher') {
        $sql_groups = "SELECT DISTINCT g.id, g.name FROM groups g JOIN teaching_assignments ta ON g.id = ta.group_id WHERE ta.teacher_id = ? ORDER BY g.name ASC";
        $stmt_groups = $conn->prepare($sql_groups);
        $stmt_groups->execute([$user_id]);
        $assigned_groups = $stmt_groups->fetchAll(PDO::FETCH_ASSOC);
        $stmt_groups = null;
    } elseif ($role === 'admin') {
        $sql_groups = "SELECT id, name FROM groups ORDER BY name ASC";
        $stmt_groups = $conn->query($sql_groups);
        $assigned_groups = $stmt_groups->fetchAll(PDO::FETCH_ASSOC);
        $stmt_groups = null;
    }
    // Обработка POST запроса
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        // Сохраняем введенные данные для предзаполнения
        $form_data = ['group_id' => $group_id, 'title' => $title, 'content' => $content];
        // Валидация
        if ($group_id <= 0) {
            $errors[] = 'Необходимо выбрать группу.';
        } else {
            // Проверка, доступна ли выбранная группа текущему пользователю
            $is_group_valid = false;
            foreach ($assigned_groups as $g) {
                if ($g['id'] === $group_id) { $is_group_valid = true; break; }
            }
            if (!$is_group_valid) { $errors[] = 'Выбранная группа недоступна.'; }
        }
        if (empty($title)) { $errors[] = 'Заголовок новости обязателен.'; }
        if (mb_strlen($title) > 255) { $errors[] = 'Заголовок слишком длинный (макс. 255 символов).'; }
        if (empty($content)) { $errors[] = 'Текст новости обязателен.'; }

        // Сохранение в БД, если нет ошибок
        if (empty($errors)) {
            try {
                $sql_insert = "INSERT INTO news (group_id, author_user_id, title, content) VALUES (?, ?, ?, ?)";
                $stmt_insert = $conn->prepare($sql_insert);
                if ($stmt_insert->execute([$group_id, $user_id, $title, $content])) {
                    $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Новость успешно создана и опубликована.']; 
                    header('Location: ' . BASE_URL . 'pages/teacher_create_news.php'); 
                    exit();
                } else {
                     $errors[] = 'Не удалось сохранить новость в базу данных.';
                     error_log("News Insert Error: " . implode(" | ", $stmt_insert->errorInfo()));
                }
            } catch (PDOException $e) {
                 $errors[] = 'Ошибка базы данных при сохранении новости.';
                 error_log("News Insert PDO Error: " . $e->getMessage());
            }
        }
    } 
} catch (PDOException $e) {
    error_log("DB Error on teacher_create_news.php: " . $e->getMessage());
    $errors[] = "Произошла ошибка базы данных при загрузке страницы.";
} finally {
    $conn = null;
}
$page_title = "Создать Новость";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = ($role === 'admin' ? 'admin-page' : 'teacher-page') . ' create-news-page app-page';
$load_notifications_css = true;
$load_teach_css = true;
ob_start();
?>
<div class="container py-4">
    <div class="page-header mb-4">
        <h1 class="h2"><i class="fas fa-newspaper me-2"></i>Создание новости для группы</h1>
    </div>
    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <strong>Обнаружены ошибки при создании новости:</strong>
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
                    <label for="group_id_select" class="form-label">Группа <span class="text-danger">*</span></label>
                    <select name="group_id" id="group_id_select" class="form-select <?php echo (isset($errors) && in_array_str_contains('группу', $errors)) ? 'is-invalid' : ''; ?>" required>
                        <option value="">-- Выберите группу --</option>
                        <?php if (empty($assigned_groups)): ?>
                            <option value="" disabled>Нет доступных вам групп</option>
                        <?php else: ?>
                            <?php foreach ($assigned_groups as $group): ?>
                                <option value="<?php echo $group['id']; ?>" <?php echo ((string)($form_data['group_id'] ?? '') === (string)$group['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($group['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($assigned_groups) && $role === 'teacher'): ?>
                         <div class="form-text text-danger mt-1">Вы не назначены ни на одну группу, поэтому не можете создать новость.</div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="news_title" class="form-label">Заголовок <span class="text-danger">*</span></label>
                    <input type="text" id="news_title" name="title" class="form-control <?php echo (isset($errors) && (in_array_str_contains('Заголовок', $errors) || in_array_str_contains('заголовок', $errors))) ? 'is-invalid' : ''; ?>" required maxlength="255" value="<?php echo htmlspecialchars($form_data['title'] ?? ''); ?>">
                </div>

                <div class="mb-3">
                    <label for="news_content" class="form-label">Текст новости <span class="text-danger">*</span></label>
                    <textarea id="news_content" name="content" class="form-control <?php echo (isset($errors) && (in_array_str_contains('Текст', $errors) || in_array_str_contains('текст', $errors))) ? 'is-invalid' : ''; ?>" rows="10" required><?php echo htmlspecialchars($form_data['content'] ?? ''); ?></textarea>
                    <div class="form-text">Поддерживается базовое HTML-форматирование, если ваш обработчик на стороне сервера и вывод это допускают и обрабатывают безопасно.</div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="<?php echo BASE_URL . ($role === 'admin' ? 'pages/home_admin.php' : 'pages/home_teacher.php'); ?>" class="btn btn-secondary">Отмена</a>
                    <button type="submit" class="btn btn-primary" <?php echo empty($assigned_groups) ? 'disabled' : ''; ?>>
                        <i class="fas fa-paper-plane me-1"></i>Опубликовать новость
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>