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
$users = [];
$all_groups_for_select = [];
$errors = []; 
$form_data = $_POST; 
$show_modal_on_load_from_session = false; 
$page_flash_message = null; 

if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}
if (isset($_SESSION['form_errors_admin_users'])) { 
    $errors = $_SESSION['form_errors_admin_users'];
    unset($_SESSION['form_errors_admin_users']);
    $show_modal_on_load_from_session = true; 
}
if (isset($_SESSION['form_data_admin_users'])) { 
    $form_data = $_SESSION['form_data_admin_users']; 
    unset($_SESSION['form_data_admin_users']);
}
if (isset($_SESSION['message'])) {
    if (!$page_flash_message && !empty($_SESSION['message']['text'])) {
        $page_flash_message = $_SESSION['message'];
    }
    unset($_SESSION['message']);
}

try {
    $conn = getDbConnection();
    $current_user_id_for_page = $_SESSION['user_id']; 

    $stmt_all_groups = $conn->query("SELECT id, name FROM groups ORDER BY name ASC");
    $all_groups_for_select = $stmt_all_groups->fetchAll(PDO::FETCH_ASSOC);
    $stmt_all_groups = null;

    $search_term_from_get = trim($_GET['search'] ?? ''); 
    $role_filter_from_get = $_GET['role'] ?? '';
    $subject_filter_id_from_get = isset($_GET['filter_subject']) ? (int)$_GET['filter_subject'] : 0;
    $query_users_select_fields = "u.id, u.full_name, u.email, u.role, u.created_at, u.group_id";

    if ($subject_filter_id_from_get > 0) {
        $query_users = "SELECT DISTINCT " . $query_users_select_fields . "
                        FROM users u JOIN teaching_assignments ta ON u.id = ta.teacher_id
                        WHERE u.role = 'teacher' AND ta.subject_id = ?";
        $params_users[] = $subject_filter_id_from_get; $role_filter_from_get = 'teacher'; 
    } else {
        $query_users = "SELECT " . $query_users_select_fields . " FROM users u WHERE 1=1";
        $params_users = []; // Инициализируем здесь, чтобы не было ошибок при пустом search и role

        if (!empty($search_term_from_get)) {
            $query_users .= " AND (LOWER(u.full_name) LIKE LOWER(?) OR LOWER(u.email) LIKE LOWER(?))";
            $search_param_sql = '%' . $search_term_from_get . '%';
            $params_users[] = $search_param_sql; $params_users[] = $search_param_sql;
        }
        if (!empty($role_filter_from_get) && in_array($role_filter_from_get, ['student', 'teacher', 'admin'])) {
            $query_users .= " AND u.role = ?"; $params_users[] = $role_filter_from_get;
        }
    }
    $query_users .= " ORDER BY u.id ASC";

    $stmt_users = $conn->prepare($query_users);
    $stmt_users->execute($params_users);
    $users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
    $stmt_users = null;

} catch (PDOException $e) {
    error_log("Database Error in admin_users.php: " . $e->getMessage());
    $errors[] = "Произошла ошибка базы данных при загрузке пользователей.";
} finally {
    $conn = null;
}

$page_title = "Управление Пользователями";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'admin-page manage-users-page app-page'; 
$load_notifications_css = true;
$load_admin_css = true;

$js_config_data = [
    'baseUrl' => BASE_URL,
    'apiUserUrl' => BASE_URL . 'api/users.php', 
    'showModalOnLoad' => $show_modal_on_load_from_session, 
    'formData' => !empty($form_data) ? $form_data : null 
];

$page_specific_js = '
<script>
    const pageConfig = ' . json_encode($js_config_data) . ';
</script>
<script src="' . BASE_URL . 'assets/js/admin_users.js?v=' . time() . '" defer></script>
';

ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-users-cog me-2"></i>Управление Пользователями</h1>
        <button type="button" id="add-user-btn-main" class="btn btn-success btn-sm mt-2 mt-md-0">
            <i class="fas fa-user-plus me-1"></i> Добавить пользователя
        </button>
    </div>

    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded shadow-sm">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_admin.php">Админ-панель</a></li>
            <li class="breadcrumb-item active" aria-current="page">Управление Пользователями</li>
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
            <strong>Обнаружены ошибки при последней операции:</strong>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Фильтр и список пользователей</h5>
        </div>
        <div class="card-body">
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="get" class="filter-form row g-3 align-items-center mb-4 needs-validation" novalidate>
                <?php if ($subject_filter_id_from_get > 0): ?>
                    <input type="hidden" name="filter_subject" value="<?php echo $subject_filter_id_from_get; ?>">
                    <div class="col-12">
                        <p class="mb-0"><strong>Фильтр по дисциплине ID: <?php echo $subject_filter_id_from_get; ?></strong>
                           (<a href="<?php echo BASE_URL; ?>pages/admin_users.php" class="text-decoration-none">Сбросить</a>)
                        </p>
                    </div>
                <?php endif; ?>
                <div class="col-md-5">
                    <label for="search_input_users" class="visually-hidden">Поиск</label>
                    <input type="text" id="search_input_users" name="search" placeholder="Поиск по имени, email..." value="<?php echo htmlspecialchars($search_term_from_get); ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label for="role_filter_select" class="visually-hidden">Роль</label>
                    <select id="role_filter_select" name="role" class="form-select form-select-sm" <?php echo ($subject_filter_id_from_get > 0) ? 'disabled' : ''; ?>>
                        <option value="">Все роли</option>
                        <option value="student" <?php echo $role_filter_from_get === 'student' ? 'selected' : ''; ?>>Студенты</option>
                        <option value="teacher" <?php echo $role_filter_from_get === 'teacher' ? 'selected' : ''; ?>>Преподаватели</option>
                        <option value="admin" <?php echo $role_filter_from_get === 'admin' ? 'selected' : ''; ?>>Администраторы</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Применить</button>
                </div>
                <?php if (!($subject_filter_id_from_get > 0)): ?>
                <div class="col-auto">
                    <a href="<?php echo BASE_URL; ?>pages/admin_users.php" class="btn btn-outline-secondary btn-sm">Сбросить фильтры</a>
                </div>
                <?php endif; ?>
            </form>

            <div class="table-responsive">
                <table class="table table-striped table-hover users-table">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Полное имя</th>
                            <th>Email (Логин)</th> 
                            <th>Роль</th>
                            <th>Группа</th>
                            <th>Зарег.</th>
                            <th class="text-end">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users) && empty($errors)): ?>
                            <tr><td colspan="7" class="text-center py-4"><i class="fas fa-info-circle fa-2x mb-2 d-block text-muted"></i>Пользователи по вашему запросу не найдены.</td></tr> <?php // Уменьшили colspan ?>
                        <?php elseif (!empty($users)): ?>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><a href="<?php echo BASE_URL; ?>pages/profile.php?id=<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></a></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars(getUserRoleLabel($user['role'])); ?></td>
                                <td>
                                    <?php
                                    if ($user['role'] === 'student' && !empty($user['group_id'])) {
                                        $group_name_display = 'ID: ' . $user['group_id']; 
                                        foreach($all_groups_for_select as $g) {
                                            if ($g['id'] == $user['group_id']) {
                                                $group_name_display = htmlspecialchars($g['name']);
                                                break;
                                            }
                                        }
                                        echo $group_name_display;
                                    } elseif ($user['role'] === 'student') {
                                        echo '<span class="text-muted">--</span>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars(format_ru_datetime($user['created_at'], false)); ?></td>
                                <td class="text-end actions-cell">
                                    <a href="<?php echo BASE_URL; ?>pages/profile.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-info" title="Просмотр профиля">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-primary ms-1 edit-user-btn-table" data-id="<?php echo $user['id']; ?>" title="Редактировать пользователя">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($user['id'] !== $current_user_id_for_page): ?>
                                    <a href="<?php echo BASE_URL; ?>actions/delete_item.php?type=user&id=<?php echo $user['id']; ?>&confirm=yes"
                                       class="btn btn-sm btn-outline-danger ms-1 delete-item-btn"
                                       data-item-name="пользователя <?php echo htmlspecialchars(addslashes($user['full_name'])); ?>"
                                       title="Удалить пользователя"
                                       onclick="return confirm('Вы уверены что хотите удалить пользователя <?php echo htmlspecialchars(addslashes($user['full_name'])); ?>?');"> <?php // Добавил onclick для подтверждения ?>
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно для добавления/редактирования пользователя -->
<div class="modal fade" id="userFormModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="user-form-modal" action="<?php echo BASE_URL; ?>api/users.php" method="post"> 
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalLabel">Добавление/Редактирование пользователя</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <?php
                // Генерируем CSRF-токен для формы
                $csrf_token_value = '';
                if (function_exists('generate_csrf_token')) {
                    $csrf_token_value = generate_csrf_token();
                } else {
                    error_log('ОШИБКА: Функция generate_csrf_token() не найдена в admin_users.php!');
                }
                ?>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token_value); ?>">
                <div class="modal-body">
                    <div id="modal-form-errors-placeholder" class="mb-3"></div> 
                    <input type="hidden" name="action" value="admin_save_user">
                    <input type="hidden" id="modal_edit_user_id" name="user_id" value="<?php echo htmlspecialchars($form_data['user_id'] ?? '0'); ?>">
                    
                    <div class="row">
                        <div class="col-md-12 mb-3"> 
                            <label for="modal_edit_full_name" class="form-label">Полное имя <span class="text-danger">*</span></label>
                            <input type="text" id="modal_edit_full_name" name="full_name" required class="form-control"
                                   value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="modal_edit_email" class="form-label">Email (Используется для входа) <span class="text-danger">*</span></label>
                        <input type="email" id="modal_edit_email" name="email" required class="form-control"
                               value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modal_edit_role" class="form-label">Роль <span class="text-danger">*</span></label>
                            <select id="modal_edit_role" name="role" required class="form-select">
                                <?php $current_role_form_modal = $form_data['role'] ?? 'student'; ?>
                                <option value="student" <?php echo ($current_role_form_modal === 'student') ? 'selected' : ''; ?>>Студент</option>
                                <option value="teacher" <?php echo ($current_role_form_modal === 'teacher') ? 'selected' : ''; ?>>Преподаватель</option>
                                <option value="admin" <?php echo ($current_role_form_modal === 'admin') ? 'selected' : ''; ?>>Администратор</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="modal-group-assignment-section" style="display: <?php echo (($form_data['role'] ?? 'student') === 'student') ? 'block' : 'none'; ?>;">
                            <label for="modal_edit_group_id" class="form-label">Группа студента:</label>
                            <select id="modal_edit_group_id" name="group_id" class="form-select">
                                <option value="">-- Не назначена --</option>
                                <?php $current_group_id_form_modal = (int)($form_data['group_id'] ?? 0); ?>
                                <?php if (empty($all_groups_for_select)): ?>
                                    <option value="" disabled>Группы не загружены</option>
                                <?php else: ?>
                                    <?php foreach ($all_groups_for_select as $group_option): ?>
                                        <option value="<?php echo $group_option['id']; ?>" <?php echo ($current_group_id_form_modal === (int)$group_option['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($group_option['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div class="form-text">Обязательно для роли "Студент".</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="modal_edit_password" class="form-label">Пароль</label>
                        <input type="password" id="modal_edit_password" name="password" class="form-control" autocomplete="new-password">
                        <div class="form-text" id="modal-password-help">
                            <?php echo (isset($form_data['user_id']) && $form_data['user_id'] > 0) ? 'Оставьте пустым, чтобы не менять.' : 'Обязателен при создании (мин. 6 символов).'; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>