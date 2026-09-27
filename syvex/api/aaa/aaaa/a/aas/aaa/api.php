<?php
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

$expectedToken = base64_encode('NeonCordValidClient');
if ($clientToken !== $expectedToken) {
    echo json_encode(['success' => false, 'message' => 'Yetkisiz istemci hatası!']);
    exit;
}

function checkProfanity($text) {
    $badWords = ['amk', 'aq', 'sik', 'orospu', 'piç', 'oç', 'anan', 'sikik', 'yarak', 'taşak'];
    $lowerText = mb_strtolower($text, 'UTF-8');
    foreach ($badWords as $word) {
        if (strpos($lowerText, $word) !== false) return true;
    }
    return false;
}

$users = json_decode(file_get_contents($dbFile), true);

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

    if (isset($users[$username])) {
        if (password_verify($password, $users[$username]['password'])) {
            echo json_encode([
                'success' => true, 
                'user' => [
                    'username' => $username, 
                    'avatar' => $users[$username]['avatar'],
                    'messages' => $users[$username]['messages'] ?? [],
                    'servers' => $users[$username]['servers'] ?? []
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Hatalı şifre! Bu kullanıcı adı başka birine ait.']);
        }
    } else {
        $users[$username] = [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'avatar' => $avatar,
            'messages' => [],
            'servers' => [],
            'created_at' => time()
        ];
        file_put_contents($dbFile, json_encode($users, JSON_PRETTY_PRINT));
        echo json_encode([
            'success' => true, 
            'user' => ['username' => $username, 'avatar' => $avatar, 'messages' => [], 'servers' => []]
        ]);
    }
    exit;
}

if ($action === 'save_message') {
    $username = $input['username'] ?? '';
    $msg = $input['message'] ?? '';
    
    if (checkProfanity($msg)) {
        echo json_encode(['muted' => true, 'message' => 'Oto-Mod: Küfür veya +18 tespit edildi, 10s zaman aşımı!']);
        exit;
    }

    if (isset($users[$username])) {
        if (!isset($users[$username]['messages'])) $users[$username]['messages'] = [];
        $users[$username]['messages'][] = ['text' => $msg, 'time' => date('H:i')];
        file_put_contents($dbFile, json_encode($users, JSON_PRETTY_PRINT));
        echo json_encode(['muted' => false, 'success' => true]);
    }
    exit;
}

if ($action === 'create_server') {
    $username = $input['username'] ?? '';
    $serverName = $input['server_name'] ?? '';
    
    if (isset($users[$username])) {
        $inviteCode = 'nc-' . substr(md5(rand()), 0, 6);
        $serverData = ['name' => $serverName, 'code' => $inviteCode];
        if (!isset($users[$username]['servers'])) $users[$username]['servers'] = [];
        $users[$username]['servers'][] = $serverData;
        file_put_contents($dbFile, json_encode($users, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'invite' => 'https://neoncord.site/join/' . $inviteCode]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
?>
