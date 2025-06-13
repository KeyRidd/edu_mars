<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../includes/functions.php'; 
require_once '../includes/auth.php'; 

logout_user();
if (!defined('BASE_URL')) { define('BASE_URL', '/project/'); }
redirect_with_message(BASE_URL . 'index.php', 'Вы успешно вышли из системы');
?>