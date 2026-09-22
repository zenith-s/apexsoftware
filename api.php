<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

try {
    // Aynı dizinde neon.db dosyası oluşturur
    $pdo = new PDO('sqlite:neon.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// Tabloları otomatik oluşturan kısım (Daha önce verdiğimiz SQLite kodları)
$pdo->exec("CREATE TABLE IF NOT EXISTS licenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    license_key TEXT NOT NULL UNIQUE,
    hwid TEXT DEFAULT NULL,
    last_ip TEXT DEFAULT NULL,
    expiry_date DATETIME DEFAULT NULL,
    status TEXT DEFAULT 'unused',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS hwid_bans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hwid TEXT NOT NULL UNIQUE,
    date DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS ip_bans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL UNIQUE,
    date DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip TEXT,
    action TEXT,
    status TEXT,
    details TEXT
)");
