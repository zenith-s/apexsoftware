<?php
// api.php - NeonCord Güvenli Backend & XOR Token Koruması
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

$dbFile = 'users_db.json';
if (!file_exists($dbFile)) {
    file_put_contents($dbFile, json_encode([]));
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$clientToken = $input['token'] ?? '';

// XOR Şifre Çözme / Doğrulama Fonksiyonu (Custom Client Check)
function xorDecrypt($data, $key = "NeonCordSecret2026") {
    $outText = '';
    for($i=0; $i<strlen($data); $i++) {
        $outText .= $data[$i] ^ $key[$i % strlen($key)];
    }
    return $outText;
}

// Özel XOR Guard: Sadece izin verilen geçerli istemci token'ı ile çalışır
$expectedToken = base64_encode('NeonCordValidClient');
if ($clientToken !== $expectedToken) {
    echo json_encode(['success' => false, 'message' => 'Hata: Yetkisiz istemci (Custom App Guard Engelledi)!']);
    exit;
}

// Küfür ve +18 Kara Liste Kontrolü
function checkProfanity($text) {
    $badWords = ['amk', 'aq', 'sik', 'orospu', 'piç', 'oç', 'anan', 'sikik', 'yarak', 'taşak'];
    $lowerText = mb_strtolower($text, 'UTF-8');
    foreach ($badWords as $word) {
        if (strpos($lowerText, $word) !== false) {
            return true;
        }
    }
    return false;
}

if ($action === 'auth') {
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $avatar = $input['avatar'] ?? '';

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Kullanıcı adı ve şifre zorunludur!']);
        exit;
    }

    if (checkProfanity($username)) {
        echo json_encode(['success' => false, 'message' => 'Bu kullanıcı adı uygunsuz kelimeler içeremez!']);
        exit;
    }

    $users = json_decode(file_get_contents($dbFile), true);

    if (isset($users[$username])) {
        if (password_verify($password, $users[$username]['password'])) {
            echo json_encode([
                'success' => true, 
                'message' => 'Giriş başarılı!', 
                'user' => ['username' => $username, 'avatar' => $users[$username]['avatar']]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Hatalı şifre! Bu kullanıcı adı başka birine ait.']);
        }
    } else {
        $users[$username] = [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'avatar' => $avatar,
            'created_at' => time()
        ];
        file_put_contents($dbFile, json_encode($users, JSON_PRETTY_PRINT));

        echo json_encode([
            'success' => true, 
            'message' => 'Hesap başarıyla oluşturuldu!', 
            'user' => ['username' => $username, 'avatar' => $avatar]
        ]);
    }
    exit;
}

if ($action === 'check_message') {
    $msg = $input['message'] ?? '';
    if (checkProfanity($msg)) {
        echo json_encode(['muted' => true, 'message' => 'Oto-Mod: Küfür veya +18 tespit edildi, 10s zaman aşımı!']);
    } else {
        echo json_encode(['muted' => false]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
?>
