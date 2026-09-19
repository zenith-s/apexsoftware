<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

if (empty($_GET) && empty($_POST)) {
    header("HTTP/1.1 403 Forbidden");
    exit(json_encode(["status" => "error", "message" => "Access Denied"]));
}

$dbFile = 'database.json';
if (!file_exists($dbFile)) {
    file_put_contents($dbFile, json_encode(["keys" => [], "clients" => []]));
}

$db = json_decode(file_get_contents($dbFile), true);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Lisans Kontrolü
if (isset($_GET['key']) && !isset($_GET['action'])) {
    $inputKey = trim($_GET['key']);
    $inputHwid = trim($_GET['hwid'] ?? '');

    foreach ($db['keys'] as &$k) {
        if ($k['key'] === $inputKey) {
            if ($k['expires_at'] > 0 && time() > $k['expires_at']) {
                echo "EXPIRED";
                exit;
            }
            if (empty($k['hwid'])) {
                $k['hwid'] = $inputHwid;
                file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
            } else if ($k['hwid'] !== $inputHwid) {
                echo "INVALID_HWID";
                exit;
            }
            echo "SUCCESS|" . ($k['note'] ?: "VIP Kullanici");
            exit;
        }
    }
    echo "INVALID";
    exit;
}

// Heartbeat Güncelleme
if ($action === 'update') {
    $key = $_POST['key'] ?? '';
    $hwid = $_POST['hwid'] ?? '';
    $pcName = $_POST['pc_name'] ?? 'Bilinmiyor';
    $pid = intval($_POST['pid'] ?? 0);
    $vgkState = $_POST['vgk_state'] ?? 'waiting';

    $clientIndex = -1;
    foreach ($db['clients'] as $index => $client) {
        if ($client['hwid'] === $hwid) {
            $clientIndex = $index;
            break;
        }
    }

    $banned = ($clientIndex !== -1) ? ($db['clients'][$clientIndex]['banned'] ?? false) : false;

    $clientData = [
        "hwid" => $hwid, "pc_name" => $pcName, "pid" => $pid,
        "vgk_state" => $vgkState, "last_seen" => time(), "online" => true, "banned" => $banned
    ];

    if ($clientIndex !== -1) {
        $db['clients'][$clientIndex] = $clientData;
    } else {
        $db['clients'][] = $clientData;
    }

    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode(["status" => $banned ? "terminate" : "ok"]);
    exit;
}

// Admin Panel Fonksiyonları
if ($action === 'get_clients') {
    $currentTime = time();
    foreach ($db['clients'] as &$c) {
        if (($currentTime - $c['last_seen']) > 10) $c['online'] = false;
    }
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode($db['clients']);
    exit;
}

if ($action === 'get_keys') {
    echo json_encode($db['keys']);
    exit;
}

if ($action === 'generate') {
    $note = $_POST['note'] ?? '';
    $days = intval($_POST['days'] ?? 30);
    $newKey = "NEON-" . strtoupper(substr(md5(mt_rand()), 0, 4) . "-" . substr(md5(mt_rand()), 0, 4) . "-" . substr(md5(mt_rand()), 0, 4));
    
    $db['keys'][] = ["key" => $newKey, "note" => $note, "duration_days" => $days, "expires_at" => 0, "hwid" => ""];
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode(["status" => "success", "key" => $newKey]);
    exit;
}

if ($action === 'delete_key') {
    $targetKey = $_POST['key'] ?? '';
    $db['keys'] = array_values(array_filter($db['keys'], fn($k) => $k['key'] !== $targetKey));
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode(["status" => "success"]);
    exit;
}

if ($action === 'send_cmd') {
    $hwid = $_POST['hwid'] ?? '';
    $cmd = $_POST['cmd'] ?? '';
    foreach ($db['clients'] as &$c) {
        if ($c['hwid'] === $hwid) {
            $c['banned'] = ($cmd === 'close');
        }
    }
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode(["status" => "success"]);
    exit;
}
?>
