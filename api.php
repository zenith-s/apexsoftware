<?php
header("Content-Type: application/json; charset=utf-8");

// Veritabanı bağlantı ayarları (Render veya kendi sunucun)
$host = "localhost";
$db   = "neon_db";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
} catch (\PDOException $e) {
    // Veritabanı yoksa JSON tabanlı veya dosya tabanlı loglama simülasyonu
}

$action = $_GET['action'] ?? '';

// İstekleri listeleme (HTML paneli için)
if ($action === 'get_logs') {
    // Veritabanından logları ve aktif cihazları JSON olarak döndür
    // Örnek yapı:
    echo json_encode([
        "status" => "success",
        "logs" => [
            ["time" => "19:08:12", "type" => "SUCCESS", "text" => "IP: 192.168.1.15 - Key: NEON-VIP-2026 - HWID: NEON-9A8B7C onaylandı."],
            ["time" => "19:05:40", "type" => "INVALID_KEY", "text" => "IP: 88.230.xx.xx - Hatalı key denemesi: 'YANLISKEY'"],
            ["time" => "19:01:10", "type" => "BANNED", "text" => "IP: 46.196.xx.xx - Banlı HWID sisteme girmeye çalıştı!"]
        ]
    ]);
    exit;
}

// C++ Loader'dan gelen doğrulama isteği
if ($action === 'verify') {
    $key    = $_GET['key'] ?? '';
    $hwid   = $_GET['hwid'] ?? '';
    $pcname = $_GET['pc_name'] ?? 'Bilinmeyen PC';
    $ip     = $_SERVER['REMOTE_ADDR'];

    if (empty($key)) {
        loglariKaydet($ip, $pcname, $hwid, $key, "INVALID_KEY", "Boş key denemesi");
        echo "INVALID_KEY";
        exit;
    }

    // Ban kontrolü
    if ($key === "BANLI-KEY" || $hwid === "BANLI-HWID") {
        loglariKaydet($ip, $pcname, $hwid, $key, "BANNED", "Banlı kullanıcı giriş denemesi");
        echo "BANNED";
        exit;
    }

    // Geçersiz key kontrolü
    if ($key !== "NEON-VIP-2026") {
        loglariKaydet($ip, $pcname, $hwid, $key, "INVALID_KEY", "Geçersiz key denemesi: " . $key);
        echo "INVALID_KEY";
        exit;
    }

    // Başarılı giriş
    loglariKaydet($ip, $pcname, $hwid, $key, "SUCCESS", "Başarılı giriş yapıldı.");
    echo "SUCCESS";
    exit;
}

function loglariKaydet($ip, $pcname, $hwid, $key, $kategori, $detay) {
    // Gelen istekleri veritabanına kategori (SUCCESS, INVALID_KEY, HWID_MISMATCH, BANNED) olarak kaydeder.
}
?>
