const express = require('express');
const path = require('path');
const fs = require('fs');
const app = express();

app.use(express.urlencoded({ extended: true }));
app.use(express.json());

const DB_FILE = path.join(__dirname, 'database.json');

if (!fs.existsSync(DB_FILE)) {
    fs.writeFileSync(DB_FILE, JSON.stringify({ keys: [], clients: [], logs: [] }, null, 2));
}

app.use(express.static(__dirname));

// C++ ve Panel İsteklerini Karşılayan Ana Nokta
app.all(['/api.php', '/api'], (req, res) => {
    const data = { ...req.query, ...req.body };
    const action = data.action;
    const db = JSON.parse(fs.readFileSync(DB_FILE, 'utf8'));

    // Gelen ham isteği loglara kaydet
    const logEntry = {
        time: new Date().toLocaleTimeString(),
        ip: req.ip || req.connection.remoteAddress,
        query: JSON.stringify(data)
    };
    db.logs = db.logs || [];
    db.logs.unshift(logEntry);
    if (db.logs.length > 50) db.logs.pop(); // Son 50 logu tut

    // C++ İstemci Bildirimi (HWID gönderiyorsa)
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

    if (action === 'get_keys') {
        fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));
        return res.json(db.keys);
    }
    
    if (action === 'get_clients') {
        db.clients.forEach(c => {
            if (Date.now() - (c.last_seen || 0) > 30000) {
                c.online = false;
                c.vgk_state = 'offline';
            }
        });
        fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));
        return res.json({ clients: db.clients, logs: db.logs });
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

    fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));
    res.send({ status: 'active' });
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Neon Server aktif, port: ${PORT}`));
