<?php
/**
 * 退出登录
 */
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    audit('退出后台', '退出登录');
}
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
redirect(app_base_path() . '/admin/login.php');
