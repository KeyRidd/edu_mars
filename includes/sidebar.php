<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$currentUserRoleForSidebar = $_SESSION['role'] ?? null;
if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $baseUrlGuess = preg_replace('/\/pages$/', '', $scriptDir);
    $baseUrlGuess = preg_replace('/\/actions$/', '', $baseUrlGuess);
    $baseUrlGuess = preg_replace('/\/api$/', '', $baseUrlGuess); 
    $baseUrlGuess = preg_replace('/\/includes$/', '', $baseUrlGuess);
    define('BASE_URL', rtrim($baseUrlGuess, '/') . '/');
}

if (!function_exists('is_active_page')) {
    $functionsPath = __DIR__ . '/functions.php'; 
    if (file_exists($functionsPath)) {
         require_once $functionsPath;
    } else {
         $functionsPathRoot = dirname(__DIR__) . '/includes/functions.php'; 
         if (file_exists($functionsPathRoot)) {
              require_once $functionsPathRoot;
         } else {
              function is_active_page(string|array $page_filenames): string { return ''; } 
              error_log("Warning: functions.php not found for sidebar.php. Path tried: $functionsPath and $functionsPathRoot");
         }
    }
}
$appNameForSidebar = defined('APP_NAME') ? APP_NAME : 'Edu.MARS';

?>
<?php if ($currentUserRoleForSidebar): ?>
<aside class="sidebar" id="main-sidebar">
    <nav class="sidebar-nav">
        <ul class="sidebar-menu">

            <li class="<?php echo is_active_page(['dashboard.php', 'student_dashboard.php', 'lesson.php', 'edit_lesson.php', 'manage_lesson_assignments.php', 'create_lesson.php', 'edit_lesson_teacher.php', 'group_info.php']); ?>">
                <?php
                $dashboard_link = BASE_URL . 'pages/dashboard.php';
                if ($currentUserRoleForSidebar === 'student') $dashboard_link = BASE_URL . 'pages/student_dashboard.php';
                ?>
                <a href="<?php echo $dashboard_link; ?>">
                    <i class="fas fa-th-large fa-fw"></i>
                    <span><?php echo ($currentUserRoleForSidebar === 'student') ? 'Расписание' : 'Группы и Занятия'; ?></span>
                </a>
            </li>
            <?php if ($currentUserRoleForSidebar === 'student'): ?>
                <li class="menu-header">Обучение</li>
                <li class="<?php echo is_active_page('student_tasks.php'); ?>">
                    <a href="<?php echo BASE_URL; ?>pages/student_tasks.php"><i class="fas fa-tasks fa-fw"></i> <span>Мои Задания</span></a>
                </li>
                 <li class="<?php echo is_active_page(['student_consultations.php', 'student_consultations_archive.php']); ?>">
                    <a href="<?php echo BASE_URL; ?>pages/student_consultations.php"><i class="fas fa-headset fa-fw"></i> <span>Консультации</span></a>
                 </li>
                 <li class="<?php echo is_active_page('student_grades.php'); ?>">
                    <a href="<?php echo BASE_URL; ?>pages/student_grades.php"><i class="fas fa-clipboard-check fa-fw"></i> <span>Мои Оценки</span></a>
                </li>
            <?php endif; ?>
            <?php if ($currentUserRoleForSidebar === 'teacher'): ?>
                <li class="menu-header">Преподавание</li>
                 <li class="<?php echo is_active_page('teacher_workload.php'); ?>">
                    <a href="<?php echo BASE_URL; ?>pages/teacher_workload.php"><i class="fas fa-book-reader fa-fw"></i> <span>Учебный процесс</span></a>
                 </li>
                 <li class="<?php echo is_active_page('teacher_gradebook.php'); ?>">
                    <a href="<?php echo BASE_URL; ?>pages/teacher_gradebook.php"><i class="fas fa-table fa-fw"></i> <span>Ведомости</span></a>
                 </li>
                 <li class="<?php echo is_active_page(['teacher_consultations.php', 'teacher_consultations_archive.php']); ?>">
                    <a href="<?php echo BASE_URL; ?>pages/teacher_consultations.php"><i class="fas fa-user-clock fa-fw"></i> <span>Консультации</span></a>
                 </li>
                  <li class="<?php echo is_active_page('teacher_debtors.php'); ?>">
                    <a href="<?php echo BASE_URL; ?>pages/teacher_debtors.php"><i class="fas fa-user-times fa-fw"></i> <span>Задолжники</span></a>
                 </li>
                 <li class="<?php echo is_active_page('teacher_create_news.php'); ?>">
                    <a href="<?php echo BASE_URL; ?>pages/teacher_create_news.php"><i class="fas fa-newspaper fa-fw"></i> <span>Новости групп</span></a>
                 </li>
            <?php endif; ?>
            <?php if ($currentUserRoleForSidebar === 'admin'): ?>
                <li class="menu-header">Администрирование</li>
                <li class="<?php echo is_active_page('admin_users.php'); ?>">
                    <a href="<?php echo BASE_URL; ?>pages/admin_users.php"><i class="fas fa-users-cog fa-fw"></i> <span>Пользователи</span></a>
                </li>
                <li class="<?php echo is_active_page(['admin_groups.php', 'edit_group.php', 'create_group.php']); ?>">
                     <a href="<?php echo BASE_URL; ?>pages/admin_groups.php"><i class="fas fa-layer-group fa-fw"></i> <span>Группы</span></a>
                 </li>
                 <li class="<?php echo is_active_page('admin_subjects.php'); ?>">
                     <a href="<?php echo BASE_URL; ?>pages/admin_subjects.php"><i class="fas fa-book fa-fw"></i> <span>Дисциплины</span></a>
                 </li>
                  <li class="<?php echo is_active_page('admin_statistics.php'); ?>">
                     <a href="<?php echo BASE_URL; ?>pages/admin_statistics.php"><i class="fas fa-chart-bar fa-fw"></i> <span>Статистика</span></a>
                 </li>
            <?php endif; ?>

            <li class="menu-divider"></li>
            <li class="<?php echo is_active_page('profile.php'); ?>">
                <a href="<?php echo BASE_URL; ?>pages/profile.php"><i class="fas fa-user-circle fa-fw"></i> <span>Мой Профиль</span></a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>pages/logout.php">
                    <i class="fas fa-sign-out-alt fa-fw"></i>
                    <span>Выход</span>
                </a>
            </li>
        </ul>
    </nav>
</aside>
<?php endif; ?>