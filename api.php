<?php
header("Content-Type: application/json; charset=UTF-8");
$db_file = __DIR__ . '/database.json';

// Veritabanı yoksa oluştur
if (!file_exists($db_file)) {
    file_put_contents($db_file, json_encode(["keys" => [], "clients" => [], "logs" => []], JSON_PRETTY_PRINT));
}

$db = json_decode(file_get_contents($db_file), true);

// Parametreleri al (GET veya POST)
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Bilinmeyen IP';

// Gelen tüm ham istekleri logla
$raw_input = array_merge($_GET, $_POST);
if (!empty($raw_input)) {
    array_unshift($db['logs'], [
        "time" => date("H:i:s"),
        "ip" => $client_ip,
        "query" => json_encode($raw_input, JSON_UNESCAPED_UNICODE)
    ]);
    if (count($db['logs']) > 100) array_pop($db['logs']);
}

// 1. C++ İstemci Lisans Doğrulama ve Cihaz Kaydı
if ($action === 'verify') {
    $key_val = $_GET['key'] ?? $_POST['key'] ?? '';
    $hwid = $_GET['hwid'] ?? $_POST['hwid'] ?? '';
    $pc_name = $_GET['pc_name'] ?? $_POST['pc_name'] ?? 'Bilinmeyen PC';

    $found = false;
    foreach ($db['keys'] as &$k) {
        if ($k['key'] === $key_val) {
            $found = true;
            if ($k['expired']) {
                file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
                echo "EXPIRED";
                exit;
            }
            if (!empty($k['hwid']) && $k['hwid'] !== $hwid) {
                file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
                echo "HWID_MISMATCH";
                exit;
            }
            if (empty($k['hwid'])) {
                $k['hwid'] = $hwid;
            }
            break;
        }
    }
    unset($k);

    if (!$found) {
        file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
        echo "INVALID_KEY";
        exit;
    }

    // Aktif cihazları güncelle / ekle
    $client_exists = false;
    foreach ($db['clients'] as &$c) {
        if ($c['hwid'] === $hwid) {
            $c['pc_name'] = $pc_name;
            $c['online'] = true;
            $c['last_seen'] = time();
            $client_exists = true;
            break;
        }
    }
    unset($c);

    if (!$client_exists) {
        $db['clients'][] = [
            "pc_name" => $pc_name,
            "hwid" => $hwid,
            "online" => true,
            "banned" => false,
            "last_seen" => time()
        ];
    }

    file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
    echo "SUCCESS";
    exit;
}

// 2. Panelden Key Üretme
if ($action === 'generate') {
    $note = $_GET['note'] ?? $_POST['note'] ?? 'VIP Kullanıcı';
    $days = intval($_GET['days'] ?? $_POST['days'] ?? 30);
    $new_key = 'NEON-' . strtoupper(substr(md5(mt_rand()), 0, 4)) . '-' . strtoupper(substr(md5(mt_rand()), 0, 4));
    
    $db['keys'][] = [
        "key" => $new_key,
        "note" => $note,
        "hwid" => "",
        "duration_days" => $days,
        "expired" => false
    ];
    
    file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode(["status" => "ok", "key" => $new_key]);
    exit;
}

// 3. Key Silme
if ($action === 'delete_key') {
    $target_key = $_GET['key'] ?? $_POST['key'] ?? '';
    $db['keys'] = array_values(array_filter($db['keys'], function($k) use ($target_key) {
        return $k['key'] !== $target_key;
    }));
    file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode(["status" => "ok"]);
    exit;
}

// 4. Panel Veri Senkronizasyonu (Aktif Cihazlar, Keyler, Loglar)
if ($action === 'get_dashboard_data') {
    $current_time = time();
    foreach ($db['clients'] as &$c) {
        // 25 saniye boyunca sinyal gelmediyse çevrimdışı yap
        if (($current_time - ($c['last_seen'] ?? 0)) > 25) {
            $c['online'] = false;
        }
    }
    unset($c);
    file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
    
    echo json_encode([
        "keys" => $db['keys'],
        "clients" => $db['clients'],
        "logs" => $db['logs']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(["status" => "active"]);
?>
