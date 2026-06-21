<?php

/**
 * 在 public/router.php 与 Composer autoload 中尽早加载。
 * PHP 8.5+ 下第三方库会产生大量 Deprecated；若 display_errors 开启，会以 HTML 混入响应体，
 * 导致 /admin 接口返回非纯 JSON，前端 Axios 报「Server error」或加载登录配置失败。
 */

if (PHP_VERSION_ID >= 80500) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}
