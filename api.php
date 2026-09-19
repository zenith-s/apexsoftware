<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

$dataFile = "devices.json";

// Dosya yoksa oluştur
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode([]));
}

$data = json_decode(file_get_contents($dataFile), true);
$action = $_GET['action'] ?? '';

// C++ Tarafından gelen ping / durum güncelleme
if ($action == 'ping') {
    $pcName = $_POST['pc_name'] ?? 'Bilinmeyen PC';
    $hwid = $_POST['hwid'] ?? '';
    
    if (empty($hwid)) {
        echo json_encode(["status" => "error", "message" => "HWID gerekli"]);
        exit;
    }

    // Global sistem ana şalter durumu (Tüm pcler için genel komut)
    $globalStateFile = "global_state.txt";
    $globalState = file_exists($globalStateFile) ? trim(file_get_contents($globalStateFile)) : "1"; // 1: Açık, 0: Kapalı

    // Kayıtlı cihazları güncelle
    $found = false;
    foreach ($data as &$device) {
        if ($device['hwid'] == $hwid) {
            $device['pc_name'] = $pcName;
            $device['last_seen'] = time();
            $found = true;
            // Eğer cihaza özel bir komut yoksa global durumu al
            $command = isset($device['command']) ? $device['command'] : $globalState;
        }
    }
    unset($device);

    if (!$found) {
        $data[] = [
            "hwid" => $hwid,
            "pc_name" => $pcName,
            "last_seen" => time(),
            "command" => "1" // Varsayılan açık
        ];
        $command = $globalState;
    }

    file_put_contents($dataFile, json_encode($data));
    echo json_encode(["status" => "success", "command" => $command]);
    exit;
}

// Panelden cihaz listesini çekme
if ($action == 'get_devices') {
    // 30 saniyeden uzun süredir ping atmayanları online listeden düşürebilirsin
    echo json_encode($data);
    exit;
}

// Panelden komut gönderme (Tekli veya Toplu)
if ($action == 'set_command') {
    $hwid = $_POST['hwid'] ?? 'all';
    $command = $_POST['command'] ?? '1'; // 1: Açık, 0: Kapalı

    if ($hwid == 'all') {
        file_put_contents("global_state.txt", $command);
        foreach ($data as &$device) {
            $device['command'] = $command;
        }
        unset($device);
    } else {
        foreach ($data as &$device) {
            if ($device['hwid'] == $hwid) {
                $device['command'] = $command;
            }
        }
        unset($device);
    }

    file_put_contents($dataFile, json_encode($data));
    echo json_encode(["status" => "success"]);
    exit;
}
?>
