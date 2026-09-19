<?php
header("Content-Type: text/plain; charset=utf-8");

$action = $_GET['action'] ?? '';
$key = $_GET['key'] ?? '';
$hwid = $_GET['hwid'] ?? '';
$pc_name = $_GET['pc_name'] ?? '';

if ($action === 'verify') {
    if (empty($key)) {
        echo "INVALID_KEY";
        exit;
    }

    // Lisans veritabanı (Burayı kendi sistemine göre düzenleyebilirsin)
    $database = [
        "NEON-4A21-09C4-E23E" => [
            "status" => "active",
            "expiry" => "31d 2h",
            "hwid" => "" // İlk girişte cihaza kilitlenir
        ],
        "NEON-PRO-9999" => [
            "status" => "active",
            "expiry" => "90d 0h",
            "hwid" => ""
        ]
    ];

    if (!isset($database[$key])) {
        echo "INVALID_KEY";
        exit;
    }

    $license = $database[$key];

    if ($license["status"] !== "active") {
        echo "EXPIRED";
        exit;
    }

    // HWID Kilitleme Kontrolü
    if (!empty($license["hwid"]) && $license["hwid"] !== $hwid) {
        echo "HWID_MISMATCH";
        exit;
    }

    // Başarılı yanıt ve lisans bitiş süresi
    echo "SUCCESS|" . $license["expiry"];
} else {
    echo "INVALID_ACTION";
}
?>
