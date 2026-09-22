<?php
// Hata raporlamayı kapat (JSON çıktısının bozulmaması için)
error_reporting(0);
ini_set('display_errors', 0);

// CORS ve İçerik Tipi Başlıkları
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- VERİTABANI BAĞLANTISI (SQLite) ---
try {
    $pdo = new PDO('sqlite:neon.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// Tabloları Otomatik Oluştur
$pdo->exec("CREATE TABLE IF NOT EXISTS licenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    license_key TEXT NOT NULL UNIQUE,
    hwid TEXT DEFAULT NULL,
    last_ip TEXT DEFAULT NULL,
    expiry_date DATETIME DEFAULT NULL,
    status TEXT DEFAULT 'unused',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS hwid_bans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hwid TEXT NOT NULL UNIQUE,
    date DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS ip_bans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL UNIQUE,
    date DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip TEXT,
    action TEXT,
    status TEXT,
    details TEXT
)");

// IP Adresi Alma ve Maskeleme Fonksiyonu
function getClientIP() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    return $ip;
}

function maskIPString($ip) {
    if (!$ip) return "127.0.***";
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        return "{$parts[0]}.{$parts[1]}.***.***";
    }
    return substr($ip, 0, 6) . "****";
}

// Log Kaydetme (IP'yi maskeli şekilde veritabanına kaydeder)
function writeLog($pdo, $ip, $action, $status, $details) {
    try {
        $maskedIp = maskIPString($ip);
        $stmt = $pdo->prepare("INSERT INTO logs (ip, action, status, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([$maskedIp, $action, $status, $details]);
    } catch (\Exception $e) {}
}

$clientIp = getClientIP();

// --- JSON İSTEKLERİ (Admin Paneli) ---
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if ($data && isset($data['action'])) {
    $adminToken = strtoupper(trim($data['admin_token'] ?? ''));
    
    // İzin verilen çoklu admin key listesi
    $validTokens = ['SYVEX', 'BQWET', 'UFC', 'NEONBEST31'];

    if (strpos($data['action'], 'admin_') === 0) {
        if (!in_array($adminToken, $validTokens)) {
            echo json_encode(["status" => "error", "message" => "Yetkisiz Token! Geçersiz Admin Key."]);
            exit;
        }
    }

    $action = $data['action'];

    switch ($action) {
        case 'admin_get_all':
            $licenses = $pdo->query("SELECT * FROM licenses ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            $hwidBans = $pdo->query("SELECT * FROM hwid_bans ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            $ipBans   = $pdo->query("SELECT * FROM ip_bans ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            $logs     = $pdo->query("SELECT * FROM logs ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "licenses" => $licenses,
                "hwid_bans" => $hwidBans,
                "ip_bans" => $ipBans,
                "logs" => $logs
            ]);
            exit;

        case 'admin_generate_keys':
            $prefix = $data['prefix'] ?? 'NEON';
            $count = intval($data['count'] ?? 1);
            $days = intval($data['days'] ?? 30);
            
            $generatedKeys = [];
            for ($i = 0; $i < $count; $i++) {
                $randomStr = strtoupper(bin2hex(random_bytes(4)));
                $key = $prefix . '-' . $randomStr;
                $generatedKeys[] = $key;

                $expiryDate = ($days > 0) ? date('Y-m-d H:i:s', strtotime("+$days days")) : NULL;

                $stmt = $pdo->prepare("INSERT INTO licenses (license_key, status, expiry_date) VALUES (?, 'unused', ?)");
                $stmt->execute([$key, $expiryDate]);
            }

            writeLog($pdo, $clientIp, 'Generate Keys', 'success', "$count adet key üretildi.");
            echo json_encode(["status" => "success", "keys" => $generatedKeys]);
            exit;

        case 'admin_reset_hwid':
            $id = intval($data['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE licenses SET hwid = NULL, status = 'unused' WHERE id = ?");
            $stmt->execute([$id]);
            writeLog($pdo, $clientIp, 'Reset HWID', 'success', "ID: $id HWID sıfırlandı.");
            echo json_encode(["status" => "success"]);
            exit;

        case 'admin_delete_license':
            $id = intval($data['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM licenses WHERE id = ?");
            $stmt->execute([$id]);
            writeLog($pdo, $clientIp, 'Delete License', 'success', "ID: $id lisans silindi.");
            echo json_encode(["status" => "success"]);
            exit;
    }
}

// --- GET İSTEKLERİ ---
$action = $_GET['action'] ?? '';

if ($action === 'ping') {
    echo "OK";
    exit;
}

if ($action === 'verify') {
    $key = $_GET['key'] ?? '';
    $hwid = $_GET['hwid'] ?? '';

    if (empty($key) || empty($hwid)) {
        echo "INVALID_REQUEST";
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM licenses WHERE license_key = ?");
    $stmt->execute([$key]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        echo "KEY_NOT_FOUND";
        exit;
    }

    if (empty($license['hwid'])) {
        $update = $pdo->prepare("UPDATE licenses SET hwid = ?, last_ip = ?, status = 'active' WHERE id = ?");
        $update->execute([$hwid, maskIPString($clientIp), $license['id']]);
    } else if ($license['hwid'] !== $hwid) {
        echo "HWID_MISMATCH";
        exit;
    }

    $expiryText = $license['expiry_date'] ? date('d.m.Y', strtotime($license['expiry_date'])) : "Sınırsız";
    echo "SUCCESS|" . $expiryText;
    exit;
}

echo "API çalışıyor ancak geçersiz eylem!";
?>
