<?php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

$dbFile = 'users_db.json';$banFile = 'bans_db.json';

if (!file_exists($dbFile)) file_put_contents($dbFile, json_encode([]));
if (!file_exists($banFile)) file_put_contents($banFile, json_encode([]));

$input = json_decode(file_get_contents('php://input'), true);
$action =$input['action'] ?? '';
$clientToken =$input['token'] ?? '';

$expectedToken = base64_encode('NeonCordValidClient');
if ($clientToken !==$expectedToken) {
    echo json_encode(['success' => false, 'message' => 'Yetkisiz istemci hatası!']);
    exit;
}

$userIP =$_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor';

// Ban Kontrolü (IP Bazlı)
$bans = json_decode(file_get_contents($banFile), true);
foreach ($bans as$ban) {
    if ($ban['ip'] ===$userIP) {
        echo json_encode(['success' => false, 'message' => 'Bu sistemden kalıcı olarak banlandınız!']);
        exit;
    }
}

function sendDiscordWebhook($title,$description, $color = 16711680) {$webhookUrl = "https://discordapp.com/api/webhooks/1553741922697216150/nKxNowFM76FYPmF28FewnLRrw0JmBTfTU7pTmVHf2rJQ0iYI3M9FSQj-DENjXz4SwQmP";
    $data = [
        "username" => "NeonCord Güvenlik & KVKK Botu",
        "avatar_url" => "https://i.imgur.com/716CCqO.png",
        "embeds" => [[
            "title" => "🚨 " . $title,
            "description" => $description,
            "color" => $color,
            "timestamp" => date('c'),
            "footer" => ["text" => "NeonCord Security & Log Sistemi"]
        ]]
    ];
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function checkKVKKAndProfanity($text) {$kvkkKeywords = ['tc kimlik', 't.c.', 'iban', 'telefon numarası', 'kredi kartı', 'adres:', 'şifrem:'];
    $badWords = ['amk', 'aq', 'sik', 'orospu', 'piç', 'oç', 'anan', 'sikik', 'yarak', 'taşak'];
    $lowerText = mb_strtolower($text, 'UTF-8');

    foreach ($kvkkKeywords as$kw) {
        if (strpos($lowerText,$kw) !== false) {
            sendDiscordWebhook("KVKK İhlali Tespit Edildi!", "**İhlal İçeriği:** ```$text```\n**IP:** `$GLOBALS[userIP]`");
            return ['type' => 'kvkk', 'msg' => 'KVKK İhlali! Bu tür kişisel bilgiler paylaşılamaz ve loglandı!'];
        }
    }

    foreach ($badWords as$word) {
        if (strpos($lowerText,$word) !== false) {
            return ['type' => 'profanity', 'msg' => 'Küfür veya +18 içerik tespit edildi!'];
        }
    }
    return null;
}

$users = json_decode(file_get_contents($dbFile), true);

if ($action === 'auth') {
    $username = trim($input['username'] ?? '');
    $password =$input['password'] ?? '';
    
    if (empty($username) \vert{}\vert{} empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Kullanıcı adı ve şifre zorunludur!']);
        exit;
    }

    if (isset($users[$username])) {
        if (password_verify($password, $users[$username]['password'])) {
            echo json_encode(['success' => true, 'user' => $users[$username]]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Hatalı şifre!']);
        }
    } else {
        $users[$username] = [
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'avatar' => $input['avatar'] ?? '',
            'messages' => [],
            'servers' => [['name' => 'Genel Sohbet', 'code' => 'genel']],
            'friends' => [],
            'bots' => []
        ];
        file_put_contents($dbFile, json_encode($users, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'user' => $users[$username]]);
    }
    exit;
}

if ($action === 'save_message') {
    $username =$input['username'] ?? '';
    $msg =$input['message'] ?? '';
    $serverCode =$input['server'] ?? 'genel';

    $check = checkKVKKAndProfanity($msg);
    if ($check) {
        echo json_encode(['muted' => true, 'message' => $check['msg']]);
        exit;
    }

    // Moderasyon Komutu (/ban)
    if (strpos($msg, '/ban ') === 0) {$targetUser = trim(str_replace('/ban ', '', $msg));$bans[] = ['ip' => $userIP, 'target' =>$targetUser, 'time' => time()];
        file_put_contents($banFile, json_encode($bans, JSON_PRETTY_PRINT));
        sendDiscordWebhook("Kullanıcı Banlandı (/ban)", "**Komutu Kullanan:** $username\n**Hedef:** $targetUser\n**IP:** $userIP", 16711680);
        echo json_encode(['success' => true, 'banned' => true, 'message' => "$targetUser ve ilişkili IP engellendi!"]);
        exit;
    }

    if (isset($users[$username])) {
        $users[$username]['messages'][] = ['text' => $msg, 'server' =>$serverCode, 'time' => date('H:i')];
        file_put_contents($dbFile, json_encode($users, JSON_PRETTY_PRINT));
        echo json_encode(['muted' => false, 'success' => true]);
    }
    exit;
}

if ($action === 'create_server') {
    $username =$input['username'] ?? '';
    $serverName =$input['server_name'] ?? '';
    if (isset($users[$username])) {$inviteCode = 'nc-' . substr(md5(rand()), 0, 6);
        $users[$username]['servers'][] = ['name' => $serverName, 'code' =>$inviteCode];
        file_put_contents($dbFile, json_encode($users, JSON_PRETTY_PRINT));
        // Render üzerindeki tam klasör yoluna göre davet linki
        echo json_encode(['success' => true, 'invite' => 'https://neonsoftwarecrackme.onrender.com/syvex/api/aaa/aaaa/a/aas/aaa/index.html?join=' . $inviteCode]);
    }
    exit;
}

if ($action === 'add_friend') {
    $username =$input['username'] ?? '';
    $friendName =$input['friend_name'] ?? '';
    if (isset($users[$username])) {$users[$username]['friends'][] =$friendName;
        file_put_contents($dbFile, json_encode($users, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true]);
    }
    exit;
}

if ($action === 'create_bot') {
    $username =$input['username'] ?? '';
    $botName =$input['bot_name'] ?? '';
    $template =$input['template'] ?? 'moderator';
    if (isset($users[$username])) {$botData = ['name' => $botName, 'template' =>$template, 'status' => 'Aktif (Hazır Şablon)'];
        $users[$username]['bots'][] =$botData;
        file_put_contents($dbFile, json_encode($users, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'bot' => $botData]);
    }
    exit;
}
?>
