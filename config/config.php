<?php
// ============================================
// KONFIGURASI UTAMA (config.php)
// ============================================

// 1. Deteksi Protokol (HTTP/HTTPS)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// 2. Deteksi Host (Menggunakan SERVER_NAME untuk mencegah Host Header Injection)
$host = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'];

// 3. Deteksi Direktori Script
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);

// Bersihkan path: Hanya hapus '/admin' jika folder saat ini memang bernama 'admin'
// (Mencegah bug jika nama folder proyek mengandung kata 'admin', misal: 'my-admin-blog')
if (basename($scriptDir) === 'admin') {
    $scriptDir = dirname($scriptDir);
}

// Normalisasi separator dan hapus trailing slash
$scriptDir = rtrim(str_replace('\\', '/', $scriptDir), '/');

// ============================================
// OPSI 1: AUTO-DETECT (Paling Aman & Fleksibel)
// ============================================
define('BASE_URL', $protocol . '://' . $host . $scriptDir . '/');

// ============================================
// OPSI 2: MANUAL (Uncomment salah satu jika auto-detect gagal di server tertentu)
// ============================================

// Jika akses via: http://localhost/blog-dosen/
// define('BASE_URL', 'http://localhost/blog-dosen/');

// Jika akses via: http://blog-dosen.test/ (Laragon Pretty URLs)
// define('BASE_URL', 'http://blog-dosen.test/');

// Jika project di root: http://localhost/
// define('BASE_URL', 'http://localhost/');

// ============================================
// PATH UNTUK UPLOAD FILE
// ============================================
// Pastikan folder assets/uploads/ ada dan memiliki permission yang cukup (misal: 755)
define('UPLOAD_PATH', dirname(__DIR__) . '/assets/uploads/');
define('UPLOAD_URL', BASE_URL . 'assets/uploads/');

// ============================================
// KONFIGURASI LINGKUNGAN (ENVIRONMENT)
// ============================================
// Set 'production' saat deploy ke server asli untuk menyembunyikan detail error dari pengguna
define('ENVIRONMENT', 'development'); // Ubah ke 'production' saat website sudah Live

if (ENVIRONMENT === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
    // Sembunyikan pesan error database agar tidak bocor ke publik
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}
?>