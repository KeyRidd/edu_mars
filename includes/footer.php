</main>
<footer>
    <p>© <?php echo date('Y'); ?> Учебная платформа. Все права защищены.</p>
</footer>
<?php
    if (!defined('BASE_URL')) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $baseUrlGuess = preg_replace(['/\/pages$/', '/\/actions$/', '/\/api$/'], '', $scriptDir);
        define('BASE_URL', rtrim($baseUrlGuess, '/') . '/');
    }
    $current_page_footer = basename($_SERVER['PHP_SELF']);
    $isLoggedInFooter = isset($_SESSION['user_id']);
?>
<script>
    const siteConfig = {
        baseUrl: <?php echo json_encode(BASE_URL); ?>,
        userId: <?php echo json_encode($isLoggedInFooter ? $_SESSION['user_id'] : 0); ?>, // Передаем ID пользователя, если авторизован
        userRole: <?php echo json_encode($isLoggedInFooter ? $_SESSION['role'] : null); ?>  // И роль
    };
    console.log('SiteConfig from footer:', siteConfig); 
</script>
<script src="<?php echo BASE_URL; ?>assets/js/functions.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo BASE_URL; ?>assets/js/main.js?v=<?php echo time(); ?>"></script>
<?php if ($isLoggedInFooter): ?>
    <script src="<?php echo BASE_URL; ?>assets/js/sidebar.js?v=<?php echo time(); ?>"></script>
<?php endif; ?>
</body>
</html>