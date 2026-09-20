<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

$dataFile = 'keys.json';
$logFile  = 'logs.json';

// İlk çalışmada örnek keyleri oluştur
if (!file_exists($dataFile)) {
    $defaultKeys = [
        "NEONBEST"      => ["expiry" => "1 Saat", "hwid" => ""],
        "NEON-2925D974" => ["expiry" => "1 Dakika", "hwid" => ""]
    ];
    file_put_contents($dataFile, json_encode($defaultKeys, JSON_PRETTY_PRINT));
}

// İlk çalışmada log dosyasını oluştur
if (!file_exists($logFile)) {
    file_put_contents($logFile, json_encode([], JSON_PRETTY_PRINT));
}

$keys = json_decode(file_get_contents($dataFile), true);
$logs = json_decode(file_get_contents($logFile), true);

$action    = $_GET['action'] ?? '';
$key       = $_GET['key'] ?? '';
$hwid      = $_GET['hwid'] ?? '';
$pcname    = $_GET['pc_name'] ?? '';
$admin_key = $_GET['admin_key'] ?? $_POST['admin_key'] ?? '';
$userIp    = $_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor';

// İstekleri loglama fonksiyonu
function addLog($logFile, $logs, $action, $key, $status, $ip) {
    $newLog = [
        "time"   => date('Y-m-d H:i:s'),
        "ip"     => $ip,
        "action" => $action,
        "key"    => $key ? $key : "Yok",
        "status" => $status
    ];
    
    // En fazla son 100 logu tut
    array_unshift($logs, $newLog);
    if (count($logs) > 100) {
        array_pop($logs);
    }
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));
}

// 1. Ping İşlemi
if ($action === 'ping') {
    addLog($logFile, $logs, 'PING', '', 'SUCCESS', $userIp);
    header("Content-Type: text/plain");
    echo "PONG";
    exit;
}

// 2. Loader Doğrulama (Verify) İstekleri
if ($action === 'verify') {
    header("Content-Type: text/plain");
    
    if (empty($key)) {
        addLog($logFile, $logs, 'VERIFY', $key, 'FAIL: Key boş', $userIp);
        echo json_encode(["status" => "invalid", "message" => "Key boş olamaz!"]);
        exit;
    }

    // Özel Admin Key Kontrolü
    if ($key === "NEONBEST31") {
        addLog($logFile, $logs, 'VERIFY (ADMIN)', $key, 'SUCCESS', $userIp);
        echo "SUCCESS|Sınırsız (Admin)";
        exit;
    }

    if (array_key_exists($key, $keys)) {
        if (empty($keys[$key]['hwid'])) {
            $keys[$key]['hwid'] = $hwid;
            file_put_contents($dataFile, json_encode($keys, JSON_PRETTY_PRINT));
        }

        if ($keys[$key]['hwid'] === $hwid) {
            addLog($logFile, $logs, 'VERIFY', $key, 'SUCCESS', $userIp);
            echo "SUCCESS|" . $keys[$key]['expiry'];
        } else {
            addLog($logFile, $logs, 'VERIFY', $key, 'FAIL: HWID Uyuşmazlığı', $userIp);
            echo json_encode(["status" => "invalid", "message" => "Bu key başka bir cihaza (HWID) kilitlenmiş!"]);
        }
    } else {
        addLog($logFile, $logs, 'VERIFY', $key, 'FAIL: Geçersiz Key', $userIp);
        echo json_encode(["status" => "invalid", "message" => "Geçersiz key!"]);
    }
    exit;
}

// 3. Admin Paneli İşlemleri (Yetki Kontrolü)
if ($admin_key === "NEONBEST31") {
    if ($action === 'get_data') {
        echo json_encode([
            "status" => "success",
            "keys"   => $keys,
            "logs"   => $logs
        ]);
        exit;
    }
    
    if ($action === 'reset_hwid') {
        $target_key = $_GET['target_key'] ?? '';
        if (isset($keys[$target_key])) {
            $keys[$target_key]['hwid'] = "";
            file_put_contents($dataFile, json_encode($keys, JSON_PRETTY_PRINT));
            addLog($logFile, $logs, 'ADMIN ACTION', $target_key, 'HWID Sıfırlandı', $userIp);
            echo json_encode(["status" => "success", "message" => "HWID başarıyla sıfırlandı!"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Key bulunamadı!"]);
        }
        exit;
    }

    if ($action === 'add_key') {
        $new_key  = $_GET['new_key'] ?? '';
        $duration = $_GET['duration'] ?? '30 Gün';
        if (!empty($new_key)) {
            $keys[$new_key] = ["expiry" => $duration, "hwid" => ""];
            file_put_contents($dataFile, json_encode($keys, JSON_PRETTY_PRINT));
            addLog($logFile, $logs, 'ADMIN ACTION', $new_key, 'Yeni Key Eklendi', $userIp);
            echo json_encode(["status" => "success", "message" => "Yeni key eklendi!"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Key adı boş olamaz!"]);
        }
        exit;
    }

    if ($action === 'delete_key') {
        $target_key = $_GET['target_key'] ?? '';
        if (isset($keys[$target_key])) {
            unset($keys[$target_key]);
            file_put_contents($dataFile, json_encode($keys, JSON_PRETTY_PRINT));
            addLog($logFile, $logs, 'ADMIN ACTION', $target_key, 'Key Silindi', $userIp);
            echo json_encode(["status" => "success", "message" => "Key silindi!"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Key bulunamadı!"]);
        }
        exit;
    }
} else if (!empty($admin_key)) {
    echo json_encode(["status" => "error", "message" => "Geçersiz Admin Anahtarı!"]);
    exit;
}

echo json_encode(["status" => "error", "message" => "Geçersiz istek."]);
?>
