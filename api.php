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

// 1. C++ Loader Tarafından Gelen Heartbeat / Durum Güncelleme
if ($action === 'update') {
    $key = isset($_POST['key']) ? trim($_POST['key']) : '';
    $hwid = isset($_POST['hwid']) ? trim($_POST['hwid']) : '';
    $pcName = isset($_POST['pc_name']) ? htmlspecialchars($_POST['pc_name']) : 'Unknown';
    $pid = isset($_POST['pid']) ? intval($_POST['pid']) : 0;
    $vgkState = isset($_POST['vgk_state']) ? htmlspecialchars($_POST['vgk_state']) : 'waiting';

    if (empty($hwid)) {
        echo json_encode(["status" => "error", "message" => "Invalid HWID"]);
        exit;
    }

    $db = loadDb();
    
    // Banlı / Engellenmiş kontrolü
    foreach ($db as $client) {
        if ($client['hwid'] === $hwid && isset($client['banned']) && $client['banned'] === true) {
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
            if (!empty($key)) $client['key'] = $key;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $db[] = [
            "key" => $key,
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

// 2. Command Center Paneli İçin Aktif Cihazları Listeleme
if ($action === 'get_clients') {
    $db = loadDb();
    $activeClients = [];
    $currentTime = time();

    foreach ($db as $client) {
        // Son 15 saniye içinde istek atanlar çevrim içi sayılır
        $client['online'] = ($currentTime - $client['last_seen']) <= 15;
        $activeClients[] = $client;
    }

    echo json_encode($activeClients);
    exit;
}

// 3. Panelden Kapat Komutu Gönderme (Bağlantıyı Kesme ve Banlama)
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

// 4. C++ Loader İlk Açılışta Lisans ve Donanım Doğrulama (GET / POST)
$key = isset($_REQUEST['key']) ? trim($_REQUEST['key']) : '';
$hwid = isset($_REQUEST['hwid']) ? trim($_REQUEST['hwid']) : '';

if (!empty($key) && $action !== 'get_clients' && $action !== 'send_cmd' && $action !== 'update') {
    $db = loadDb();
    $valid = false;

    foreach ($db as &$item) {
        if (isset($item['key']) && $item['key'] === $key) {
            if (empty($item['hwid']) || $item['hwid'] === '') {
                $item['hwid'] = $hwid;
                $item['status'] = 'active';
                saveDb($db);
                $valid = true;
            } else if ($item['hwid'] === $hwid) {
                $valid = true;
            }
            break;
        }
    }

    if ($valid) {
        echo "SUCCESS";
    } else {
        echo "INVALID";
    }
    exit;
}

echo json_encode(["status" => "API is running."]);
?>
