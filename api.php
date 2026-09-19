<?php
header("Content-Type: application/json; charset=utf-8");

$logFile = 'sys_logs.json'; // Logları tutacağımız dosya tabanlı veritabanı simülasyonu (MySQL de kullanabilirsin)

$action = $_GET['action'] ?? '';

// 1. Arayüz için logları ve aktif cihazları JSON olarak döndür
if ($action === 'get_data') {
    if (file_exists($logFile)) {
        $data = json_decode(file_get_contents($logFile), true);
    } else {
        $data = ["logs" => [], "devices" => []];
    }
    echo json_encode($data);
    exit;
}

// 2. Loader'dan gelen doğrulama ve HWID / Key istekleri
if ($action === 'verify') {
    $key    = $_GET['key'] ?? '';
    $hwid   = $_GET['hwid'] ?? '';
    $pcname = $_GET['pc_name'] ?? 'Bilinmeyen PC';
    $ip     = $_SERVER['REMOTE_ADDR'];
    $time   = date('H:i:s');

    $kategori = "SUCCESS";
    $mesaj = "";

    if (empty($key)) {
        $kategori = "INVALID_KEY";
        $mesaj = "Boş key denemesi yapıldı.";
        echo "EMPTY_KEY";
    } elseif ($key === "BANLI-KEY" || $hwid === "BANLI-HWID") {
        $kategori = "BANNED";
        $mesaj = "Banlı HWID veya Key ile erişim engellendi!";
        echo "BANNED";
    } elseif ($key !== "NEON-VIP-2026") {
        $kategori = "INVALID_KEY";
        $mesaj = "Geçersiz key girildi: " . $key;
        echo "INVALID_KEY";
    } else {
        $kategori = "SUCCESS";
        $mesaj = "Key ve HWID başarıyla onaylandı.";
        echo "SUCCESS";
    }

    // Logu kaydet
    logEkle($time, $ip, $pcname, $hwid, $key, $kategori, $mesaj);
    exit;
}

function logEkle($time, $ip, $pcname, $hwid, $key, $kategori, $mesaj) {
    global $logFile;
    $currentData = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : ["logs" => [], "devices" => []];
    
    // Yeni logu en başa ekle (En fazla 700 satır/kayıt tutulsun)
    array_unshift($currentData["logs"], [
        "time" => $time,
        "ip" => $ip,
        "pc" => $pcname,
        "hwid" => $hwid,
        "key" => $key,
        "type" => $kategori,
        "text" => $mesaj
    ]);

    if (count($currentData["logs"]) > 700) {
        array_pop($currentData["logs"]); // 700'den fazlasını uçur
    }

    file_put_contents($logFile, json_encode($currentData, JSON_UNESCAPED_UNICODE));
}
?>
