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

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    $_SESSION['message_flash'] = ['type' => 'error', 'text' => 'Доступ запрещен. Эта страница только для студентов.'];
    header('Location: ' . BASE_URL . 'pages/login.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$archived_student_consultations = [];
$db_error_message = '';
$page_flash_message = null;

// Получение флеш-сообщения из сессии
if (isset($_SESSION['message_flash'])) {
    $page_flash_message = $_SESSION['message_flash'];
    unset($_SESSION['message_flash']);
}

try {
    $conn = getDbConnection();

     $sql_archived_s = "
        SELECT
            cr.id,
            cr.teacher_response_message, cr.scheduled_datetime_start,
            cr.scheduled_datetime_end, cr.consultation_location_or_link,
            cr.status, cr.created_at, cr.updated_at,
            cr.student_rejection_comment,
            cr.student_message,
            u_teacher.full_name as teacher_name,
            s.name as subject_name
        FROM consultation_requests cr
        JOIN users u_teacher ON cr.teacher_id = u_teacher.id
        JOIN subjects s ON cr.subject_id = s.id
        WHERE cr.student_id = :student_id
          AND cr.status IN (
              'completed',
              'cancelled_by_student_before_confirmation',
              'cancelled_by_student_after_confirmation',
              'cancelled_by_teacher',
              'student_rejected_offer'
          )
        ORDER BY cr.updated_at DESC, cr.scheduled_datetime_start DESC, cr.created_at DESC
    ";
    $stmt_archived_s = $conn->prepare($sql_archived_s);
    $stmt_archived_s->execute([':student_id' => $student_id]);
    $archived_student_consultations = $stmt_archived_s->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("DB Error student_consultations_archive.php (Student ID: {$student_id}): " . $e->getMessage());
    $db_error_message = "Ошибка загрузки архивных консультаций.";
} finally {
    if ($conn) { $conn = null; }
}

$page_title = "Архив моих консультаций";
$show_sidebar = true;
$is_auth_page = false;
$is_landing_page = false;
$body_class = 'consultations-archive-page app-page';
$load_notifications_css = true;
$load_stud_csss = true; 

ob_start();
?>

<div class="container py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h1 class="h2 mb-0 me-3"><i class="fas fa-archive me-2"></i>Архив моих консультаций</h1>
        <a href="<?php echo BASE_URL; ?>pages/student_consultations.php" class="btn btn-outline-primary btn-sm mt-2 mt-md-0">
            <i class="fas fa-arrow-left me-1"></i> К активным заявкам
        </a>
    </div>
    <nav aria-label="breadcrumb" class="mb-4 bg-light p-2 rounded">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/home_student.php">Главная</a></li>
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/student_consultations.php">Мои консультации</a></li>
            <li class="breadcrumb-item active" aria-current="page">Архив</li>
        </ol>
    </nav>
    <?php if ($page_flash_message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($page_flash_message['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo htmlspecialchars($page_flash_message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($db_error_message)): ?>
        <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($db_error_message); ?></div>
    <?php endif; ?>

    <?php if (empty($archived_student_consultations) && empty($db_error_message)): ?>
        <div class="card card-body text-center text-muted py-5">
            <p class="mb-0 fs-5"><i class="fas fa-box-open fa-2x mb-3 d-block"></i>В вашем архиве пока нет консультаций.</p>
        </div>
    <?php elseif (!empty($archived_student_consultations)): ?>
        <div class="card shadow-sm">
            <div class="card-body p-0"> 
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width: 5%;">ID</th>
                                <th scope="col">Преподаватель</th>
                                <th scope="col">Дисциплина</th>
                                <th scope="col">Планируемая дата</th>
                                <th scope="col">Статус</th>
                                <th scope="col">Комментарий/Ответ</th>
                                <th scope="col">Дата обновления</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archived_student_consultations as $request): ?>
                                <tr>
                                    <td>#<?php echo $request['id']; ?></td>
                                    <td><?php echo htmlspecialchars($request['teacher_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['subject_name']); ?></td>
                                    <td>
                                        <?php echo $request['scheduled_datetime_start']
                                            ? htmlspecialchars(format_ru_datetime($request['scheduled_datetime_start']))
                                            : '<span class="text-muted">Не назначено</span>'; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo htmlspecialchars(get_consultation_request_status_badge_class($request['status'])); ?> fs-xs">
                                            <?php echo htmlspecialchars(get_consultation_request_status_text($request['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $details_to_show = '';
                                        if (!empty($request['student_rejection_comment'])) {
                                            $details_to_show = "Ваш комм. при откл.: " . $request['student_rejection_comment'];
                                        } elseif (!empty($request['teacher_response_message'])) {
                                            $details_to_show = "Ответ препод.: " . $request['teacher_response_message'];
                                        } elseif (in_array($request['status'], ['cancelled_by_student_before_confirmation', 'cancelled_by_student_after_confirmation']) && !empty($request['student_message'])) {
                                            $details_to_show = "Ваш первоначальный запрос: " . $request['student_message'];
                                        }
                                        ?>
                                        <small class="text-muted" title="<?php echo htmlspecialchars($details_to_show); ?>">
                                            <?php echo htmlspecialchars(truncate_text($details_to_show, 70)); ?>
                                        </small>
                                    </td>
                                    <td class="text-nowrap">
                                        <small class="text-muted" title="<?php echo htmlspecialchars($request['updated_at']); ?>">
                                            <?php echo htmlspecialchars(format_ru_datetime_short($request['updated_at'])); ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require_once LAYOUTS_PATH . 'main_layout.php';
?>