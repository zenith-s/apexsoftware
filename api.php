<?php
header("Content-Type: application/json; charset=utf-8");

$dataFile = 'sys_database.json';

function verileriOku() {
    global $dataFile;
    if (!file_exists($dataFile)) {
        return ["keys" => [], "logs" => [], "devices" => []];
    }
    return json_decode(file_get_contents($dataFile), true) ?: ["keys" => [], "logs" => [], "devices" => []];
}

function verileriKaydet($data) {
    global $dataFile;
    file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// 1. Arayüz için tüm verileri getirme
if ($action === 'get_data') {
    $db = verileriOku();
    echo json_encode([
        "status" => "success",
        "total_keys" => count($db["keys"]),
        "keys" => $db["keys"],
        "logs" => array_slice($db["logs"], 0, 700), // Max 700 satır
        "devices" => $db["devices"]
    ]);
    exit;
}

// 2. YENİ KEY ÜRETME İŞLEMİ
if ($action === 'generate_key') {
    $db = verileriOku();
    
    $duration = $_POST['duration'] ?? '30'; // Gün
    $count    = max(1, min(50, intval($_POST['count'] ?? 1))); // 1-50 arası
    $note     = $_POST['note'] ?? 'Özel Lisans';
    
    $generatedKeys = [];

    for ($i = 0; $i < $count; $i++) {
        $randomCode = "NEON-" . strtoupper(bin2hex(random_bytes(2))) . "-" . strtoupper(bin2hex(random_bytes(2))) . "-" . strtoupper(bin2hex(random_bytes(2)));
        
        $newKey = [
            "key" => $randomCode,
            "note" => $note,
            "duration" => $duration == "LIFETIME" ? "Sınırsız" : $duration . " Gün",
            "hwid" => "KİLİTLENMEDİ",
            "status" => "AKTİF",
            "created_at" => date('Y-m-d H:i:s')
        ];

        array_unshift($db["keys"], $newKey);
        $generatedKeys[] = $randomCode;
    }

    // Log ekle
    array_unshift($db["logs"], [
        "time" => date('H:i:s'),
        "ip" => $_SERVER['REMOTE_ADDR'],
        "pc" => "ADMIN-PANEL",
        "hwid" => "-",
        "key" => implode(", ", $generatedKeys),
        "type" => "SUCCESS",
        "text" => "$count adet yeni key oluşturuldu ($note)."
    ]);

    verileriKaydet($db);
    echo json_encode(["status" => "success", "generated_keys" => $generatedKeys]);
    exit;
}

// 3. KEY HWID SIFIRLAMA
if ($action === 'reset_hwid') {
    $db = verileriOku();
    $targetKey = $_POST['key'] ?? '';

    foreach ($db["keys"] as &$k) {
        if ($k['key'] === $targetKey) {
            $k['hwid'] = "KİLİTLENMEDİ";
            break;
        }
    }

    array_unshift($db["logs"], [
        "time" => date('H:i:s'),
        "ip" => $_SERVER['REMOTE_ADDR'],
        "pc" => "ADMIN-PANEL",
        "hwid" => "-",
        "key" => $targetKey,
        "type" => "SUCCESS",
        "text" => "HWID kilidi yönetici tarafından sıfırlandı."
    ]);

    verileriKaydet($db);
    echo json_encode(["status" => "success"]);
    exit;
}

// 4. KEY SİLME
if ($action === 'delete_key') {
    $db = verileriOku();
    $targetKey = $_POST['key'] ?? '';

    $db["keys"] = array_values(array_filter($db["keys"], function($k) use ($targetKey) {
        return $k['key'] !== $targetKey;
    }));

    verileriKaydet($db);
    echo json_encode(["status" => "success"]);
    exit;
}
?>
