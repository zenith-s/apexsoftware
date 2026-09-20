<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

$dataFile = 'keys.json';

// İlk çalışmada örnek keyleri oluşturan dosya yapısı
if (!file_exists($dataFile)) {
    $defaultKeys = [
        "NEONBEST"      => ["expiry" => "1 Saat", "hwid" => ""],
        "NEON-2925D974" => ["expiry" => "1 Dakika", "hwid" => ""]
    ];
    file_put_contents($dataFile, json_encode($defaultKeys, JSON_PRETTY_PRINT));
}

$keys = json_decode(file_get_contents($dataFile), true);

$action    = $_GET['action'] ?? '';
$key       = $_GET['key'] ?? '';
$hwid      = $_GET['hwid'] ?? '';
$pcname    = $_GET['pc_name'] ?? '';
$admin_key = $_GET['admin_key'] ?? $_POST['admin_key'] ?? '';

// 1. Ping İşlemi (Sunucuyu uyandırmak için)
if ($action === 'ping') {
    header("Content-Type: text/plain");
    echo "PONG";
    exit;
}

// 2. Loader'dan Gelen Doğrulama (Verify) İstekleri
if ($action === 'verify') {
    header("Content-Type: text/plain");
    if (empty($key)) {
        echo json_encode(["status" => "invalid", "message" => "Key boş olamaz!"]);
        exit;
    }

    // Özel Admin Key Kontrolü (Loader'da girilirse)
    if ($key === "NEONBEST31") {
        echo "SUCCESS|Sınırsız (Admin)";
        exit;
    }

    if (array_key_exists($key, $keys)) {
        // HWID boşsa cihaza kilitle
        if (empty($keys[$key]['hwid'])) {
            $keys[$key]['hwid'] = $hwid;
            file_put_contents($dataFile, json_encode($keys, JSON_PRETTY_PRINT));
        }

        // HWID eşleşmesi kontrolü
        if ($keys[$key]['hwid'] === $hwid) {
            echo "SUCCESS|" . $keys[$key]['expiry'];
        } else {
            echo json_encode(["status" => "invalid", "message" => "Bu key başka bir cihaza (HWID) kilitlenmiş!"]);
        }
    } else {
        echo json_encode(["status" => "invalid", "message" => "Geçersiz key!"]);
    }
    exit;
}

// 3. Admin Paneli İşlemleri (Sadece 'NEONBEST31' anahtarı ile çalışır)
if ($admin_key === "NEONBEST31") {
    if ($action === 'get_keys') {
        echo json_encode(["status" => "success", "keys" => $keys]);
        exit;
    }
    
    if ($action === 'reset_hwid') {
        $target_key = $_GET['target_key'] ?? '';
        if (isset($keys[$target_key])) {
            $keys[$target_key]['hwid'] = ""; // HWID kilidini kaldır (Boşta yap)
            file_put_contents($dataFile, json_encode($keys, JSON_PRETTY_PRINT));
            echo json_encode(["status" => "success", "message" => "HWID başarıyla sıfırlandı!"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Key bulunamadı!"]);
        }
        exit;
    }

    if ($action === 'add_key') {
        $new_key = $_GET['new_key'] ?? '';
        $duration = $_GET['duration'] ?? '1 Gün';
        if (!empty($new_key)) {
            $keys[$new_key] = ["expiry" => $duration, "hwid" => ""];
            file_put_contents($dataFile, json_encode($keys, JSON_PRETTY_PRINT));
            echo json_encode(["status" => "success", "message" => "Yeni key eklendi!"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Key adı boş olamaz!"]);
        }
        exit;
    }
} else if (!empty($admin_key)) {
    echo json_encode(["status" => "error", "message" => "Geçersiz Admin Anahtarı!"]);
    exit;
}

echo json_encode(["status" => "error", "message" => "Geçersiz istek."]);
?>
