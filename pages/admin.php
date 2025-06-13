<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (!defined('BASE_URL')) { define('BASE_URL', '/project/'); }
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!is_logged_in() || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Доступ запрещен. У вас нет прав администратора.'];
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit();
}

$conn = null;
$stats = [
    'users_total' => 0, 'students' => 0, 'teachers' => 0, 'admins' => 0,
    'groups' => 0, 'subjects' => 0, 'lessons' => 0
];
$db_error_message = '';

try {
    $conn = getDbConnection();

    // Статистика пользователей
    $stmt_users = $conn->query("SELECT COUNT(*) as total, role FROM users GROUP BY role");
    $user_stats_raw = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
    $stmt_users = null;
    foreach ($user_stats_raw as $stat) {
        $stats['users_total'] += $stat['total'];
        $role_key = $stat['role'] . 's';
        if (array_key_exists($role_key, $stats)) {
            $stats[$role_key] = $stat['total'];
        }
    }

    // Статистика групп
    $stmt_groups = $conn->query("SELECT COUNT(*) FROM groups");
    $stats['groups'] = $stmt_groups->fetchColumn();
    $stmt_groups = null;

    // Статистика предметов
    $stmt_subjects = $conn->query("SELECT COUNT(*) FROM subjects");
    $stats['subjects'] = $stmt_subjects->fetchColumn();
    $stmt_subjects = null;

    // Статистика занятий
    $stmt_lessons = $conn->query("SELECT COUNT(*) FROM lessons");
    $stats['lessons'] = $stmt_lessons->fetchColumn();
    $stmt_lessons = null;

} catch (PDOException $e) {
    error_log("Database Error in admin.php (stats): " . $e->getMessage());
    $db_error_message = "Произошла ошибка при загрузке статистики.";
} finally {
    $conn = null;
}?>


<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Административная панель</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/main.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container admin-page">
        <h1>Административная панель</h1>

        <?php display_message();?>
        <?php if (!empty($db_error_message)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($db_error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Статистика -->
        <section class="admin-stats card">
            <h2><i class="fas fa-chart-bar"></i> Общая статистика</h2>
            <div class="stats-grid">
                <div class="stat-card"><h3>Пользователи</h3><p class="stat-value"><?php echo $stats['users_total']; ?></p></div>
                <div class="stat-card"><h3>Студенты</h3><p class="stat-value"><?php echo $stats['students']; ?></p></div>
                <div class="stat-card"><h3>Преподаватели</h3><p class="stat-value"><?php echo $stats['teachers']; ?></p></div>
                <div class="stat-card"><h3>Админы</h3><p class="stat-value"><?php echo $stats['admins']; ?></p></div>
                <div class="stat-card"><h3>Группы</h3><p class="stat-value"><?php echo $stats['groups']; ?></p></div>
                <div class="stat-card"><h3>Дисциплины</h3><p class="stat-value"><?php echo $stats['subjects']; ?></p></div>
                <div class="stat-card"><h3>Занятия</h3><p class="stat-value"><?php echo $stats['lessons']; ?></p></div>
            </div>
        </section>

        <!-- Ссылки на разделы управления -->
        <section class="admin-navigation">
            <h2><i class="fas fa-cogs"></i> Разделы управления</h2>
            <div class="admin-dashboard-grid">
                <a href="<?php echo BASE_URL; ?>pages/admin_users.php" class="admin-link-card">
                    <i class="fas fa-users"></i>
                    <h3>Пользователи</h3>
                    <p>Управление учетными записями, ролями и назначениями.</p>
                </a>
                <a href="<?php echo BASE_URL; ?>pages/admin_groups.php" class="admin-link-card">
                     <i class="fas fa-users-cog"></i>
                    <h3>Группы</h3>
                    <p>Создание и редактирование групп, назначение дисциплин и кураторов.</p>
                </a>
                <a href="<?php echo BASE_URL; ?>pages/admin_subjects.php" class="admin-link-card">
                     <i class="fas fa-book"></i>
                    <h3>Дисциплины</h3>
                    <p>Управление списком учебных дисциплин.</p>
                </a>
            </div>
        </section>

    </div> 

    <?php include '../includes/footer.php'; ?>
</body>
</html>