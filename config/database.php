<?php
// ============================================
// KONEKSI DATABASE (config/database.php)
// ============================================
// Catatan: Pastikan file yang memanggil fungsi db() 
// sudah me-require 'config/config.php' sebelumnya.

function db() {
    static $pdo = null;
    
    if ($pdo === null) {
        // Ambil kredensial dari Environment Variables (.env)
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $dbname = $_ENV['DB_NAME'] ?? 'blog_dosen';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? '';

        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        
        // Konfigurasi PDO untuk keamanan dan performa maksimal
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lempar exception jika error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Return array asosiatif
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Gunakan prepared statements asli dari MySQL
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // 1. Catat error detail ke log server (aman dari user)
            error_log("Database Connection Failed: " . $e->getMessage());
            
            // 2. Tampilkan pesan ramah ke user (mencegah bocornya struktur DB)
            die("Koneksi database gagal. Silakan hubungi administrator.");
        }
    }
    return $pdo;
}