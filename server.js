const express = require('express');
const path = require('path');
const fs = require('fs');
const app = express();

app.use(express.urlencoded({ extended: true }));
app.use(express.json());

const DB_FILE = path.join(__dirname, 'database.json');

// Veritabanı dosyası yoksa oluştur
if (!fs.existsSync(DB_FILE)) {
    fs.writeFileSync(DB_FILE, JSON.stringify({ keys: [], clients: [] }, null, 2));
}

// Statik dosyaları sun (index.html ana dizinde olsun)
app.use(express.static(__dirname));

// --- C++'DAN GELEN İSTEKLER (api.php yerine bu çalışacak) ---
app.all('/api.php', (req, res) => {
    const data = { ...req.query, ...req.body };
    const action = data.action;
    const db = JSON.parse(fs.readFileSync(DB_FILE, 'utf8'));

    // C++ istemci bildirimi (HWID gönderiyorsa)
    if (data.hwid && !action) {
        let client = db.clients.find(c => c.hwid === data.hwid);
        if (client) {
            client.pc_name = data.pc_name || client.pc_name;
            client.pid = parseInt(data.pid) || client.pid;
            client.vgk_state = 'active';
            client.online = true;
            client.last_seen = Date.now();
        } else {
            db.clients.push({
                pc_name: data.pc_name || 'Bilinmeyen PC',
                hwid: data.hwid,
                pid: parseInt(data.pid) || 0,
                vgk_state: 'active',
                online: true,
                banned: false,
                last_seen: Date.now()
            });
        }
        fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));
        return res.send("SUCCESS");
    }

    // Panel İşlemleri (GET / POST)
    if (action === 'get_keys') {
        return res.json(db.keys);
    }
    
    if (action === 'get_clients') {
        db.clients.forEach(c => {
            if (Date.now() - (c.last_seen || 0) > 30000) {
                c.online = false;
                c.vgk_state = 'offline';
            }
        });
        return res.json(db.clients);
    }

    if (action === 'generate') {
        const newKey = 'NEON-' + Math.random().toString(36).substring(2, 6).toUpperCase() + '-' + Math.random().toString(36).substring(2, 6).toUpperCase();
        db.keys.push({
            key: newKey,
            note: data.note || 'VIP',
            hwid: '',
            duration_days: parseInt(data.days) || 30,
            expires_at: 0,
            expired: false
        });
        fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));
        return res.json({ key: newKey });
    }

    if (action === 'delete_key') {
        db.keys = db.keys.filter(k => k.key !== data.key);
        fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));
        return res.json({ status: 'ok' });
    }

    if (action === 'send_cmd') {
        let client = db.clients.find(c => c.hwid === data.hwid);
        if (client) {
            if (data.cmd === 'close') {
                client.banned = true;
                client.vgk_state = 'offline';
            } else {
                client.banned = false;
                client.vgk_state = 'active';
            }
            fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));
        }
        return res.json({ status: 'ok' });
    }

    res.send({ status: 'active' });
});

const PORT = process.code?.env?.PORT || 3000;
app.listen(PORT, () => console.log(`Neon Server aktif, port: ${PORT}`));
