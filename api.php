<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// API Sabit Sürüm Tanımı
define("API_CURRENT_VERSION", "v1.2.0");
define("DB_FILE", "neon_database.db");
define("UPLOAD_DIR", "uploads/");

// SQLite Veritabanı Bağlantısı ve Tablo Kurulumu
try {
    $db = new PDO("sqlite:" . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Lisanslar Tablosu
    $db->exec("CREATE TABLE IF NOT EXISTS licenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        license_key TEXT UNIQUE,
        expiry_date TEXT,
        hwid TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Loglar Tablosu
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

// Upload klasörü kontrolü
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(["status" => "error", "message" => "API çalışıyor ancak geçersiz eylem!"]);
    exit;
}

$action = $input['action'] ?? '';
$panelVersion = $input['panel_version'] ?? 'v1.0.0';
$rawIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// IP Maskeleme Fonksiyonu (Güvenlik için son kısımlar gizlenir)
function maskIP($ip) {
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        return "{$parts[0]}.{$parts[1]}.***.***";
    }
    return substr($ip, 0, 6) . "****";
}
$clientIp = maskIP($rawIp);

// Sürüm uyuşmazlığı kontrolü
$versionWarning = null;
if ($panelVersion !== API_CURRENT_VERSION) {
    $versionWarning = "SÜRÜM UYUŞMAZLIĞI: Panel Eski Sürüm ($panelVersion) | API Güncel (" . API_CURRENT_VERSION . ")";
}

// Log Kaydetme Fonksiyonu
function logAction($db, $ip, $act, $stat, $warn) {
    $stmt = $db->prepare("INSERT INTO logs (date, ip, action, status, version_warning) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([date("Y-m-d H:i:s"), $ip, $act, $stat, $warn]);
}

$response = ["status" => "success"];

// 1. TÜM VERİLERİ ÇEKME (Panel için)
if ($action === 'admin_get_all') {
    $stmt = $db->query("SELECT * FROM licenses ORDER BY id DESC");
    $response['licenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtLogs = $db->query("SELECT * FROM logs ORDER BY id DESC LIMIT 50");
    $response['logs'] = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
} 

// 2. YENİ LİSANS ÜRETME (NEON-XXXX-XXXX-XXXX formatında)
elseif ($action === 'admin_generate_keys') {
    $count = intval($input['count'] ?? 1);
    $days = floatval($input['days'] ?? 30);
    $expiryText = intval($days) . " Gün";

    for ($i = 0; $i < $count; $i++) {
        $key = "NEON-" . rand(1000, 9999) . "-" . rand(1000, 9999) . "-" . rand(1000, 9999);
        try {
            $stmt = $db->prepare("INSERT INTO licenses (license_key, expiry_date) VALUES (?, ?)");
            $stmt->execute([$key, $expiryText]);
        } catch (Exception $e) {
            // Aynı key üretilirse tekrar dene
            $i--; 
        }
    }
    logAction($db, $clientIp, 'generate_keys', 'success', $versionWarning);
    $response['message'] = "Lisanslar başarıyla oluşturuldu.";
} 

// 3. HWID SIFIRLAMA
elseif ($action === 'admin_reset_hwid') {
    $id = intval($input['id'] ?? 0);
    $stmt = $db->prepare("UPDATE licenses SET hwid = '' WHERE id = ?");
    $stmt->execute([$id]);
    
    logAction($db, $clientIp, 'reset_hwid', 'success', $versionWarning);
    $response['message'] = "HWID sıfırlandı.";
} 

// 4. LİSANS SİLME
elseif ($action === 'admin_delete_license') {
    $id = intval($input['id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM licenses WHERE id = ?");
    $stmt->execute([$id]);

    logAction($db, $clientIp, 'delete_license', 'success', $versionWarning);
    $response['message'] = "Lisans silindi.";
} 

// 5. UPDATE GETİR (EXE / CPP DOSYA YÜKLEME)
elseif ($action === 'admin_upload_update') {
    $fileName = basename($input['filename'] ?? 'update.bin');
    $version = $input['version'] ?? 'v1.0.0';
    $fileData = $input['file_content'] ?? '';

    if (!empty($fileData)) {
        // Base64 formatındaki dosyayı çözüp sunucuya kaydediyoruz
        list($type, $fileData) = explode(';', $fileData);
        list(, $fileData)      = explode(',', $fileData);
        $decodedData = base64_decode($fileData);

        $filePath = UPLOAD_DIR . $fileName;
        file_put_contents($filePath, $decodedData);

        logAction($db, $clientIp, 'upload_update', 'success', $versionWarning);
        $response['message'] = "Güncelleme dosyası başarıyla kaydedildi: $fileName ($version)";
    } else {
        $response['status'] = "error";
        $response['message'] = "Dosya içeriği boş!";
    }
} 

// 6. İSTEMCİ/C++ TARAFINDAN LİSANS DOĞRULAMA VE HWID KİLİTLEME
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
        // HWID Kontrolü ve Kilitleme
        if (empty($license['hwid'])) {
            // İlk defa kullanılıyorsa bu HWID'ye kilitle
            $updateHwid = $db->prepare("UPDATE licenses SET hwid = ? WHERE id = ?");
            $updateHwid->execute([$hwid, $license['id']]);
            $response['message'] = "Lisans doğrulandı ve HWID sabitlendi.";
            logAction($db, $clientIp, 'validate_bind', 'success', $versionWarning);
        } elseif ($license['hwid'] === $hwid) {
            // HWID eşleşiyorsa geçiş izni ver
            $response['message'] = "Lisans aktif ve doğrulandı.";
            logAction($db, $clientIp, 'validate', 'success', $versionWarning);
        } else {
            // Başka bir bilgisayara aitse reddet
            $response['status'] = "error";
            $response['message'] = "HWID Uyuşmazlığı! Bu anahtar başka bir cihaza kilitlenmiş.";
            logAction($db, $clientIp, 'validate_hwid_mismatch', 'failed', $versionWarning);
        }
    }
} else {
    $response['status'] = "error";
    $response['message'] = "Geçersiz eylem!";
}

echo json_encode($response);
exit;
?>
