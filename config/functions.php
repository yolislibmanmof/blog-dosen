<?php
// ============================================
// HELPER FUNCTIONS (config/functions.php)
// ============================================

// 1. Pastikan session berjalan (dengan guard anti double-start)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Load konfigurasi utama & koneksi database
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// ❌ Fungsi db() SUDAH DIHAPUS dari sini.
// ✅ Versi final ada di config/database.php (terintegrasi .env + PDO)

// ============================================
// HELPER URL
// ============================================
function url($path = '') {
    $path = ltrim($path, '/');
    return BASE_URL . $path;
}

function asset($path) {
    return url('assets/' . ltrim($path, '/'));
}

// Render URL foto/gambar dengan aman
// Mencegah URL bertumpuk jika nilai di DB sudah berupa URL absolut
function fotoUrl($path, $default = 'assets/uploads/default.png') {
    if (empty($path)) {
        return url($default);
    }
    if (strpos($path, 'http') === 0 || strpos($path, '//') === 0) {
        return $path;
    }
    return url(ltrim($path, '/'));
}

// ============================================
// SETTINGS
// ============================================
function getSettings() {
    static $settings = null;
    if ($settings === null) {
        try {
            $stmt = db()->query("SELECT * FROM settings LIMIT 1");
            $settings = $stmt->fetch();
        } catch (Exception $e) {
            // Fallback jika tabel settings belum ada / DB error
            error_log("getSettings Error: " . $e->getMessage());
            $settings = [
                'nama_kampus'  => 'UNIVERSITAS [NAMA KAMPUS]',
                'quote'        => 'Orang boleh pandai setinggi langit, tapi selama ia tidak menulis, ia akan hilang di dalam masyarakat dan dari sejarah.',
                'quote_author' => 'Pramoedya A. Toer'
            ];
        }
    }
    return $settings;
}

// ============================================
// HELPER TEKS
// ============================================
function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ASCII//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    return $text !== '' ? $text : 'slug-' . time();
}

function excerpt($text, $length = 150) {
    $text = strip_tags($text);
    if (strlen($text) > $length) {
        return substr($text, 0, $length) . '...';
    }
    return $text;
}

function formatDate($date) {
    return date('d F Y', strtotime($date));
}

// ============================================
// AUTH & SESSION
// ============================================
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function checkAuth() {
    if (!isLoggedIn()) {
        header('Location: ' . url('admin/login.php'));
        exit;
    }
}

function isAdmin() {
    return isLoggedIn() && $_SESSION['user_role'] === 'admin';
}

// ============================================
// UPLOAD
// ============================================
function uploadImage($file, $folder = 'articles') {
    $targetDir = UPLOAD_PATH . $folder . '/';

    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        return false;
    }

    $filename = uniqid('img_') . '.' . $ext;
    $targetFile = $targetDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return UPLOAD_URL . $folder . '/' . $filename;
    }
    return false;
}

// ============================================
// FLASH MESSAGE & REDIRECT
// ============================================
function flash($key, $message = null) {
    if ($message) {
        $_SESSION['flash'][$key] = $message;
    } else {
        if (isset($_SESSION['flash'][$key])) {
            $msg = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $msg;
        }
    }
    return null;
}

function redirect($path) {
    header('Location: ' . url($path));
    exit;
}

// ============================================
// CSRF PROTECTION
// ============================================
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}