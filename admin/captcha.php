<?php
/**
 * 图形验证码
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('YHSESSID');
    session_start();
}

$code = '';
$chars = 'ABCDEFGHJKLMNPQRSTUVWXY3456789';
for ($i = 0; $i < 4; $i++) {
    $code .= $chars[random_int(0, strlen($chars) - 1)];
}
$_SESSION['captcha'] = strtoupper($code);
$_SESSION['captcha_at'] = time();

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!function_exists('imagecreatetruecolor')) {
    // 无 GD 扩展时输出纯文本占位（不影响后台登录校验逻辑）
    header('Content-Type: text/plain; charset=utf-8');
    echo $code;
    exit;
}

$w = 110;
$h = 38;
$im = imagecreatetruecolor($w, $h);
$bg = imagecolorallocate($im, 246, 248, 251);
imagefill($im, 0, 0, $bg);

// 干扰线
for ($i = 0; $i < 5; $i++) {
    $c = imagecolorallocate($im, random_int(190, 225), random_int(195, 228), random_int(200, 232));
    imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
}

// 噪点
for ($i = 0; $i < 90; $i++) {
    $c = imagecolorallocate($im, random_int(170, 220), random_int(175, 222), random_int(180, 226));
    imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $c);
}

// 文字
for ($i = 0; $i < 4; $i++) {
    $c = imagecolorallocate($im, random_int(25, 90), random_int(45, 110), random_int(120, 190));
    $font = 5;
    $x = 12 + $i * 24 + random_int(-2, 2);
    $y = random_int(8, 15);
    imagestring($im, $font, $x, $y, $code[$i], $c);
}

imagepng($im);
imagedestroy($im);
