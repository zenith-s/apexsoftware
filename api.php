<?php
header("Content-Type: application/json; charset=UTF-8");
error_reporting(0);

// Verilerin tutulduğu basit bir JSON veritabanı dosyası
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

// --- C++ LOADER TARAFINDAN ÇAĞRILAN KISIM (Heartbeat & Update) ---
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
    $found = false;

    foreach ($db as &$client) {
        if ($client['hwid'] === $hwid) {
            $client['pc_name'] = $pcName;
            $client['pid'] = $pid;
            $client['vgk_state'] = $vgkState;
            $client['last_seen'] = time();
            // Eğer panelden bu cihaza özel bir komut atanmadıysa boş bırakma
            $found = true;
            break;
        }
    }

    // Yeni cihaz ise kaydet
    if (!$found) {
        $db[] = [
            "hwid" => $hwid,
            "pc_name" => $pcName,
            "pid" => $pid,
            "vgk_state" => $vgkState,
            "last_seen" => time(),
            "cmd" => "none"
        ];
    }

    saveDb($db);

    // İlgili cihaza panelden komut gönderilmiş mi kontrol et
    $assignedCmd = "none";
    foreach ($db as $client) {
        if ($client['hwid'] === $hwid) {
            $assignedCmd = isset($client['cmd']) ? $client['cmd'] : "none";
            // Komut bir kez okunduktan sonra sıfırlanabilir veya tutulabilir
            break;
        }
    }

    echo json_encode(["status" => "success", "cmd" => $assignedCmd]);
    exit;
}

// --- WEB PANEL TARAFINDAN ÇAĞRILAN KISIMLAR ---

// Cihazları Listeleme
if ($action === 'get_clients') {
    $db = loadDb();
    $activeClients = [];
    $currentTime = time();

    foreach ($db as $client) {
        // 25 saniye içinde sinyal gönderdiyse çevrim içi say
        $client['online'] = ($currentTime - $client['last_seen']) <= 25;
        $activeClients[] = $client;
    }

    echo json_encode($activeClients);
    exit;
}

// Panele Özel Komut Gönderme (Örn: Close / Ban)
if ($action === 'send_cmd') {
    $hwid = isset($_POST['hwid']) ? $_POST['hwid'] : '';
    $command = isset($_POST['cmd']) ? $_POST['cmd'] : 'none';

    $db = loadDb();
    foreach ($db as &$client) {
        if ($client['hwid'] === $hwid) {
            $client['cmd'] = $command;
            break;
        }
    }
    saveDb($db);
    echo json_encode(["status" => "ok"]);
    exit;
}

echo json_encode(["status" => "API is running."]);
?>
