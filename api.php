<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/plain; charset=utf-8");

$action   = $_GET['action'] ?? '';
$key      = trim($_GET['key'] ?? '');
$expiry   = trim($_GET['expiry'] ?? '30d');
$hwid     = trim($_GET['hwid'] ?? '');
$pc_name  = trim($_GET['pc_name'] ?? 'Bilinmeyen PC');
$ip       = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$keysFile = 'keys.txt';
$logsFile = 'logs.txt';

if (!file_exists($keysFile)) file_put_contents($keysFile, "NEON-PRO-1234|30d|||\n");
if (!file_exists($logsFile)) file_put_contents($logsFile, "");

// İstekleri loglayan fonksiyon
function kayitLogEkle($ip, $key, $hwid, $pc_name, $status) {
    global $logsFile;
    $zaman = date('Y-m-d H:i:s');
    $temizKey = empty($key) ? "BOŞ_KEY" : $key;
    $temizHwid = empty($hwid) ? "HWID_YOK" : $hwid;
    $logSatiri = "$zaman | IP: $ip | PC: $pc_name | KEY: $temizKey | HWID: $temizHwid | DURUM: $status\n";
    file_put_contents($logsFile, $logSatiri, FILE_APPEND);
}

// 1. Yeni Key Oluşturma
if ($action === 'create') {
    if (empty($key)) { echo "ERROR_EMPTY"; exit; }
    $lines = file($keysFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $p = explode('|', $line);
        if (trim($p[0]) === $key) { echo "KEY_EXISTS"; exit; }
    }
    file_put_contents($keysFile, "$key|$expiry|||\n", FILE_APPEND);
    echo "SUCCESS";
    exit;
}

// 2. Key Silme
else if ($action === 'delete') {
    if (empty($key)) { echo "ERROR_EMPTY"; exit; }
    $lines = file($keysFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $yeniSatirlar = [];
    foreach ($lines as $line) {
        $p = explode('|', $line);
        if (trim($p[0]) !== $key) $yeniSatirlar[] = $line;
    }
    file_put_contents($keysFile, implode("\n", $yeniSatirlar) . (!empty($yeniSatirlar) ? "\n" : ""));
    echo "SUCCESS";
    exit;
}

// 3. Aktif Keyleri Listeleme
else if ($action === 'list') {
    echo file_get_contents($keysFile);
    exit;
}

// 4. Tüm Canlı İstek ve Crack Loglarını Listeleme
else if ($action === 'logs') {
    echo file_get_contents($logsFile);
    exit;
}

// 5. C++ Loader Doğrulama ve Sabit HWID Kilitleme
else if ($action === 'verify') {
    if (empty($key)) {
        kayitLogEkle($ip, "", $hwid, $pc_name, "GECERSIZ_BOS_KEY");
        echo "INVALID_KEY";
        exit;
    }

    $lines = file($keysFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $bulundu = false;
    $eslesenSure = "";
    $guncellenmisSatirlar = [];

    foreach ($lines as $line) {
        $p = explode('|', $line);
        $kayitliKey   = trim($p[0] ?? '');
        $kayitliSure  = trim($p[1] ?? '30d');
        $kayitliHwid  = trim($p[2] ?? '');
        $kayitliPc    = trim($p[3] ?? '');

        if ($kayitliKey === $key) {
            $bulundu = true;
            if (empty($kayitliHwid)) {
                // İlk defa kullanılıyor: Sabit HWID ve PC adını ömür boyu kilitle
                $kayitliHwid = $hwid;
                $kayitliPc = $pc_name;
                $eslesenSure = $kayitliSure;
                kayitLogEkle($ip, $key, $hwid, $pc_name, "ILK_GIRIS_HWID_KILITLENDI");
            } else if ($kayitliHwid !== $hwid) {
                // Farklı PC'den deneniyor: Crack veya HWID Değiştirme Girişimi!
                kayitLogEkle($ip, $key, $hwid, $pc_name, "CRACK_GIRISIMI_HWID_UYUSMAZLIGI (Kayitli HWID: $kayitliHwid)");
                echo "HWID_MISMATCH";
                exit;
            } else {
                $eslesenSure = $kayitliSure;
                kayitLogEkle($ip, $key, $hwid, $pc_name, "BASARILI_GIRIS");
            }
            $guncellenmisSatirlar[] = "$kayitliKey|$kayitliSure|$kayitliHwid|$kayitliPc";
        } else {
            $guncellenmisSatirlar[] = $line;
        }
    }

    if (!$bulundu) {
        kayitLogEkle($ip, $key, $hwid, $pc_name, "GECERSIZ_KEY_DENEMESI");
        echo "INVALID_KEY";
        exit;
    }

    file_put_contents($keysFile, implode("\n", $guncellenmisSatirlar) . "\n");
    echo "SUCCESS|" . $eslesenSure;
    exit;
}

// 6. Render Ping
else if ($action === 'ping') {
    echo "OK";
    exit;
}

echo "INVALID_ACTION";
?>
