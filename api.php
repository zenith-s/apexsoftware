<?php
header("Content-Type: application/json; charset=UTF-8");
$file = 'devices.json';

// Dosya yoksa oluştur
if (!file_exists($file)) {
    file_put_contents($file, json_encode([]));
}

$devices = json_decode(file_get_contents($file), true);
if (!is_array($devices)) {
    $devices = [];
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 1. C++ Tarafından Ping Geldiğinde
if ($action === 'ping') {
    $hwid = isset($_POST['hwid']) ? $_POST['hwid'] : '';
    $pc_name = isset($_POST['pc_name']) ? $_POST['pc_name'] : '';

    if (!empty($hwid)) {
        $found = false;
        foreach ($devices as &$dev) {
            if ($dev['hwid'] === $hwid) {
                $dev['pc_name'] = !empty($pc_name) ? $pc_name : $dev['pc_name'];
                $dev['last_seen'] = time();
                $found = true;
                break;
            }
        }
        if (!$found) {
            // Yeni cihaz ekle (Varsayılan komut: 1 yani açık)
            $devices[] = [
                'hwid' => $hwid,
                'pc_name' => !empty($pc_name) ? $pc_name : 'Bilinmeyen PC',
                'command' => '1',
                'last_seen' => time()
            ];
        }
        file_put_contents($file, json_encode($devices));
    }

    // Cihazın komutunu bul ve dön
    $current_command = "1";
    foreach ($devices as $dev) {
        if ($dev['hwid'] === (isset($_POST['hwid']) ? $_POST['hwid'] : '')) {
            $current_command = $dev['command'];
            break;
        }
    }

    echo json_encode(["status" => "success", "command" => $current_command]);
    exit;
}

// 2. Panel İçin Cihazları Listeleme
if ($action === 'get_devices') {
    echo json_encode($devices);
    exit;
}

// 3. Panelden Komut Değiştirme (Aç / Kapat)
if ($action === 'set_command') {
    $hwid = isset($_POST['hwid']) ? $_POST['hwid'] : '';
    $cmd = isset($_POST['command']) ? $_POST['command'] : '1';

    if ($hwid === 'all') {
        foreach ($devices as &$dev) {
            $dev['command'] = $cmd;
        }
    } else {
        foreach ($devices as &$dev) {
            if ($dev['hwid'] === $hwid) {
                $dev['command'] = $cmd;
                break;
            }
        }
    }
    file_put_contents($file, json_encode($devices));
    echo json_encode(["status" => "updated"]);
    exit;
}
?>
