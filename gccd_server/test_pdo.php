<?php
$env = parse_ini_file('.env', true);
$db = $env['DATABASE'];
$dsn = "mysql:host={$db['HOSTNAME']};port={$db['HOSTPORT']};dbname={$db['DATABASE']};charset={$db['CHARSET']}";
try {
    $pdo = new PDO($dsn, $db['USERNAME'], $db['PASSWORD']);
    $stmt = $pdo->query("SELECT id, repair_name, parent_id FROM ya_product_count WHERE is_delete = 0");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
