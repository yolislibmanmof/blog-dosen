<?php
// ============================================
// 0. ENVIRONMENT LOADER (Paling Awal)
// ============================================
// Memuat variabel dari file .env ke $_ENV
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line || strpos($line, '#') === 0) continue; // Lewati komentar
        if (strpos($line, '=') === false) continue;

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        // Hapus tanda kutip jika ada (misal: "password")
        if (preg_match('/^"(.*)"$/', $value, $m) || preg_match("/^'(.*)'$/", $value, $m)) {
            $value = $m[1];
        }

        $_ENV[$name] = $value;
        putenv("$name=$value");
    }
}

// ============================================
// 1. Deteksi Protokol (HTTP/HTTPS)
// ============================================
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// 2. Deteksi Host (Menggunakan SERVER_NAME untuk mencegah Host Header Injection)
$host = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'];

// 3. Deteksi Direktori Script
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);

// Bersihkan path: Hanya hapus '/admin' jika folder saat ini memang bernama 'admin'
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
// OPSI 2: MANUAL (Uncomment jika auto-detect gagal di server tertentu)
// ============================================
// define('BASE_URL', 'http://localhost/blog-dosen/');
// define('BASE_URL', 'http://blog-dosen.test/');
// define('BASE_URL', 'http://localhost/');

// ============================================
// PATH UNTUK UPLOAD FILE
// ============================================
define('UPLOAD_PATH', dirname(__DIR__) . '/assets/uploads/');
define('UPLOAD_URL', BASE_URL . 'assets/uploads/');

// ============================================
// KONFIGURASI LINGKUNGAN (ENVIRONMENT) & ERROR HANDLER
// ============================================
// Ambil APP_ENV dari file .env, jika tidak ada fallback ke 'development'
define('ENVIRONMENT', $_ENV['APP_ENV'] ?? 'development'); 

$logDir = dirname(__DIR__) . '/storage/logs/';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true); // Otomatis buat folder logs jika belum ada
}
$logFile = $logDir . 'error-' . date('Y-m-d') . '.log';

if (ENVIRONMENT === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', $logFile);
    
    // Tangani Exception Global yang tidak di-catch (Mencegah layar putih/error aneh bocor ke user)
    set_exception_handler(function(Throwable $e) use ($logFile) {
        $errorMsg = sprintf("[%s] Uncaught Exception: %s in %s:%d\n", 
            date('Y-m-d H:i:s'), 
            $e->getMessage(), 
            $e->getFile(), 
            $e->getLine()
        );
        error_log($errorMsg, 3, $logFile);
        
        http_response_code(500);
        echo "<h1>500 - Terjadi Kesalahan Sistem</h1>";
        echo "<p>Mohon maaf, server sedang mengalami kendala. Tim IT telah diberitahu.</p>";
        exit;
    });
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}
?>