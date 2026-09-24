<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, X-Neon-Secret");
header("Content-Type: application/json; charset=UTF-8");

define("DB_FILE", "neon_database.db");
define("UPLOAD_DIR", "uploads/");
define("API_SECRET_KEY", "NEON_ULTRA_SECURE_SECRET_2026_KEY!");

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
    echo json_encode(["status" => "error", "message" => "Veritabani hatasi: " . $e->getMessage()]);
    exit;
}

if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

$rawIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
function maskIP($ip) {
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        return "{$parts[0]}.{$parts[1]}.***.***";
    }
    return substr($ip, 0, 6) . "****";
}
$clientIp = maskIP($rawIp);

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(["status" => "error", "message" => "Gecersiz veri paketi."]);
    exit;
}

$action = $input['action'] ?? '';

function logAction($db, $ip, $act, $stat) {
    try {
        $stmt = $db->prepare("INSERT INTO logs (date, ip, action, status, version_warning) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([date("Y-m-d H:i:s"), $ip, $act, $stat, "OK"]);
    } catch (Exception $e) {}
}

$response = ["status" => "error", "message" => "Bilinmeyen islem."];

// 🔒 Admin İşlemleri İçin Merkezi Kontrol (JSON hata döndürecek şekilde düzeltildi)
$validTokens = ["SYVEX", "BQWET", "UFC", "NEONBEST31"];
if (strpos($action, 'admin_') === 0) {
    $adminToken = $input['admin_token'] ?? '';
    if (!in_array($adminToken, $validTokens)) {
        echo json_encode(["status" => "error", "message" => "Yetkisiz Admin Erisimi! Token hatali."]);
        exit;
    }
}

if ($action === 'admin_get_all') {
    $stmt = $db->query("SELECT * FROM licenses ORDER BY id DESC");
    $response['licenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtLogs = $db->query("SELECT * FROM logs ORDER BY id DESC LIMIT 50");
    $response['logs'] = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    $response['status'] = "success";
} 
elseif ($action === 'admin_generate_keys') {
    $count = intval($input['count'] ?? 1);
    $days = intval($input['days'] ?? 30);
    $expiryText = date("Y-m-d H:i:s", strtotime("+{$days} days"));

    for ($i = 0; $i < $count; $i++) {
        $key = "NEON-" . rand(1000, 9999) . "-" . rand(1000, 9999) . "-" . rand(1000, 9999);
        try {
            $stmt = $db->prepare("INSERT INTO licenses (license_key, expiry_date) VALUES (?, ?)");
            $stmt->execute([$key, $expiryText]);
        } catch (Exception $e) { $i--; }
    }
    logAction($db, $clientIp, 'generate_keys', 'success');
    $response = ["status" => "success", "message" => "Lisanslar basariyla uretildi."];
} 
elseif ($action === 'admin_reset_hwid') {
    $id = intval($input['id'] ?? 0);
    $stmt = $db->prepare("UPDATE licenses SET hwid = '' WHERE id = ?");
    $stmt->execute([$id]);
    logAction($db, $clientIp, 'reset_hwid', 'success');
    $response = ["status" => "success", "message" => "HWID sifirlandi."];
} 
elseif ($action === 'admin_delete_license') {
    $id = intval($input['id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM licenses WHERE id = ?");
    $stmt->execute([$id]);
    logAction($db, $clientIp, 'delete_license', 'success');
    $response = ["status" => "success", "message" => "Lisans silindi."];
} 
// 🟢 C++ LOADER TARAFINDAN GELEN 'auth' İSTEĞİ
elseif ($action === 'auth' || $action === 'validate_license') {
    $clientTime = intval($input['time'] ?? 0);
    $clientSignature = $input['signature'] ?? '';
    
    // Zaman damgası kontrolü (5 dakika tolerans)
    if (abs(time() - $clientTime) > 300) {
        echo json_encode(["status" => "error", "message" => "Zaman damgasi gecersiz!"]);
        exit;
    }

    $expectedSignature = hash_hmac('sha256', $action . $clientTime, API_SECRET_KEY);
    if (!hash_equals($expectedSignature, $clientSignature)) {
        echo json_encode(["status" => "error", "message" => "Imza dogrulanamadi!"]);
        exit;
    }

    $key = trim($input['key'] ?? $input['license_key'] ?? '');
    $hwid = trim($input['hwid'] ?? '');

    if (empty($key) || empty($hwid)) {
        $response = ["status" => "error", "message" => "Eksik parametre!"];
    } else {
        $stmt = $db->prepare("SELECT * FROM licenses WHERE license_key = ?");
        $stmt->execute([$key]);
        $license = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$license) {
            $response = ["status" => "error", "message" => "Gecersiz Lisans Anahtari!"];
            logAction($db, $clientIp, 'auth_failed_notfound', 'failed');
        } else {
            if (strtotime($license['expiry_date']) < time()) {
                $response = ["status" => "error", "message" => "Lisans suresi dolmus!"];
                logAction($db, $clientIp, 'auth_failed_expired', 'failed');
            } elseif (empty($license['hwid'])) {
                $updateHwid = $db->prepare("UPDATE licenses SET hwid = ? WHERE id = ?");
                $updateHwid->execute([$hwid, $license['id']]);
                
                $response = ["status" => "success", "message" => "Bitis: " . $license['expiry_date']];
                logAction($db, $clientIp, 'auth_bind_hwid', 'success');
            } elseif ($license['hwid'] === $hwid) {
                $response = ["status" => "success", "message" => "Bitis: " . $license['expiry_date']];
                logAction($db, $clientIp, 'auth_success', 'success');
            } else {
                $response = ["status" => "error", "message" => "HWID Uyusmazligi! Baska cihaza kayitli."];
                logAction($db, $clientIp, 'auth_failed_hwid', 'failed');
            }
        }
    }
}

echo json_encode($response);
exit;
?>
