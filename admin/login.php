<?php
require_once __DIR__ . '/../config/functions.php';

// ============================================
// 🔒 REDIRECT JIKA SUDAH LOGIN
// ============================================
if (isLoggedIn()) {
    header('Location: ' . url('admin/'));
    exit;
}

// ============================================
// 🛡️ SECURITY: Rate Limiting Setup
// ============================================
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

// Bersihkan attempts lama (>15 menit)
$cutoff = time() - (15 * 60);
$_SESSION['login_attempts'] = array_filter($_SESSION['login_attempts'], function($t) use ($cutoff) {
    return $t > $cutoff;
});

$attemptsCount = count($_SESSION['login_attempts']);
$maxAttempts = 5;
$isLockedOut = $attemptsCount >= $maxAttempts;
$lockoutRemaining = 0;

if ($isLockedOut) {
    $oldestAttempt = min($_SESSION['login_attempts']);
    $lockoutRemaining = ($oldestAttempt + (15 * 60)) - time();
}

// ============================================
// 🔐 HANDLE LOGIN POST
// ============================================
$loginError = '';
$loginSuccess = false;
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot check (anti-bot)
    if (!empty($_POST['website_url'])) {
        // Bot detected! Silent fail
        sleep(2);
        $loginError = 'Deteksi aktivitas mencurigakan.';
    }
    // Rate limit check
    elseif ($isLockedOut) {
        $loginError = "Terlalu banyak percobaan login. Coba lagi dalam " . ceil($lockoutRemaining / 60) . " menit.";
    }
    else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']) && $_POST['remember'] === '1';
        
        $emailValue = $email;
        
        // Validasi input
        if (empty($email) || empty($password)) {
            $loginError = 'Email dan password wajib diisi!';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $loginError = 'Format email tidak valid!';
        } else {
            try {
                $stmt = db()->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password'])) {
                    // ✅ LOGIN BERHASIL
                    
                    // Regenerate session ID untuk security
                    session_regenerate_id(true);
                    
                    // Set session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['nama'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['login_time'] = date('Y-m-d H:i:s');
                    $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                    $_SESSION['login_ua'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                    
                    // Clear attempts
                    $_SESSION['login_attempts'] = [];
                    
                    // Update last login di database
                    try {
                        db()->prepare("UPDATE users SET last_login = NOW(), updated_at = NOW() WHERE id = ?")
                          ->execute([$user['id']]);
                    } catch (Exception $e) {}
                    
                    // Remember me (30 hari)
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        $expires = time() + (30 * 24 * 60 * 60); // 30 hari
                        
                        // Simpan token di database
                        try {
                            db()->prepare("
                                INSERT INTO remember_tokens (user_id, token, expires_at, created_at) 
                                VALUES (?, ?, FROM_UNIXTIME(?), NOW())
                            ")->execute([$user['id'], hash('sha256', $token), $expires]);
                        } catch (Exception $e) {
                            // Table mungkin belum ada, skip
                        }
                        
                        setcookie('remember_token', $token, $expires, '/', '', false, true);
                    }
                    
                    $loginSuccess = true;
                    flash('success', "👋 Selamat datang kembali, {$user['nama']}!");
                    
                    // Redirect setelah delay
                    header('Refresh: 1.5; url=' . url('admin/'));
                    exit;
                    
                } else {
                    // ❌ LOGIN GAGAL
                    $_SESSION['login_attempts'][] = time();
                    $attemptsCount = count($_SESSION['login_attempts']);
                    $remaining = $maxAttempts - $attemptsCount;
                    
                    if ($remaining > 0) {
                        $loginError = "Email atau password salah! Sisa $remaining percobaan sebelum akun dikunci sementara.";
                    } else {
                        $loginError = "Terlalu banyak percobaan gagal. Akun dikunci selama 15 menit.";
                    }
                    
                    // Log failed attempt (optional)
                    error_log("Failed login attempt for email: $email from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
                }
            } catch (Exception $e) {
                $loginError = 'Terjadi kesalahan sistem. Silakan coba lagi.';
            }
        }
    }
}

// ============================================
// 🍪 AUTO-LOGIN VIA REMEMBER ME COOKIE
// ============================================
if (!isLoggedIn() && isset($_COOKIE['remember_token'])) {
    try {
        $hashedToken = hash('sha256', $_COOKIE['remember_token']);
        $stmt = db()->prepare("
            SELECT u.* FROM remember_tokens rt
            JOIN users u ON rt.user_id = u.id
            WHERE rt.token = ? AND rt.expires_at > NOW() AND u.status = 'active'
        ");
        $stmt->execute([$hashedToken]);
        $user = $stmt->fetch();
        
        if ($user) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['nama'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['login_time'] = date('Y-m-d H:i:s');
            flash('success', "👋 Selamat datang kembali, {$user['nama']}!");
            header('Location: ' . url('admin/'));
            exit;
        } else {
            // Invalid/expired token, hapus cookie
            setcookie('remember_token', '', time() - 3600, '/', '', false, true);
        }
    } catch (Exception $e) {
        // Table tidak ada, skip
    }
}

$pageTitle = 'Login Dosen';
$hasRememberTable = false;
try {
    db()->query("SELECT 1 FROM remember_tokens LIMIT 1");
    $hasRememberTable = true;
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Blog Dosen</title>
    
    <link rel="stylesheet" href="<?php echo url('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            background: #0f172a;
            overflow-x: hidden;
            position: relative;
        }
        
        /* ===== ANIMATED BACKGROUND ===== */
        .login-bg {
            position: fixed;
            inset: 0;
            background: 
                radial-gradient(circle at 20% 30%, rgba(243, 156, 18, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(52, 152, 219, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 50% 100%, rgba(155, 89, 182, 0.1) 0%, transparent 50%),
                linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            z-index: 0;
        }
        
        .login-bg::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: 
                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: gridMove 20s linear infinite;
        }
        
        @keyframes gridMove {
            from { transform: translate(0, 0); }
            to { transform: translate(50px, 50px); }
        }
        
        /* Floating orbs */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.5;
            animation: floatOrb 20s ease-in-out infinite;
        }
        .orb-1 {
            width: 300px; height: 300px;
            background: #f39c12;
            top: 10%; left: 10%;
            animation-delay: 0s;
        }
        .orb-2 {
            width: 400px; height: 400px;
            background: #3498db;
            bottom: 10%; right: 10%;
            animation-delay: -7s;
        }
        .orb-3 {
            width: 250px; height: 250px;
            background: #9b59b6;
            top: 50%; left: 50%;
            animation-delay: -14s;
        }
        
        @keyframes floatOrb {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 30px) scale(0.9); }
        }
        
        /* ===== MAIN CONTAINER ===== */
        .login-wrapper {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        
        .login-card {
            display: grid;
            grid-template-columns: 1fr 1fr;
            max-width: 1100px;
            width: 100%;
            background: rgba(255, 255, 255, 0.98);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5);
            animation: cardAppear 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            backdrop-filter: blur(20px);
        }
        
        @keyframes cardAppear {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* ===== LEFT PANEL (HERO) ===== */
        .login-hero {
            background: linear-gradient(135deg, #1e3a5f 0%, #2c5f8d 50%, #1e3a5f 100%);
            color: white;
            padding: 3rem;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .login-hero::before {
            content: '';
            position: absolute;
            top: -50%; right: -20%;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(243, 156, 18, 0.3), transparent 70%);
            border-radius: 50%;
            animation: pulseGlow 4s ease-in-out infinite;
        }
        
        .login-hero::after {
            content: '';
            position: absolute;
            bottom: -30%; left: -20%;
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(52, 152, 219, 0.2), transparent 70%);
            border-radius: 50%;
        }
        
        @keyframes pulseGlow {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.2); opacity: 0.8; }
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
        }
        
        .hero-brand {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 3rem;
        }
        .hero-brand-icon {
            width: 50px; height: 50px;
            background: linear-gradient(135deg, #f39c12, #e67e22);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 8px 20px rgba(243, 156, 18, 0.4);
        }
        .hero-brand-text {
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .hero-brand-text small {
            display: block;
            font-size: 0.7rem;
            opacity: 0.7;
            font-weight: 400;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 0.1rem;
        }
        
        .hero-title {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 1rem;
            letter-spacing: -1px;
        }
        .hero-title .highlight {
            background: linear-gradient(135deg, #f39c12, #f1c40f);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .hero-subtitle {
            font-size: 1rem;
            opacity: 0.85;
            line-height: 1.6;
            margin-bottom: 2.5rem;
        }
        
        .hero-features {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .hero-features li {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.6rem 0;
            font-size: 0.9rem;
            opacity: 0.95;
        }
        .hero-features li i {
            width: 28px; height: 28px;
            background: rgba(243, 156, 18, 0.2);
            color: #f1c40f;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            flex-shrink: 0;
        }
        
        .hero-footer {
            position: relative;
            z-index: 2;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 0.82rem;
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .hero-footer i { color: #f39c12; }
        
        /* ===== RIGHT PANEL (FORM) ===== */
        .login-form-panel {
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .form-header {
            margin-bottom: 2rem;
        }
        .form-header h2 {
            color: #1e3a5f;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.4rem;
            letter-spacing: -0.5px;
        }
        .form-header p {
            color: #666;
            font-size: 0.92rem;
        }
        
        /* ===== ALERT MESSAGES ===== */
        .alert-box {
            padding: 0.9rem 1.1rem;
            border-radius: 10px;
            margin-bottom: 1.2rem;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            animation: slideInRight 0.3s ease;
            border-left: 4px solid;
        }
        .alert-box.error {
            background: #fef2f2;
            color: #991b1b;
            border-left-color: #dc2626;
        }
        .alert-box.success {
            background: #f0fdf4;
            color: #166534;
            border-left-color: #16a34a;
        }
        .alert-box.warning {
            background: #fffbeb;
            color: #92400e;
            border-left-color: #f59e0b;
        }
        .alert-box i { font-size: 1.1rem; }
        
        @keyframes slideInRight {
            from { transform: translateX(20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* ===== FORM FIELDS ===== */
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 1.1rem;
        }
        
        .field-group {
            position: relative;
        }
        .field-group label {
            display: block;
            color: #1e3a5f;
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.4rem;
        }
        
        .input-wrap {
            position: relative;
        }
        .input-wrap i.input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.95rem;
            transition: color 0.2s ease;
            pointer-events: none;
        }
        .input-wrap input {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 2.8rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: inherit;
            outline: none;
            transition: all 0.2s ease;
            background: white;
            color: #1e293b;
        }
        .input-wrap input:focus {
            border-color: #f39c12;
            box-shadow: 0 0 0 4px rgba(243, 156, 18, 0.1);
        }
        .input-wrap input:focus + i.input-icon,
        .input-wrap input:focus ~ i.input-icon {
            color: #f39c12;
        }
        .input-wrap input::placeholder { color: #cbd5e1; }
        
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0.3rem;
            font-size: 0.95rem;
            transition: color 0.2s ease;
        }
        .password-toggle:hover { color: #1e3a5f; }
        
        .input-wrap input[type="password"] {
            padding-right: 2.8rem;
        }
        
        /* ===== OPTIONS ROW ===== */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.3rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            font-size: 0.85rem;
            color: #475569;
            user-select: none;
        }
        .remember-me input[type="checkbox"] {
            width: 18px; height: 18px;
            accent-color: #f39c12;
            cursor: pointer;
        }
        
        .forgot-link {
            color: #f39c12;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: color 0.2s ease;
        }
        .forgot-link:hover {
            color: #e67e22;
            text-decoration: underline;
        }
        
        /* ===== SUBMIT BUTTON ===== */
        .btn-login-ultimate {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
            color: white;
            border: none;
            padding: 0.95rem;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            box-shadow: 0 8px 20px rgba(243, 156, 18, 0.3);
            font-family: inherit;
            margin-top: 0.5rem;
            position: relative;
            overflow: hidden;
        }
        .btn-login-ultimate::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, #e67e22, #d35400);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .btn-login-ultimate:hover:not(:disabled)::before {
            opacity: 1;
        }
        .btn-login-ultimate:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(243, 156, 18, 0.4);
        }
        .btn-login-ultimate:active:not(:disabled) {
            transform: translateY(0);
        }
        .btn-login-ultimate:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        .btn-login-ultimate > * {
            position: relative;
            z-index: 1;
        }
        .btn-login-ultimate .spinner {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* ===== DIVIDER ===== */
        .form-divider {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin: 1.5rem 0 1rem;
            color: #94a3b8;
            font-size: 0.82rem;
        }
        .form-divider::before,
        .form-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        
        /* ===== BACK LINK ===== */
        .back-home {
            text-align: center;
            margin-top: 1rem;
        }
        .back-home a {
            color: #64748b;
            text-decoration: none;
            font-size: 0.88rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: color 0.2s ease;
        }
        .back-home a:hover { color: #1e3a5f; }
        
        /* ===== SECURITY NOTICE ===== */
        .security-notice {
            margin-top: 1.5rem;
            padding: 0.8rem 1rem;
            background: #f8fafc;
            border-radius: 10px;
            font-size: 0.78rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            border: 1px solid #e2e8f0;
        }
        .security-notice i {
            color: #16a34a;
            font-size: 1rem;
        }
        
        /* ===== LOCKOUT MESSAGE ===== */
        .lockout-box {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border: 2px solid #fecaca;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .lockout-box i {
            font-size: 2.5rem;
            color: #dc2626;
            margin-bottom: 0.8rem;
            display: block;
        }
        .lockout-box h4 {
            color: #991b1b;
            font-size: 1.1rem;
            margin-bottom: 0.4rem;
        }
        .lockout-box p {
            color: #7f1d1d;
            font-size: 0.88rem;
            margin-bottom: 0.8rem;
        }
        .lockout-timer {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 700;
            color: #dc2626;
            font-size: 0.95rem;
        }
        
        /* ===== SUCCESS OVERLAY ===== */
        .success-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
            backdrop-filter: blur(10px);
        }
        .success-content {
            text-align: center;
            color: white;
            animation: bounceIn 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        .success-icon {
            width: 100px; height: 100px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
            margin: 0 auto 1.5rem;
            box-shadow: 0 20px 50px rgba(16, 185, 129, 0.4);
        }
        .success-content h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        .success-content p {
            opacity: 0.8;
            margin-bottom: 1rem;
        }
        .redirect-text {
            font-size: 0.88rem;
            opacity: 0.6;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes bounceIn {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); opacity: 1; }
        }
        
        /* ===== HONEYPOT ===== */
        .hp-field {
            position: absolute;
            left: -9999px;
            opacity: 0;
            height: 0;
            width: 0;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 968px) {
            .login-card {
                grid-template-columns: 1fr;
                max-width: 500px;
            }
            .login-hero {
                padding: 2rem;
            }
            .hero-title { font-size: 2rem; }
            .hero-features { display: none; }
            .login-form-panel { padding: 2rem; }
        }
        @media (max-width: 480px) {
            .login-hero { padding: 1.5rem; }
            .hero-title { font-size: 1.6rem; }
            .hero-brand-text { font-size: 1.1rem; }
            .login-form-panel { padding: 1.5rem; }
            .form-header h2 { font-size: 1.4rem; }
        }
        
        /* ===== FLASH MESSAGES (global) ===== */
        .global-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            max-width: 380px;
            padding: 1rem 1.3rem;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 0.8rem;
            animation: slideInRight 0.4s ease;
        }
        .global-alert.success {
            background: #10b981;
            color: white;
        }
        .global-alert.error {
            background: #ef4444;
            color: white;
        }
    </style>
</head>
<body>

<!-- Animated Background -->
<div class="login-bg">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
</div>

<!-- Global Flash Messages -->
<?php if ($msg = flash('success')): ?>
    <div class="global-alert success">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($msg); ?></span>
    </div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="global-alert error">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($msg); ?></span>
    </div>
<?php endif; ?>

<!-- Success Overlay (setelah login berhasil) -->
<?php if ($loginSuccess): ?>
    <div class="success-overlay">
        <div class="success-content">
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
            <h2>Login Berhasil! 🎉</h2>
            <p>Mengalihkan ke dashboard...</p>
            <div class="redirect-text">
                <i class="fas fa-spinner spinner"></i>
                Mohon tunggu sebentar
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Main Login Wrapper -->
<div class="login-wrapper">
    <div class="login-card">
        
        <!-- ===== LEFT: HERO PANEL ===== -->
        <div class="login-hero">
            <div class="hero-content">
                <div class="hero-brand">
                    <div class="hero-brand-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="hero-brand-text">
                        Blog Dosen
                        <small>Admin Panel</small>
                    </div>
                </div>
                
                <h1 class="hero-title">
                    Selamat Datang di<br>
                    <span class="highlight">Portal Akademik</span>
                </h1>
                <p class="hero-subtitle">
                    Platform eksklusif bagi para dosen untuk berbagi ilmu, 
                    penelitian, dan pengalaman melalui tulisan berkualitas.
                </p>
                
                <ul class="hero-features">
                    <li>
                        <i class="fas fa-check"></i>
                        <span>Editor CKEditor 5 dengan auto-save</span>
                    </li>
                    <li>
                        <i class="fas fa-check"></i>
                        <span>Dashboard statistik lengkap & real-time</span>
                    </li>
                    <li>
                        <i class="fas fa-check"></i>
                        <span>Kelola artikel, kategori & komentar</span>
                    </li>
                    <li>
                        <i class="fas fa-check"></i>
                        <span>SEO tools & analytics terintegrasi</span>
                    </li>
                    <li>
                        <i class="fas fa-check"></i>
                        <span>Dark mode & responsive design</span>
                    </li>
                </ul>
            </div>
            
            <div class="hero-footer">
                <i class="fas fa-shield-alt"></i>
                <span>Dilindungi dengan enkripsi & rate limiting</span>
            </div>
        </div>

        <!-- ===== RIGHT: FORM PANEL ===== -->
        <div class="login-form-panel">
            <div class="form-header">
                <h2>Masuk ke Akun Anda 👋</h2>
                <p>Silakan masukkan kredensial untuk melanjutkan</p>
            </div>

            <?php if ($isLockedOut): ?>
                <!-- LOCKOUT STATE -->
                <div class="lockout-box">
                    <i class="fas fa-lock"></i>
                    <h4>Akun Terkunci Sementara</h4>
                    <p>Terlalu banyak percobaan login gagal. Demi keamanan, akun dikunci selama 15 menit.</p>
                    <div class="lockout-timer" id="lockoutTimer">
                        <i class="fas fa-clock"></i>
                        <span id="lockoutTime"><?php echo ceil($lockoutRemaining / 60); ?>:00</span>
                    </div>
                </div>
                
                <div class="back-home">
                    <a href="<?php echo url(); ?>">
                        <i class="fas fa-arrow-left"></i> Kembali ke Beranda
                    </a>
                </div>
                
            <?php else: ?>
                <!-- ERROR MESSAGE -->
                <?php if ($loginError): ?>
                    <div class="alert-box error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo htmlspecialchars($loginError); ?></span>
                    </div>
                <?php endif; ?>

                <!-- ATTEMPTS WARNING -->
                <?php if ($attemptsCount >= 3 && $attemptsCount < $maxAttempts): ?>
                    <div class="alert-box warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Peringatan: <?php echo $attemptsCount; ?> percobaan gagal terdeteksi. Sisa <?php echo $maxAttempts - $attemptsCount; ?> percobaan.</span>
                    </div>
                <?php endif; ?>

                <!-- LOGIN FORM -->
                <form method="POST" class="login-form" id="loginForm" autocomplete="on">
                    
                    <!-- Honeypot (anti-bot) -->
                    <div class="hp-field" aria-hidden="true">
                        <label>Website</label>
                        <input type="text" name="website_url" tabindex="-1" autocomplete="off">
                    </div>

                    <!-- Email -->
                    <div class="field-group">
                        <label for="email">Email Address</label>
                        <div class="input-wrap">
                            <input type="email" 
                                   id="email" 
                                   name="email" 
                                   required 
                                   placeholder="nama@kampus.ac.id"
                                   value="<?php echo htmlspecialchars($emailValue); ?>"
                                   autocomplete="email"
                                   autofocus>
                            <i class="fas fa-envelope input-icon"></i>
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="field-group">
                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   required 
                                   placeholder="Masukkan password Anda"
                                   autocomplete="current-password">
                            <i class="fas fa-lock input-icon"></i>
                            <button type="button" class="password-toggle" onclick="togglePassword()" title="Lihat password">
                                <i class="fas fa-eye" id="passwordToggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Options -->
                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember" value="1">
                            <span>Ingat saya selama 30 hari</span>
                        </label>
                        <a href="#" class="forgot-link" onclick="showForgotInfo(event)">
                            Lupa password?
                        </a>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="btn-login-ultimate" id="submitBtn">
                        <i class="fas fa-sign-in-alt"></i>
                        <span>Masuk ke Dashboard</span>
                    </button>

                    <!-- Divider -->
                    <div class="form-divider">
                        <span>Atau</span>
                    </div>

                    <!-- Back Link -->
                    <div class="back-home">
                        <a href="<?php echo url(); ?>">
                            <i class="fas fa-arrow-left"></i> Kembali ke Beranda
                        </a>
                    </div>

                    <!-- Security Notice -->
                    <div class="security-notice">
                        <i class="fas fa-shield-alt"></i>
                        <span>
                            Koneksi Anda aman. Login dilindungi dengan rate limiting & session encryption.
                        </span>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    // ===== PASSWORD TOGGLE =====
    window.togglePassword = function() {
        var input = document.getElementById('password');
        var icon = document.getElementById('passwordToggleIcon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'fas fa-eye';
        }
    };

    // ===== FORM SUBMIT LOADING =====
    var form = document.getElementById('loginForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            var btn = document.getElementById('submitBtn');
            var email = document.getElementById('email').value.trim();
            var password = document.getElementById('password').value;
            
            if (!email || !password) {
                e.preventDefault();
                return;
            }
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner spinner"></i> <span>Memverifikasi...</span>';
        });
    }

    // ===== LOCKOUT TIMER =====
    <?php if ($isLockedOut): ?>
    var remaining = <?php echo $lockoutRemaining; ?>;
    var timerEl = document.getElementById('lockoutTime');
    
    function updateTimer() {
        if (remaining <= 0) {
            window.location.reload();
            return;
        }
        var min = Math.floor(remaining / 60);
        var sec = remaining % 60;
        timerEl.textContent = min + ':' + (sec < 10 ? '0' : '') + sec;
        remaining--;
    }
    
    updateTimer();
    setInterval(updateTimer, 1000);
    <?php endif; ?>

    // ===== FORGOT PASSWORD INFO =====
    window.showForgotInfo = function(e) {
        e.preventDefault();
        var msg = '🔐 Lupa Password?\n\n' +
                  'Untuk reset password, silakan hubungi administrator sistem:\n\n' +
                  '📧 Email: admin@kampus.ac.id\n' +
                  '📞 Telepon: (021) 1234-5678\n\n' +
                  'Sertakan NIP/NIDN dan email terdaftar Anda untuk verifikasi identitas.';
        alert(msg);
    };

    // ===== AUTO-DISMISS ALERTS =====
    var alerts = document.querySelectorAll('.global-alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.animation = 'slideInRight 0.3s ease reverse forwards';
            setTimeout(function() { alert.remove(); }, 300);
        }, 4000);
    });

    // ===== CAPS LOCK WARNING =====
    var passwordInput = document.getElementById('password');
    if (passwordInput) {
        passwordInput.addEventListener('keypress', function(e) {
            if (e.getModifierState && e.getModifierState('CapsLock')) {
                // Bisa ditambahkan warning caps lock di sini
            }
        });
    }

    // ===== KEYBOARD SHORTCUT: Enter to Submit =====
    document.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'BUTTON' && form) {
            form.dispatchEvent(new Event('submit'));
        }
    });

    // ===== FOCUS FIRST INPUT =====
    var emailInput = document.getElementById('email');
    if (emailInput && !emailInput.value) {
        setTimeout(function() { emailInput.focus(); }, 100);
    } else {
        var pwdInput = document.getElementById('password');
        if (pwdInput) setTimeout(function() { pwdInput.focus(); }, 100);
    }

    console.log('%c🔐 Login Page Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
    console.log('%cSecurity: Rate limiting | Honeypot | Session encryption', 'font-size:11px;color:#888;');
})();
</script>
</body>
</html>