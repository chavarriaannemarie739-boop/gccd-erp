<?php
namespace app;
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/vendor/autoload.php';
$http = (new \think\App())->http;
$response = $http->run();

$data = \think\facade\Db::name('product_count')->select()->toArray();
print_r($data);
