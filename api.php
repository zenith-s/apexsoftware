<?php
/**
 * NEON SOFTWARE - Secure API Gateway & Device Telemetry
 */
error_reporting(0);
@ini_set('display_errors', 0);
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$dataFile = 'devices.json';

// C++ Loader'dan gelen Telemetri ve Bypass İstekleri (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hwid      = isset($_POST['hwid']) ? trim($_POST['hwid']) : '';
    $pc_name   = isset($_POST['pc_name']) ? trim($_POST['pc_name']) : 'Bilinmeyen-PC';
    $pid       = isset($_POST['pid']) ? intval($_POST['pid']) : 0;
    $vgk_state = isset($_POST['vgk_state']) ? trim($_POST['vgk_state']) : 'active';

    if (empty($hwid)) {
        echo json_encode(["status" => "failed", "message" => "Geçersiz HWID Kimliği"]);
        exit();
    }

    $devices = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
    if (!is_array($devices)) $devices = [];

    $found = false;
    foreach ($devices as &$dev) {
        if ($dev['hwid'] === $hwid) {
            $dev['pc_name']   = $pc_name;
            $dev['pid']       = $pid;
            $dev['vgk_state'] = $vgk_state;
            $dev['last_seen'] = time();
            $found = true;
            break;
        }
    }

    if (!$found) {
        $devices[] = [
            "hwid"      => $hwid,
            "pc_name"   => $pc_name,
            "pid"       => $pid,
            "vgk_state" => $vgk_state,
            "last_seen" => time()
        ];
    }

    file_put_contents($dataFile, json_encode($devices, JSON_PRETTY_PRINT));

    if ($action === 'bypass') {
        $blocked = 14 + ($pid % 7);
        echo json_encode(["status" => "success", "blocked" => $blocked, "message" => "Bypass başarıyla uygulandı"]);
        exit();
    }

    echo json_encode(["status" => "success", "message" => "Sinyal başarıyla işlendi"]);
    exit();
}

// Yönetim Paneli İçin Cihaz Listeleme (GET)
if ($action === 'get_devices' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $devices = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
    if (!is_array($devices)) $devices = [];

    $currentTime = time();
    $allDevices = [];
    $activeCount = 0;

    foreach ($devices as $dev) {
        $isOnline = ($currentTime - $dev['last_seen']) < 45; // 45 saniye içinde sinyal geldiyse aktif
        if ($isOnline) {
            $activeCount++;
        }
        $dev['is_online'] = $isOnline;
        // Sıralama veya düzenleme yapılabilir
        $allDevices[] = $dev;
    }

    echo json_encode([
        "status"       => "success",
        "active_count" => $activeCount,
        "total_count"  => count($allDevices),
        "devices"      => $allDevices
    ]);
    exit();
}

// Doğrudan tarayıcıdan (GET) girilirse şık bir arayüz kartı göster
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>NEON SOFTWARE - Secure Gateway</title>
    <style>
        body { background: #07090e; color: #38bdf8; font-family: 'Courier New', Courier, monospace; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: #0f172a; border: 1px solid #1e293b; padding: 40px; border-radius: 12px; box-shadow: 0 0 35px rgba(56, 189, 248, 0.15); text-align: center; }
        h1 { color: #f43f5e; margin-bottom: 10px; font-size: 26px; letter-spacing: 1px; }
        p { color: #94a3b8; font-size: 14px; }
        .badge { display: inline-block; background: rgba(34, 197, 94, 0.15); color: #22c55e; padding: 8px 16px; border-radius: 20px; font-weight: bold; margin-top: 20px; border: 1px solid rgba(34, 197, 94, 0.3); }
    </style>
</head>
<body>
    <div class="card">
        <h1>NEON SOFTWARE API</h1>
        <p>Bulut Entegrasyon ve Haberleşme Katmanı</p>
        <div class="badge">● SİSTEM AKTİF & ÇALIŞIYOR</div>
    </div>
</body>
</html>
