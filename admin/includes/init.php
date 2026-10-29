<?php
/**
 * 后台公共初始化：会话、登录校验、CSRF、审计日志
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_name('YHSESSID');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

// ---------------- CSRF ----------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_str(32);
    }
    return (string) $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string) ($_POST['_token'] ?? $_GET['_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
        http_response_code(419);
        exit('会话已过期，请刷新页面后重试。');
    }
}

// ---------------- 登录态 ----------------
function current_admin(): ?array
{
    static $admin = null;
    if ($admin !== null) {
        return $admin;
    }
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $admin = Database::one('SELECT * FROM `admins` WHERE `id`=?', [$id]) ?: null;
    return $admin;
}

function is_logged_in(): bool
{
    return current_admin() !== null;
}

function login_admin(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_name'] = (string) $admin['username'];
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . app_base_path() . '/admin/login.php');
        exit;
    }
}

// ---------------- 提示消息 ----------------
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $message, 'type' => $type];
}

function take_flash(): array
{
    $list = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $list;
}

// ---------------- 审计日志 ----------------
function audit(string $action, string $detail = ''): void
{
    $admin = current_admin();
    Database::insert('operation_logs', [
        'admin_id'   => (int) ($admin['id'] ?? 0),
        'username'   => (string) ($admin['username'] ?? 'system'),
        'action'     => mb_substr($action, 0, 60),
        'detail'     => mb_substr($detail, 0, 480),
        'ip'         => client_ip(),
        'created_at' => Database::now(),
    ]);
}

// ---------------- 通用小工具 ----------------
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** 分页信息 */
function pagination(int $total, int $page, int $perPage): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'per_page' => $perPage];
}

function pager_html(array $pg, array $query = []): string
{
    if ($pg['pages'] <= 1) {
        return '';
    }
    $make = static function (int $p) use ($query) {
        $query['page'] = $p;
        return '?' . http_build_query($query);
    };
    $html = '<div class="pager">';
    if ($pg['page'] > 1) {
        $html .= '<a href="' . e($make($pg['page'] - 1)) . '">上一页</a>';
    }
    $start = max(1, $pg['page'] - 3);
    $end   = min($pg['pages'], $pg['page'] + 3);
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $pg['page']
            ? '<span class="cur">' . $i . '</span>'
            : '<a href="' . e($make($i)) . '">' . $i . '</a>';
    }
    if ($pg['page'] < $pg['pages']) {
        $html .= '<a href="' . e($make($pg['page'] + 1)) . '">下一页</a>';
    }
    $html .= '<span class="muted">共 ' . $pg['pages'] . ' 页</span>';
    return $html . '</div>';
}
