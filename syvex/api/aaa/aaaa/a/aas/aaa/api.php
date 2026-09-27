<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// İstek metodu kontrolü
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek metodu.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Güvenlik token doğrulaması
$expectedToken = base64_encode('NeonCordValidClient');
if (!isset($input['token']) || $input['token'] !== $expectedToken) {
    echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim reddedildi.']);
    exit;
}

$action = $input['action'] ?? '';
$dbFile = 'database.json';

// Veritabanı dosyası yoksa başlat
if (!file_exists($dbFile)) {
    file_put_contents(
        $dbFile,
        json_encode([
            'users' => [],
            'servers' => [],
            'messages' => [],
            'bans' => [],
            'timeouts' => [],
        ])
    );
}

$db = json_decode(file_get_contents($dbFile), true);

// Küfür / KVKK / Yasaklı Kelime Filtresi
function checkContentFilter($text)
{
  $forbiddenWords = [
      'kufur1',
      'kufur2',
      'discord.gg/',
      't.me/',
      'tc kimlik',
      'telefon numaram',
  ];
  foreach ($forbiddenWords as $word) {
    if (
        stripos($text, $word) !== false ||
        preg_match(
            '/\b\d{11}\b/',
            $text
        ) // 11 haneli T.C. numarası tespiti
    ) {
      return true;
    }
  }
  return false;
}

// 1. GİRİŞ / KAYIT İŞLEMİ
if ($action === 'auth') {
  $username = trim($input['username'] ?? '');
  $password = $input['password'] ?? '';

  if (empty($username) || empty($password)) {
    echo json_encode([
        'success' => false,
        'message' => 'Kullanıcı adı ve şifre zorunludur.',
    ]);
    exit;
  }

  // Banlı kullanıcı kontrolü
  if (in_array(strtolower($username), array_map('strtolower', $db['bans']))) {
    echo json_encode([
        'success' => false,
        'message' => 'Bu hesap sistemden kalıcı olarak yasaklanmıştır.',
    ]);
    exit;
  }

  $userFound = null;
  foreach ($db['users'] as &$u) {
    if (strtolower($u['username']) === strtolower($username)) {
      $userFound = &$u;
      break;
    }
  }

  if ($userFound) {
    // Giriş yapılıyor
    if (password_verify($password, $userFound['password'])) {
      echo json_encode(['success' => true, 'user' => $userFound]);
    } else {
      echo json_encode([
          'success' => false,
          'message' => 'Hatalı şifre girdiniz.',
      ]);
    }
  } else {
    // Yeni kayıt oluşturuluyor
    $newUser = [
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $db['users'][] = $newUser;
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'user' => $newUser]);
  }
  exit();
}

// 2. SUNUCU / ODA OLUŞTURMA
if ($action === 'create_server') {
  $username = $input['username'] ?? '';
  $serverName = trim($input['server_name'] ?? '');

  if (empty($serverName)) {
    echo json_encode([
        'success' => false,
        'message' => 'Sunucu adı boş olamaz.',
    ]);
    exit;
  }

  $inviteCode = 'nc-' . substr(md5(uniqid()), 0, 8);
  $db['servers'][] = [
      'name' => $serverName,
      'owner' => $username,
      'invite' => $inviteCode,
  ];
  file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));

  echo json_encode([
      'success' => true,
      'invite' => 'https://neoncord.com/invite/' . $inviteCode,
  ]);
  exit();
}

// 3. MESAJ GÖNDERME & FİLTRELEME
if ($action === 'save_message') {
  $username = $input['username'] ?? '';
  $messageText = trim($input['message'] ?? '');

  // Timeout (Susturulma) kontrolü
  if (isset($db['timeouts'][$username])) {
    if (time() < $db['timeouts'][$username]) {
      $remaining = ceil(($db['timeouts'][$username] - time()) / 60);
      echo json_encode([
          'success' => false,
          'muted' => true,
          'message' =>
              "Susturuldunuz! Kalan süre: yaklaşık {$remaining} dakika.",
      ]);
      exit;
    } else {
      unset($db['timeouts'][$username]);
    }
  }

  // /ban komutu simülasyonu
  if (strpos($messageText, '/ban') === 0) {
    $parts = explode(' ', $messageText);
    if (isset($parts[1])) {
      $target = trim($parts[1]);
      $db['bans'][] = $target;
      file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
      echo json_encode([
          'success' => true,
          'message' => "{$target} sistemden banlandı.",
      ]);
      exit();
    }
  }

  // Küfür veya KVKK / Hassas veri kontrolü
  if (checkContentFilter($messageText)) {
    // 5 dakika (300 saniye) timeout cezası ver
    $db['timeouts'][$username] = time() + 300;
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));

    echo json_encode([
        'success' => false,
        'muted' => true,
        'message' =>
            'Uyarı: Küfür, hakaret veya KVKK / hassas veri paylaşımı tespit edildi! 5 dakika süreyle susturuldunuz.',
    ]);
    exit();
  }

  $db['messages'][] = [
      'username' => $username,
      'text' => htmlspecialchars($messageText),
      'time' => date('H:i'),
  ];

  // Sadece son 100 mesajı tut
  if (count($db['messages']) > 100) {
    array_shift($db['messages']);
  }

  file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
  echo json_encode(['success' => true]);
  exit();
}

echo json_encode(['success' => false, 'message' => 'Geçersiz aksiyon.']);
?>
