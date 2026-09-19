<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

$db_file = __DIR__ . '/database.json';

// Veritabanı yoksa ilk kurulumu yap
if (!file_exists($db_file)) {
    $initial_data = [
        "keys" => [],
        "devices" => [],
        "logs" => [
            ["time" => date("H:i:s"), "message" => "Sistem başarıyla başlatıldı."]
        ]
    ];
    file_put_contents($db_file, json_encode($initial_data, JSON_PRETTY_PRINT));
}

$data = json_decode(file_get_contents($db_file), true);
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Bilinmeyen IP';

// 1. Panel için verileri çekme isteği
if ($action === 'get_data') {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Panelden yeni key üretme isteği
if ($action === 'generate_key' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_key = "NEON-" . strtoupper(bin2hex(random_bytes(4))) . "-" . strtoupper(bin2hex(random_bytes(4)));
    
    array_unshift($data['keys'], [
        "license_key" => $new_key,
        "note" => "Admin Paneli Üretimi",
        "hwid" => null,
        "status" => "Aktif"
    ]);

    array_unshift($data['logs'], [
        "time" => date("H:i:s"),
        "message" => "[Panel] Yeni key üretildi: " . $new_key
    ]);

    file_put_contents($db_file, json_encode($data, JSON_PRETTY_PRINT));
    echo json_encode(["success" => true, "key" => $new_key]);
    exit;
}

// 3. C++ İstemci (Client) Tarafından Gelen Lisans ve Crack / Doğrulama İstekleri
if ($action === 'verify') {
    $key_val = $_GET['key'] ?? $_POST['key'] ?? '';
    $hwid = $_GET['hwid'] ?? $_POST['hwid'] ?? '';
    $pc_name = $_GET['pc_name'] ?? $_POST['pc_name'] ?? 'Bilinmeyen PC';

    $found_key = null;
    foreach ($data['keys'] as &$k) {
        if ($k['license_key'] === $key_val) {
            $found_key = &$k;
            break;
        }
    }
    unset($k);

    // Key sistemde yoksa (Geçersiz Key / Crack girişimi)
    if (!$found_key) {
        array_unshift($data['logs'], [
            "time" => date("H:i:s"),
            "message" => "[RED] Geçersiz Key Girişi | IP: {$client_ip} | Key: {$key_val}"
        ]);
        file_put_contents($db_file, json_encode($data, JSON_PRETTY_PRINT));
        echo "INVALID_KEY";
        exit;
    }

    // Key süresi dolmuş veya banlıysa
    if ($found_key['status'] !== 'Aktif') {
        array_unshift($data['logs'], [
            "time" => date("H:i:s"),
            "message" => "[UYARI] Pasif/Süresi Dolan Key Denemesi | IP: {$client_ip} | Key: {$key_val}"
        ]);
        file_put_contents($db_file, json_encode($data, JSON_PRETTY_PRINT));
        echo "EXPIRED";
        exit;
    }

    // HWID Uyuşmazlığı kontrolü (Başka PC'de deneniyorsa)
    if (!empty($found_key['hwid']) && $found_key['hwid'] !== $hwid) {
        array_unshift($data['logs'], [
            "time" => date("H:i:s"),
            "message" => "[GÜVENLİK] HWID Uyuşmazlığı (Başka PC denemesi) | IP: {$client_ip} | Key: {$key_val}"
        ]);
        file_put_contents($db_file, json_encode($data, JSON_PRETTY_PRINT));
        echo "HWID_MISMATCH";
        exit;
    }

    // İlk defa giriliyorsa HWID'yi bu key'e sabitle
    if (empty($found_key['hwid'])) {
        $found_key['hwid'] = $hwid;
    }

    // Cihazı aktif cihazlar listesine ekle veya güncelle
    $device_found = false;
    foreach ($data['devices'] as &$dev) {
        if ($dev['hwid'] === $hwid) {
            $dev['pc_name'] = $pc_name;
            $dev['online'] = true;
            $dev['last_seen'] = time();
            $device_found = true;
            break;
        }
    }
    unset($dev);

    if (!$device_found) {
        $data['devices'][] = [
            "pc_name" => $pc_name,
            "hwid" => $hwid,
            "online" => true,
            "last_seen" => time()
        ];
    }

    // Başarılı giriş logu
    array_unshift($data['logs'], [
        "time" => date("H:i:s"),
        "message" => "[BAŞARILI] Cihaz Giriş Yaptı: {$pc_name} | IP: {$client_ip}"
    ]);

    file_put_contents($db_file, json_encode($data, JSON_PRETTY_PRINT));
    echo "SUCCESS";
    exit;
}

// Bilinmeyen istekler
echo json_encode(["success" => false, "error" => "Geçersiz işlem"]);
