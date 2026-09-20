<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

$dataFile = 'keys.json';
$logFile  = 'logs.json';
$updateFile = 'update.exe';
$versionFile = 'version.json';

// İlk kurulum - keys.json
if (!file_exists($dataFile)) {
    $defaultKeys = [
        "NEONBEST" => ["expiry_time" => 0, "duration_text" => "Sınırsız", "hwid" => ""],
        "NEON-TEST" => ["expiry_time" => time() + 60, "duration_text" => "60 Saniye", "hwid" => ""]
    ];
    file_put_contents($dataFile, json_encode($defaultKeys, JSON_PRETTY_PRINT));
}

// İlk kurulum - logs.json
if (!file_exists($logFile)) {
    file_put_contents($logFile, json_encode([], JSON_PRETTY_PRINT));
}

// İlk kurulum - version.json
if (!file_exists($versionFile)) {
    file_put_contents($versionFile, json_encode(["version" => 1, "filename" => "update.exe"], JSON_PRETTY_PRINT));
}

$keys = json_decode(file_get_contents($dataFile), true);
$logs = json_decode(file_get_contents($logFile), true);

$action    = $_GET['action'] ?? $_POST['action'] ?? '';
$key       = $_GET['key'] ?? $_POST['key'] ?? '';
$hwid      = $_GET['hwid'] ?? $_POST['hwid'] ?? '';
$admin_key = $_GET['admin_key'] ?? $_POST['admin_key'] ?? '';
$userIp    = $_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor';

function addLog($logFile, $logs, $action, $key, $status, $ip) {
    $newLog = [
        "time"   => date('Y-m-d H:i:s'),
        "ip"     => $ip,
        "action" => $action,
        "key"    => $key ? $key : "Yok",
        "status" => $status
    ];
    array_unshift($logs, $newLog);
    if (count($logs) > 150) array_pop($logs);
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));
}

// Saniye hesaplama yardımcı fonksiyonu
function calculateExpiryTimestamp($durationStr) {
    if ($durationStr === 'Sınırsız') return 0;
    
    preg_match('/^(\d+)\s*(Saniye|Dakika|Saat|Gün)$/ui', trim($durationStr), $matches);
    if (!$matches) return time() + 86400; // Varsayılan 1 gün
    
    $val = (int)$matches[1];
    $unit = mb_strtolower($matches[2]);
    
    if (strpos($unit, 'saniye') !== false) return time() + $val;
    if (strpos($unit, 'dakika') !== false) return time() + ($val * 60);
    if (strpos($unit, 'saat') !== false) return time() + ($val * 3600);
    if (strpos($unit, 'gün') !== false) return time() + ($val * 86400);
    
    return time() + 86400;
}

// 1. PING
if ($action === 'ping') {
    echo "PONG";
    exit;
}

// 2. VERSİYON & UPDATE KONTROLÜ (Loader için)
if ($action === 'check_update') {
    $verData = json_decode(file_get_contents($versionFile), true);
    echo json_encode([
        "status" => "success",
        "version" => $verData['version'],
        "download_url" => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/api.php?action=download_exe"
    ]);
    exit;
}

if ($action === 'download_exe') {
    if (file_exists($updateFile)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="update.exe"');
        header('Content-Length: ' . filesize($updateFile));
        readfile($updateFile);
    } else {
        http_response_code(404);
        echo "Güncelleme dosyası bulunamadı!";
    }
    exit;
}

// 3. VERIFY (Loader Doğrulama)
if ($action === 'verify') {
    header("Content-Type: text/plain");
    
    if (empty($key)) {
        addLog($logFile, $logs, 'VERIFY', $key, 'FAIL: Key boş', $userIp);
        echo json_encode(["status" => "invalid", "message" => "Key boş olamaz!"]);
        exit;
    }

    if ($key === "NEONBEST31") {
        addLog($logFile, $logs, 'VERIFY (ADMIN)', $key, 'SUCCESS', $userIp);
        echo "SUCCESS|Sınırsız (Admin)";
        exit;
    }

    if (array_key_exists($key, $keys)) {
        $kData = $keys[$key];
        
        // Süre Kontrolü
        if ($kData['expiry_time'] != 0 && time() > $kData['expiry_time']) {
            addLog($logFile, $logs, 'VERIFY', $key, 'FAIL: Süresi Bitmiş', $userIp);
            echo json_encode(["status" => "invalid", "message" => "Bu key'in süresi dolmuş!"]);
            exit;
        }

        if (empty($kData['hwid'])) {
            $keys[$key]['hwid'] = $hwid;
            file_put_contents($dataFile, json_encode($keys, JSON_PRETTY_PRINT));
        }

        if ($keys[$key]['hwid'] === $hwid) {
            addLog($logFile, $logs, 'VERIFY', $key, 'SUCCESS', $userIp);
            $kalan = ($kData['expiry_time'] == 0) ? "Sınırsız" : max(0, $kData['expiry_time'] - time()) . " Saniye kaldı";
            echo "SUCCESS|" . $kalan;
        } else {
            addLog($logFile, $logs, 'VERIFY', $key, 'FAIL: HWID Uyuşmazlığı', $userIp);
            echo json_encode(["status" => "invalid", "message" => "Bu key başka bir cihaza kilitlenmiş!"]);
        }
    } else {
        addLog($logFile, $logs, 'VERIFY', $key, 'FAIL: Geçersiz Key', $userIp);
        echo json_encode(["status" => "invalid", "message" => "Geçersiz key!"]);
    }
    exit;
}

// 4. ADMIN PANEL İŞLEMLERİ
if ($admin_key === "NEONBEST31") {
    if ($action === 'get_data') {
        // Süresi bitenleri otomatik temizle veya işaretle bilgisi sun
        echo json_encode([
            "status" => "success",
            "keys" => $keys,
            "logs" => $logs,
            "server_time" => time()
        ]);
        exit;
    }
    
    if ($action === 'reset_hwid') {
        $target_key = $_GET['target_key'] ?? '';
        if (isset($keys[$target_key])) {
            $keys[$target_key]['hwid'] = "";
            file_put_contents($dataFile, json_encode($keys, JSON_PRETTY_PRINT));
            addLog($logFile, $logs, 'ADMIN', $target_key, 'HWID Sıfırlandı', $userIp);
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
            $expiryTime = calculateExpiryTimestamp($duration);
            $keys[$new_key] = [
                "expiry_time" => $expiryTime,
                "duration_text" => $duration,
                "hwid" => ""
            ];
            file_put_contents($dataFile, json_encode($keys, JSON_PRETTY_PRINT));
            addLog($logFile, $logs, 'ADMIN', $new_key, "Yeni Key Eklendi ($duration)", $userIp);
            echo json_encode(["status" => "success", "message" => "Yeni key başarıyla eklendi!"]);
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
            addLog($logFile, $logs, 'ADMIN', $target_key, 'Key Silindi', $userIp);
            echo json_encode(["status" => "success", "message" => "Key silindi!"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Key bulunamadı!"]);
        }
        exit;
    }

    if ($action === 'upload_exe') {
        if (isset($_FILES['exe_file'])) {
            if (move_uploaded_file($_FILES['exe_file']['tmp_name'], $updateFile)) {
                $verData = json_decode(file_get_contents($versionFile), true);
                $verData['version'] += 1;
                file_put_contents($versionFile, json_encode($verData, JSON_PRETTY_PRINT));
                addLog($logFile, $logs, 'ADMIN', 'SYSTEM', 'Yeni .EXE Güncellendi v' . $verData['version'], $userIp);
                echo json_encode(["status" => "success", "message" => "Yeni .exe dosyası başarıyla yüklendi! Sürüm v" . $verData['version']]);
            } else {
                echo json_encode(["status" => "error", "message" => "Dosya yüklenirken hata oluştu!"]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "Dosya seçilmedi!"]);
        }
        exit;
    }
} else if (!empty($admin_key)) {
    echo json_encode(["status" => "error", "message" => "Geçersiz Admin Anahtarı!"]);
    exit;
}

echo json_encode(["status" => "error", "message" => "Geçersiz istek."]);
?>
