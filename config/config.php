<?php
// ============================================
// KONFIGURASI UNTUK LARAGON
// ============================================

// Opsi 1: Auto-detect (paling aman)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);

// Bersihkan path (hapus /admin jika ada di URL admin)
$scriptDir = str_replace('/admin', '', $scriptDir);
$scriptDir = rtrim($scriptDir, '/\\');

define('BASE_URL', $protocol . '://' . $host . $scriptDir . '/');

// Opsi 2: Manual (kalau auto-detect gagal)
// Uncomment salah satu sesuai kondisi:

// Jika akses via: http://localhost/blog-dosen/
// define('BASE_URL', 'http://localhost/blog-dosen/');

// Jika akses via: http://blog-dosen.test/ (Laragon Pretty URLs)
// define('BASE_URL', 'http://blog-dosen.test/');

// Jika project di root: http://localhost/
// define('BASE_URL', 'http://localhost/');

// Path untuk upload
define('UPLOAD_PATH', dirname(__DIR__) . '/assets/uploads/');
define('UPLOAD_URL', BASE_URL . 'assets/uploads/');

// Debug (hapus di production)
// echo "BASE_URL: " . BASE_URL;
?>