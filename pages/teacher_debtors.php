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

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. Эта страница только для преподавателей.'];
    header('Location: ' . BASE_URL . 'pages/login.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$available_groups = [];
$available_subjects = [];
$debtors = [];
$selected_group_id = null;
$selected_subject_id = null;
$db_error_message = '';
$info_message = '';
$page_flash_message = null;
$errors_from_page = []; // Используем это имя для ошибок валидации GET-параметров

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}
try {
    $conn = getDbConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Получаем список групп, доступных преподавателю
    $sql_groups = "SELECT DISTINCT g.id, g.name FROM groups g JOIN teaching_assignments ta ON g.id = ta.group_id WHERE ta.teacher_id = ? ORDER BY g.name ASC";
    $stmt_groups = $conn->prepare($sql_groups);
    $stmt_groups->execute([$teacher_id]);
    $available_groups = $stmt_groups->fetchAll(PDO::FETCH_ASSOC);
    $stmt_groups = null;

    // Определяем выбранную группу 
    if (isset($_GET['group_id']) && $_GET['group_id'] !== '') {
        $requested_group_id = (int)$_GET['group_id'];
        // Проверяем, доступна ли эта группа преподавателю
        $is_group_valid = false;
        foreach ($available_groups as $group) {
            if ($group['id'] === $requested_group_id) {
                $selected_group_id = $requested_group_id;
                $is_group_valid = true;
                break;
            }
        }
        if (!$is_group_valid) {
            $errors[] = "Выбранная группа недоступна."; 
        }
    }
    // Если группа выбрана, получаем список предметов, которые препод ведет в ЭТОЙ группе
    if ($selected_group_id) {
        $sql_subjects = "SELECT DISTINCT s.id, s.name FROM subjects s JOIN teaching_assignments ta ON s.id = ta.subject_id WHERE ta.teacher_id = ? AND ta.group_id = ? ORDER BY s.name ASC";
        $stmt_subjects = $conn->prepare($sql_subjects);
        $stmt_subjects->execute([$teacher_id, $selected_group_id]);
        $available_subjects = $stmt_subjects->fetchAll(PDO::FETCH_ASSOC);
        $stmt_subjects = null;

        // Определяем выбранный предмет
        if (isset($_GET['subject_id']) && $_GET['subject_id'] !== '') {
             $requested_subject_id = (int)$_GET['subject_id'];
             $is_subject_valid = false;
             foreach ($available_subjects as $subject) {
                  if ($subject['id'] === $requested_subject_id) {
                       $selected_subject_id = $requested_subject_id;
                       $is_subject_valid = true;
                       break;
                  }
             }
             if (!$is_subject_valid) {
                  $errors[] = "Выбранная дисциплина недоступна для этой группы или преподавателя.";
             }
        }
    }
    // Если выбраны и группа, и предмет, ищем задолжников
    if ($selected_group_id && $selected_subject_id && empty($errors)) {

        // Проверяем дату ИА и проходной балл перед основным запросом
        $sql_check_assessment = "
            SELECT s.min_passing_grade, MAX(ta.final_assessment_date) AS final_assessment_date
            FROM subjects s
            JOIN teaching_assignments ta ON s.id = ta.subject_id
            WHERE ta.group_id = :group_id AND ta.subject_id = :subject_id
            GROUP BY s.min_passing_grade"; 
        $stmt_check = $conn->prepare($sql_check_assessment);
        $stmt_check->bindParam(':group_id', $selected_group_id, PDO::PARAM_INT);
        $stmt_check->bindParam(':subject_id', $selected_subject_id, PDO::PARAM_INT);
        $stmt_check->execute();
        $assessment_info = $stmt_check->fetch(PDO::FETCH_ASSOC);
        $stmt_check = null;

        if (!$assessment_info || $assessment_info['min_passing_grade'] === null) {
            $info_message = "Для данной дисциплины не установлен проходной балл. Список задолжников не может быть сформирован.";
        } elseif (!$assessment_info['final_assessment_date']) {
            $info_message = "Для данной дисциплины в этой группе не установлена дата итоговой аттестации.";
        } elseif (new DateTime() <= new DateTime($assessment_info['final_assessment_date'])) {
            $info_message = "Дата итоговой аттестации (" . date('d.m.Y', strtotime($assessment_info['final_assessment_date'])) . ") еще не наступила.";
        } else {
             // Все проверки пройдены, можно искать должников
            $sql_debtors = "
                SELECT
                    u.id AS student_id, u.full_name AS student_name, u.email AS student_email,
                    COALESCE(SUM(CASE WHEN a.status IN ('approved', 'graded') AND l.subject_id = :subject_id_sum THEN a.grade ELSE 0 END), 0) AS current_total_grade,
                    s.min_passing_grade
                FROM users u
                JOIN teaching_assignments ta ON u.group_id = ta.group_id
                JOIN subjects s ON ta.subject_id = s.id
                LEFT JOIN assignments a ON u.id = a.student_id
                LEFT JOIN assignment_definitions ad ON a.assignment_definition_id = ad.id
                LEFT JOIN lessons l ON ad.lesson_id = l.id
                WHERE u.role = 'student'
                  AND u.group_id = :group_id
                  AND ta.subject_id = :subject_id_main 
                  AND ta.final_assessment_date IS NOT NULL
                  AND ta.final_assessment_date < CURRENT_DATE
                  AND s.min_passing_grade IS NOT NULL
                GROUP BY u.id, u.full_name, u.email, s.min_passing_grade 
                HAVING COALESCE(SUM(CASE WHEN a.status IN ('approved', 'graded') AND l.subject_id = :subject_id_having THEN a.grade ELSE 0 END), 0) < s.min_passing_grade
                ORDER BY u.full_name ASC";
            $stmt_debtors = $conn->prepare($sql_debtors);
            // Привязываем параметры по имени
            $stmt_debtors->bindParam(':group_id', $selected_group_id, PDO::PARAM_INT);
            $stmt_debtors->bindParam(':subject_id_main', $selected_subject_id, PDO::PARAM_INT); 
            $stmt_debtors->bindParam(':subject_id_sum', $selected_subject_id, PDO::PARAM_INT);
            $stmt_debtors->bindParam(':subject_id_having', $selected_subject_id, PDO::PARAM_INT); 
            $stmt_debtors->execute();
            $debtors = $stmt_debtors->fetchAll(PDO::FETCH_ASSOC);
            $stmt_debtors = null;

            // Добавляем недостающие данные (дату ИА и проходной балл) к результату для вывода в таблице
            if (!empty($debtors) && $assessment_info) {
                 foreach ($debtors as $key => $debtor) {
                      $debtors[$key]['min_passing_grade'] = $assessment_info['min_passing_grade'];
                      $debtors[$key]['final_assessment_date'] = $assessment_info['final_assessment_date'];
                 }
            }
            if (empty($debtors) && empty($info_message)) {
                $info_message = "Задолжники по данной дисциплине в этой группе не найдены.";
            }
        }
    }

} catch (PDOException $e) {
    error_log("DB Error on teacher_debtors.php for teacher ID {$teacher_id}: " . $e->getMessage());
    $db_error_message = "Произошла ошибка базы данных.";
} finally {
    $conn = null;
}
$page_title = "Задолжники";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'teacher-debtors-page app-page dashboard-page'; 
$load_notifications_css = true;
$load_teach_css = true; 
$load_dashboard_styles_css = true;
$page_specific_js = '
    <script>
        window.teacherDebtorsPageConfig = {
            sendNotificationUrl: ' . json_encode(BASE_URL . 'actions/send_debtor_notification.php') . ',
            csrfToken: null
        };
        console.log("PHP: teacherDebtorsPageConfig defined on window", window.teacherDebtorsPageConfig);
    </script>
    <script src="' . BASE_URL . 'assets/js/teacher_debtors.js?v=' . time() . '" defer></script>
';

ob_start();
?>

<div class="container py-4">
    <div class="page-header mb-4">
        <h1 class="h2"><i class="fas fa-user-clock me-2"></i>Задолжники</h1>
    </div>
    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($errors_from_page)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <strong>Обнаружены ошибки в параметрах:</strong>
            <ul class="mb-0 ps-3">
                <?php foreach($errors_from_page as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($db_error_message)): ?>
        <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($db_error_message); ?></div>
    <?php endif; ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <!-- Форма фильтров -->
            <form method="GET" action="<?php echo BASE_URL; ?>pages/teacher_debtors.php" id="debtorsFilterForm">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label for="group_id_filter" class="form-label">Группа:</label>
                        <select name="group_id" id="group_id_filter" class="form-select"> 
                            <option value="">-- Выберите группу --</option>
                            <?php foreach ($available_groups as $group): ?>
                                <option value="<?php echo $group['id']; ?>" <?php echo ($selected_group_id == $group['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($group['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label for="subject_id_filter" class="form-label">Дисциплина:</label>
                        <select name="subject_id" id="subject_id_filter" class="form-select" <?php echo !$selected_group_id ? 'disabled' : ''; ?>>
                            <option value="">-- Выберите дисциплину --</option>
                            <?php if ($selected_group_id && !empty($available_subjects)): ?>
                                <?php foreach ($available_subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo ($selected_subject_id == $subject['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php elseif ($selected_group_id): ?>
                                <option value="" disabled>Нет доступных вам дисциплин для этой группы</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100" <?php echo !$selected_group_id || !$selected_subject_id ? 'disabled' : ''; ?>>
                            <i class="fas fa-filter me-1"></i>Показать
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php if ($selected_group_id && $selected_subject_id && empty($errors_from_page) && empty($db_error_message)): ?>
        <div class="card shadow-sm">
            <div class="card-header">
                <h3 class="h5 mb-0">Список задолжников</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($info_message)): ?>
                    <div class="alert alert-info"><?php echo htmlspecialchars($info_message); ?></div>
                <?php elseif (empty($debtors)): ?>
                    <div class="alert alert-success text-center py-4">
                        <i class="fas fa-check-circle fa-3x mb-3 d-block text-success"></i>
                        Задолжники по данной дисциплине в этой группе не найдены.
                    </div>
                <?php else: ?>
                    <form id="debtorsNotificationForm" method="POST" action="<?php echo BASE_URL; ?>actions/send_debtor_notification.php">
                        <input type="hidden" name="action_type" value="send_debtor_notification">
                        <input type="hidden" name="group_id_form" value="<?php echo htmlspecialchars((string)$selected_group_id); ?>">
                        <input type="hidden" name="subject_id_form" value="<?php echo htmlspecialchars((string)$selected_subject_id); ?>">

                        <div class="table-responsive">
                            <table class="table table-striped table-hover table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40px;" class="text-center"><input type="checkbox" id="select-all-debtors-checkbox" class="form-check-input" title="Выбрать всех/снять выделение"></th>
                                        <th>Студент</th>
                                        <th>Email</th>
                                        <th class="text-center">Текущий балл</th>
                                        <th class="text-center">Проходной</th>
                                        <th>Дата ИА</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($debtors as $debtor): ?>
                                        <tr>
                                            <td class="text-center"><input type="checkbox" name="student_ids[]" value="<?php echo $debtor['student_id']; ?>" class="form-check-input debtor-checkbox"></td>
                                            <td>
                                                <a href="<?php echo BASE_URL; ?>pages/profile.php?id=<?php echo $debtor['student_id']; ?>">
                                                    <?php echo htmlspecialchars($debtor['student_name']); ?>
                                                </a>
                                            </td>
                                            <td><a href="mailto:<?php echo htmlspecialchars($debtor['student_email']); ?>"><?php echo htmlspecialchars($debtor['student_email']); ?></a></td>
                                            <td class="text-center text-danger fw-bold"><?php echo htmlspecialchars(number_format((float)($debtor['current_total_grade'] ?? 0), 1)); ?></td>
                                            <td class="text-center"><?php echo htmlspecialchars((string)($debtor['min_passing_grade'] ?? '-')); ?></td>
                                            <td class="text-nowrap"><?php echo isset($debtor['final_assessment_date']) ? htmlspecialchars(format_ru_date($debtor['final_assessment_date'])) : '<span class="text-muted">—</span>'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer text-end bg-light border-top py-2">
                            <button type="button" id="openNotificationModalBtn" class="btn btn-warning" disabled data-bs-toggle="modal" data-bs-target="#notificationModal">
                                <i class="fas fa-bell me-1"></i> Отправить уведомление выбранным
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif (!$selected_group_id && !empty($available_groups) && empty($errors_from_page) && empty($db_error_message)): ?>
          <div class="alert alert-info text-center py-4"><i class="fas fa-info-circle fa-2x mb-3 d-block"></i>Пожалуйста, выберите группу для просмотра информации.</div>
    <?php elseif ($selected_group_id && empty($available_subjects) && empty($errors_from_page) && empty($db_error_message)): ?>
           <div class="alert alert-warning text-center py-4"><i class="fas fa-exclamation-triangle fa-2x mb-3 d-block"></i>Для выбранной группы нет назначенных вам дисциплин, по которым можно было бы искать задолжников.</div>
    <?php elseif ($selected_group_id && !$selected_subject_id && !empty($available_subjects) && empty($errors_from_page) && empty($db_error_message)): ?>
            <div class="alert alert-info text-center py-4"><i class="fas fa-hand-pointer fa-2x mb-3 d-block"></i>Пожалуйста, выберите дисциплину для поиска задолжников.</div>
    <?php endif; ?>
</div>
        <!-- Модальное окно для отправки внутрисистемного уведомления -->
<div class="modal fade" id="notificationModal" tabindex="-1" aria-labelledby="notificationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="sendSystemNotificationModalForm"> 
                <div class="modal-header">
                    <h5 class="modal-title" id="notificationModalLabel">Отправка уведомления задолжникам</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Вы собираетесь отправить уведомление <strong id="selectedDebtorsCountModal">0</strong> выбранным студентам.</p>
                    <div class="mb-3">
                       <label for="notificationTitleModal" class="form-label">Заголовок уведомления:</label>
                       <input type="text" id="notificationTitleModal" name="notification_title" class="form-control" value="Уведомление о задолженности">
                    </div>
                    <div class="mb-3">
                       <label for="notificationMessageModal" class="form-label">Текст уведомления (будет отправлено каждому индивидуально):</label>
                       <textarea id="notificationMessageModal" name="notification_message" class="form-control" rows="5" required placeholder="Введите сообщение для отправки."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary" id="sendSystemNotificationModalSubmitBtn">Отправить уведомления</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>