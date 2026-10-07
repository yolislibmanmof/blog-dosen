<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

function db() {
    static $conn = null;
    if ($conn === null) {
        $conn = (new Database())->getConnection();
    }
    return $conn;
}

// Helper URL yang lebih robust
function url($path = '') {
    // Hapus slash di awal path kalau ada
    $path = ltrim($path, '/');
    return BASE_URL . $path;
}

// Helper untuk asset (CSS, JS, images)
function asset($path) {
    return url('assets/' . ltrim($path, '/'));
}

// ✅ HELPER GLOBAL: Render URL foto/gambar dengan aman
// Mencegah URL bertumpuk (http://... + http://...) jika nilai di database
// sudah berupa URL absolut hasil dari uploadImage()
function fotoUrl($path, $default = 'assets/uploads/default.png') {
    if (empty($path)) {
        return url($default);
    }
    if (strpos($path, 'http') === 0 || strpos($path, '//') === 0) {
        return $path; // Sudah URL absolut, gunakan apa adanya
    }
    return url(ltrim($path, '/')); // Path relatif, tambahkan BASE_URL
}

function getSettings() {
    static $settings = null;
    if ($settings === null) {
        try {
            $stmt = db()->query("SELECT * FROM settings LIMIT 1");
            $settings = $stmt->fetch();
        } catch (Exception $e) {
            $settings = [
                'nama_kampus' => 'UNIVERSITAS [NAMA KAMPUS]',
                'quote' => 'Orang boleh pandai setinggi langit, tapi selama ia tidak menulis, ia akan hilang di dalam masyarakat dan dari sejarah.',
                'quote_author' => 'Pramoedya A. Toer'
            ];
        }
    }
    return $settings;
}

function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ASCII//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    return $text !== '' ? $text : 'slug-' . time();
}

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

function uploadImage($file, $folder = 'articles') {
    $targetDir = UPLOAD_PATH . $folder . '/';
    
    // Buat folder jika belum ada
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    // Validasi file
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

function formatDate($date) {
    return date('d F Y', strtotime($date));
}

function excerpt($text, $length = 150) {
    $text = strip_tags($text);
    if (strlen($text) > $length) {
        return substr($text, 0, $length) . '...';
    }
    return $text;
}

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

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

session_start();
?>