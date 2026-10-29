<?php
/**
 * 应用引导（跳转链路与后台共用）
 */
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);
define('APP_START', microtime(true));

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/helpers.php';

// 时区
$tz = Config::get('app.timezone', 'Asia/Shanghai');
date_default_timezone_set(is_string($tz) && $tz !== '' ? $tz : 'Asia/Shanghai');

// 目录准备
foreach (['/runtime', '/runtime/logs', '/runtime/backup'] as $dir) {
    if (!is_dir(ROOT_PATH . $dir)) {
        @mkdir(ROOT_PATH . $dir, 0755, true);
    }
}

// 数据库结构自动升级（幂等，升级完成后由标记文件跳过）
Schema::ensure();

// 错误处理
error_reporting(E_ALL);
ini_set('display_errors', Config::debug() ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', ROOT_PATH . '/runtime/logs/php_error.log');

set_exception_handler(static function (Throwable $e): void {
    Logger::error('未捕获异常: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (Config::debug()) {
        http_response_code(500);
        echo '<pre style="padding:20px;font:13px/1.6 monospace;color:#b91c1c">'
            . e($e->getMessage()) . "\n" . e($e->getTraceAsString()) . '</pre>';
        return;
    }
    http_response_code(500);
    echo '服务器内部错误';
});

/**
 * 应用在站点中的访问前缀，例如部署在 /daoliu 目录下则返回 "/daoliu"
 * 部署在站点根目录则返回 ""
 */
function app_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $base    = '';
    $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $appRoot = realpath(ROOT_PATH);
    if ($docRoot && $appRoot && strpos($appRoot, $docRoot) === 0) {
        $base = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
    }
    $base = rtrim($base, '/');
    return $base;
}
