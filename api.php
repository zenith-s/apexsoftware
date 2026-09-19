<?php
header("Content-Type: application/json; charset=UTF-8");
error_reporting(0);

$dbFile = "licenses_db.json";

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

// 1. Panelden Key Oluşturma
if ($action === 'generate') {
    $note = isset($_POST['note']) ? htmlspecialchars($_POST['note']) : 'Standard Key';
    $newKey = "NEON-" . strtoupper(bin2hex(random_bytes(4))) . "-" . strtoupper(bin2hex(random_bytes(4)));
    
    $db = loadDb();
    $db[] = [
        "key" => $newKey,
        "note" => $note,
        "hwid" => "", 
        "status" => "unused",
        "created_at" => date("Y-m-d H:i:s")
    ];
    saveDb($db);
    echo json_encode(["status" => "success", "key" => $newKey]);
    exit;
}

// 2. Panel İçin Keyleri Listeleme
if ($action === 'list_keys') {
    echo json_encode(loadDb());
    exit;
}

// 3. C++ Loader Lisans ve Sabit Donanım Doğrulama
$key = isset($_REQUEST['key']) ? trim($_REQUEST['key']) : '';
$hwid = isset($_REQUEST['hwid']) ? trim($_REQUEST['hwid']) : '';

if (!empty($key)) {
    $db = loadDb();
    $valid = false;

    foreach ($db as &$item) {
        if ($item['key'] === $key) {
            // İlk kullanımda donanıma kilitle
            if (empty($item['hwid']) || $item['hwid'] === '') {
                $item['hwid'] = $hwid;
                $item['status'] = 'active';
                saveDb($db);
                $valid = true;
            } 
            // Daha önce kilitlendiyse hwid uyuşuyor mu bak
            else if ($item['hwid'] === $hwid) {
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
