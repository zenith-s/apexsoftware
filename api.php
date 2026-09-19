<?php
error_reporting(0);
ini_set('display_errors', 0);
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$dataFile = 'devices.json';

// C++ Loader'dan veya Panelden gelen POST istekleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hwid = isset($_POST['hwid']) ? trim($_POST['hwid']) : '';
    $pc_name = isset($_POST['pc_name']) ? trim($_POST['pc_name']) : 'Bilinmeyen PC';
    $pid = isset($_POST['pid']) ? intval($_POST['pid']) : 0;
    $vgk_state = isset($_POST['vgk_state']) ? trim($_POST['vgk_state']) : 'active';

    if (empty($hwid)) {
        echo json_encode(["status" => "failed", "message" => "Invalid HWID"]);
        exit();
    }

    $devices = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
    if (!is_array($devices)) $devices = [];

    $found = false;
    foreach ($devices as &$dev) {
        if ($dev['hwid'] === $hwid) {
            $dev['pc_name'] = $pc_name;
            $dev['pid'] = $pid;
            $dev['vgk_state'] = $vgk_state;
            $dev['last_seen'] = time();
            $found = true;
            break;
        }
    }
    if (!$found) {
        $devices[] = [
            "hwid" => $hwid,
            "pc_name" => $pc_name,
            "pid" => $pid,
            "vgk_state" => $vgk_state,
            "last_seen" => time()
        ];
    }
    file_put_contents($dataFile, json_encode($devices));

    if ($action === 'bypass') {
        $blocked = 14 + ($pid % 7);
        echo json_encode(["status" => "success", "blocked" => $blocked]);
        exit();
    }

    echo json_encode(["status" => "success"]);
    exit();
}

// Panel için aktif cihazları listeleme (GET isteği)
if ($action === 'get_devices' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $devices = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
    if (!is_array($devices)) $devices = [];
    
    $activeDevices = [];
    foreach ($devices as $dev) {
        // Son 30 saniye içinde sinyal gönderenleri aktif say
        if (time() - $dev['last_seen'] < 30) {
            $activeDevices[] = $dev;
        }
    }
    echo json_encode(["status" => "success", "count" => count($activeDevices), "devices" => $activeDevices]);
    exit();
}
?>
