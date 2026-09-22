<?php
// ============================================================================
// NEON SOFTWARE - ULTRA SECURE API & BACKEND v3.0
// ============================================================================
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

define('SECRET_KEY', 'NEON_ULTRA_SECURE_SECRET_2026_KEY!'); 
define('DB_HOST', 'localhost');
define('DB_NAME', 'neon_db');
define('DB_USER', 'root');
define('DB_PASS', 'sifreniz_buraya');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    exit(json_encode(['status' => 'error', 'message' => 'Veritabanı bağlantı hatası!']));
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!$data || !isset($data['action'])) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Geçersiz istek paketi!']));
}

$action = $data['action'];

// İstek Loglama Fonksiyonu
function logRequest($pdo, $ip, $action, $status, $details) {
    try {
        $stmt = $pdo->prepare("INSERT INTO request_logs (ip, action, status, details, date) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$ip, $action, $status, $details]);
    } catch (Exception $e) {}
}

// IP Ban Kontrolü
$stmt = $pdo->prepare("SELECT * FROM ip_bans WHERE ip = ?");
$stmt->execute([$clientIp]);
if ($stmt->rowCount() > 0) {
    logRequest($pdo, $clientIp, $action, 'banned', 'IP Banlı');
    exit(json_encode(['status' => 'banned', 'message' => 'IP adresiniz sistemden kalıcı olarak yasaklandı!']));
}

// --- İŞLEM YÖNETİCİSİ ---
switch ($action) {
    case 'auth': // C++ İstemci Lisans Kontrolü
        $licenseKey = $data['key'] ?? '';
        $hwid = $data['hwid'] ?? '';
        $timestamp = $data['time'] ?? 0;

        // İmza Doğrulama (Anti-Tamper)
        $expectedSignature = hash_hmac('sha256', $action . $timestamp, SECRET_KEY);
        if (!hash_equals($expectedSignature, $data['signature'] ?? '')) {
            logRequest($pdo, $clientIp, 'auth', 'error', 'Geçersiz İmza');
            exit(json_encode(['status' => 'error', 'message' => 'Güvenlik imzası uyuşmazlığı!']));
        }

        // HWID Ban Kontrolü
        $stmt = $pdo->prepare("SELECT * FROM hwid_bans WHERE hwid = ?");
        $stmt->execute([$hwid]);
        if ($stmt->rowCount() > 0) {
            logRequest($pdo, $clientIp, 'auth', 'banned', 'HWID Banlı: ' . $hwid);
            exit(json_encode(['status' => 'banned', 'message' => 'Bu donanım (HWID) yasaklanmıştır!']));
        }

        // Lisans Sorgulama
        $stmt = $pdo->prepare("SELECT * FROM licenses WHERE license_key = ?");
        $stmt->execute([$licenseKey]);
        $lic = $stmt->fetch();

        if (!$lic) {
            logRequest($pdo, $clientIp, 'auth', 'error', 'Geçersiz Key: ' . $licenseKey);
            exit(json_encode(['status' => 'error', 'message' => 'Geçersiz Lisans Anahtarı!']));
        }

        if ($lic['status'] === 'banned') {
            logRequest($pdo, $clientIp, 'auth', 'error', 'Banlı Key Kullanımı');
            exit(json_encode(['status' => 'error', 'message' => 'Bu lisans anahtarı yasaklanmış!']));
        }

        // Süre Kontrolü
        if ($lic['expiry_date'] && strtotime($lic['expiry_date']) < time()) {
            $pdo->prepare("UPDATE licenses SET status = 'expired' WHERE id = ?")->execute([$lic['id']]);
            logRequest($pdo, $clientIp, 'auth', 'expired', 'Süresi Dolan Key');
            exit(json_encode(['status' => 'error', 'message' => 'Lisans anahtarınızın süresi dolmuştur!']));
        }

        // HWID Eşleştirme
        if (empty($lic['hwid'])) {
            $upd = $pdo->prepare("UPDATE licenses SET hwid = ?, last_ip = ?, status = 'active', last_login = NOW() WHERE id = ?");
            $upd->execute([$hwid, $clientIp, $lic['id']]);
            logRequest($pdo, $clientIp, 'auth', 'success', 'İlk Cihaz Eşleşmesi');
            echo json_encode(['status' => 'success', 'message' => 'Lisans başarıyla bu cihaza bağlandı! Bitiş: ' . ($lic['expiry_date'] ?: 'Sınırsız')]);
        } elseif ($lic['hwid'] === $hwid) {
            $pdo->prepare("UPDATE licenses SET last_ip = ?, last_login = NOW() WHERE id = ?")->execute([$clientIp, $lic['id']]);
            logRequest($pdo, $clientIp, 'auth', 'success', 'Giriş Başarılı');
            echo json_encode(['status' => 'success', 'message' => 'Giriş başarılı! Bitiş: ' . ($lic['expiry_date'] ?: 'Sınırsız')]);
        } else {
            logRequest($pdo, $clientIp, 'auth', 'error', 'HWID Uyuşmazlığı');
            echo json_encode(['status' => 'error', 'message' => 'Bu lisans başka bir cihaza kayıtlı!']);
        }
        break;

    // --- ADMIN İŞLEMLERİ ---
    case 'admin_get_all':
        $adminToken = $data['admin_token'] ?? '';
        if ($adminToken !== 'ADMIN_GIZLI_TOKEN_123') {
            exit(json_encode(['status' => 'error', 'message' => 'Yetkisiz Erişim!']));
        }

        $licenses = $pdo->query("SELECT * FROM licenses ORDER BY id DESC")->fetchAll();
        $hwidBans = $pdo->query("SELECT * FROM hwid_bans ORDER BY id DESC")->fetchAll();
        $ipBans = $pdo->query("SELECT * FROM ip_bans ORDER BY id DESC")->fetchAll();
        $logs = $pdo->query("SELECT * FROM request_logs ORDER BY id DESC LIMIT 100")->fetchAll();

        echo json_encode([
            'status' => 'success',
            'licenses' => $licenses,
            'hwid_bans' => $hwidBans,
            'ip_bans' => $ipBans,
            'logs' => $logs
        ]);
        break;

    case 'admin_generate_keys':
        $adminToken = $data['admin_token'] ?? '';
        if ($adminToken !== 'ADMIN_GIZLI_TOKEN_123') exit(json_encode(['status' => 'error']));

        $count = intval($data['count'] ?? 1);
        $days = intval($data['days'] ?? 30); // 0 = Sınırsız
        $prefix = preg_replace('/[^A-Z]/', '', strtoupper($data['prefix'] ?? 'NEON'));

        $expiryDate = $days > 0 ? date('Y-m-d H:i:s', strtotime("+$days days")) : null;
        $generated = [];

        for ($i = 0; $i < $count; $i++) {
            $key = $prefix . '-' . strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $pdo->prepare("INSERT INTO licenses (license_key, status, expiry_date, created_at) VALUES (?, 'unused', ?, NOW())");
            $stmt->execute([$key, $expiryDate]);
            $generated[] = $key;
        }

        logRequest($pdo, $clientIp, 'admin_generate', 'success', "$count adet lisans üretildi.");
        echo json_encode(['status' => 'success', 'keys' => $generated]);
        break;

    case 'admin_reset_hwid':
        $adminToken = $data['admin_token'] ?? '';
        $id = intval($data['id'] ?? 0);
        if ($adminToken !== 'ADMIN_GIZLI_TOKEN_123') exit(json_encode(['status' => 'error']));

        $pdo->prepare("UPDATE licenses SET hwid = NULL, status = 'unused' WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'admin_delete_license':
        $adminToken = $data['admin_token'] ?? '';
        $id = intval($data['id'] ?? 0);
        if ($adminToken !== 'ADMIN_GIZLI_TOKEN_123') exit(json_encode(['status' => 'error']));

        $pdo->prepare("DELETE FROM licenses WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'admin_ban_hwid':
        $adminToken = $data['admin_token'] ?? '';
        $hwid = $data['hwid'] ?? '';
        if ($adminToken !== 'ADMIN_GIZLI_TOKEN_123' || empty($hwid)) exit(json_encode(['status' => 'error']));

        $pdo->prepare("INSERT IGNORE INTO hwid_bans (hwid, date) VALUES (?, NOW())")->execute([$hwid]);
        $pdo->prepare("UPDATE licenses SET status = 'banned' WHERE hwid = ?")->execute([$hwid]);
        echo json_encode(['status' => 'success']);
        break;

    case 'admin_remove_hwid_ban':
        $adminToken = $data['admin_token'] ?? '';
        $id = intval($data['id'] ?? 0);
        if ($adminToken !== 'ADMIN_GIZLI_TOKEN_123') exit(json_encode(['status' => 'error']));

        $pdo->prepare("DELETE FROM hwid_bans WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'admin_ban_ip':
        $adminToken = $data['admin_token'] ?? '';
        $ip = $data['ip'] ?? '';
        if ($adminToken !== 'ADMIN_GIZLI_TOKEN_123' || empty($ip)) exit(json_encode(['status' => 'error']));

        $pdo->prepare("INSERT IGNORE INTO ip_bans (ip, date) VALUES (?, NOW())")->execute([$ip]);
        echo json_encode(['status' => 'success']);
        break;

    case 'admin_remove_ip_ban':
        $adminToken = $data['admin_token'] ?? '';
        $id = intval($data['id'] ?? 0);
        if ($adminToken !== 'ADMIN_GIZLI_TOKEN_123') exit(json_encode(['status' => 'error']));

        $pdo->prepare("DELETE FROM ip_bans WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Bilinmeyen işlem komutu.']);
        break;
}
