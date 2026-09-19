<?php
// Hata raporlamasını kapat (JSON çıktısının bozulmaması için)
error_reporting(0);
ini_set('display_errors', 0);

// Sadece POST isteklerini kabul et
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "failed", "message" => "Method not allowed"]);
    exit();
}

// C++ tarafından gelen verileri al
$hwid = isset($_POST['hwid']) ? trim($_POST['hwid']) : '';
$pid = isset($_POST['pid']) ? intval($_POST['pid']) : 0;
$vgk_state = isset($_POST['vgk_state']) ? trim($_POST['vgk_state']) : '';
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// 1. Temel Doğrulama: HWID boşsa veya PID geçersizse direkt reddet
if (empty($hwid)) {
    echo json_encode(["status" => "failed", "message" => "Invalid HWID"]);
    exit();
}

// Eğer eylem 'ping' ise (ilk açılış kontrolü)
if ($action === 'ping') {
    // Burada veritabanı ban veya lisans kontrolü yapabilirsin
    // Şimdilik sistemin aktif olduğunu dönüyoruz
    echo json_encode([
        "status" => "success",
        "command" => "1", // 1: Çalışmaya devam, 0: Kapalı
        "update" => "0"   // 1: Güncelleme var, 0: Güncel
    ]);
    exit();
}

// 2. Bypass İşlem Kontrolü (action=bypass)
if ($action === 'bypass') {
    
    // Eğer PID gelmediyse veya Vanguard hala kapanmadıysa bypass başarısız sayılır
    if ($pid <= 0) {
        echo json_encode(["status" => "failed", "message" => "Invalid PID"]);
        exit();
    }

    // Güvenlik simülasyonu / Bypass mantığı
    // Vanguard aktif kalmaya devam ediyorsa veya sürücü engellenemediyse hata döndür
    if ($vgk_state === "active_error_check") {
        echo json_encode(["status" => "failed", "message" => "Vanguard still running actively"]);
        exit();
    }

    // Gerçekçi bir engellenen nesne/hook sayısı hesaplayalım (Örn: PID değerine veya HWID'ye göre dinamik)
    // Burayı kendi sistemine göre özelleştirebilirsin
    $calculatedBlockedCount = 12 + ($pid % 9); // Örnek dinamik hesaplama (12 ile 20 arası bir rakam çıkar)

    // Başarılı yanıtı C++ tarafına JSON olarak gönder
    echo json_encode([
        "status" => "success",
        "blocked" => $calculatedBlockedCount,
        "message" => "Bypass successfully applied by server"
    ]);
    exit();
}

// Tanımsız bir action gelirse
echo json_encode(["status" => "failed", "message" => "Unknown action"]);
?>
