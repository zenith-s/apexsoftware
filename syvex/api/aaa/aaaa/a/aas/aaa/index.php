<?php
// Oturum ve Hata Ayarları
session_start();
$dbFile = 'neoncord.db';

// Webhook URL'si Base64 ile şifrelendi (Açıkta görünmez)
// Yeni webhook adresini buraya Base64 formatında yazabilirsin
$encodedWebhook = 'aHR0cHM6Ly9kaXNjb3JkLmNvbS9hcGkvd2ViaG9va3MvMTU1NDA3ODcyMzUzNDM1NjUzNi8wWmpWRXJydFR6RVgtMld2VnIwOXFzQkFZSkY2STZ5TjFBOXpkVWFXZUNFRF9KYWU1WkRBbTU1TVVaMWlQM01JRk5tTQ==';
$webhookUrl = base64_decode($encodedWebhook);

function sendDiscordWebhook($url, $message) {
    if (empty($url)) return;
    $data = json_encode(["content" => $message]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data)
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

try {
    $db = new PDO("sqlite:" . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = 'syvex'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $hashedPassword = password_hash('dNjk6vgnnmV47xD', PASSWORD_DEFAULT);
        $insert = $db->prepare("INSERT INTO users (email, username, password) VALUES (?, ?, ?)");
        $insert->execute(['syvex@gmail.com', 'syvex', $hashedPassword]);
    }
} catch (Exception $e) {
    $dbError = "Sunucu bağlantı hatası!";
}

$message = "";
$messageType = "";

// Oturum ve Beni Hatırla Kontrolü
if (!isset($_SESSION['user']) && isset($_COOKIE['neon_user'])) {
    $_SESSION['user'] = $_COOKIE['neon_user'];
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$_COOKIE['neon_user']]);
    $uData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($uData) {
        $_SESSION['user_id'] = $uData['id'];
    }
}

// Form İşlemleri ve Webhook Tetikleyicileri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (isset($dbError)) {
        $message = $dbError;
        $messageType = "error";
    } else {
        if ($action === 'register') {
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if (!empty($email) && !empty($username) && !empty($password)) {
                try {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (email, username, password) VALUES (?, ?, ?)");
                    $stmt->execute([$email, $username, $hashed]);
                    $message = "Kayıt başarılı! Şimdi giriş yapabilirsiniz.";
                    $messageType = "success";
                    sendDiscordWebhook($webhookUrl, "📥 **Hesap Oluşturuldu (Kayıt):** `$username` ($email) sisteme yeni kayıt oldu!");
                } catch (Exception $e) {
                    $message = "Bu e-posta veya kullanıcı adı zaten kullanımda.";
                    $messageType = "error";
                }
            }
        } elseif ($action === 'login') {
            $identity = trim($_POST['identity'] ?? '');
            $password = $_POST['password'] ?? '';
            $keepSigned = isset($_POST['keep_signed']);

            $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$identity, $identity]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user'] = $user['username'];
                $_SESSION['user_id'] = $user['id'];
                if ($keepSigned) {
                    setcookie('neon_user', $user['username'], time() + (86400 * 30), "/");
                }
                sendDiscordWebhook($webhookUrl, "🔑 **Giriş Yapıldı:** `{$user['username']}` hesabına başarıyla giriş yapıldı.");
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
                    sendDiscordWebhook($webhookUrl, "📅 **Etkinlik Planlandı:** `$username` kullanıcısı **$eTitle** ($eDate) etkinliğini başlattı.");
                }
            } elseif ($action === 'add_friend') {
                $fName = trim($_POST['friend_username'] ?? '');
                if (!empty($fName)) {
                    $stmt =$db->prepare("INSERT INTO friends (user_id, friend_username, status) VALUES (?, ?, 'pending')");
                    $stmt->execute([$userId,$fName]);
                    $message = "$fName adlı kullanıcıya arkadaşlık isteği gönderildi.";
                    $messageType = "success";
                    sendDiscordWebhook($webhookUrl, "👥 **Arkadaş Ekleme:** `$username`, `$fName` kullanıcısına arkadaşlık isteği gönderdi.");
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
                    sendDiscordWebhook($webhookUrl, "⚠️ **ŞİKAYET/REPORT:** `$username`, `$target` hedefini şikayet etti. Sebep: **$reason**");
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
    <title>NeonCord - Discord GUI</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'gg sans', 'Noto Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif; }
        body { background-color: #313338; color: #dbdee1; height: 100vh; display: flex; overflow: hidden; }

        /* Discord Sol Sunucu Listesi */
        .guilds-sidebar { width: 72px; background-color: #1e1f22; display: flex; flex-direction: column; align-items: center; padding-top: 12px; gap: 8px; z-index: 10; }
        .guild-icon { width: 48px; height: 48px; border-radius: 50%; background-color: #313338; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: bold; cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); position: relative; }
        .guild-icon:hover { border-radius: 16px; background-color: #5865f2; }
        .guild-icon.active { border-radius: 16px; background-color: #5865f2; }
        .guild-icon.active::before { content: ''; position: absolute; left: -12px; width: 8px; height: 40px; background-color: #fff; border-radius: 0 4px 4px 0; animation: scaleIn 0.2s ease; }
        .guild-separator { width: 32px; height: 2px; background-color: #35363c; border-radius: 1px; }

        /* Discord Kanal Listesi Sidebar */
        .channels-sidebar { width: 240px; background-color: #2b2d31; display: flex; flex-direction: column; border-radius: 8px 0 0 8px; }
        .server-header { height: 48px; padding: 0 16px; display: flex; align-items: center; justify-content: space-between; font-weight: bold; color: #f2f3f5; border-bottom: 2px solid #1f2023; font-size: 15px; }
        .channels-list { padding: 16px 8px; flex: 1; overflow-y: auto; }
        .channel-category { font-size: 12px; font-weight: bold; color: #949ba4; text-transform: uppercase; margin-bottom: 6px; padding: 0 8px; }
        .channel-item { padding: 8px; border-radius: 4px; color: #949ba4; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 14px; transition: all 0.15s ease; margin-bottom: 2px; }
        .channel-item:hover, .channel-item.active { background-color: #35373c; color: #dbdee1; }
        .channel-item.active { background-color: #404249; color: #fff; font-weight: 500; }

        /* Ana İçerik Alanı (Chat / Panel Ekranı) */
        .chat-container { flex: 1; background-color: #313338; display: flex; flex-direction: column; position: relative; }
        .chat-header { height: 48px; border-bottom: 2px solid #1f2023; display: flex; align-items: center; padding: 0 16px; font-weight: bold; color: #f2f3f5; gap: 8px; font-size: 16px; }
        .chat-content { flex: 1; padding: 24px; overflow-y: auto; display: flex; justify-content: center; align-items: center; }

        /* Sağ Üye Listesi Sidebar */
        .members-sidebar { width: 240px; background-color: #2b2d31; padding: 16px; display: flex; flex-direction: column; gap: 8px; }
        .member-header { font-size: 12px; font-weight: bold; color: #949ba4; text-transform: uppercase; margin-bottom: 4px; }
        .member-item { display: flex; align-items: center; gap: 10px; padding: 6px 8px; border-radius: 4px; cursor: pointer; transition: background 0.15s; }
        .member-item:hover { background-color: #35373c; }
        .member-avatar { width: 32px; height: 32px; border-radius: 50%; background-color: #5865f2; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 14px; position: relative; }
        .status-dot { width: 10px; height: 10px; background-color: #23a55a; border-radius: 50%; position: absolute; bottom: 0; right: 0; border: 2px solid #2b2d31; }
        .member-name { font-size: 14px; color: #f2f3f5; font-weight: 500; }

        /* Discord Kartları & Form Kutuları (Animasyonlu) */
        .discord-card { background-color: #2b2d31; padding: 24px; border-radius: 8px; width: 100%; max-width: 440px; box-shadow: 0 8px 24px rgba(0,0,0,0.4); animation: fadeInScale 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .discord-title { font-size: 22px; font-weight: bold; color: #f2f3f5; text-align: center; margin-bottom: 8px; }
        .discord-subtitle { font-size: 14px; color: #949ba4; text-align: center; margin-bottom: 20px; }
        
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 8px; font-size: 12px; font-weight: bold; color: #b5bac1; text-transform: uppercase; }
        input, textarea, select { width: 100%; padding: 10px 12px; background-color: #1e1f22; border: 1px solid transparent; border-radius: 4px; color: #dbdee1; font-size: 14px; outline: none; transition: border-color 0.2s; }
        input:focus, textarea:focus { border-color: #5865f2; }
        
        .btn-discord { width: 100%; padding: 10px; background-color: #5865f2; border: none; border-radius: 4px; color: #fff; font-weight: 500; font-size: 14px; cursor: pointer; transition: background 0.2s, transform 0.1s; margin-top: 4px; }
        .btn-discord:hover { background-color: #4752c4; }
        .btn-discord:active { transform: scale(0.98); }
        .btn-danger { background-color: #ed4245; }
        .btn-danger:hover { background-color: #c03537; }

        .message { margin-bottom: 16px; padding: 10px; border-radius: 4px; font-size: 13px; text-align: center; animation: fadeIn 0.3s ease; }
        .message.success { background-color: rgba(35, 165, 90, 0.15); color: #23a55a; border: 1px solid #23a55a; }
        .message.error { background-color: rgba(237, 66, 69, 0.15); color: #ed4245; border: 1px solid #ed4245; }

        .auth-switch { text-align: center; margin-top: 16px; font-size: 14px; color: #949ba4; }
        .auth-switch span { color: #00a8fc; cursor: pointer; font-weight: 500; }
        .auth-switch span:hover { text-decoration: underline; }

        .logout-link { display: block; text-align: center; margin-top: 16px; color: #ed4245; font-size: 13px; text-decoration: none; font-weight: 500; }
        .logout-link:hover { text-decoration: underline; }

        /* Animasyonlar */
        @keyframes fadeInScale {
            0% { opacity: 0; transform: scale(0.95); }
            100% { opacity: 1; transform: scale(1); }
        }
        @keyframes fadeIn {
            0% { opacity: 0; transform: translateY(-4px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        @keyframes scaleIn {
            0% { transform: scaleY(0.3); }
            100% { transform: scaleY(1); }
        }
        .section { display: none; width: 100%; justify-content: center; }
        .section.active { display: flex; animation: fadeIn 0.3s ease; }
    </style>
</head>
<body>

    <!-- SOL SUNUCU LİSTESİ -->
    <div class="guilds-sidebar">
        <div class="guild-icon active" title="NeonCord Home">⚡</div>
        <div class="guild-separator"></div>
        <div class="guild-icon" title="Oyun Topluluğu">🎮</div>
        <div class="guild-icon" title="Yazılım Odası">💻</div>
    </div>

    <!-- KANAL LİSTESİ -->
    <div class="channels-sidebar">
        <div class="server-header">
            <span>NeonCord Hub</span>
        </div>
        <div class="channels-list">
            <?php if (!isset($_SESSION['user'])): ?>
                <div class="channel-category">Giriş / Kayıt</div>
                <div class="channel-item active" onclick="switchSection('login', this)"># 🔑-giris-yap</div>
                <div class="channel-item" onclick="switchSection('register', this)"># 📥-kayit-ol</div>
            <?php else: ?>
                <div class="channel-category">Kontrol Paneli</div>
                <div class="channel-item active" onclick="switchSection('server', this)"># 🖥️-sunucu-kur</div>
                <div class="channel-item" onclick="switchSection('event', this)"># 📅-etkinlik-planla</div>
                <div class="channel-item" onclick="switchSection('friend', this)"># 👥-arkadaş-ekle</div>
                <div class="channel-item" onclick="switchSection('block', this)"># 🚫-kullanici-engelle</div>
                <div class="channel-item" onclick="switchSection('report', this)"># ⚠️-sikayet-bildir</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ORTA CHAT & İÇERİK ALANI -->
    <div class="chat-container">
        <div class="chat-header">
            <span># genel-panel</span>
        </div>

        <div class="chat-content">
            <?php if (!empty($message)): ?>
                <div style="position: absolute; top: 16px; width: 400px; z-index: 99;" class="message <?php echo $messageType; ?>"><?php echo $message; ?></div>
            <?php endif; ?>

            <?php if (!isset($_SESSION['user'])): ?>
                <!-- GİRİŞ YAP FORMU -->
                <div id="section-login" class="section active">
                    <div class="discord-card">
                        <div class="discord-title">Tekrar Hoşgeldin!</div>
                        <div class="discord-subtitle">NeonCord hesabına giriş yap ve sohbete başla.</div>
                        <form method="POST">
                            <input type="hidden" name="action" value="login">
                            <div class="form-group">
                                <label>Kullanıcı Adı veya E-posta</label>
                                <input type="text" name="identity" required placeholder="syvex">
                            </div>
                            <div class="form-group">
                                <label>Şifre</label>
                                <input type="password" name="password" required placeholder="••••••••">
                            </div>
                            <div style="margin-bottom: 16px; font-size: 13px; color: #b5bac1; display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="keep_signed" value="1" style="width: 16px; height: 16px; accent-color: #5865f2;"> Oturumu açık tut
                            </div>
                            <button type="submit" class="btn-discord">Giriş Yap</button>
                            <div class="auth-switch">Hesabın yok mu? <span onclick="switchSection('register', document.querySelectorAll('.channel-item')[1])">Kayıt ol</span></div>
                        </form>
                    </div>
                </div>

                <!-- KAYIT OL FORMU -->
                <div id="section-register" class="section">
                    <div class="discord-card">
                        <div class="discord-title">Bir hesap oluştur</div>
                        <div class="discord-subtitle">Hemen üye ol ve tüm ayrıcalıklardan yararlan.</div>
                        <form method="POST">
                            <input type="hidden" name="action" value="register">
                            <div class="form-group">
                                <label>E-Posta</label>
                                <input type="email" name="email" required placeholder="ornek@gmail.com">
                            </div>
                            <div class="form-group">
                                <label>Kullanıcı Adı</label>
                                <input type="text" name="username" required placeholder="kullaniciadi">
                            </div>
                            <div class="form-group">
                                <label>Şifre</label>
                                <input type="password" name="password" required placeholder="••••••••">
                            </div>
                            <button type="submit" class="btn-discord">Devam Et</button>
                            <div class="auth-switch">Zaten hesabın var mı? <span onclick="switchSection('login', document.querySelectorAll('.channel-item')[0])">Giriş yap</span></div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- SUNUCU KUR FORMU -->
                <div id="section-server" class="section active">
                    <div class="discord-card">
                        <div class="discord-title">Sunucu Oluştur</div>
                        <div class="discord-subtitle">Kendi topluluk sunucunu kur ve yönetmeye başla.</div>
                        <form method="POST">
                            <input type="hidden" name="action" value="create_server">
                            <div class="form-group">
                                <label>Sunucu Adı</label>
                                <input type="text" name="server_name" required placeholder="Harika Topluluk">
                            </div>
                            <div class="form-group">
                                <label>Açıklama</label>
                                <textarea name="server_desc" rows="3" placeholder="Sunucunun amacı nedir?"></textarea>
                            </div>
                            <button type="submit" class="btn-discord">Oluştur</button>
                        </form>
                    </div>
                </div>

                <!-- ETKİNLİK PLANLA FORMU -->
                <div id="section-event" class="section">
                    <div class="discord-card">
                        <div class="discord-title">Etkinlik Planla</div>
                        <div class="discord-subtitle">Topluluğun için özel bir etkinlik oluştur.</div>
                        <form method="POST">
                            <input type="hidden" name="action" value="create_event">
                            <div class="form-group">
                                <label>Etkinlik Başlığı</label>
                                <input type="text" name="event_title" required placeholder="CS2 / Valorant Turnuvası">
                            </div>
                            <div class="form-group">
                                <label>Tarih ve Saat</label>
                                <input type="text" name="event_date" required placeholder="28.09.2026 - 21:00">
                            </div>
                            <div class="form-group">
                                <label>Detaylar</label>
                                <textarea name="event_desc" rows="2" placeholder="Kurallar ve ödüller..."></textarea>
                            </div>
                            <button type="submit" class="btn-discord">Etkinliği Başlat</button>
                        </form>
                    </div>
                </div>

                <!-- ARKADAŞ EKLE FORMU -->
                <div id="section-friend" class="section">
                    <div class="discord-card">
                        <div class="discord-title">Arkadaş Ekle</div>
                        <div class="discord-subtitle">Kullanıcı adına göre arkadaşlık isteği gönder.</div>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_friend">
                            <div class="form-group">
                                <label>Kullanıcı Adı</label>
                                <input type="text" name="friend_username" required placeholder="arkadas_adi">
                            </div>
                            <button type="submit" class="btn-discord">İstek Gönder</button>
                        </form>
                    </div>
                </div>

                <!-- KULLANICI ENGELLE FORMU -->
                <div id="section-block" class="section">
                    <div class="discord-card">
                        <div class="discord-title">Kullanıcıyı Engelle</div>
                        <div class="discord-subtitle">İstemediğin kullanıcıların seninle iletişimini kes.</div>
                        <form method="POST">
                            <input type="hidden" name="action" value="block_user">
                            <div class="form-group">
                                <label>Engellenecek Kullanıcı Adı</label>
                                <input type="text" name="blocked_username" required placeholder="istenmeyen_kisi">
                            </div>
                            <button type="submit" class="btn-discord btn-danger">Engelle</button>
                        </form>
                    </div>
                </div>

                <!-- ŞİKAYET BİLDİR FORMU -->
                <div id="section-report" class="section">
                    <div class="discord-card">
                        <div class="discord-title">Şikayet Bildir</div>
                        <div class="discord-subtitle">Kural dışı davranan kullanıcıları veya sunucuları bildir.</div>
                        <form method="POST">
                            <input type="hidden" name="action" value="report_user">
                            <div class="form-group">
                                <label>Şikayet Edilen Kişi / Sunucu</label>
                                <input type="text" name="reported_target" required placeholder="Hedef İsim">
                            </div>
                            <div class="form-group">
                                <label>Şikayet Nedeni</label>
                                <textarea name="reason" rows="2" placeholder="Dolandırıcılık, küfür vb..."></textarea>
                            </div>
                            <button type="submit" class="btn-discord btn-danger">Şikayeti Gönder</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SAĞ ÜYE LİSTESİ -->
    <div class="members-sidebar">
        <div class="member-header">Çevrimiçi — 1</div>
        <div class="member-item">
            <div class="member-avatar">
                <?php echo isset($_SESSION['user']) ? strtoupper(substr($_SESSION['user'], 0, 1)) : 'S'; ?>
                <div class="status-dot"></div>
            </div>
            <div class="member-name"><?php echo isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']) : 'syvex'; ?></div>
        </div>
        <?php if (isset($_SESSION['user'])): ?>
            <a href="?logout=true" class="logout-link">Oturumu Kapat</a>
        <?php endif; ?>
    </div>

    <script>
        function switchSection(sectionId, element) {
            document.querySelectorAll('.section').forEach(sec => sec.classList.remove('active'));
            document.querySelectorAll('.channel-item').forEach(item => item.classList.remove('active'));
            
            const target = document.getElementById('section-' + sectionId);
            if (target) {
                target.classList.add('active');
            }
            if (element) {
                element.classList.add('active');
            }
        }
    </script>
</body>
</html>
