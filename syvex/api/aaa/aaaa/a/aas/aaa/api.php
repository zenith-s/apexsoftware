<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$expectedToken = base64_encode('NeonCordSecureClient');

if (!isset($input['token']) || $input['token'] !== $expectedToken) {
    echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim.']);
    exit;
}

$action = $input['action'] ?? '';
$dbFile = 'neoncord_database.json';

if (!file_exists($dbFile)) {
    file_put_contents($dbFile, json_encode(['users' => [], 'events' => []]));
}

$db = json_decode(file_get_contents($dbFile), true);

// Geçici (Temp Mail) Uzantı Engelleme Listesi
function isTempMail($email) {
    $tempDomains = [
        'tempmail.com', '10minutemail.com', 'guerrillamail.com', 'trashmail.com',
        'sharklasers.com', 'getairmail.com', 'dispostable.com', 'mailinator.com',
        'yopmail.com', 'temp-mail.org', 'fakeinbox.com', 'maildrop.cc'
    ];
    $domain = strtolower(substr(strrchr($email, "@"), 1));
    return in_array($domain, $tempDomains);
}

// KAYIT OLMA İŞLEMİ
if ($action === 'register') {
    $email = trim(strtolower($input['email'] ?? ''));
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Geçerli bir e-posta adresi girmelisiniz.']);
        exit;
    }

    if (isTempMail($email)) {
        echo json_encode(['success' => false, 'message' => 'Geçici (temp-mail) e-posta servisleri kabul edilmemektedir. Gerçek e-posta kullanın!']);
        exit;
    }

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Kullanıcı adı ve şifre zorunludur.']);
        exit;
    }

    // Tek hesap / E-posta kontrolü
    foreach ($db['users'] as $u) {
        if ($u['email'] === $email) {
            echo json_encode(['success' => false, 'message' => 'Bu e-posta adresi ile zaten bir hesap açılmış! Her kullanıcı sadece 1 hesap açabilir.']);
            exit;
        }
        if (strtolower($u['username']) === strtolower($username)) {
            echo json_encode(['success' => false, 'message' => 'Bu kullanıcı adı zaten alınmış.']);
            exit;
        }
    }

    $newUser = [
        'email' => $email,
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date('Y-m-d H:i:s')
    ];

    $db['users'][] = $newUser;
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));

    echo json_encode(['success' => true, 'user' => ['username' => $username, 'email' => $email]]);
    exit;
}

// GİRİŞ YAPMA İŞLEMİ
if ($action === 'login') {
    $email = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'E-posta ve şifre gereklidir.']);
        exit;
    }

    $foundUser = null;
    foreach ($db['users'] as $u) {
        if ($u['email'] === $email) {
            $foundUser = $u;
            break;
        }
    }

    if ($foundUser && password_verify($password, $foundUser['password'])) {
        echo json_encode(['success' => true, 'user' => ['username' => $foundUser['username'], 'email' => $foundUser['email']]]);
    } else {
        echo json_encode(['success' => false, 'message' => 'E-posta veya şifre hatalı!']);
    }
    exit;
}

// GERÇEK ETKİNLİK / YAYIN OLUŞTURMA
if ($action === 'create_event') {
    $username = trim($input['username'] ?? '');
    $title = trim($input['title'] ?? '');
    $category = trim($input['category'] ?? 'NeonCord • #Genel');

    if (empty($title) || empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Etkinlik başlığı boş olamaz.']);
        exit;
    }

    $newEvent = [
        'id' => uniqid(),
        'username' => $username,
        'title' => htmlspecialchars($title),
        'category' => htmlspecialchars($category),
        'time' => date('H:i')
    ];

    // En başa ekle ki yeni oluşturanlar hemen önde görünsün
    array_unshift($db['events'], $newEvent);
    
    // Maksimum 20 etkinlik tut
    if (count($db['events']) > 20) {
        array_pop($db['events']);
    }

    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
    exit;
}

// ETKİNLİKLERİ LİSTELEME
if ($action === 'get_events') {
    echo json_encode(['success' => true, 'events' => $db['events'] ?? []]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
?>
