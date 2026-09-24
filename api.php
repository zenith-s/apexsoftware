<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

define("API_CURRENT_VERSION", "v1.2.0");
define("DB_FILE", "neon_database.db");
define("UPLOAD_DIR", "uploads/");

try {
    $db = new PDO("sqlite:" . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS licenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        license_key TEXT UNIQUE,
        expiry_date TEXT,
        hwid TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT,
        ip TEXT,
        action TEXT,
        status TEXT,
        version_warning TEXT
    )");
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Veritabanı hatası: " . $e->getMessage()]);
    exit;
}

if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(["status" => "error", "message" => "API aktif fakat geçersiz istek verisi!"]);
    exit;
}

$action = $input['action'] ?? '';
$panelVersion = $input['panel_version'] ?? 'v1.0.0';
$rawIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

function maskIP($ip) {
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        return "{$parts[0]}.{$parts[1]}.***.***";
    }
    return substr($ip, 0, 6) . "****";
}
$clientIp = maskIP($rawIp);

$versionWarning = null;
if ($panelVersion !== API_CURRENT_VERSION) {
    $versionWarning = "SÜRÜM UYUŞMAZLIĞI: Panel ($panelVersion) | API (" . API_CURRENT_VERSION . ")";
}

function logAction($db, $ip, $act, $stat, $warn) {
    $stmt = $db->prepare("INSERT INTO logs (date, ip, action, status, version_warning) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([date("Y-m-d H:i:s"), $ip, $act, $stat, $warn]);
}

$response = ["status" => "success"];

if ($action === 'admin_get_all') {
    $stmt = $db->query("SELECT * FROM licenses ORDER BY id DESC");
    $response['licenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtLogs = $db->query("SELECT * FROM logs ORDER BY id DESC LIMIT 50");
    $response['logs'] = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
} 
elseif ($action === 'admin_generate_keys') {
    $count = intval($input['count'] ?? 1);
    $years = intval($input['years'] ?? 0);
    $months = intval($input['months'] ?? 0);
    $days = intval($input['days'] ?? 30);
    $hours = intval($input['hours'] ?? 0);
    $seconds = intval($input['seconds'] ?? 0);

    // Süre hesaplama mantığı
    $intervalString = "+{$years} years +{$months} months +{$days} days +{$hours} hours +{$seconds} seconds";
    $expiryTimestamp = strtotime($intervalString);
    if ($expiryTimestamp === false) {
        $expiryTimestamp = strtotime("+30 days");
    }
    $expiryText = date("Y-m-d H:i:s", $expiryTimestamp);

    for ($i = 0; $i < $count; $i++) {
        $key = "NEON-" . rand(1000, 9999) . "-" . rand(1000, 9999) . "-" . rand(1000, 9999);
        try {
            $stmt = $db->prepare("INSERT INTO licenses (license_key, expiry_date) VALUES (?, ?)");
            $stmt->execute([$key, $expiryText]);
        } catch (Exception $e) {
            $i--; 
        }
    }
    logAction($db, $clientIp, 'generate_keys', 'success', $versionWarning);
    $response['message'] = "Lisanslar başarıyla üretildi.";
} 
elseif ($action === 'admin_reset_hwid') {
    $id = intval($input['id'] ?? 0);
    $stmt = $db->prepare("UPDATE licenses SET hwid = '' WHERE id = ?");
    $stmt->execute([$id]);
    
    logAction($db, $clientIp, 'reset_hwid', 'success', $versionWarning);
    $response['message'] = "HWID başarıyla sıfırlandı.";
} 
elseif ($action === 'admin_delete_license') {
    $id = intval($input['id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM licenses WHERE id = ?");
    $stmt->execute([$id]);

    logAction($db, $clientIp, 'delete_license', 'success', $versionWarning);
    $response['message'] = "Lisans silindi.";
} 
elseif ($action === 'admin_upload_update') {
    $fileName = basename($input['filename'] ?? 'update.bin');
    $version = $input['version'] ?? 'v1.0.0';
    $fileData = $input['file_content'] ?? '';

    if (!empty($fileData)) {
        list($type, $fileData) = explode(';', $fileData);
        list(, $fileData)      = explode(',', $fileData);
        $decodedData = base64_decode($fileData);

        $filePath = UPLOAD_DIR . $fileName;
        file_put_contents($filePath, $decodedData);

        logAction($db, $clientIp, 'upload_update', 'success', $versionWarning);
        $response['message'] = "Güncelleme dosyası yüklendi: $fileName ($version)";
    } else {
        $response['status'] = "error";
        $response['message'] = "Dosya içeriği boş!";
    }
} 
elseif ($action === 'validate_license') {
    $key = $input['license_key'] ?? '';
    $hwid = $input['hwid'] ?? '';

    $stmt = $db->prepare("SELECT * FROM licenses WHERE license_key = ?");
    $stmt->execute([$key]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        $response['status'] = "error";
        $response['message'] = "Geçersiz Lisans Anahtarı!";
        logAction($db, $clientIp, 'validate', 'failed', $versionWarning);
    } else {
        // Süre kontrolü
        if (strtotime($license['expiry_date']) < time()) {
            $response['status'] = "error";
            $response['message'] = "Lisans süresi dolmuş!";
            logAction($db, $clientIp, 'validate_expired', 'failed', $versionWarning);
        } elseif (empty($license['hwid'])) {
            $updateHwid = $db->prepare("UPDATE licenses SET hwid = ? WHERE id = ?");
            $updateHwid->execute([$hwid, $license['id']]);
            $response['message'] = "Lisans doğrulandı ve HWID kilitlendi.";
            logAction($db, $clientIp, 'validate_bind', 'success', $versionWarning);
        } elseif ($license['hwid'] === $hwid) {
            $response['message'] = "Lisans aktif ve geçerli.";
            logAction($db, $clientIp, 'validate', 'success', $versionWarning);
        } else {
            $response['status'] = "error";
            $response['message'] = "HWID Uyuşmazlığı! Bu anahtar başka cihaza kayıtlı.";
            logAction($db, $clientIp, 'validate_hwid_mismatch', 'failed', $versionWarning);
        }
    }
} else {
    $response['status'] = "error";
    $response['message'] = "Geçersiz eylem komutu!";
}

echo json_encode($response);
exit;
?>
