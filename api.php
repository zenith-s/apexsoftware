<?php
header("Content-Type: application/json; charset=UTF-8");
error_reporting(0);

$dbFile = "clients_db.json";

function loadDb() {
    global $dbFile;
    if (!file_exists($dbFile)) return [];
    $data = file_get_contents($dbFile);
    return json_decode($data, true) ?: [];
}

function saveDb($data) {
    global $dbFile;
    file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT));
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// --- C++ LOADER TARAFINDAN ÇAĞRILAN KISIM ---
if ($action === 'update') {
    $hwid = isset($_POST['hwid']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['hwid']) : '';
    $pcName = isset($_POST['pc_name']) ? htmlspecialchars($_POST['pc_name']) : 'Unknown';
    $pid = isset($_POST['pid']) ? intval($_POST['pid']) : 0;
    $vgkState = isset($_POST['vgk_state']) ? htmlspecialchars($_POST['vgk_state']) : 'waiting';

    if (empty($hwid)) {
        echo json_encode(["status" => "error", "message" => "Invalid HWID"]);
        exit;
    }

    $db = loadDb();
    
    // 1. Önce bu HWID banlanmış/engellenmiş mi kontrol et
    foreach ($db as $client) {
        if ($client['hwid'] === $hwid && isset($client['banned']) && $client['banned'] === true) {
            // Engellenen cihaza kalıcı kapatma komutu gönder
            echo json_encode(["status" => "blocked", "cmd" => "terminate"]);
            exit;
        }
    }

    $found = false;
    foreach ($db as &$client) {
        if ($client['hwid'] === $hwid) {
            $client['pc_name'] = $pcName;
            $client['pid'] = $pid;
            $client['vgk_state'] = $vgkState;
            $client['last_seen'] = time();
            $found = true;
            break;
        }
    }

    if (!$found) {
        $db[] = [
            "hwid" => $hwid,
            "pc_name" => $pcName,
            "pid" => $pid,
            "vgk_state" => $vgkState,
            "last_seen" => time(),
            "cmd" => "none",
            "banned" => false
        ];
    }

    saveDb($db);

    // Komut kontrolü
    $assignedCmd = "none";
    foreach ($db as $client) {
        if ($client['hwid'] === $hwid) {
            $assignedCmd = isset($client['cmd']) ? $client['cmd'] : "none";
            break;
        }
    }

    echo json_encode(["status" => "success", "cmd" => $assignedCmd]);
    exit;
}

// --- WEB PANEL TARAFINDAN ÇAĞRILAN KISIMLAR ---

if ($action === 'get_clients') {
    $db = loadDb();
    $activeClients = [];
    $currentTime = time();

    foreach ($db as $client) {
        $client['online'] = ($currentTime - $client['last_seen']) <= 25;
        $activeClients[] = $client;
    }

    echo json_encode($activeClients);
    exit;
}

// Kapat komutu verildiğinde hem close atar hem de cihazı kalıcı olarak engeller (banned = true)
if ($action === 'send_cmd') {
    $hwid = isset($_POST['hwid']) ? $_POST['hwid'] : '';
    $command = isset($_POST['cmd']) ? $_POST['cmd'] : 'none';

    $db = loadDb();
    foreach ($db as &$client) {
        if ($client['hwid'] === $hwid) {
            $client['cmd'] = $command;
            if ($command === 'close') {
                $client['banned'] = true; // Sunucu artık bu PC'nin isteklerini reddedecek
            }
            break;
        }
    }
    saveDb($db);
    echo json_encode(["status" => "ok"]);
    exit;
}

echo json_encode(["status" => "API is running."]);
?>
