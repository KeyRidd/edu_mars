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
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. У вас нет прав для редактирования дисциплин.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit();
}

$subject_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($subject_id <= 0) {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Некорректный ID дисциплины.'];
    header('Location: ' . BASE_URL . 'pages/admin_subjects.php');
    exit();
}

$errors = [];
$form_data = null;
$page_flash_message = null;
$conn = null;

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}
// Для предзаполнения формы при ошибке валидации
if (isset($_SESSION['form_data_subject_edit'])) {
    $form_data = $_SESSION['form_data_subject_edit'];
    unset($_SESSION['form_data_subject_edit']);
}
if (isset($_SESSION['form_errors_subject_edit'])) {
    $errors = array_merge($errors, $_SESSION['form_errors_subject_edit']);
    unset($_SESSION['form_errors_subject_edit']);
}
// Обработка Post-запроса (обновление предмета)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data['id'] = $subject_id; // ID редактируемого предмета
    $form_data['name'] = trim($_POST['name'] ?? '');
    $form_data['description'] = trim($_POST['description'] ?? '');
    $min_passing_grade_input_post = $_POST['min_passing_grade'] ?? null;
    $form_data['min_passing_grade'] = 61; 

    // Валидация
    if (empty($form_data['name'])) { $errors[] = 'Название дисциплины обязательно.'; }
    if (mb_strlen($form_data['name']) > 255) { $errors[] = 'Название дисциплины слишком длинное.'; }
    if ($min_passing_grade_input_post !== null && $min_passing_grade_input_post !== '') {
         if (!is_numeric($min_passing_grade_input_post)) { $errors[] = 'Проходной балл должен быть числом.'; }
         else {
             $form_data['min_passing_grade'] = (int)$min_passing_grade_input_post;
             if ($form_data['min_passing_grade'] < 0 || $form_data['min_passing_grade'] > 1000) { $errors[] = 'Проходной балл (0-1000).';}
         }
    }
    if (empty($errors)) {
        try {
            $conn = getDbConnection();
            $conn->beginTransaction();

            // Проверка уникальности имени
            $sql_check_name_update = "SELECT id FROM subjects WHERE LOWER(name) = LOWER(?) AND id != ?";
            $stmt_check_name_update = $conn->prepare($sql_check_name_update);
            $stmt_check_name_update->execute([mb_strtolower($form_data['name']), $subject_id]);
            if ($stmt_check_name_update->fetch()) {
                $errors[] = 'Дисциплина с названием "' . htmlspecialchars($form_data['name']) . '" уже существует.';
            } else {
                // Обновление предмета
                $sql_update_subject = "UPDATE subjects SET name = ?, description = ?, min_passing_grade = ? WHERE id = ?";
                $stmt_update_subject = $conn->prepare($sql_update_subject);
                if ($stmt_update_subject->execute([$form_data['name'], $form_data['description'], $form_data['min_passing_grade'], $subject_id])) {
                    $conn->commit();
                    $_SESSION['message_flash'] = ['type' => $stmt_update_subject->rowCount() > 0 ? 'success' : 'info',
                                             'text' => $stmt_update_subject->rowCount() > 0 ? 'Дисциплина "' . htmlspecialchars($form_data['name']) . '" успешно обновлен.' : 'Изменений не было внесено.'];
                    header('Location: ' . BASE_URL . 'pages/admin_subjects.php');
                    exit;
                } else {
                    $conn->rollBack();
                    $errors[] = 'Не удалось обновить дисциплину. Ошибка БД.';
                    error_log("Subject Update Error (PDO Execute): " . implode(" | ", $stmt_update_subject->errorInfo()));
                }
            }
        } catch (PDOException $e) {
            if ($conn && $conn->inTransaction()) { $conn->rollBack(); }
            if (in_array($e->getCode(), ['23000', '23505'])) {
                 $errors[] = 'Дисциплина с названием "' . htmlspecialchars($form_data['name']) . '" уже существует (ошибка БД).';
            } else {
                 $errors[] = 'Произошла ошибка БД при обновлении дисциплины: ' . $e->getMessage();
            }
            error_log("Subject Update PDO Error: " . $e->getMessage());
        } finally {
            if ($conn) { $conn = null; }
        }
    }
} else {
    if (!$form_data) {
        try {
            $conn = getDbConnection();
            $sql_get_subject = "SELECT id, name, description, min_passing_grade FROM subjects WHERE id = ?";
            $stmt_get_subject = $conn->prepare($sql_get_subject);
            $stmt_get_subject->execute([$subject_id]);
            $form_data = $stmt_get_subject->fetch(PDO::FETCH_ASSOC);
            if (!$form_data) {
                $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Дисциплина для редактирования не найдена.'];
                header('Location: ' . BASE_URL . 'pages/admin_subjects.php');
                exit();
            }
        } catch (PDOException $e) {
            error_log("Error loading subject for edit (ID: $subject_id): " . $e->getMessage());
            $errors[] = "Ошибка загрузки данных дисциплины: " . htmlspecialchars($e->getMessage());
        } finally {
            if ($conn) { $conn = null; }
        }
    }
}

$page_title = "Редактирование дисциплины: " . ($form_data['name'] ?? 'Дисциплина не найдена');
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'admin-page edit-subject-page app-page';
$load_notifications_css = true;
$load_admin_css = true;

$page_specific_js = '
<script>
document.addEventListener("DOMContentLoaded", function() {
    const deleteButton = document.getElementById("deleteSubjectBtn");
    if (deleteButton) {
        deleteButton.addEventListener("click", function(event) {
            const subjectName = this.dataset.subjectName || "эта дисциплина";
            const message = `Вы уверены, что хотите удалить ${subjectName}? Это действие необратимо и может повлиять на связанные занятия и назначения.`;
            if (!confirm(message)) {
                event.preventDefault(); 
            } else {
            }
        });
    }
});
</script>
';

ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-edit me-2"></i>Редактирование дисциплины</h1>
        <a href="<?php echo BASE_URL; ?>pages/admin_subjects.php" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
            <i class="fas fa-arrow-left me-1"></i> К списку дисциплин
        </a>
    </div>

    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_admin.php">Админ-панель</a></li>
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/admin_subjects.php">Управление дисциплинами</a></li>
            <li class="breadcrumb-item active" aria-current="page">Редактирование: <?php echo htmlspecialchars($form_data['name'] ?? '...'); ?></li>
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

    <?php if ($form_data): // Показываем форму, только если данные предмета загружены или были введены ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $subject_id; ?>" class="needs-validation" novalidate>
                <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>"> 
                <div class="row g-3">
                    <div class="col-md-7 mb-3">
                        <label for="subject_name_input" class="form-label">Название дисциплины <span class="text-danger">*</span></label>
                        <input type="text" id="subject_name_input" name="name" class="form-control" required maxlength="255" value="<?php echo htmlspecialchars($form_data['name'] ?? ''); ?>">
                        <div class="invalid-feedback">Пожалуйста, введите название дисциплины.</div>
                    </div>

                    <div class="col-md-5 mb-3">
                        <label for="min_passing_grade_input" class="form-label">Проходной балл</label>
                        <input type="number" id="min_passing_grade_input" name="min_passing_grade" class="form-control" min="0" max="1000" step="1" value="<?php echo htmlspecialchars((string)($form_data['min_passing_grade'] ?? '61')); ?>">
                        <div class="form-text">По умолчанию: 61. Целое число от 0 до 1000.</div>
                    </div>

                    <div class="col-12 mb-3">
                        <label for="subject_description_input" class="form-label">Описание дисциплины</label>
                        <textarea id="subject_description_input" name="description" class="form-control" rows="4"><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="<?php echo BASE_URL; ?>pages/admin_subjects.php" class="btn btn-secondary">Отмена</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Сохранить изменения</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-danger">
        <div class="card-body">
            <p class="text-danger mb-2"><strong>Внимание!</strong> Это действие необратимо и удалит дисциплину. Убедитесь, что дисциплина не используется в активных занятиях или назначениях.</p>
            <p class="small text-muted mb-3">Если дисциплина используется, система может не позволить её удалить или это приведет к ошибкам в связанных данных.</p>
            <a href="<?php echo BASE_URL; ?>actions/delete_item.php?type=subject&id=<?php echo $subject_id; ?>&confirm=yes"
               class="btn btn-danger delete-item-btn" id="deleteSubjectBtn"
               data-subject-name="<?php echo htmlspecialchars(addslashes($form_data['name'] ?? 'эта дисциплина')); ?>">
                <i class="fas fa-trash-alt me-1"></i>Удалить дисциплину
            </a>
        </div>
    </div>

    <?php else: ?>
        <div class="alert alert-warning">
            Не удалось загрузить данные дисциплины для редактирования. Возможно, она была удален.
            <a href="<?php echo BASE_URL; ?>pages/admin_subjects.php" class="alert-link ms-2">Вернуться к списку дисциплин.</a>
        </div>
    <?php endif; ?>
</div>

<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>