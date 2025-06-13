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
    header('Location: ' . BASE_URL . 'pages/login.php');
    exit();
}

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? null;

$conn = null;
$user = null;
$group_name = null;
$teacher_groups = [];
$activity = ['assignments' => [], 'materials' => [], 'messages' => []];
$can_edit_password = false;
$profile_user_id = $current_user_id;
$db_error_message = '';
$profile_errors = [];
$page_flash_message = null;
$profile_user_id = isset($_GET['id']) ? (int)$_GET['id'] : $current_user_id;

// Проверка прав доступа к просмотру
if ($profile_user_id !== $current_user_id && $current_user_role !== 'admin' && $current_user_role !== 'teacher') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'У вас нет прав для просмотра этого профиля.'];
    header('Location: ' . BASE_URL . 'pages/profile.php');
    exit();
}

try {
    $conn = getDbConnection();

    $sql_user = "SELECT id  , email, full_name, role, created_at, last_login, group_id FROM users WHERE id = :profile_user_id";
    $stmt_user = $conn->prepare($sql_user);
    $stmt_user->execute([':profile_user_id' => $profile_user_id]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Профиль пользователя не найден.'];
        header('Location: ' . BASE_URL . 'pages/dashboard.php'); 
        exit();
    }
    $can_edit_password = ($current_user_id === $profile_user_id);
    // Дополнительная информация в зависимости от роли
    if ($user['role'] === 'student' && !empty($user['group_id'])) {
        $stmt_group = $conn->prepare("SELECT name FROM groups WHERE id = :group_id");
        $stmt_group->execute([':group_id' => $user['group_id']]);
        $group_name = $stmt_group->fetchColumn();

        $sql_activity_assign = "SELECT a.id as submission_id, a.submitted_at, ad.title as assignment_title, l.title as lesson_title, l.id as lesson_id FROM assignments a JOIN assignment_definitions ad ON a.assignment_definition_id = ad.id JOIN lessons l ON ad.lesson_id = l.id WHERE a.student_id = :profile_user_id ORDER BY a.submitted_at DESC LIMIT 3";
        $stmt_activity_assign = $conn->prepare($sql_activity_assign);
        $stmt_activity_assign->execute([':profile_user_id' => $profile_user_id]);
        $activity['assignments'] = $stmt_activity_assign->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($user['role'] === 'teacher') {
        $sql_groups = "SELECT DISTINCT g.id, g.name FROM groups g JOIN teaching_assignments ta ON g.id = ta.group_id WHERE ta.teacher_id = :profile_user_id ORDER BY g.name";
        $stmt_groups = $conn->prepare($sql_groups);
        $stmt_groups->execute([':profile_user_id' => $profile_user_id]);
        $teacher_groups = $stmt_groups->fetchAll(PDO::FETCH_ASSOC);

        $sql_activity_mat = "SELECT m.id, m.title, m.created_at as uploaded_at, l.title as lesson_title, l.id as lesson_id FROM materials m JOIN lessons l ON m.lesson_id = l.id WHERE m.uploaded_by = :profile_user_id ORDER BY m.created_at DESC LIMIT 3";
        $stmt_activity_mat = $conn->prepare($sql_activity_mat);
        $stmt_activity_mat->execute([':profile_user_id' => $profile_user_id]);
        $activity['materials'] = $stmt_activity_mat->fetchAll(PDO::FETCH_ASSOC);
    }

    // Общая активность - сообщения 
    if ($user['role'] === 'student' || $user['role'] === 'teacher') {
        $sql_activity_msg = "SELECT m.id, m.message, m.created_at, l.title as lesson_title, l.id as lesson_id FROM messages m JOIN lessons l ON m.lesson_id = l.id WHERE m.user_id = :profile_user_id ORDER BY m.created_at DESC LIMIT 3";
        $stmt_activity_msg = $conn->prepare($sql_activity_msg);
        $stmt_activity_msg->execute([':profile_user_id' => $profile_user_id]);
        $activity['messages'] = $stmt_activity_msg->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("DB Error on profile.php (Profile ID: {$profile_user_id}, Current User ID: {$current_user_id}): " . $e->getMessage());
    $db_error_message = 'Произошла ошибка при загрузке данных профиля.';
    if (!$user) {
        $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Не удалось загрузить профиль. Ошибка базы данных.'];
        header('Location: ' . BASE_URL . 'pages/dashboard.php'); exit();
    }
} finally {
    $conn = null;
}

// Флеш-сообщения и ошибки формы
if (isset($_SESSION['message_flash'])) { $page_flash_message = $_SESSION['message_flash']; unset($_SESSION['message_flash']); }
if (isset($_SESSION['form_errors_profile'])) { $profile_errors = $_SESSION['form_errors_profile']; unset($_SESSION['form_errors_profile']); }
$show_profile_modal_on_load = !empty($profile_errors);
$page_title = "Профиль: " . ($user ? htmlspecialchars($user['full_name']) : 'Пользователь');
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'profile-page app-page';
$load_notifications_css = true;
$load_profile_css = true;

// --- Генерируем CSRF-токен перед его использованием ---
$csrf_token_for_profile = ''; 
if (function_exists('generate_csrf_token')) {
    $csrf_token_for_profile = generate_csrf_token();
} else {
    error_log("CSRF function generate_csrf_token() not found in profile.php!");
}

// Для JS модального окна
$page_specific_js = ''; 

if ($user) { 
    $js_config_data = [
        'showProfileModalOnError' => $show_profile_modal_on_load
    ];
    if ($can_edit_password) {
        $js_config_data['apiUserUrl'] = BASE_URL . 'api/users.php';
        $js_config_data['csrfToken'] = htmlspecialchars($csrf_token_for_profile);
    }

    $page_specific_js = '
<script>
    window.profilePageConfig = ' . json_encode($js_config_data) . ';
</script>
<script src="' . BASE_URL . 'assets/js/profile.js?v=' . time() . '" defer></script>
';
}
ob_start();
?>

<div class="container py-4">
    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($db_error_message) && !$user): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($db_error_message); ?></div>
        <a href="<?php echo BASE_URL; ?>pages/dashboard.php" class="btn btn-secondary">Вернуться на дашборд</a>
    <?php elseif (!empty($db_error_message)): ?>
        <div class="alert alert-warning mb-4"><?php echo htmlspecialchars($db_error_message); ?></div>
    <?php endif; ?>

    <?php if ($user): ?>
        <div class="page-header mb-4">
            <h1 class="h2"><?php echo htmlspecialchars($user['full_name']); ?></h1>
            <p class="text-muted mb-0"><?php echo getUserRoleLabel($user['role']); ?></p>
        </div>
        <div class="row">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h5 mb-0"><i class="fas fa-info-circle me-2"></i>Личная информация</h2>
                    </div>
                    <div class="card-body">
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                        <?php if ($user['role'] === 'student'): ?>
                            <p><strong>Группа:</strong>
                                <?php if (!empty($user['group_id']) && $group_name): ?>
                                    <a href="<?php echo BASE_URL; ?>pages/student_dashboard.php">
                                        <?php echo htmlspecialchars($group_name); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Не назначена</span>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <p><strong>Дата регистрации:</strong> <?php echo htmlspecialchars(format_ru_datetime($user['created_at'])); ?></p>
                        <?php if (!empty($user['last_login'])): ?>
                            <p><strong>Последний вход:</strong> <?php echo htmlspecialchars(format_ru_datetime($user['last_login'])); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($can_edit_password): ?>
                        <div class="card-footer text-end">
                             <button type="button" id="edit-profile-btn" class="btn btn-primary"><i class="fas fa-key me-2"></i>Сменить пароль</button>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($user['role'] === 'teacher' && !empty($teacher_groups)): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h2 class="h5 mb-0"><i class="fas fa-users me-2"></i>Назначенные группы</h2>
                        </div>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($teacher_groups as $group): ?>
                                <li class="list-group-item">
                                     <a href="<?php echo BASE_URL; ?>pages/teacher_group_view.php?group_id=<?php echo $group['id']; ?>">
                                        <?php echo htmlspecialchars($group['name']); ?>
                                     </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php elseif ($user['role'] === 'teacher' && empty($teacher_groups)): ?>
                     <div class="card card-body text-muted mb-4">Вы пока не назначены ни на одну группу.</div>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <?php if (!empty($activity['assignments']) || !empty($activity['materials']) || !empty($activity['messages'])): ?>
                    <div class="card">
                        <div class="card-header">
                            <h2 class="h5 mb-0"><i class="fas fa-history me-2"></i>Последняя активность</h2>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php if (!empty($activity['assignments'])): ?>
                                <?php foreach ($activity['assignments'] as $item): ?>
                                    <a href="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $item['lesson_id']; ?>&tab=assignments#submission-<?php echo $item['submission_id']; ?>" class="list-group-item list-group-item-action">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><i class="fas fa-file-alt fa-fw me-1 text-primary"></i><?php echo htmlspecialchars($item['assignment_title']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars(format_ru_datetime_short($item['submitted_at'])); ?></small>
                                        </div>
                                        <small class="text-muted">Урок: <?php echo htmlspecialchars($item['lesson_title']); ?></small>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if (!empty($activity['materials'])): ?>
                                <?php foreach ($activity['materials'] as $item): ?>
                                     <a href="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $item['lesson_id']; ?>&tab=materials" class="list-group-item list-group-item-action">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><i class="fas fa-folder-open fa-fw me-1 text-success"></i><?php echo htmlspecialchars($item['title']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars(format_ru_datetime_short($item['uploaded_at'])); ?></small>
                                        </div>
                                        <small class="text-muted">Урок: <?php echo htmlspecialchars($item['lesson_title']); ?></small>
                                     </a>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if (!empty($activity['messages'])): ?>
                                <?php foreach ($activity['messages'] as $item): ?>
                                    <a href="<?php echo BASE_URL; ?>pages/lesson.php?id=<?php echo $item['lesson_id']; ?>#chat-messages-list" class="list-group-item list-group-item-action">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><i class="fas fa-comments fa-fw me-1 text-info"></i>Сообщение в чате</h6>
                                            <small class="text-muted"><?php echo htmlspecialchars(format_ru_datetime_short($item['created_at'])); ?></small>
                                        </div>
                                        <p class="mb-1 small text-muted"><?php echo nl2br(htmlspecialchars(truncate_text($item['message'], 70))); ?></p>
                                        <small class="text-muted">Урок: <?php echo htmlspecialchars($item['lesson_title']); ?></small>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                     <div class="card card-body text-center text-muted">Нет недавней активности.</div>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>
        <div class="alert alert-danger">Не удалось загрузить данные профиля.</div>
        <a href="<?php echo BASE_URL; ?>pages/dashboard.php" class="btn btn-secondary">Вернуться на дашборд</a>
    <?php endif; ?>
</div>

<!-- Модалка -->
<?php if ($can_edit_password && $user): ?>
    <div id="edit-profile-modal" class="modal" style="display: <?php echo $show_profile_modal_on_load ? 'block' : 'none'; ?>;">
        <div class="modal-content">
            <span class="close-modal-btn" style="float:right; cursor:pointer; font-size: 1.5em;">×</span>
            <h2>Смена Пароля</h2>
            <?php if (!empty($profile_errors)): ?>
                <div class="alert alert-danger">
                    <strong>Ошибка!</strong>
                    <ul>
                        <?php foreach ($profile_errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form id="profile-form" action="<?php echo BASE_URL; ?>api/users.php" method="post"> 
                <input type="hidden" name="action" value="update_profile"> 
                <input type="hidden" name="user_id" value="<?php echo $profile_user_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token_for_profile); ?>">
                <div class="form-group">
                    <label for="profile_edit_password">Новый пароль <span class="text-danger">*</span></label>
                    <div class="password-input-wrapper">
                        <input type="password" id="profile_edit_password" name="password"
                            required minlength="8" class="form-control" autocomplete="new-password"
                            pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$"
                            title="Минимум 8 символов, включая заглавную, строчную буквы и цифру.">
                        <span class="toggle-password-visibility" id="toggle_profile_password_icon" title="Показать/скрыть пароль">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                    <div class="invalid-feedback">Пароль должен быть не менее 8 символов, содержать заглавную, строчную буквы и цифру.</div>
                    <small class="form-text text-muted">Оставьте пустым, если не хотите менять пароль.</small>
                </div>
                <div class="form-group">
                    <label for="profile_edit_password_confirm">Подтверждение нового пароля <span class="text-danger">*</span></label>
                    <div class="password-input-wrapper">
                        <input type="password" id="profile_edit_password_confirm" name="password_confirm"
                            required class="form-control" autocomplete="new-password">
                        <span class="toggle-password-visibility" id="toggle_profile_confirm_password_icon" title="Показать/скрыть пароль">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                    <div class="invalid-feedback" id="confirm_password_feedback_profile">Пожалуйста, подтвердите новый пароль. Пароли должны совпадать.</div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Сменить пароль</button>
                    <button type="button" class="btn btn-secondary close-modal-btn">Отмена</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>