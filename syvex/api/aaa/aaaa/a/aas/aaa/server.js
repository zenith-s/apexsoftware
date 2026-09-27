const express = require('express');
const bodyParser = require('body-parser');
const fs = require('fs');
const path = require('path');
const bcrypt = require('bcryptjs');
const axios = require('axios');

const app = express();
const PORT = process.env.PORT || 3000;
const DB_FILE = path.join(__dirname, 'database.json');

// Senin webhook adresin doğrudan entegre edildi:
const WEBHOOK_URL = 'https://discord.com/api/webhooks/1553741922697216150/nKxNowFM76FYPmF28FewnLRrw0JmBTfTU7pTmVHf2rJQ0iYI3M9FSQj-DENjXz4SwQmP';

app.use(bodyParser.json());
app.use(express.static(path.join(__dirname, 'public'))); // index.html public klasöründe olmalı

// Veritabanı kontrol
if (!fs.existsSync(DB_FILE)) {
    fs.writeFileSync(DB_FILE, JSON.stringify({ users: [], events: [] }, null, 2));
}

// IP Tespiti
function getClientIP(req) {
    return req.headers['x-forwarded-for'] || req.socket.remoteAddress || '127.0.0.1';
}

// Temp Mail Engeli
function isTempMail(email) {
    const tempDomains = ['tempmail.com', '10minutemail.com', 'guerrillamail.com', 'mailinator.com', 'temp-mail.org', 'dispostable.com', 'yopmail.com'];
    const domain = email.split('@')[1]?.toLowerCase();
    return tempDomains.includes(domain);
}

// Kayıt Ol
app.post('/api/register', async (req, res) => {
    try {
        const { email, username, password } = req.body;
        const userIP = getClientIP(req);

        if (!email || !username || !password) {
            return res.json({ success: false, message: 'Tüm alanları doldurunuz.' });
        }
        if (isTempMail(email)) {
            return res.json({ success: false, message: 'Geçici (Temp) e-posta servisleri kabul edilmez!' });
        }

        const db = JSON.parse(fs.readFileSync(DB_FILE));
        
        // IP kontrolü
        const existingIP = db.users.find(u => u.ip === userIP);
        if (existingIP) {
            return res.json({ success: false, message: 'Bu IP adresinden zaten hesap açılmış!' });
        }

        const existingEmail = db.users.find(u => u.email === email.toLowerCase());
        if (existingEmail) {
            return res.json({ success: false, message: 'Bu e-posta zaten kullanımda.' });
        }

        const hashedPassword = await bcrypt.hash(password, 10);
        const newUser = { email: email.toLowerCase(), username, password: hashedPassword, ip: userIP, createdAt: Date.now() };

        db.users.push(newUser);
        fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));

        res.json({ success: true, user: { username, email } });
    } catch (err) {
        res.json({ success: false, message: 'Sunucu hatası oluştu.' });
    }
});

// Giriş Yap
app.post('/api/login', async (req, res) => {
    try {
        const { email, password } = req.body;
        const db = JSON.parse(fs.readFileSync(DB_FILE));
        
        const user = db.users.find(u => u.email === email.toLowerCase());
        if (!user || !(await bcrypt.compare(password, user.password))) {
            return res.json({ success: false, message: 'E-posta veya şifre hatalı!' });
        }

        res.json({ success: true, user: { username: user.username, email: user.email } });
    } catch (err) {
        res.json({ success: false, message: 'Sunucu hatası oluştu.' });
    }
});

// Etkinlik Oluştur & Webhook Gönder
app.post('/api/create_event', async (req, res) => {
    try {
        const { name, description, is_private } = req.body;
        const userIP = getClientIP(req);

        if (!name || !description) {
            return res.json({ success: false, message: 'Etkinlik adı ve açıklaması zorunludur.' });
        }

        const db = JSON.parse(fs.readFileSync(DB_FILE));
        const currentTime = Date.now();

        // 12 saat filtresi
        db.events = db.events.filter(ev => currentTime - ev.createdAt < 12 * 3600 * 1000);

        const existingEvent = db.events.find(ev => ev.ip === userIP);
        if (existingEvent) {
            return res.json({ success: false, message: 'Zaten aktif bir etkinlik oluşturdunuz (12 saatte 1 sınır).' });
        }

        const newEvent = {
            id: Math.random().toString(36.substring(2, 9)),
            name,
            description,
            is_private: is_private ? 1 : 0,
            ip: userIP,
            createdAt: currentTime
        };

        db.events.push(newEvent);
        fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));

        // Webhook Bildirimi
        await axios.post(WEBHOOK_URL, {
            content: '@everyone Yeni Bir Etkinlik / Sunucu Oluşturuldu!',
            embeds: [{
                title: name,
                description: description,
                color: 16766720,
                fields: [
                    { name: 'Gizlilik', value: is_private ? '🔒 Private (Gizli)' : '🌍 Herkese Açık', inline: true },
                    { name: 'Oluşturan IP', value: `\`${userIP}\``, inline: true }
                ],
                timestamp: new Date().toISOString()
            }]
        });

        res.json({ success: true });
    } catch (err) {
        res.json({ success: false, message: 'Webhook veya sunucu hatası.' });
    }
});

// Etkinlikleri Listele
app.get('/api/events', (req, res) => {
    const db = JSON.parse(fs.readFileSync(DB_FILE));
    const currentTime = Date.now();
    
    db.events = db.events.filter(ev => currentTime - ev.createdAt < 12 * 3600 * 1000);
    fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));

    const eventsWithRemaining = db.events.map(ev => {
        const elapsed = currentTime - ev.createdAt;
        const remainingHours = Math.ceil((12 * 3600 * 1000 - elapsed) / (3600 * 1000));
        return { ...ev, remaining_hours: remainingHours };
    });

    res.json({ success: true, events: eventsWithRemaining });
});

app.listen(PORT, () => console.log(`NeonCord sunucusu ${PORT} portunda çalışıyor...`));
