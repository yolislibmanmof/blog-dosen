<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

function db() {
    return (new Database())->getConnection();
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
    return strtolower($text) . '-' . time();
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

session_start();
?>