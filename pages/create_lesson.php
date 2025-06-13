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

$errors = [];
$page_flash_message = null; // Для флеш-сообщений из сессии
$form_data = [ // Для предзаполнения формы
    'title' => '',
    'description' => '',
    'lesson_date' => date('Y-m-d\TH:i'), // Дефолтное значение
    'subject_id' => 0,
    'lesson_type' => '',
    'duration_minutes' => 90 // Дефолтное значение
];
$group = null;
$subjects_in_group = [];

$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
if ($group_id <= 0) {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Не указана группа для создания занятия.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php'); 
    exit;
}

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}
// Старый механизм сообщений
if (isset($_SESSION['message'])) {
    if (!$page_flash_message && !empty($_SESSION['message']['text'])) {
         $page_flash_message = $_SESSION['message'];
    }
    unset($_SESSION['message']);
}


$conn = null;
try {
    $conn = getDbConnection();

    // Получаем информацию о группе
    $sql_group_info = "SELECT name FROM groups WHERE id = ?";
    $stmt_group_info = $conn->prepare($sql_group_info);
    $stmt_group_info->execute([$group_id]);
    $group = $stmt_group_info->fetch(PDO::FETCH_ASSOC);
    $stmt_group_info = null;
    if (!$group) {
        // Устанавливаем флеш-сообщение
        $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Указанная группа не найдена.'];
        header('Location: ' . BASE_URL . 'pages/admin_groups.php'); 
        exit;
    }

    // Получаем предметы, назначенные этой группе
    $sql_group_subjects = "
        SELECT DISTINCT s.id, s.name
        FROM subjects s
        JOIN teaching_assignments ta ON s.id = ta.subject_id
        WHERE ta.group_id = ?
        ORDER BY s.name ASC
    ";
    $stmt_group_subjects = $conn->prepare($sql_group_subjects);
    $stmt_group_subjects->execute([$group_id]);
    $subjects_in_group = $stmt_group_subjects->fetchAll(PDO::FETCH_ASSOC);
    $stmt_group_subjects = null;

    // Обработка POST-запроса
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Заполняем $form_data данными из POST для предзаполнения в случае ошибки
        $form_data['title'] = trim($_POST['title'] ?? '');
        $form_data['description'] = trim($_POST['description'] ?? '');
        $form_data['lesson_date'] = trim($_POST['lesson_date'] ?? '');
        $form_data['subject_id'] = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
        $form_data['lesson_type'] = trim($_POST['lesson_type'] ?? '');
        $form_data['duration_minutes'] = isset($_POST['duration_minutes']) ? (int)$_POST['duration_minutes'] : 0;

        // Валидация
        if (empty($form_data['title'])) { $errors[] = 'Название занятия обязательно.'; }
        if (empty($form_data['lesson_date'])) { $errors[] = 'Дата и время занятия обязательны.'; }
        else {
            $date_obj = DateTime::createFromFormat('Y-m-d\TH:i', $form_data['lesson_date']);
            if (!$date_obj || $date_obj->format('Y-m-d\TH:i') !== $form_data['lesson_date']) { $errors[] = 'Некорректный формат даты.'; }
        }
        if ($form_data['subject_id'] <= 0) {
            $errors[] = 'Необходимо выбрать дисциплину.';
        } else {
            $is_subject_valid_post = false;
            foreach ($subjects_in_group as $subj) { if ($subj['id'] === $form_data['subject_id']) { $is_subject_valid_post = true; break; } }
            if (!$is_subject_valid_post) { $errors[] = 'Выбрана недопустимая дисциплина для этой группы.';}
        }
        $allowed_lesson_types_post = ['lecture', 'practice', 'assessment', 'other'];
        if (empty($form_data['lesson_type'])) { $errors[] = 'Тип занятия обязателен.'; }
        elseif (!in_array($form_data['lesson_type'], $allowed_lesson_types_post)) { $errors[] = 'Выбран недопустимый тип занятия.';}
        if ($form_data['duration_minutes'] <= 0) { $errors[] = 'Продолжительность занятия должна быть положительным числом.'; }
        elseif ($form_data['duration_minutes'] < 15 || $form_data['duration_minutes'] > 480) { $errors[] = 'Продолжительность (15-480 мин).';}


        if (empty($errors)) {
            try {
                $sql_insert_lesson = "INSERT INTO lessons (group_id, subject_id, title, description, lesson_date, lesson_type, duration_minutes, created_at)
                                      VALUES (?, ?, ?, ?, ?, ?::lesson_type_enum, ?, NOW())";
                $stmt_insert_lesson = $conn->prepare($sql_insert_lesson);

                if ($stmt_insert_lesson->execute([
                    $group_id,
                    $form_data['subject_id'],
                    $form_data['title'],
                    $form_data['description'],
                    $form_data['lesson_date'],
                    $form_data['lesson_type'],
                    $form_data['duration_minutes']
                ])) {
                    $new_lesson_id = $conn->lastInsertId();
                    $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Занятие "' . htmlspecialchars($form_data['title']) . '" успешно создано.'];
                    header('Location: ' . BASE_URL . 'pages/lesson.php?id=' . $new_lesson_id);
                    exit;
                } else {
                    $errors[] = 'Не удалось создать занятие. Ошибка базы данных при сохранении.';
                    error_log("Lesson Creation Error (PDO Insert): " . implode(" | ", $stmt_insert_lesson->errorInfo()));
                }
                 $stmt_insert_lesson = null;
            } catch (PDOException $e) {
                $errors[] = 'Произошла ошибка базы данных при создании занятия.';
                error_log("Lesson Creation PDO Error: " . $e->getMessage());
            }
        }
    }

} catch (PDOException | Exception $e) { 
     error_log("Error loading create_lesson.php (Group ID: $group_id): " . $e->getMessage());
     $errors[] = "Произошла ошибка при загрузке данных страницы: " . htmlspecialchars($e->getMessage());
     if (!$group && empty($page_flash_message)) { 
         $_SESSION['message_flash'] = ['type' => 'error', 'text' => "Ошибка: Группа не найдена или ошибка загрузки."];
     }
} finally {
    $conn = null;
}

$page_title = "Создание нового занятия" . ($group ? " для группы: " . htmlspecialchars($group['name']) : '');
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'admin-page create-lesson-page app-page';
$load_notifications_css = true;
$load_admin_css = true;

ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-calendar-plus me-2"></i>Создание нового занятия</h1>
        <a href="<?php echo BASE_URL; ?>pages/dashboard.php?group_id=<?php echo $group_id; ?>" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
            <i class="fas fa-arrow-left me-1"></i> К расписанию группы
        </a>
    </div>

    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_admin.php">Админ-панель</a></li>
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/admin_groups.php">Управление Группами</a></li>
            <?php if ($group): ?>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/dashboard.php?group_id=<?php echo $group_id; ?>">Группа: <?php echo htmlspecialchars($group['name']); ?></a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page">Новое занятие</li>
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
                <?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>


    <?php if ($group): // Показываем форму, только если данные группы успешно загружены ?>
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Параметры занятия для группы "<?php echo htmlspecialchars($group['name']); ?>"</h5>
        </div>
        <div class="card-body p-4">
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?group_id=' . $group_id; ?>" class="needs-validation" novalidate>
                <div class="row g-3">
                    <div class="col-md-12">
                        <label for="title_input" class="form-label">Название занятия <span class="text-danger">*</span></label>
                        <input type="text" id="title_input" name="title" class="form-control" required maxlength="255" value="<?php echo htmlspecialchars($form_data['title']); ?>">
                        <div class="invalid-feedback">Пожалуйста, введите название занятия.</div>
                    </div>

                    <div class="col-md-6">
                         <label for="subject_id_select" class="form-label">Дисциплина <span class="text-danger">*</span></label>
                         <select name="subject_id" id="subject_id_select" class="form-select" required>
                              <option value="">-- Выберите дисциплину --</option>
                              <?php if (empty($subjects_in_group)): ?>
                                  <option value="" disabled>В этой группе нет назначенных дисциплин.</option>
                              <?php else: ?>
                                   <?php foreach ($subjects_in_group as $subject_item): ?>
                                       <option value="<?php echo $subject_item['id']; ?>" <?php echo ($form_data['subject_id'] === $subject_item['id']) ? 'selected' : ''; ?>>
                                           <?php echo htmlspecialchars($subject_item['name']); ?>
                                       </option>
                                   <?php endforeach; ?>
                              <?php endif; ?>
                         </select>
                         <div class="invalid-feedback">Пожалуйста, выберите дисциплину.</div>
                         <?php if (empty($subjects_in_group)): ?>
                              <small class="text-danger d-block mt-1">Сначала <a href="<?php echo BASE_URL; ?>pages/edit_group.php?id=<?php echo $group_id; ?>">назначьте дисциплины</a> этой группе.</small>
                         <?php endif; ?>
                     </div>

                    <div class="col-md-6">
                        <label for="lesson_type_select" class="form-label">Тип занятия <span class="text-danger">*</span></label>
                        <select name="lesson_type" id="lesson_type_select" class="form-select" required>
                            <option value="">-- Выберите тип --</option>
                            <?php $allowed_lesson_types_display = ['lecture' => 'Лекция', 'practice' => 'Практика/Семинар', 'assessment' => 'Аттестация/Контроль', 'other' => 'Другое']; ?>
                            <?php foreach ($allowed_lesson_types_display as $type_key => $type_name): ?>
                                <option value="<?php echo $type_key; ?>" <?php echo ($form_data['lesson_type'] === $type_key) ? 'selected' : ''; ?>><?php echo $type_name; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Пожалуйста, выберите тип занятия.</div>
                    </div>

                    <div class="col-md-6">
                        <label for="lesson_date_input" class="form-label">Дата и время занятия <span class="text-danger">*</span></label>
                        <input type="datetime-local" id="lesson_date_input" name="lesson_date" class="form-control" required value="<?php echo htmlspecialchars($form_data['lesson_date']); ?>">
                        <div class="invalid-feedback">Пожалуйста, укажите дату и время.</div>
                    </div>

                    <div class="col-md-6">
                        <label for="duration_minutes_input" class="form-label">Продолжительность (в минутах) <span class="text-danger">*</span></label>
                        <input type="number" id="duration_minutes_input" name="duration_minutes" class="form-control" required min="15" max="480" step="5" value="<?php echo htmlspecialchars((string)$form_data['duration_minutes']); ?>">
                        <div class="form-text">Обычно 90 минут для стандартной пары.</div>
                        <div class="invalid-feedback">Укажите корректную продолжительность (15-480 мин).</div>
                    </div>

                    <div class="col-12">
                        <label for="description_textarea" class="form-label">Описание занятия</label>
                        <textarea id="description_textarea" name="description" class="form-control" rows="4"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                        <div class="form-text">Тема, план, и т.д. (необязательно).</div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="<?php echo BASE_URL; ?>pages/dashboard.php?group_id=<?php echo $group_id; ?>" class="btn btn-secondary">Отмена</a>
                    <button type="submit" class="btn btn-primary" <?php echo empty($subjects_in_group) ? 'disabled' : ''; ?>>
                        <i class="fas fa-plus-circle me-1"></i>Создать занятие
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php else: ?>
         <div class="alert alert-warning">
             Невозможно отобразить форму создания занятия, так как данные группы не были загружены или группа не найдена.
             <?php if (empty($errors)): ?>
                Пожалуйста, вернитесь на <a href="<?php echo BASE_URL; ?>pages/admin_groups.php" class="alert-link">страницу управления группами</a>.
             <?php endif; ?>
         </div>
    <?php endif; ?>
</div>

<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>