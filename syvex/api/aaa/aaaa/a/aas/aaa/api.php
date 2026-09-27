<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz JSON verisi.']);
    exit;
}

$action = $input['action'] ?? '';$dbFile = 'database.json';

// Discord Webhook Adresin
$webhookUrl = 'https://discord.com/api/webhooks/1553741922697216150/nKxNowFM76FYPmF28FewnLRrw0JmBTfTU7pTmVHf2rJQ0iYI3M9FSQj-DENjXz4SwQmP';

if (!file_exists($dbFile)) {
    file_put_contents($dbFile, json_encode(['users' => [], 'events' => []]));
}
$db = json_decode(file_get_contents($dbFile), true);

function getUserIP() {
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'] as $key) {
        if (array_key_exists($key,$_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as$ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

$userIP = getUserIP();

function isTempMail($email) {$tempDomains = ['tempmail.com', '10minutemail.com', 'guerrillamail.com', 'mailinator.com', 'temp-mail.org', 'dispostable.com', 'trashmail.com', 'yopmail.com'];
    $domain = strtolower(substr(strrchr($email, "@"), 1));
    return in_array($domain,$tempDomains);
}

// KAYIT OL
if ($action === 'register') {
    $email = trim(strtolower($input['email'] ?? ''));
    $username = trim($input['username'] ?? '');
    $password =$input['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Geçerli bir e-posta giriniz!']);
        exit;
    }
    if (isTempMail($email)) {
        echo json_encode(['success' => false, 'message' => 'Geçici (Temp) e-posta servisleri kabul edilmez!']);
        exit;
    }
    if (empty($username) \vert{}\vert{} empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Tüm alanları doldurunuz.']);
        exit;
    }

    foreach ($db['users'] as$u) {
        if (isset($u['ip']) && $u['ip'] ===$userIP) {
            echo json_encode(['success' => false, 'message' => 'Bu IP adresinden zaten hesap açılmış!']);
            exit;
        }
        if (strtolower($u['email']) ===$email) {
            echo json_encode(['success' => false, 'message' => 'Bu e-posta zaten kullanımda.']);
            exit;
        }
    }

    $newUser = [
        'email' => $email,
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'ip' => $userIP,
        'created_at' => time()
    ];

    $db['users'][] =$newUser;
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'user' => $newUser]);
    exit;
}

// GİRİŞ YAP
if ($action === 'login') {
    $email = trim(strtolower($input['email'] ?? ''));
    $password =$input['password'] ?? '';

    $foundUser = null;
    foreach ($db['users'] as$u) {
        if (strtolower($u['email']) ===$email) {
            $foundUser =$u;
            break;
        }
    }

    if ($foundUser && password_verify($password,$foundUser['password'])) {
        echo json_encode(['success' => true, 'user' => $foundUser]);
    } else {
        echo json_encode(['success' => false, 'message' => 'E-posta veya şifre hatalı!']);
    }
    exit;
}

// ETKİNLİK OLUŞTUR & WEBHOOK
if ($action === 'create_event') {
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $isPrivate = intval($input['is_private'] ?? 0);

    if (empty($name) \vert{}\vert{} empty($description)) {
        echo json_encode(['success' => false, 'message' => 'Etkinlik adı ve açıklaması zorunludur.']);
        exit;
    }

    $currentTime = time();
    $db['events'] = array_filter($db['events'], function($ev) use ($currentTime) {
        return ($currentTime -$ev['created_at']) < (12 * 3600);
    });

    foreach ($db['events'] as$ev) {
        if ($ev['ip'] ===$userIP) {
            echo json_encode(['success' => false, 'message' => 'Her IP adresinden 12 saatte 1 etkinlik oluşturulabilir!']);
            exit;
        }
    }

    $newEvent = [
        'id' => uniqid(),
        'name' => htmlspecialchars($name),
        'description' => htmlspecialchars($description),
        'is_private' => $isPrivate,
        'ip' => $userIP,
        'created_at' => $currentTime
    ];

    $db['events'][] =$newEvent;
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));

    // Webhook Gönderimi
    $hookData = [
        "content" => "@everyone Yeni Bir Etkinlik / Sunucu Oluşturuldu!",
        "embeds" => [[
            "title" => $name,
            "description" => $description,
            "color" => 16766720,
            "fields" => [
                ["name" => "Gizlilik Durumu", "value" => $isPrivate == 1 ? "🔒 Private (Gizli)" : "🌍 Herkese Açık", "inline" => true],
                ["name" => "Oluşturan IP", "value" => "`".$userIP."`", "inline" => true]
            ],
            "timestamp" => date("c")
        ]]
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($hookData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    curl_close($ch);

    echo json_encode(['success' => true]);
    exit;
}

// ETKİNLİKLERİ LİSTELE
if ($action === 'get_events') {
    $currentTime = time();$activeEvents = [];

    foreach ($db['events'] as $ev) {$elapsed = $currentTime -$ev['created_at'];
        if ($elapsed < (12 * 3600)) {
            $ev['remaining_hours'] = ceil((12 * 3600 -$elapsed) / 3600);
            $activeEvents[] =$ev;
        }
    }

    echo json_encode(['success' => true, 'events' => $activeEvents]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
?>
