<?php
// Hata ayıklama kapalı (JSON çıktısının bozulmaması için)
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

// --- VERİTABANI BAĞLANTISI (MySQL Örneği - Dilerseniz SQLite yapabilirsiniz) ---
$host = 'localhost';
$db   = 'neon_security';
$user = 'root';
$pass = 'sifreniz';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Veritabanı yoksa otomatik oluşturma denemesi veya hata döndürme
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// Tabloların varlığını kontrol et / Yoksa oluştur
$pdo->exec("CREATE TABLE IF NOT EXISTS licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(255) NOT NULL UNIQUE,
    hwid VARCHAR(255) DEFAULT NULL,
    last_ip VARCHAR(50) DEFAULT NULL,
    expiry_date DATETIME DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'unused',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS hwid_bans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hwid VARCHAR(255) NOT NULL UNIQUE,
    date DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS ip_bans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(50) NOT NULL UNIQUE,
    date DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip VARCHAR(50),
    action VARCHAR(100),
    status VARCHAR(50),
    details TEXT
)");

// Yardımcı Fonksiyon: İstemci IP adresini al
function getClientIP() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    return $ip;
}

// Log kaydetme fonksiyonu
function writeLog($pdo, $ip, $action, $status, $details) {
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (ip, action, status, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([$ip, $action, $status, $details]);
    } catch (\Exception $e) {}
}

$clientIp = getClientIP();

// --- 1. ADMIN PANELİ İSTEKLERİ (POST / JSON) ---
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if ($data && isset($data['action'])) {
    $adminToken = $data['admin_token'] ?? '';
    $expectedToken = 'ADMIN_GIZLI_TOKEN_123'; // Admin panelindeki token ile birebir aynı olmalı!

    // Admin yetki kontrolü gerektiren işlemler
    if (strpos($data['action'], 'admin_') === 0) {
        if ($adminToken !== $expectedToken) {
            echo json_encode(["status" => "error", "message" => "Unauthorized Admin Token"]);
            exit;
        }
    }

    $action = $data['action'];

    switch ($action) {
        case 'admin_get_all':
            $licenses = $pdo->query("SELECT * FROM licenses ORDER BY id DESC")->fetchAll();
            $hwidBans = $pdo->query("SELECT * FROM hwid_bans ORDER BY id DESC")->fetchAll();
            $ipBans   = $pdo->query("SELECT * FROM ip_bans ORDER BY id DESC")->fetchAll();
            $logs     = $pdo->query("SELECT * FROM logs ORDER BY id DESC LIMIT 100")->fetchAll();

            // Durum güncellemeleri (Süresi bitenleri expired yap)
            foreach($licenses as &$l) {
                if ($l['expiry_date'] && strtotime($l['expiry_date']) < time() && $l['status'] == 'active') {
                    $up = $pdo->prepare("UPDATE licenses SET status = 'expired' WHERE id = ?");
                    $up->execute([$l['id']]);
                    $l['status'] = 'expired';
                }
            }

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
            writeLog($pdo, $clientIp, 'Delete License', 'success', "ID: $id silindi.");
            echo json_encode(["status" => "success"]);
            exit;

        case 'admin_ban_hwid':
            $hwid = $data['hwid'] ?? '';
            if ($hwid) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO hwid_bans (hwid) VALUES (?)");
                $stmt->execute([$hwid]);
                // İlgili lisansı banla
                $stmt2 = $pdo->prepare("UPDATE licenses SET status = 'banned' WHERE hwid = ?");
                $stmt2->execute([$hwid]);
                writeLog($pdo, $clientIp, 'Ban HWID', 'error', "HWID banlandı: $hwid");
            }
            echo json_encode(["status" => "success"]);
            exit;

        case 'admin_remove_hwid_ban':
            $id = intval($data['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM hwid_bans WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(["status" => "success"]);
            exit;

        case 'admin_ban_ip':
            $ip = $data['ip'] ?? '';
            if ($ip) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO ip_bans (ip) VALUES (?)");
                $stmt->execute([$ip]);
                writeLog($pdo, $clientIp, 'Ban IP', 'error', "IP banlandı: $ip");
            }
            echo json_encode(["status" => "success"]);
            exit;

        case 'admin_remove_ip_ban':
            $id = intval($data['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM ip_bans WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(["status" => "success"]);
            exit;
    }
}

// --- 2. C++ İSTEMCİ (CLIENT) İSTEKLERİ (GET) ---
$action = $_GET['action'] ?? '';

// IP Ban Kontrolü
$stmt = $pdo->prepare("SELECT * FROM ip_bans WHERE ip = ?");
$stmt->execute([$clientIp]);
if ($stmt->fetch()) {
    writeLog($pdo, $clientIp, 'Client Connect', 'banned', 'Yasaklı IP erişim denemesi.');
    echo "IP_BANNED";
    exit;
}

if ($action === 'ping') {
    echo "OK";
    exit;
}

if ($action === 'verify') {
    $key = $_GET['key'] ?? '';
    $hwid = $_GET['hwid'] ?? '';
    $pcName = $_GET['pc_name'] ?? '';

    if (empty($key) || empty($hwid)) {
        echo "INVALID_REQUEST";
        exit;
    }

    // HWID Ban Kontrolü
    $stmt = $pdo->prepare("SELECT * FROM hwid_bans WHERE hwid = ?");
    $stmt->execute([$hwid]);
    if ($stmt->fetch()) {
        writeLog($pdo, $clientIp, 'Verify Key', 'banned', "Yasaklı HWID ile giriş denemesi: $hwid");
        echo "HWID_BANNED";
        exit;
    }

    // Lisans Sorgula
    $stmt = $pdo->prepare("SELECT * FROM licenses WHERE license_key = ?");
    $stmt->execute([$key]);
    $license = $stmt->fetch();

    if (!$license) {
        writeLog($pdo, $clientIp, 'Verify Key', 'error', "Geçersiz key denemesi: $key");
        echo "KEY_NOT_FOUND";
        exit;
    }

    if ($license['status'] === 'banned') {
        echo "KEY_BANNED";
        exit;
    }

    // Süre kontrolü
    if ($license['expiry_date'] && strtotime($license['expiry_date']) < time()) {
        $up = $pdo->prepare("UPDATE licenses SET status = 'expired' WHERE id = ?");
        $up->execute([$license['id']]);
        writeLog($pdo, $clientIp, 'Verify Key', 'error', "Süresi dolmuş key: $key");
        echo "KEY_EXPIRED";
        exit;
    }

    // HWID Kilitleme ve Eşleştirme
    if (empty($license['hwid'])) {
        $update = $pdo->prepare("UPDATE licenses SET hwid = ?, last_ip = ?, status = 'active' WHERE id = ?");
        $update->execute([$hwid, $clientIp, $license['id']]);
        writeLog($pdo, $clientIp, 'Verify Key', 'success', "Yeni HWID bağlandı: $key -> $hwid");
    } else if ($license['hwid'] !== $hwid) {
        writeLog($pdo, $clientIp, 'Verify Key', 'error', "HWID uyuşmazlığı: $key");
        echo "HWID_MISMATCH";
        exit;
    } else {
        // IP ve aktiflik güncelle
        $update = $pdo->prepare("UPDATE licenses SET last_ip = ?, status = 'active' WHERE id = ?");
        $update->execute([$clientIp, $license['id']]);
    }

    // C++ İstemcisinin Beklediği Format: SUCCESS|Kalan Süre (veya Tarih)
    $expiryText = $license['expiry_date'] ? date('d.m.Y', strtotime($license['expiry_date'])) : "Sınırsız";
    writeLog($pdo, $clientIp, 'Verify Key', 'success', "Başarılı giriş: $key");
    echo "SUCCESS|" . $expiryText;
    exit;
}

echo "INVALID_ACTION";
?>
