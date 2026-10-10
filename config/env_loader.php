<?php
// config/env_loader.php

function loadEnv($path) {
    if (!file_exists($path)) {
        die("File .env tidak ditemukan. Silakan duplikat .env.example menjadi .env");
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line || strpos($line, '#') === 0) continue; // Lewati komentar
        if (strpos($line, '=') === false) continue;

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        // Hapus tanda kutip jika ada (misal: "password" atau 'password')
        if (preg_match('/^"(.*)"$/', $value, $m) || preg_match("/^'(.*)'$/", $value, $m)) {
            $value = $m[1];
        }

        $_ENV[$name] = $value;
        putenv("$name=$value");
    }
}

// Panggil fungsi ini, asumsikan config.php ada di folder yang sama
$envPath = dirname(__DIR__) . '/.env'; 
loadEnv($envPath);