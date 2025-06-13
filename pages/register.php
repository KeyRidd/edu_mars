<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

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
    $role_reg_redirect = $_SESSION['role'] ?? null; 
    $redirect_url_reg = BASE_URL . 'pages/dashboard.php';
    if ($role_reg_redirect === 'student') $redirect_url_reg = BASE_URL . 'pages/student_dashboard.php';
    elseif ($role_reg_redirect === 'teacher') $redirect_url_reg = BASE_URL . 'pages/teacher_dashboard.php';
    elseif ($role_reg_redirect === 'admin') $redirect_url_reg = BASE_URL . 'pages/admin_home.php';
    header('Location: ' . $redirect_url_reg);
    exit;
}

$errors_register = [];
$email_value = '';
$full_name_value = '';
$page_flash_message_register = null;

// Флеш-сообщения от других страниц
if (isset($_SESSION['message_flash'])) {
    $page_flash_message_register = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

$csrf_token = generate_csrf_token(); // Генерируем CSRF-токен для формы

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf_token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($submitted_csrf_token)) {
        $errors_register[] = 'Ошибка безопасности формы. Пожалуйста, обновите страницу и попробуйте еще раз.';
    } else {
        // Данные из формы
        $password = trim($_POST['password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');
        $email_input_reg = trim($_POST['email'] ?? '');
        $full_name_input_reg = trim($_POST['full_name'] ?? ''); 
        $role_for_registration = 'student';

        // Предзаполнение формы в случае ошибки
        $email_value = htmlspecialchars($email_input_reg);
        $full_name_value = htmlspecialchars($full_name_input_reg);

        // Валидация
        // Полное имя (ФИО)
        if (empty($full_name_input_reg)) {
            $errors_register[] = 'Пожалуйста, укажите ваше полное имя.';
        } elseif (mb_strlen($full_name_input_reg) > 50) {
            $errors_register[] = 'Полное имя не должно превышать 50 символов.';
        } elseif (mb_strlen($full_name_input_reg) < 2) { 
            $errors_register[] = 'Полное имя слишком короткое.';
        } else {
            $name_parts = array_filter(explode(' ', trim($full_name_input_reg)), function($part) { return !empty(trim($part)); });
            if (count($name_parts) < 2 && mb_strlen(trim($full_name_input_reg)) > 0) {
                $errors_register[] = 'Полное имя должно состоять как минимум из двух слов (например, Имя Фамилия).';
            } else {
                $all_parts_capitalized = true;
                foreach ($name_parts as $part) {
                    $first_char = mb_substr($part, 0, 1, 'UTF-8');
                    // Проверяем, что первая буква в верхнем регистре
                    if (mb_strtoupper($first_char, 'UTF-8') !== $first_char) {
                        $all_parts_capitalized = false;
                        break;
                    }
                }
                if (!$all_parts_capitalized && count($name_parts) >= 2) { 
                    $errors_register[] = 'Каждое слово в ФИО должно начинаться с заглавной буквы.';
                }
            }

            if (!empty(trim($full_name_input_reg)) && !preg_match('/^[\p{L}\s\'-]+$/u', $full_name_input_reg)) { /
                $errors_register[] = 'Полное имя может содержать только буквы, пробелы, апострофы и дефисы.';
            }
        }

        // Email
        if (empty($email_input_reg)) {
            $errors_register[] = 'Пожалуйста, укажите ваш Email.';
        } elseif (!filter_var($email_input_reg, FILTER_VALIDATE_EMAIL)) {
            $errors_register[] = 'Некорректный формат Email.';
        }

        // Пароль
        if (empty($password)) {
            $errors_register[] = 'Пожалуйста, введите пароль.';
        } elseif (mb_strlen($password) < 8) {
            $errors_register[] = 'Пароль должен содержать не менее 8 символов.';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password)) {
            $errors_register[] = 'Пароль должен содержать минимум 8 символов, включая хотя бы одну заглавную букву, одну строчную букву и одну цифру.';
        }

        // Подтверждение пароля
        if (empty($confirm_password)) {
            $errors_register[] = 'Пожалуйста, подтвердите пароль.';
        } elseif ($password !== $confirm_password) {
            $errors_register[] = 'Пароли не совпадают.';
        }

        // Если нет ошибок валидации полей, проверяем уникальность Email в БД
        if (empty($errors_register)) {
            $conn_reg_db = null;
            try {
                $conn_reg_db = getDbConnection();
                $sql_check_email_exists = "SELECT id FROM users WHERE LOWER(email) = LOWER(?)";
                $stmt_check_email_exists = $conn_reg_db->prepare($sql_check_email_exists);
                $stmt_check_email_exists->execute([mb_strtolower($email_input_reg)]);
                if ($stmt_check_email_exists->fetch()) {
                    $errors_register[] = 'Пользователь с таким Email уже зарегистрирован.';
                } else {
                    $hashed_password_reg = password_hash($password, PASSWORD_DEFAULT);
                    if ($hashed_password_reg === false) { throw new Exception("Ошибка хеширования пароля."); }

                    $sql_insert_user = "INSERT INTO users (password, email, full_name, role, group_id, created_at) VALUES (?, ?, ?, ?, NULL, NOW())";
                    $stmt_insert_user = $conn_reg_db->prepare($sql_insert_user);
                    if ($stmt_insert_user->execute([$hashed_password_reg, $email_input_reg, $full_name_input_reg, $role_for_registration])) {
    $new_user_id = $conn_reg_db->lastInsertId();
    // Устанавливаем сессионные переменные, как при обычном входе
    $_SESSION['user_id'] = (int)$new_user_id;
    $_SESSION['email'] = $email_input_reg; 
    $_SESSION['full_name'] = $full_name_input_reg;
    $_SESSION['role'] = $role_for_registration; 
    $_SESSION['group_id'] = null; 
    try {
        $sql_update_last_login = "UPDATE users SET last_login = NOW() WHERE id = ?";
        $stmt_update_login = $conn_reg_db->prepare($sql_update_last_login);
        $stmt_update_login->execute([(int)$new_user_id]);
    } catch (PDOException $e_login_update) {
        error_log("Failed to update last_login after registration for user ID $new_user_id: " . $e_login_update->getMessage());
    }
    
    // Устанавливаем сообщение об успехе 
    $_SESSION['message_flash'] = ['type' => 'success', 'text' => 'Регистрация успешна! Добро пожаловать, ' . htmlspecialchars($full_name_input_reg) . '!'];
    // Редирект на дашборд студента
    header('Location: ' . BASE_URL . 'pages/home_student.php');
    exit();
} else {
                        $errors_register[] = 'Ошибка при регистрации пользователя.';
                        error_log("Register Insert Error: " . implode(" | ", $stmt_insert_user->errorInfo()));
                    }
                }
            } catch (PDOException $e_pdo_reg) {
                $errors_register[] = 'Ошибка базы данных при регистрации.';
                error_log("Register PDO Error: " . $e_pdo_reg->getMessage());
            } catch (Exception $e_gen_reg) {
                 $errors_register[] = 'Произошла внутренняя ошибка: ' . $e_gen_reg->getMessage();
                 error_log("Register General Error: " . $e_gen_reg->getMessage());
            } finally {
                $conn_reg_db = null;
            }
        }
    } 
} 

$page_title = 'Регистрация - ' . APP_NAME;
$body_class = 'landing-page-body auth-page-content register-page-styling';
$show_sidebar = false;
$is_auth_page = false;
$is_landing_page = true;
$container_class_main = '';
ob_start();
?>

<div class="auth-page-main-content">
<div class="auth-form-wrapper">
    <div class="auth-form-container">
        <h2>Регистрация студента</h2>
        <?php if ($page_flash_message_register): ?>
            <div class="alert alert-<?php echo htmlspecialchars($page_flash_message_register['type']); ?> mb-3 alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($page_flash_message_register['text']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors_register)): ?>
            <div class="alert alert-danger mb-3">
                <strong>При регистрации произошли ошибки:</strong>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors_register as $error_item_reg): ?><li><?php echo htmlspecialchars($error_item_reg); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'pages/register.php'); ?>" novalidate id="registerForm" class="needs-validation">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="form-group">
                <label for="reg_full_name">Полное имя <span class="required-mark">*</span></label>
                <input type="text" id="reg_full_name" name="full_name" value="<?php echo htmlspecialchars($full_name_value); ?>"
                       required minlength="2" maxlength="50" class="form-control"
                       placeholder="Фамилия Имя Отчество"
                       title="Введите, пожалуйста, ваше полное имя (например, Алексадров Алексадр Александрович). Каждое слово должно начинаться с заглавной буквы.">
                <div class="invalid-feedback">Пожалуйста, укажите ваше полное имя (от 2 до 50 символов).</div>
            </div>
            <div class="form-group">
                <label for="reg_email">Email <span class="required-mark">*</span></label>
                <input type="email" id="reg_email" name="email" value="<?php echo $email_value; ?>"
                       required class="form-control" placeholder="myemail@example.com">
                <div class="invalid-feedback">Пожалуйста, введите корректный Email.</div>
            </div>
             <div class="form-group">
                <label for="reg_password">Пароль <span class="required-mark">*</span></label>
                <div class="password-input-wrapper"> 
                    <input type="password" id="reg_password" name="password" required minlength="8" class="form-control"
                           placeholder="Не менее 8 символов"
                           pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$"
                           title="Минимум 8 символов, включая заглавную, строчную буквы, цифру и символ.">
                    <span class="toggle-password-visibility" id="toggle_reg_password_icon" title="Показать/скрыть пароль">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
                <div class="invalid-feedback">Пароль должен быть не менее 8 символов, содержать заглавную, строчную буквы и цифру.</div>
            </div>
            <div class="form-group">
                <label for="reg_confirm_password">Подтверждение пароля <span class="required-mark">*</span></label>
                <div class="password-input-wrapper"> 
                    <input type="password" id="reg_confirm_password" name="confirm_password" required minlength="8" class="form-control"
                           placeholder="Повторите пароль">
                    <span class="toggle-password-visibility" id="toggle_reg_confirm_password_icon" title="Показать/скрыть пароль">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
                <div class="invalid-feedback" id="confirm_password_feedback_reg">Пожалуйста, подтвердите пароль. Пароли должны совпадать.</div>
            </div>
        </form>
        <div class="form-footer">
            <div class="form-group">
                <button type="submit" class="btn btn-primary" form="registerForm">Зарегистрироваться</button>
            </div>
            <p>Уже есть аккаунт? <a href="<?php echo htmlspecialchars(BASE_URL . 'pages/login.php'); ?>">Войти</a></p>
        </div>
    </div>
</div>
</div>
<?php
$page_content = ob_get_clean();
// Добавляем JS для валидации и совпадения паролей
$page_specific_js = <<<'EOT'
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
})();
const regPasswordEl = document.getElementById('reg_password');
const regConfirmPasswordEl = document.getElementById('reg_confirm_password');
const confirmPasswordFeedbackElReg = document.getElementById('confirm_password_feedback_reg');
function validatePasswordConfirmationReg() {
    if (regPasswordEl && regConfirmPasswordEl && confirmPasswordFeedbackElReg) {
        if (regPasswordEl.value !== regConfirmPasswordEl.value && regConfirmPasswordEl.value !== '') {
            regConfirmPasswordEl.setCustomValidity('Пароли не совпадают.');
            confirmPasswordFeedbackElReg.textContent = 'Пароли не совпадают.';
            // Bootstrap сам добавит/уберет is-invalid на основе setCustomValidity
        } else {
            regConfirmPasswordEl.setCustomValidity('');
            confirmPasswordFeedbackElReg.textContent = 'Пожалуйста, подтвердите пароль.';
        }
    }
}
if (regPasswordEl) regPasswordEl.addEventListener('input', validatePasswordConfirmationReg);
if (regConfirmPasswordEl) regConfirmPasswordEl.addEventListener('input', validatePasswordConfirmationReg);
if (regConfirmPasswordEl && regConfirmPasswordEl.value !== ''){
    validatePasswordConfirmationReg();
}
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
    }
};
togglePasswordVisibility('reg_password', 'toggle_reg_password_icon');
togglePasswordVisibility('reg_confirm_password', 'toggle_reg_confirm_password_icon');
</script>
EOT;
require_once LAYOUTS_PATH . 'main_layout.php';
?>