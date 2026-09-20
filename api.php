<?php
header('Content-Type: application/json');
$dbFile = 'keys.json';

// Varsayılan veritabanı ve NEONBEST anahtarı
if (!file_exists($dbFile)) {
    $initial = [
        ['key' => 'NEONBEST', 'duration' => 3600, 'duration_text' => '1 Saat', 'hwid' => null, 'first_used' => null]
    ];
    file_put_contents($dbFile, json_encode($initial, JSON_PRETTY_PRINT));
}

$data = json_decode(file_get_contents($dbFile), true);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// C++ Loader'dan gelen kontrol isteği
if ($action === 'verify') {
    $inputKey = $_POST['key'] ?? '';
    $inputHwid = $_POST['hwid'] ?? '';
    
    foreach ($data as &$item) {
        if ($item['key'] === $inputKey) {
            // Süre başladıysa bitiş kontrolü yap
            if ($item['first_used'] !== null) {
                $elapsed = time() - $item['first_used'];
                if ($elapsed > $item['duration']) {
                    echo json_encode(['status' => 'expired', 'message' => 'Keyinizin süresi bitti!']);
                    exit;
                }
            }

            // HWID Eşleştirme
            if ($item['hwid'] === null) {
                $item['hwid'] = $inputHwid;
                $item['first_used'] = time(); // İlk kullanımda süre başlar
                file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT));
            } else if ($item['hwid'] !== $inputHwid) {
                echo json_encode(['status' => 'hwid_mismatch', 'message' => 'Bu key başka bir cihaza bağlı!']);
                exit;
            }

            $remaining = $item['first_used'] ? ($item['duration'] - (time() - $item['first_used'])) : $item['duration'];
            echo json_encode(['status' => 'success', 'remaining' => $remaining]);
            exit;
        }
    }
    echo json_encode(['status' => 'invalid', 'message' => 'Geçersiz key!']);
    exit;
}

// Admin Paneli için Verileri Listeleme
if ($action === 'get_data') {
    echo json_encode($data);
    exit;
}

// Admin Paneli için Yeni Key Üretme
if ($action === 'create') {
    $durationSec = intval($_POST['duration_sec'] ?? 60);
    $durationText = $_POST['duration_text'] ?? '1 Dakika';
    $newKey = 'NEON-' . strtoupper(substr(md5(mt_rand()), 0, 8));
    
    $data[] = [
        'key' => $newKey,
        'duration' => $durationSec,
        'duration_text' => $durationText,
        'hwid' => null,
        'first_used' => null
    ];
    file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success', 'key' => $newKey]);
    exit;
}

// HWID Sıfırlama
if ($action === 'reset_hwid') {
    $targetKey = $_POST['key'] ?? '';
    foreach ($data as &$item) {
        if ($item['key'] === $targetKey) {
            $item['hwid'] = null;
            $item['first_used'] = null;
            file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT));
            echo json_encode(['status' => 'success']);
            exit;
        }
    }
    echo json_encode(['status' => 'error']);
    exit;
}
?>
