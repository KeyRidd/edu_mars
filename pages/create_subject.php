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
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. У вас нет прав для создания дисциплин.']; 
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit();
}

$errors = [];
$form_data = [ // Используем массив для предзаполнения
    'name' => '',
    'description' => '',
    'min_passing_grade' => 61 // Значение по умолчанию
];
$page_flash_message = null;

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

// Обработка Post-запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Получаем и очищаем данные, сохраняем в $form_data
    $form_data['name'] = trim($_POST['name'] ?? '');
    $form_data['description'] = trim($_POST['description'] ?? '');
    $min_passing_grade_input = $_POST['min_passing_grade'] ?? null;
    $form_data['min_passing_grade'] = 61; // Дефолт, если не валидно или пусто

    // Валидация
    if (empty($form_data['name'])) {
        $errors[] = 'Название дисциплины обязательно для заполнения.';
    }
    if (mb_strlen($form_data['name']) > 255) { // Ограничение для названия предмета
        $errors[] = 'Название дисциплины слишком длинное (макс. 255 символов).';
    }

    if ($min_passing_grade_input !== null && $min_passing_grade_input !== '') {
         if (!is_numeric($min_passing_grade_input)) {
             $errors[] = 'Проходной балл должен быть числом.';
         } else {
             $form_data['min_passing_grade'] = (int)$min_passing_grade_input;
             if ($form_data['min_passing_grade'] < 0 || $form_data['min_passing_grade'] > 1000) {
                  $errors[] = 'Проходной балл должен быть в диапазоне от 0 до 1000.';
             }
         }
    }

    if (empty($errors)) {
        $conn = null;
        try {
            $conn = getDbConnection();
            $conn->beginTransaction();

            // Проверка уникальности имени предмета
            $sql_check_subject = "SELECT id FROM subjects WHERE LOWER(name) = LOWER(?)";
            $stmt_check_subject = $conn->prepare($sql_check_subject);
            $stmt_check_subject->execute([mb_strtolower($form_data['name'])]);
            if ($stmt_check_subject->fetch()) {
                $errors[] = 'Дисциплина с названием "' . htmlspecialchars($form_data['name']) . '" уже существует.';
            } else {
                // Создание нового предмета
                $sql_insert_subject = "INSERT INTO subjects (name, description, min_passing_grade, created_at) VALUES (?, ?, ?, NOW())";
                $stmt_insert_subject = $conn->prepare($sql_insert_subject);
                if ($stmt_insert_subject->execute([$form_data['name'], $form_data['description'], $form_data['min_passing_grade']])) {
                    $subject_id = $conn->lastInsertId();
                    $conn->commit();
                    $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Дисциплина "' . htmlspecialchars($form_data['name']) . '" успешно создан.'];
                    header('Location: ' . BASE_URL . 'pages/admin_subjects.php'); 
                    exit;
                } else {
                    $conn->rollBack();
                    $errors[] = 'Не удалось создать дисциплину. Ошибка базы данных при вставке.';
                    error_log("Subject Creation Error (PDO Insert): " . implode(" | ", $stmt_insert_subject->errorInfo()));
                }
            }
        } catch (PDOException $e) {
            if ($conn && $conn->inTransaction()) { $conn->rollBack(); }
            if (in_array($e->getCode(), ['23000', '23505'])) {
                 $errors[] = 'Дисциплина с названием "' . htmlspecialchars($form_data['name']) . '" уже существует (ошибка БД).';
            } else {
                 $errors[] = 'Произошла ошибка базы данных при создании дисциплины: ' . $e->getMessage();
            }
            error_log("Subject Creation PDO Error: " . $e->getMessage());
        } finally {
            if ($conn) { $conn = null; }
        }
    }
}

$page_title = "Создание новой дисциплины";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'admin-page create-subject-page app-page'; 
$load_notifications_css = true;
$load_admin_css = true;

ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-book-medical me-2"></i>Создание новой дисциплины</h1>
        <a href="<?php echo BASE_URL; ?>pages/admin_subjects.php" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
            <i class="fas fa-arrow-left me-1"></i> К списку дисциплин
        </a>
    </div>

    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_admin.php">Админ-панель</a></li>
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/admin_subjects.php">Управление дисциплинами</a></li>
            <li class="breadcrumb-item active" aria-current="page">Создание дисциплины</li>
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
        <div class="card-body p-4">
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="needs-validation" novalidate>
                <div class="row g-3">
                    <div class="col-md-7 mb-3">
                        <label for="subject_name_input" class="form-label">Название дисциплины <span class="text-danger">*</span></label>
                        <input type="text" id="subject_name_input" name="name" class="form-control" required maxlength="255" value="<?php echo htmlspecialchars($form_data['name']); ?>">
                        <div class="invalid-feedback">Пожалуйста, введите название дисциплины.</div>
                    </div>

                    <div class="col-md-5 mb-3">
                        <label for="min_passing_grade_input" class="form-label">Проходной балл</label>
                        <input type="number" id="min_passing_grade_input" name="min_passing_grade" class="form-control" min="0" max="1000" step="1" value="<?php echo htmlspecialchars((string)$form_data['min_passing_grade']); ?>">
                        <div class="form-text">По умолчанию: 61. Целое число от 0 до 1000.</div>
                    </div>

                    <div class="col-12 mb-3">
                        <label for="subject_description_input" class="form-label">Описание дисциплины</label>
                        <textarea id="subject_description_input" name="description" class="form-control" rows="4"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                        <div class="form-text">Краткое описание или цели дисциплины (необязательно).</div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="<?php echo BASE_URL; ?>pages/admin_subjects.php" class="btn btn-secondary">Отмена</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle me-1"></i>Создать дисциплину</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();

// ----- ПОДКЛЮЧЕНИЕ ОСНОВНОГО МАКЕТА -----
require_once LAYOUTS_PATH . 'main_layout.php';
?>