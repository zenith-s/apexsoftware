<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);$action = $input['action'] ?? '';$dbFile = 'database.json';

// Discord Webhook URL'ni buraya yaz
$webhookUrl = 'BURAYA_DISCORD_WEBHOOK_URL_YAZ';

// Veritabanı başlatma
if (!file_exists($dbFile)) {
    file_put_contents(
        $dbFile,
        json_encode([
            'users' => [],
            'events' => [],
            'ip_logs' => [],
        ])
    );
}
$db = json_decode(file_get_contents($dbFile), true);

// Kullanıcı Gerçek IP Tespiti
function getUserIP()
{
  foreach (
      [
          'HTTP_CLIENT_IP',
          'HTTP_X_FORWARDED_FOR',
          'HTTP_X_FORWARDED',
          'HTTP_X_CLUSTER_CLIENT_IP',
          'HTTP_FORWARDED_FOR',
          'HTTP_FORWARDED',
          'REMOTE_ADDR',
      ]
      as $key
  ) {
    if (array_key_exists($key,$_SERVER) === true) {
      foreach (explode(',', $_SERVER[$key]) as$ip) {
        $ip = trim($ip);
        if (
            filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false
        ) {
          return $ip;
        }
      }
    }
  }
  return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

$userIP = getUserIP();

// Anti-VPN / Proxy Basit Kontrolü (Hosting ve Bilinen Datacenter IP aralıkları engeli)
$blockedSubnets = ['198.51.100.', '203.0.113.']; // Örnek proxy/vpn aralıkları
foreach ($blockedSubnets as$subnet) {
  if (strpos($userIP,$subnet) === 0) {
    echo json_encode([
        'success' => false,
        'message' =>
            'Güvenlik Uyarısı: VPN veya Proxy (Hosting IP) tespiti! Erişim engellendi.',
    ]);
    exit;
  }
}

// Temp Mail Engelleme Listesi
function isTempMail($email)
{
  $tempDomains = [
      'tempmail.com',
      '10minutemail.com',
      'guerrillamail.com',
      'mailinator.com',
      'temp-mail.org',
      'dispostable.com',
      'trashmail.com',
      'yopmail.com',
  ];
  $domain = strtolower(substr(strrchr($email, '@'), 1));
  return in_array($domain,$tempDomains);
}

// 1. KAYIT OLMA (Mail Zorunlu & Temp Mail Engeli)
if ($action === 'register') {
  $email = trim(strtolower($input['email'] ?? ''));
  $username = trim($input['username'] ?? '');
  $password =$input['password'] ?? '';

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'message' => 'Geçerli bir e-posta adresi girmelisiniz!',
    ]);
    exit;
  }

  if (isTempMail($email)) {
    echo json_encode([
        'success' => false,
        'message' =>
            'Geçici (Temp) e-posta servisleri kesinlikle kabul edilmemektedir!',
    ]);
    exit;
  }

  if (empty($username) \vert{}\vert{} empty($password)) {
    echo json_encode([
        'success' => false,
        'message' => 'Tüm alanları doldurunuz.',
    ]);
    exit;
  }

  // Her IP adresinden sadece 1 hesap açma kuralı
  foreach ($db['users'] as$u) {
    if (isset($u['ip']) && $u['ip'] ===$userIP) {
      echo json_encode([
          'success' => false,
          'message' => 'Bu IP adresinden zaten bir hesap oluşturulmuş!',
      ]);
      exit;
    }
    if (strtolower($u['email']) ===$email) {
      echo json_encode([
          'success' => false,
          'message' => 'Bu e-posta adresi zaten kullanımda.',
      ]);
      exit;
    }
  }

  $newUser = [
      'email' => $email,
      'username' => $username,
      'password' => password_hash($password, PASSWORD_DEFAULT),
      'ip' => $userIP,
      'created_at' => time(),
  ];

  $db['users'][] =$newUser;
  file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));

  $_SESSION['user'] =$newUser;
  echo json_encode(['success' => true, 'user' => $newUser]);
  exit();
}

// 2. GİRİŞ YAPMA
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
    $_SESSION['user'] =$foundUser;
    echo json_encode(['success' => true, 'user' => $foundUser]);
  } else {
    echo json_encode([
        'success' => false,
        'message' => 'E-posta veya şifre hatalı!',
    ]);
  }
  exit();
}

// 3. ETKİNLİK / SUNUCU OLUŞTURMA (IP Başına 1 Kez, 12 Saat Süreli, Webhook Bildirimli)
if ($action === 'create_event') {
  $name = trim($input['name'] ?? '');
  $description = trim($input['description'] ?? '');
  $isPrivate = intval($input['is_private'] ?? 0);

  if (empty($name) \vert{}\vert{} empty($description)) {
    echo json_encode([
        'success' => false,
        'message' => 'Etkinlik adı ve açıklaması zorunludur.',
    ]);
    exit;
  }

  // 12 saati geçmiş etkinlikleri otomatik temizle
  $currentTime = time();
  $db['events'] = array_filter($db['events'], function ($ev) use ($currentTime
  ) {
    return $currentTime -$ev['created_at'] < 12 * 3600;
  });

  // Bu IP adresinden halihazırda aktif etkinlik var mı kontrol et
  foreach ($db['events'] as$ev) {
    if ($ev['ip'] ===$userIP) {
      echo json_encode([
          'success' => false,
          'message' =>
              'Zaten aktif bir etkinlik oluşturdunuz! Her IP için 1 etkinlik sınırı vardır ve 12 saat sonra yenisi açılabilir.',
      ]);
      exit;
    }
  }

  $newEvent = [
      'id' => uniqid(),
      'name' => htmlspecialchars($name),
      'description' => htmlspecialchars($description),
      'is_private' => $isPrivate,
      'ip' => $userIP,
      'created_at' => $currentTime,
  ];

  $db['events'][] =$newEvent;
  file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));

  // Discord Webhook Gönderimi (Kullanıcı ID / IP ve Detaylar)
  if (!empty($webhookUrl) && $webhookUrl !== 'BURAYA_DISCORD_WEBHOOK_URL_YAZ') {$hookData = [
        'content' => '@everyone Yeni Bir Etkinlik / Sunucu Oluşturuldu!',
        'embeds' => [
            [
                'title' => $name,
                'description' => $description,
                'color' => 16766720,
                'fields' => [
                    [
                        'name' => 'Gizlilik Durumu',
                        'value' =>
                            $isPrivate == 1
                                ? '🔒 Private (Gizli)'
                                : '🌍 Herkese Açık',
                        'inline' => true,
                    ],
                    [
                        'name' => 'Oluşturan IP / Sistem',
                        'value' => '`' . $userIP . '`',
                        'inline' => true,
                    ],
                ],
                'timestamp' => date('c'),
            ],
        ],
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($hookData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    curl_close($ch);
  }

  echo json_encode(['success' => true]);
  exit();
}

// 4. ETKİNLİKLERİ LİSTELEME
if ($action === 'get_events') {
  $currentTime = time();$activeEvents = [];

  foreach ($db['events'] as $ev) {$elapsed = $currentTime -$ev['created_at'];
    if ($elapsed < 12 * 3600) {
      $remainingHours = ceil((12 * 3600 -$elapsed) / 3600);
      $ev['remaining_hours'] =$remainingHours;
      $activeEvents[] =$ev;
    }
  }

  echo json_encode(['success' => true, 'events' => $activeEvents]);
  exit();
}

echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
?>
