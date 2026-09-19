<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/plain; charset=utf-8");

$action = $_GET['action'] ?? '';
$key = trim($_GET['key'] ?? '');
$expiry = trim($_GET['expiry'] ?? '30d 0h');
$hwid = trim($_GET['hwid'] ?? '');

$file = 'keys.txt';

// keys.txt yoksa otomatik oluştur
if (!file_exists($file)) {
    file_put_contents($file, "NEON-TEST-RANDOM|30d|\n");
}

// 1. HTML Admin Panelden Yeni Key Üretme İstegi
if ($action === 'create') {
    if (empty($key)) {
        echo "ERROR_EMPTY_KEY";
        exit;
    }
    
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = explode('|', $line);
        if (trim($parts[0]) === $key) {
            echo "KEY_ALREADY_EXISTS";
            exit;
        }
    }

    // Format: KEY|SÜRE|HWID (HWID boş bırakılır, ilk giren cihaza kilitlenir)
    file_put_contents($file, "$key|$expiry|\n", FILE_APPEND);
    echo "SUCCESS_CREATED";
    exit;
} 
// 2. C++ Loader'dan Key Doğrulama ve HWID Kilitleme İstegi
else if ($action === 'verify') {
    if (empty($key)) {
        echo "INVALID_KEY";
        exit;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $found = false;
    $matchedExpiry = "";
    $updatedLines = [];

    foreach ($lines as $line) {
        $parts = explode('|', $line);
        $storedKey = trim($parts[0] ?? '');
        $storedExpiry = trim($parts[1] ?? '30d 0h');
        $storedHwid = trim($parts[2] ?? '');

        if ($storedKey === $key) {
            $found = true;
            if (empty($storedHwid)) {
                $storedHwid = $hwid; // İlk girişte cihaza kilitlenir
            } else if ($storedHwid !== $hwid) {
                echo "HWID_MISMATCH";
                exit;
            }
            $matchedExpiry = $storedExpiry;
            $updatedLines[] = "$storedKey|$storedExpiry|$storedHwid";
        } else {
            $updatedLines[] = $line;
        }
    }

    if (!$found) {
        echo "INVALID_KEY";
        exit;
    }

    file_put_contents($file, implode("\n", $updatedLines) . "\n");
    echo "SUCCESS|" . $matchedExpiry;
    exit;
} 
// 3. Admin Panel İçin Aktif Keyleri Listeleme
else if ($action === 'list') {
    echo file_get_contents($file);
    exit;
}
// 4. Render Sunucusunu Uykudan Uyandırma (Ping) İstemi
else if ($action === 'ping') {
    echo "SERVER_AWAKE";
    exit;
}

echo "INVALID_ACTION";
?>
