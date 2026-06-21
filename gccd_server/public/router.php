<?php
// +----------------------------------------------------------------------
// | PHP 内置开发服务器路由（ThinkPHP）
// | 用法：在 public 目录下执行 php -S 127.0.0.1:9526 router.php
// +----------------------------------------------------------------------

if (PHP_VERSION_ID >= 80500 && is_file(__DIR__ . '/../app/bootstrap_php85.php')) {
    require __DIR__ . '/../app/bootstrap_php85.php';
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$decoded = rawurldecode($path);
$norm = str_replace('\\', '/', $decoded);
if ($norm !== '/' && $norm !== '' && !str_contains($norm, '..')) {
    $file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, ltrim($norm, '/'));
    if (is_file($file)) {
        return false;
    }
}

require __DIR__ . '/index.php';
