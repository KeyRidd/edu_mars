<?php
declare(strict_types=1);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Подключение конфигурации и основных файлов
if (file_exists(dirname(__DIR__) . '/config/app_config.php')) {
    require_once dirname(__DIR__) . '/config/app_config.php';
} else {
    if (!defined('BASE_URL')) define('BASE_URL', '/project/');
    if (!defined('APP_NAME')) define('APP_NAME', 'Edu.MARS');
    if (!defined('LAYOUTS_PATH')) define('LAYOUTS_PATH', dirname(__DIR__) . '/layouts/');
    if (!defined('INCLUDES_PATH')) define('INCLUDES_PATH', dirname(__DIR__) . '/includes/');
    if (!defined('CONFIG_PATH')) define('CONFIG_PATH', dirname(__DIR__) . '/config/'); 
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once CONFIG_PATH . 'database.php';
require_once INCLUDES_PATH . 'functions.php';
require_once INCLUDES_PATH . 'auth.php';

if (is_logged_in()) {
    $role = $_SESSION['role'] ?? null;
    $redirect_url = BASE_URL . 'pages/dashboard.php'; 
    if ($role === 'student') $redirect_url = BASE_URL . 'pages/home_student.php';
    elseif ($role === 'teacher') $redirect_url = BASE_URL . 'pages/home_teacher.php';
    elseif ($role === 'admin') $redirect_url = BASE_URL . 'pages/home_admin.php';
    header('Location: ' . $redirect_url);
    exit;
}

$error_message_login = '';
$email_value = '';
$page_flash_message_login = null; // Для флеш-сообщений от других страниц

// Флеш-сообщения
if (isset($_SESSION['message_flash'])) {
    $page_flash_message_login = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}
if (isset($_SESSION['message']) && !$page_flash_message_login && !empty($_SESSION['message']['text'])) {
    $page_flash_message_login = $_SESSION['message'];
    unset($_SESSION['message']);
}

$csrf_token_login = generate_csrf_token(); // Генерируем CSRF-токен для формы входа

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf_token_login = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($submitted_csrf_token_login)) {
        $error_message_login = 'Ошибка безопасности формы. Пожалуйста, обновите страницу и попробуйте еще раз.';
    } else {
        $email_input_login = trim($_POST['email'] ?? '');
        $password_login = trim($_POST['password'] ?? ''); // Пароль только trim
        $email_value = htmlspecialchars($email_input_login);

        if (empty($email_input_login) || empty($password_login)) {
            $error_message_login = 'Пожалуйста, заполните поля Email и Пароль.';
        } elseif (!filter_var($email_input_login, FILTER_VALIDATE_EMAIL)) {
            $error_message_login = 'Пожалуйста, введите корректный Email.';
        } else {
            $pdo_conn_login = null;
            try {
                $pdo_conn_login = getDbConnection();
                $sql_login = "SELECT id, password, role, group_id, full_name FROM users WHERE LOWER(email) = LOWER(:email)";
                $stmt_login = $pdo_conn_login->prepare($sql_login);
                $stmt_login->execute([':email' => mb_strtolower($email_input_login)]); 
                $user_login_data = $stmt_login->fetch(PDO::FETCH_ASSOC);

                if ($user_login_data && password_verify($password_login, $user_login_data['password'])) {
                    // Успешный вход
                    session_regenerate_id(true); 

                    $_SESSION['user_id'] = $user_login_data['id'];
                    $_SESSION['role'] = $user_login_data['role'];
                    $_SESSION['full_name'] = $user_login_data['full_name'];
                    if ($user_login_data['role'] === 'student' && isset($user_login_data['group_id'])) {
                        $_SESSION['group_id'] = (int)$user_login_data['group_id'];
                    } else {
                        unset($_SESSION['group_id']);
                    }

                    // Обновляем last_login
                    $update_login_sql = "UPDATE users SET last_login = NOW() WHERE id = :id";
                    $update_login_stmt = $pdo_conn_login->prepare($update_login_sql);
                    $update_login_stmt->execute(['id' => $user_login_data['id']]);

                    // Редирект 
                    $role_after_login = $user_login_data['role'];
                    $redirect_url_after_login = BASE_URL . 'pages/dashboard.php'; 
                    if ($role_after_login === 'student') $redirect_url_after_login = BASE_URL . 'pages/home_student.php';
                    elseif ($role_after_login === 'teacher') $redirect_url_after_login = BASE_URL . 'pages/home_teacher.php';
                    elseif ($role_after_login === 'admin') $redirect_url_after_login = BASE_URL . 'pages/home_admin.php';
                    header('Location: ' . $redirect_url_after_login);
                    exit();
                } else {
                    $error_message_login = 'Неверный Email или пароль.';
                }
            } catch (PDOException $e_pdo_login) {
                error_log("Login PDO Error: " . $e_pdo_login->getMessage());
                $error_message_login = "Произошла ошибка на сервере. Пожалуйста, попробуйте войти позже.";
            } finally {
                $pdo_conn_login = null;
            }
        }
    } 
}

$page_title = 'Вход в систему - ' . APP_NAME;
$body_class = 'landing-page-body auth-page-content';
$show_sidebar = false;
$is_auth_page = false;
$is_landing_page = true;
$container_class_main = '';

$page_specific_js = ''; 
if (true) { 
$page_specific_js = <<<'EOT'
<script>
document.addEventListener('DOMContentLoaded', function() {
    const togglePasswordVisibility = (inputId, iconWrapperId) => {
        const passwordInput = document.getElementById(inputId);
        const iconWrapper = document.getElementById(iconWrapperId);
        if (passwordInput && iconWrapper) {
            const icon = iconWrapper.querySelector('i');
            iconWrapper.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                if (type === 'text') {
                    passwordInput.classList.add('password-visible');
                } else {
                    passwordInput.classList.remove('password-visible');
                }
                if (icon) {
                    if (type === 'password') {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                        this.setAttribute('title', 'Показать пароль');
                    } else {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                        this.setAttribute('title', 'Скрыть пароль');
                    }
                }
            });
        } else {
            if (!passwordInput) console.error('Input with ID ' + inputId + ' not found for togglePasswordVisibility.');
            if (!iconWrapper) console.error('Icon wrapper with ID ' + iconWrapperId + ' not found for togglePasswordVisibility.');
        }
    };
    togglePasswordVisibility('login_password_main', 'toggle_login_password_icon');
    (function () {
      'use strict'
      var forms = document.querySelectorAll('.needs-validation')
      Array.prototype.slice.call(forms)
        .forEach(function (form) {
          form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
              event.preventDefault()
              event.stopPropagation()
            }
            form.classList.add('was-validated')
          }, false)
        })
    })();
});
</script>
EOT;
}

ob_start();
?>

<div class="auth-page-main-content">
<div class="auth-form-wrapper">
    <div class="auth-form-container">
        <h2>Вход в систему</h2>
        <?php
        $page_flash_message_login = null;
        if (isset($_SESSION['message_flash'])) {
            $page_flash_message_login = $_SESSION['message_flash'];
            unset($_SESSION['message_flash']);
        } elseif (isset($_SESSION['message'])) { 
            $page_flash_message_login = $_SESSION['message'];
            unset($_SESSION['message']);
        }

        if ($page_flash_message_login): ?>
            <div class="alert alert-<?php echo htmlspecialchars($page_flash_message_login['type']); ?> mb-3 alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($page_flash_message_login['text']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message_login)): ?>
            <div class="alert alert-danger mb-3"><?php echo htmlspecialchars($error_message_login); ?></div>
        <?php endif; ?>

         <form method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'pages/login.php'); ?>" novalidate id="loginForm" class="needs-validation">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token_login); ?>">
            <div class="form-group">
                <label for="login_email_main">Email</label>
                <input type="email" id="login_email_main" name="email" value="<?php echo $email_value; ?>"
                       required class="form-control" placeholder="Ваш Email">
                <div class="invalid-feedback">Пожалуйста, введите ваш Email.</div>
            </div>
            <div class="form-group">
                <label for="login_password_main">Пароль</label>
                <div class="password-input-wrapper">
                    <input type="password" id="login_password_main" name="password" required class="form-control" placeholder="Ваш пароль">
                    <span class="toggle-password-visibility" id="toggle_login_password_icon" title="Показать/скрыть пароль"> 
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
                <div class="invalid-feedback">Пожалуйста, введите ваш пароль.</div>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-primary" form="loginForm">Войти</button>
            </div>
        </form>
        <div class="form-footer">
            <p>Еще нет аккаунта? <a href="<?php echo htmlspecialchars(BASE_URL . 'pages/register.php'); ?>">Зарегистрироваться</a></p>
        </div>
    </div>
</div>
</div>
<?php
$page_content = ob_get_clean();

// JS для валидации 
if (strpos($page_specific_js, '.needs-validation') === false) { // Проверяем, не добавлен ли уже
    $page_specific_js .= <<<'EOT'
<script>
(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms)
    .forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }
        form.classList.add('was-validated')
      }, false)
    })
})()
</script>
EOT;
}
require_once LAYOUTS_PATH . 'main_layout.php';
?>