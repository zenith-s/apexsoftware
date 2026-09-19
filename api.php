<?php
header("Content-Type: text/plain; charset=utf-8");

// Veritabanı bağlantısı (Render / SQLite veya MySQL ayarına göre güncelleyebilirsin)
// Örnek PDO bağlantısı:
$host = "localhost";
$db   = "neon_db";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
} catch (\PDOException $e) {
    // Veritabanı yoksa veya dosya tabanlı simülasyon yapıyorsan burayı ayarlayabilirsin
}

$action = $_GET['action'] ?? '';

if ($action === 'verify') {
    $key    = $_GET['key'] ?? '';
    $hwid   = $_GET['hwid'] ?? '';
    $pcname = $_GET['pc_name'] ?? 'Bilinmeyen PC';
    $ip     = $_SERVER['REMOTE_ADDR'];

    // 1. Key veritabanında var mı ve banlı mı kontrol et
    // (Aşağıdaki mantığı kendi veritabanı yapına göre bağlayabilirsin)
    
    // Örnek simülasyon yanıtları:
    if (empty($key)) {
        loglariKaydet($ip, $pcname, $hwid, $key, "INVALID_KEY", "Boş key denemesi");
        echo "INVALID_KEY";
        exit;
    }

    // Örnek kontrol: Eğer key "BANLI" tablosundaysa
    if ($key === "BANLI-KEY-ORNEGI") {
        loglariKaydet($ip, $pcname, $hwid, $key, "BANNED", "Banlı key ile giriş denemesi");
        echo "BANNED";
        exit;
    }

    if ($key !== "NEON-VIP-2026") { // Kendi key kontrol mekanizman
        loglariKaydet($ip, $pcname, $hwid, $key, "INVALID_KEY", "Geçersiz key girildi");
        echo "INVALID_KEY";
        exit;
    }

    // Başarılı giriş ve Aktif Cihaz olarak kaydetme (Loader Açık durumu)
    loglariKaydet($ip, $pcname, $hwid, $key, "SUCCESS", "Başarılı giriş yapıldı");
    echo "SUCCESS";
    exit;
}

if ($action === 'heartbeat') {
    // Loader açık olduğu sürece saniyede bir sinyal göndererek aktif olduğunu bildirir
    $hwid = $_GET['hwid'] ?? '';
    // Aktif cihazlar tablosunda son görülme zamanını güncelle
    echo "OK";
    exit;
}

function loglariKaydet($ip, $pcname, $hwid, $key, $kategori, $detay) {
    global $pdo;
    // İstekleri kategorilerine göre log tablosuna işleme
    // Kategoriler: SUCCESS, INVALID_KEY, HWID_MISMATCH, BANNED, EXPIRED
    $tarih = date('Y-m-d H:i:s');
    // Veritabanı kayıt sorgusu buraya eklenecek
}
?>
