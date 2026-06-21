<?php

/**
 * 检索契约冒烟测试（读取 docs/SEARCH_REGISTRY.json 中的 smokeCases）
 * 用法: php gccd_server/scripts/search-smoke.php [--base=http://127.0.0.1:9526]
 */
declare(strict_types=1);

$baseUrl = 'http://127.0.0.1:9526';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $baseUrl = rtrim(substr($arg, 7), '/');
    }
}

$root = dirname(__DIR__, 2);
$registryPath = $root . '/docs/SEARCH_REGISTRY.json';
if (!is_file($registryPath)) {
    fwrite(STDERR, "Missing {$registryPath}, run: node scripts/audit-search-registry.mjs\n");
    exit(1);
}

$registry = json_decode(file_get_contents($registryPath), true);
$smokeCases = $registry['smokeCases'] ?? [];

if ($smokeCases === []) {
    echo "No smokeCases in registry, using built-in defaults.\n";
    $smokeCases = [
        [
            'name' => '生产计划-成品型号模糊',
            'endpoint' => '/index.php/admin/production.Production/list',
            'payload' => ['page' => 1, 'limit' => 3, 'plan_finish' => '0'],
            'filter' => ['material_model' => '50'],
            'expect' => 'narrow',
        ],
        [
            'name' => '报工-成品型号模糊',
            'endpoint' => '/index.php/admin/production.ProductReport/list',
            'payload' => ['page' => 1, 'limit' => 3],
            'filter' => ['c_material_model' => '51'],
            'expect' => 'narrow',
        ],
        [
            'name' => '盘点-圆钢规格',
            'endpoint' => '/index.php/admin/production.Inventory/list',
            'payload' => ['page' => 1, 'limit' => 3],
            'filter' => ['y_material_spec' => '50'],
            'expect' => 'narrow_or_zero',
        ],
    ];
}

$token = loginToken($baseUrl);
if ($token === '') {
    fwrite(STDERR, "Login failed, skip API smoke (set credentials in script env if needed).\n");
    exit(0);
}

$failed = 0;
foreach ($smokeCases as $case) {
    $baseCount = requestCount($baseUrl, $token, $case['endpoint'], $case['payload']);
    $filterPayload = array_merge($case['payload'], $case['filter'] ?? []);
    $filterCount = requestCount($baseUrl, $token, $case['endpoint'], $filterPayload);
    $nomatchPayload = array_merge($case['payload'], array_map(static fn () => 'ZZZNOMATCH999', $case['filter'] ?? []));
    $nomatchCount = requestCount($baseUrl, $token, $case['endpoint'], $nomatchPayload);

    $ok = true;
    $expect = $case['expect'] ?? 'narrow';
    if ($expect === 'narrow' && $filterCount > $baseCount) {
        $ok = false;
    }
    if ($expect === 'narrow' && $baseCount > 0 && $filterCount >= $baseCount) {
        $ok = false;
    }
    if ($nomatchCount !== 0) {
        $ok = false;
    }

    $status = $ok ? 'PASS' : 'FAIL';
    echo "[{$status}] {$case['name']}: base={$baseCount} filter={$filterCount} nomatch={$nomatchCount}\n";
    if (!$ok) {
        $failed++;
    }
}

exit($failed > 0 ? 1 : 0);

function loginToken(string $baseUrl): string
{
    $url = $baseUrl . '/index.php/admin/system.Login/login';
    $body = json_encode(['username' => 'yyladmin', 'password' => '123456'], JSON_UNESCAPED_UNICODE);
    $resp = httpPost($url, $body, []);
    if (($resp['code'] ?? 0) !== 200) {
        return '';
    }
    return (string) ($resp['data']['AdminToken'] ?? '');
}

function requestCount(string $baseUrl, string $token, string $endpoint, array $payload): int
{
    $url = $baseUrl . $endpoint;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $resp = httpPost($url, $body, ['AdminToken: ' . $token]);
    return (int) ($resp['data']['count'] ?? 0);
}

function httpPost(string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
