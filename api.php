<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

define("DB_FILE", "neon_database.db");
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
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Veritabani hatasi"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Gecersiz veri paketi"]);
    exit;
}

$action = $input['action'] ?? '';

if ($action === 'auth') {
    $clientTime = intval($input['time'] ?? 0);
    $clientSignature = $input['signature'] ?? '';
    $key = trim($input['key'] ?? '');
    $hwid = trim($input['hwid'] ?? '');

    // Zaman aşımı kontrolü (5 dakika tolerans)
    if (abs(time() - $clientTime) > 300) {
        echo json_encode(["status" => "error", "message" => "Zaman damgasi hatasi"]);
        exit;
    }

    // HMAC İmza Doğrulama
    $expectedSig = hash_hmac('sha256', $action . $clientTime, API_SECRET_KEY);
    if (!hash_equals($expectedSig, $clientSignature)) {
        echo json_encode(["status" => "error", "message" => "Imza dogrulanamadi"]);
        exit;
    }

    if (empty($key) || empty($hwid)) {
        echo json_encode(["status" => "error", "message" => "Eksik parametre"]);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM licenses WHERE license_key = ?");
    $stmt->execute([$key]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        echo json_encode(["status" => "error", "message" => "Gecersiz Lisans Anahtari"]);
        exit;
    }

    if (strtotime($license['expiry_date']) < time()) {
        echo json_encode(["status" => "error", "message" => "Lisans Suresi Dolmus"]);
        exit;
    }

    if (empty($license['hwid'])) {
        $update = $db->prepare("UPDATE licenses SET hwid = ? WHERE id = ?");
        $update->execute([$hwid, $license['id']]);
        echo json_encode(["status" => "success", "message" => "Basariyla etkinlestirildi! Bitis: " . $license['expiry_date']]);
        exit;
    } elseif ($license['hwid'] === $hwid) {
        echo json_encode(["status" => "success", "message" => "Giris basarili! Bitis: " . $license['expiry_date']]);
        exit;
    } else {
        echo json_encode(["status" => "error", "message" => "HWID Uyusmazligi! Baska cihaza kayitli."]);
        exit;
    }
}

echo json_encode(["status" => "error", "message" => "Bilinmeyen islem"]);
?>
