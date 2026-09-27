<?php
// api.php - Basit Hesap ve İstek Yöneticisi
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['action']) && $data['action'] === 'login_or_register') {
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Alanlar boş bırakılamaz!']);
        exit;
    }

    // Burada veritabanı (MySQL) veya dosya tabanlı kayıt işlemleri yapabilirsin
    // Örnek olması açısından başarılı döndürüyoruz:
    echo json_encode([
        'success' => true, 
        'message' => 'Giriş başarılı',
        'user' => $username
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Geçersiz istek.']);
?>
