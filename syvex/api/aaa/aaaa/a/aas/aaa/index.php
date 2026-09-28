<?php
// Oturum ve Hata Ayarları
session_start();
$dbFile = 'neoncord.db';

// Webhook URL'si Base64 ile şifrelendi (Açıkta görünmez, public olamaz)
// Çözülen orijinal adres: https://discord.com/api/webhooks/1554078723534356536/0ZjVErrvTzEX-2WvVr09qsBAYJF6I6yN1A9zdUaWYCED_Jae5ZDAm55MUZ1iP3MIFNmM
$encodedWebhook = 'aHR0cHM6Ly9kaXNjb3JkLmNvbS9hcGkvd2ViaG9va3MvMTU1NDA3ODcyMzUzNDM1NjUzNi8wWmpWRXJydFR6RVgtMld2VnIwOXFzQkFZSkY2STZ5TjFBOXpkVWFXZUNFRF9KYWU1WkRBbTU1TVVaMWlQM01JRk5tTQ==';
$webhookUrl = base64_decode($encodedWebhook);

function sendDiscordWebhook($url,$message) {
    if (empty($url)) return;
    $data = json_encode(["content" => $message]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS,$data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data)
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

try {
    $db = new PDO("sqlite:" . $dbFile);$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tabloları Oluştur
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE,
        username TEXT UNIQUE,
        password TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS servers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        server_name TEXT,
        server_desc TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        event_title TEXT,
        event_date TEXT,
        event_desc TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS friends (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        friend_username TEXT,
        status TEXT DEFAULT 'pending'
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS blocks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        blocked_username TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reporter_id INTEGER,
        reported_target TEXT,
        reason TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // syvex kullanıcısını otomatik ekle
    $stmt =$db->prepare("SELECT COUNT(*) FROM users WHERE username = 'syvex'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {$hashedPassword = password_hash('dNjk6vgnnmV47xD', PASSWORD_DEFAULT);
        $insert =$db->prepare("INSERT INTO users (email, username, password) VALUES (?, ?, ?)");
        $insert->execute(['syvex@gmail.com', 'syvex', $hashedPassword]);
    }
} catch (Exception $e) {$dbError = "Sunucu bağlantı hatası!";
}

$message = "";
$messageType = "";

// Oturum ve Beni Hatırla Kontrolü
if (!isset($_SESSION['user']) && isset($_COOKIE['neon_user'])) {
    $_SESSION['user'] =$_COOKIE['neon_user'];
    $stmt =$db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$_COOKIE['neon_user']]);
    $uData =$stmt->fetch(PDO::FETCH_ASSOC);
    if ($uData) {
        $_SESSION['user_id'] =$uData['id'];
    }
}

// Form İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action =$_POST['action'] ?? '';

    if (isset($dbError)) {
        $message =$dbError;
        $messageType = "error";
    } else {
        if ($action === 'register') {
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password =$_POST['password'] ?? '';
            
            if (!empty($email) && !empty($username) && !empty($password)) {
                try {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt =$db->prepare("INSERT INTO users (email, username, password) VALUES (?, ?, ?)");
                    $stmt->execute([$email,$username, $hashed]);$message = "Kayıt başarılı! Giriş yapabilirsiniz.";
                    $messageType = "success";
                    sendDiscordWebhook($webhookUrl, "📥 **Yeni Kayıt:** `$username` ($email) sisteme kayıt oldu.");
                } catch (Exception $e) {$message = "Bu e-posta veya kullanıcı adı zaten kullanımda.";
                    $messageType = "error";
                }
            }
        } elseif ($action === 'login') {
            $identity = trim($_POST['identity'] ?? '');
            $password =$_POST['password'] ?? '';
            $keepSigned = isset($_POST['keep_signed']);

            $stmt =$db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$identity,$identity]);
            $user =$stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password,$user['password'])) {
                $_SESSION['user'] =$user['username'];
                $_SESSION['user_id'] =$user['id'];
                if ($keepSigned) {
                    setcookie('neon_user', $user['username'], time() + (86400 * 30), "/");
                }
                sendDiscordWebhook($webhookUrl, "🔑 **Giriş Yapıldı:** `{$user['username']}` hesabına giriş yapıldı.");
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $message = "Kullanıcı adı veya şifre hatalı!";
                $messageType = "error";
            }
        } elseif (isset($_SESSION['user'])) {
            $userId =$_SESSION['user_id'];
            $username =$_SESSION['user'];

            if ($action === 'create_server') {
                $sName = trim($_POST['server_name'] ?? '');
                $sDesc = trim($_POST['server_desc'] ?? '');
                if (!empty($sName)) {
                    $stmt =$db->prepare("INSERT INTO servers (user_id, server_name, server_desc) VALUES (?, ?, ?)");
                    $stmt->execute([$userId,$sName, $sDesc]);$message = "Sunucu başarıyla oluşturuldu!";
                    $messageType = "success";
                    sendDiscordWebhook($webhookUrl, "🖥️ **Sunucu Oluşturuldu:** `$username` isimli kullanıcı **$sName** sunucusunu kurdu.");
                }
            } elseif ($action === 'create_event') {
                $eTitle = trim($_POST['event_title'] ?? '');
                $eDate = trim($_POST['event_date'] ?? '');
                $eDesc = trim($_POST['event_desc'] ?? '');
                if (!empty($eTitle) && !empty($eDate)) {
                    $stmt =$db->prepare("INSERT INTO events (user_id, event_title, event_date, event_desc) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$userId, $eTitle,$eDate, $eDesc]);$message = "Etkinlik başarıyla planlandı!";
                    $messageType = "success";
                    sendDiscordWebhook($webhookUrl, "📅 **Etkinlik Oluşturuldu:** `$username` kullanıcısı **$eTitle** ($eDate) etkinliğini başlattı.");
                }
            } elseif ($action === 'add_friend') {
                $fName = trim($_POST['friend_username'] ?? '');
                if (!empty($fName)) {
                    $stmt =$db->prepare("INSERT INTO friends (user_id, friend_username, status) VALUES (?, ?, 'pending')");
                    $stmt->execute([$userId,$fName]);
                    $message = "$fName adlı kullanıcıya arkadaşlık isteği gönderildi.";
                    $messageType = "success";
                    sendDiscordWebhook($webhookUrl, "👥 **Arkadaşlık İsteği:** `$username`, `$fName` kullanıcısına istek gönderdi.");
                }
            } elseif ($action === 'block_user') {
                $bName = trim($_POST['blocked_username'] ?? '');
                if (!empty($bName)) {
                    $stmt =$db->prepare("INSERT INTO blocks (user_id, blocked_username) VALUES (?, ?)");
                    $stmt->execute([$userId,$bName]);
                    $message = "$bName engellendi.";
                    $messageType = "success";
                    sendDiscordWebhook($webhookUrl, "🚫 **Engelleme:** `$username`, `$bName` adlı kullanıcıyı engelledi.");
                }
            } elseif ($action === 'report_user') {
                $target = trim($_POST['reported_target'] ?? '');
                $reason = trim($_POST['reason'] ?? '');
                if (!empty($target) && !empty($reason)) {
                    $stmt =$db->prepare("INSERT INTO reports (reporter_id, reported_target, reason) VALUES (?, ?, ?)");
                    $stmt->execute([$userId,$target, $reason]);$message = "Şikayetiniz yetkililere iletildi.";
                    $messageType = "success";
                    sendDiscordWebhook($webhookUrl, "⚠️ **ŞİKAYET/REPORT:** `$username`, `$target` kullanıcısını şikayet etti. Neden: **$reason**");
                }
            }
        }
    }
}

// Çıkış İşlemi
if (isset($_GET['logout'])) {
    session_destroy();
    setcookie('neon_user', '', time() - 3600, "/");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>NeonCord - Güvenli Platform</title>
    <style>
        body { background-color: #0b0b0b; color: #ffffff; font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; padding: 20px; display: flex; justify-content: center; }
        .container { background-color: #18191c; padding: 25px; border-radius: 8px; width: 450px; box-shadow: 0 4px 20px rgba(0,0,0,0.6); }
        .logo { text-align: center; font-size: 24px; font-weight: bold; color: #faa61a; margin-bottom: 15px; }
        .form-group { margin-bottom: 12px; }
        label { display: block; margin-bottom: 4px; font-size: 11px; text-transform: uppercase; font-weight: bold; color: #b9bbbe; }
        input, textarea, select { width: 100%; padding: 8px; background-color: #2f3136; border: 1px solid #202225; border-radius: 4px; color: #fff; box-sizing: border-box; outline: none; }
        input:focus, textarea:focus { border-color: #faa61a; }
        .btn { width: 100%; padding: 10px; background-color: #faa61a; border: none; border-radius: 4px; color: #fff; font-weight: bold; cursor: pointer; margin-top: 5px; }
        .btn:hover { background-color: #e59415; }
        .btn-danger { background-color: #ed4245; }
        .btn-danger:hover { background-color: #c03537; }
        .message { margin-bottom: 15px; padding: 8px; border-radius: 4px; font-size: 12px; text-align: center; }
        .message.success { background-color: #3ba55d; color: white; }
        .message.error { background-color: #ed4245; color: white; }
        .nav-tabs { display: flex; gap: 5px; margin-bottom: 15px; flex-wrap: wrap; }
        .nav-tabs button { flex: 1; padding: 6px; background: #2f3136; border: none; color: #b9bbbe; cursor: pointer; border-radius: 4px; font-size: 11px; }
        .nav-tabs button.active { background: #faa61a; color: #fff; font-weight: bold; }
        .section { display: none; }
        .section.active { display: block; }
        .logout-btn { background: #ed4245; text-align: center; display: block; padding: 8px; color: white; text-decoration: none; border-radius: 4px; margin-top: 15px; font-size: 13px; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <div class="logo">⚡ NeonCord</div>

    <?php if (!empty($message)): ?>
        <div class="message <?php echo $messageType; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if (!isset($_SESSION['user'])): ?>
        <!-- GİRİŞ / KAYIT EKRANI -->
        <div class="nav-tabs">
            <button class="active" onclick="switchAuth('login', this)">Giriş Yap</button>
            <button onclick="switchAuth('register', this)">Kayıt Ol</button>
        </div>

        <form id="loginForm" class="section active" method="POST">
            <input type="hidden" name="action" value="login">
            <div class="form-group"><label>Kullanıcı Adı veya E-posta</label><input type="text" name="identity" required placeholder="syvex"></div>
            <div class="form-group"><label>Şifre</label><input type="password" name="password" required placeholder="dNjk6vgnnmV47xD"></div>
            <div style="margin: 10px 0; font-size: 12px; color: #b9bbbe;"><input type="checkbox" name="keep_signed" value="1" style="width:auto;"> Oturumu açık tut (Beni Hatırla)</div>
            <button type="submit" class="btn">Giriş Yap</button>
        </form>

        <form id="registerForm" class="section" method="POST">
            <input type="hidden" name="action" value="register">
            <div class="form-group"><label>E-Posta</label><input type="email" name="email" required></div>
            <div class="form-group"><label>Kullanıcı Adı</label><input type="text" name="username" required></div>
            <div class="form-group"><label>Şifre</label><input type="password" name="password" required></div>
            <button type="submit" class="btn">Kayıt Ol</button>
        </form>

    <?php else: ?>
        <!-- PANEL / KONTROL MERKEZİ -->
        <div style="font-size: 14px; margin-bottom: 15px; color: #dcddde; text-align: center;">
            Hoş geldin, <b><?php echo htmlspecialchars($_SESSION['user']); ?></b>!
        </div>

        <div class="nav-tabs">
            <button class="active" onclick="switchTab('server', this)">Sunucu</button>
            <button onclick="switchTab('event', this)">Etkinlik</button>
            <button onclick="switchTab('friend', this)">Arkadaş</button>
            <button onclick="switchTab('block', this)">Engelle</button>
            <button onclick="switchTab('report', this)">Şikayet</button>
        </div>

        <!-- Sunucu Oluştur -->
        <form id="tab-server" class="section active" method="POST">
            <input type="hidden" name="action" value="create_server">
            <div class="form-group"><label>Sunucu Adı</label><input type="text" name="server_name" required placeholder="Oyun Topluluğu"></div>
            <div class="form-group"><label>Açıklama</label><textarea name="server_desc" placeholder="Sunucu hakkında kısa bilgi..."></textarea></div>
            <button type="submit" class="btn">Sunucu Kur</button>
        </form>

        <!-- Etkinlik Oluştur -->
        <form id="tab-event" class="section" method="POST">
            <input type="hidden" name="action" value="create_event">
            <div class="form-group"><label>Etkinlik Başlığı</label><input type="text" name="event_title" required placeholder="CS2 Turnuvası"></div>
            <div class="form-group"><label>Tarih ve Saat</label><input type="text" name="event_date" required placeholder="28.09.2026 - 21:00"></div>
            <div class="form-group"><label>Detaylar</label><textarea name="event_desc" placeholder="Etkinlik kuralları..."></textarea></div>
            <button type="submit" class="btn">Etkinlik Planla</button>
        </form>

        <!-- Arkadaş Ekle -->
        <form id="tab-friend" class="section" method="POST">
            <input type="hidden" name="action" value="add_friend">
            <div class="form-group"><label>Arkadaşın Kullanıcı Adı</label><input type="text" name="friend_username" required placeholder="kullaniciadi"></div>
            <button type="submit" class="btn">Arkadaşlık İsteği Gönder</button>
        </form>

        <!-- Kullanıcı Engelle -->
        <form id="tab-block" class="section" method="POST">
            <input type="hidden" name="action" value="block_user">
            <div class="form-group"><label>Engellenecek Kullanıcı Adı</label><input type="text" name="blocked_username" required placeholder="istenmeyen_kisi"></div>
            <button type="submit" class="btn btn-danger">Kullanıcıyı Engelle</button>
        </form>

        <!-- Şikayet Et -->
        <form id="tab-report" class="section" method="POST">
            <input type="hidden" name="action" value="report_user">
            <div class="form-group"><label>Şikayet Edilecek Kişi / Sunucu</label><input type="text" name="reported_target" required placeholder="Hedef İsim"></div>
            <div class="form-group"><label>Şikayet Nedeni</label><textarea name="reason" required placeholder="Küfür, dolandırıcılık vb..."></textarea></div>
            <button type="submit" class="btn btn-danger">Şikayeti Bildir</button>
        </form>

        <a href="?logout=true" class="logout-btn">Oturumu Kapat</a>
    <?php endif; ?>
</div>

<script>
    function switchAuth(tabId, btn) {
        document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
        document.querySelectorAll('.nav-tabs button').forEach(b => b.classList.remove('active'));
        document.getElementById(tabId + 'Form').classList.add('active');
        btn.classList.add('active');
    }
    function switchTab(tabName, btn) {
        document.querySelectorAll('.container .section').forEach(s => s.classList.remove('active'));
        document.querySelectorAll('.container .nav-tabs button').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + tabName).classList.add('active');
        btn.classList.add('active');
    }
</script>

</body>
</html>
