<?php
// Veritabanı ve Oturum Ayarları
$dbFile = 'neoncord.db'; // SQLite kullanılarak ekstra SQL ayarı derdi ortadan kaldırıldı

try {
    $db = new PDO("sqlite:" . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tabloyu oluştur
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE,
        username TEXT UNIQUE,
        password TEXT
    )");

    // syvex kullanıcısı veritabanında yoksa otomatik ekle (Şifre hash'lenmiş olarak)
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = 'syvex'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $hashedPassword = password_hash('dNjk6vgnnmV47xD', PASSWORD_DEFAULT);
        $insert = $db->prepare("INSERT INTO users (email, username, password) VALUES (?, ?, ?)");
        $insert->execute(['syvex@gmail.com', 'syvex', $hashedPassword]);
    }
} catch (Exception $e) {
    $dbError = "Sunucu bağlantı hatası! api.php dosyasını kontrol et.";
}

$message = "";
$messageType = "";

// Form Gönderildiğinde İşlem Yap
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $keepSigned = isset($_POST['keep_signed']);

    if (isset($dbError)) {
        $message = $dbError;
        $messageType = "error";
    } else {
        if ($action === 'register') {
            // Kayıt Olma İşlemi
            if (empty($email) || empty($username) || empty($password)) {
                $message = "Lütfen tüm alanları doldurun.";
                $messageType = "error";
            } else {
                try {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (email, username, password) VALUES (?, ?, ?)");
                    $stmt->execute([$email, $username, $hashedPassword]);
                    $message = "Kayıt başarılı! Şimdi giriş yapabilirsiniz.";
                    $messageType = "success";
                } catch (Exception $e) {
                    $message = "Bu e-posta veya kullanıcı adı zaten kullanımda.";
                    $messageType = "error";
                }
            }
        } else {
            // Giriş Yapma İşlemi
            $identity = trim($_POST['identity'] ?? '');
            if (empty($identity) || empty($password)) {
                $message = "Lütfen kullanıcı adı/e-posta ve şifrenizi girin.";
                $messageType = "error";
            } else {
                $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$identity, $identity]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password'])) {
                    $message = "Giriş başarılı! Yönlendiriliyorsunuz...";
                    $messageType = "success";
                    
                    // Beni Hatırla (Çerez Ayarla - 30 Gün)
                    if ($keepSigned) {
                        setcookie('neon_user', $user['username'], time() + (86400 * 30), "/");
                    }
                } else {
                    $message = "Kullanıcı adı veya şifre hatalı!";
                    $messageType = "error";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>NeonCord - Güvenli Topluluk ve Etkinlik Platformu</title>
    <style>
        body {
            background-color: #0b0b0b;
            color: #ffffff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background-color: #18191c;
            padding: 30px;
            border-radius: 8px;
            width: 400px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.6);
        }
        .logo {
            text-align: center;
            font-size: 26px;
            font-weight: bold;
            margin-bottom: 5px;
            color: #faa61a;
        }
        .subtitle {
            text-align: center;
            font-size: 12px;
            color: #8e9297;
            margin-bottom: 25px;
        }
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #2f3136;
        }
        .tab {
            flex: 1;
            text-align: center;
            padding: 10px;
            cursor: pointer;
            color: #8e9297;
            font-weight: bold;
            border-bottom: 2px solid transparent;
        }
        .tab.active {
            color: #fff;
            border-bottom-color: #faa61a;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-size: 12px;
            text-transform: uppercase;
            font-weight: bold;
            color: #b9bbbe;
        }
        input[type="text"], input[type="password"], input[type="email"] {
            width: 100%;
            padding: 10px;
            background-color: #2f3136;
            border: 1px solid #202225;
            border-radius: 4px;
            color: #fff;
            box-sizing: border-box;
            outline: none;
        }
        input:focus {
            border-color: #faa61a;
        }
        .checkbox-container {
            display: flex;
            align-items: center;
            margin: 15px 0;
            font-size: 13px;
            color: #b9bbbe;
            cursor: pointer;
        }
        .checkbox-container input {
            margin-right: 8px;
            accent-color: #faa61a;
            cursor: pointer;
        }
        .btn {
            width: 100%;
            padding: 12px;
            background-color: #faa61a;
            border: none;
            border-radius: 4px;
            color: #fff;
            font-weight: bold;
            cursor: pointer;
            font-size: 15px;
            transition: background 0.2s;
        }
        .btn:hover {
            background-color: #e59415;
        }
        .message {
            margin-top: 15px;
            padding: 10px;
            border-radius: 4px;
            font-size: 13px;
            text-align: center;
        }
        .message.success {
            background-color: #3ba55d;
            color: white;
        }
        .message.error {
            background-color: #ed4245;
            color: white;
        }
        .form-section {
            display: none;
        }
        .form-section.active {
            display: block;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="logo">⚡ NeonCord</div>
    <div class="subtitle">Güvenli Topluluk ve Etkinlik Platformu</div>

    <!-- Sekmeler (Giriş Yap / Kayıt Ol) -->
    <div class="tabs">
        <div class="tab active" onclick="switchTab('login')">Giriş Yap</div>
        <div class="tab" onclick="switchTab('register')">Kayıt Ol</div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="message <?php echo $messageType; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <!-- Giriş Formu -->
    <form id="loginForm" class="form-section active" method="POST">
        <input type="hidden" name="action" value="login">
        <div class="form-group">
            <label>Kullanıcı Adı veya E-Posta</label>
            <input type="text" name="identity" required placeholder="syvex">
        </div>
        
        <div class="form-group">
            <label>Şifre</label>
            <input type="password" name="password" required placeholder="dNjk6vgnnmV47xD">
        </div>

        <div class="checkbox-container">
            <input type="checkbox" id="keepSignedLogin" name="keep_signed" value="1">
            <label for="keepSignedLogin" style="margin-bottom:0; color:#b9bbbe; display:inline; text-transform:none; font-weight:normal;">Oturumu açık tut (Beni Hatırla)</label>
        </div>

        <button type="submit" class="btn">Giriş Yap</button>
    </form>

    <!-- Kayıt Ol Formu -->
    <form id="registerForm" class="form-section" method="POST">
        <input type="hidden" name="action" value="register">
        <div class="form-group">
            <label>E-Posta Adresi</label>
            <input type="email" name="email" required placeholder="ornek@gmail.com">
        </div>

        <div class="form-group">
            <label>Kullanıcı Adı</label>
            <input type="text" name="username" required placeholder="kullaniciadi">
        </div>
        
        <div class="form-group">
            <label>Şifre</label>
            <input type="password" name="password" required placeholder="••••••••••••">
        </div>

        <div class="checkbox-container">
            <input type="checkbox" id="keepSignedReg" name="keep_signed" value="1">
            <label for="keepSignedReg" style="margin-bottom:0; color:#b9bbbe; display:inline; text-transform:none; font-weight:normal;">Oturumu açık tut (Beni Hatırla)</label>
        </div>

        <button type="submit" class="btn">Hemen Kayıt Ol</button>
    </form>
</div>

<script>
    function switchTab(tabName) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.form-section').forEach(f => f.classList.remove('active'));

        if (tabName === 'login') {
            document.querySelectorAll('.tab')[0].classList.add('active');
            document.getElementById('loginForm').classList.add('active');
        } else {
            document.querySelectorAll('.tab')[1].classList.add('active');
            document.getElementById('registerForm').classList.add('active');
        }
    }
</script>

</body>
</html>
