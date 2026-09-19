<?php
header("Content-Type: application/json; charset=UTF-8");
error_reporting(0);

$dbFile = "clients_db.json";

function loadDb() {
    global $dbFile;
    if (!file_exists($dbFile)) return ["keys" => [], "clients" => []];
    $data = file_get_contents($dbFile);
    $decoded = json_decode($data, true);
    if (!isset($decoded['keys'])) $decoded['keys'] = [];
    if (!isset($decoded['clients'])) $decoded['clients'] = [];
    return $decoded;
}

function saveDb($data) {
    global $dbFile;
    file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT));
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// 1. Yeni Key Oluşturma
if ($action === 'generate') {
    $note = isset($_POST['note']) ? htmlspecialchars($_POST['note']) : 'Not yok';
    $days = isset($_POST['days']) ? intval($_POST['days']) : 30;
    
    $db = loadDb();
    $newKey = "NEON-" . strtoupper(substr(md5(mt_rand()), 0, 4) . "-" . substr(md5(mt_rand()), 0, 4) . "-" . substr(md5(mt_rand()), 0, 4));
    
    $db['keys'][] = [
        "key" => $newKey,
        "note" => $note,
        "hwid" => "",
        "created_at" => time(),
        "expires_at" => 0, // İlk girişte süre başlar veya gün seçimine göre ayarlanır
        "duration_days" => $days,
        "banned" => false
    ];
    
    saveDb($db);
    echo json_encode(["status" => "success", "key" => $newKey]);
    exit;
}

// 2. Key Listesini ve Cihazları Getir
if ($action === 'get_keys' || $action === 'get_clients') {
    $db = loadDb();
    $currentTime = time();
    
    // Süresi bitenleri kontrol et
    foreach ($db['keys'] as &$k) {
        if ($k['expires_at'] > 0 && $currentTime > $k['expires_at']) {
            $k['expired'] = true;
        } else {
            $k['expired'] = false;
        }
    }
    
    if ($action === 'get_keys') {
        echo json_encode($db['keys']);
        exit;
    }
    
    $activeClients = [];
    foreach ($db['clients'] as $client) {
        $client['online'] = ($currentTime - $client['last_seen']) <= 15;
        $activeClients[] = $client;
    }
    echo json_encode($activeClients);
    exit;
}

// 3. Key Silme
if ($action === 'delete_key') {
    $keyToDelete = isset($_POST['key']) ? $_POST['key'] : '';
    $db = loadDb();
    
    $db['keys'] = array_values(array_filter($db['keys'], function($k) use ($keyToDelete) {
        return $k['key'] !== $keyToDelete;
    }));
    
    saveDb($db);
    echo json_encode(["status" => "ok"]);
    exit;
}

// 4. C++ Loader Heartbeat / Durum
if ($action === 'update') {
    $key = isset($_POST['key']) ? trim($_POST['key']) : '';
    $hwid = isset($_POST['hwid']) ? trim($_POST['hwid']) : '';
    $pcName = isset($_POST['pc_name']) ? htmlspecialchars($_POST['pc_name']) : 'Unknown';
    $pid = isset($_POST['pid']) ? intval($_POST['pid']) : 0;
    $vgkState = isset($_POST['vgk_state']) ? htmlspecialchars($_POST['vgk_state']) : 'waiting';

    $db = loadDb();
    
    foreach ($db['clients'] as $client) {
        if ($client['hwid'] === $hwid && isset($client['banned']) && $client['banned'] === true) {
            echo json_encode(["status" => "blocked", "cmd" => "terminate"]);
            exit;
        }
    }

    $found = false;
    foreach ($db['clients'] as &$client) {
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
        $db['clients'][] = [
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
    foreach ($db['clients'] as $client) {
        if ($client['hwid'] === $hwid) {
            $assignedCmd = isset($client['cmd']) ? $client['cmd'] : "none";
            break;
        }
    }

    echo json_encode(["status" => "success", "cmd" => $assignedCmd]);
    exit;
}

// 5. Panelden Kapat Komutu
if ($action === 'send_cmd') {
    $hwid = isset($_POST['hwid']) ? $_POST['hwid'] : '';
    $command = isset($_POST['cmd']) ? $_POST['cmd'] : 'none';

    $db = loadDb();
    foreach ($db['clients'] as &$client) {
        if ($client['hwid'] === $hwid) {
            $client['cmd'] = $command;
            if ($command === 'close') {
                $client['banned'] = true;
            }
            break;
        }
    }
    saveDb($db);
    echo json_encode(["status" => "ok"]);
    exit;
}

// 6. Loader İlk Lisans Doğrulama
$key = isset($_REQUEST['key']) ? trim($_REQUEST['key']) : '';
$hwid = isset($_REQUEST['hwid']) ? trim($_REQUEST['hwid']) : '';

if (!empty($key) && $action === '') {
    $db = loadDb();
    $valid = false;
    $noteFound = "";

    foreach ($db['keys'] as &$item) {
        if ($item['key'] === $key) {
            $currentTime = time();
            
            // Süre bitmiş mi?
            if ($item['expires_at'] > 0 && $currentTime > $item['expires_at']) {
                echo "EXPIRED";
                exit;
            }

            if (empty($item['hwid']) || $item['hwid'] === '') {
                $item['hwid'] = $hwid;
                $item['expires_at'] = time() + ($item['duration_days'] * 86400); // Süreyi başlat
                saveDb($db);
                $valid = true;
                $noteFound = $item['note'];
            } else if ($item['hwid'] === $hwid) {
                $valid = true;
                $noteFound = $item['note'];
            }
            break;
        }
    }

    if ($valid) {
        echo "SUCCESS|" . $noteFound;
    } else {
        echo "INVALID";
    }
    exit;
}

echo json_encode(["status" => "API is running."]);
?>
