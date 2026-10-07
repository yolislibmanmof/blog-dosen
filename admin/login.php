<?php
require_once __DIR__ . '/../config/functions.php';

if (isLoggedIn()) {
    header('Location: ' . url('admin/'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    $stmt = db()->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['nama'];
        $_SESSION['user_role'] = $user['role'];
        flash('success', "Selamat datang, {$user['nama']}!");
        header('Location: ' . url('admin/'));
        exit;
    } else {
        flash('error', 'Email atau password salah!');
    }
}

$pageTitle = 'Login Dosen';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login - Blog Dosen</title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="login-container">
    <div class="login-box">
        <div class="login-header">
            <i class="fas fa-graduation-cap"></i>
            <h2>Login Dosen</h2>
            <p>Masuk untuk menulis artikel</p>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required placeholder="email@kampus.ac.id">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Password Anda">
            </div>
            <button type="submit" class="btn-login"><i class="fas fa-sign-in-alt"></i> Masuk</button>
        </form>
        <div class="login-footer">
            <p><a href="<?= url() ?>">← Kembali ke Beranda</a></p>
            <small>Default: admin@kampus.ac.id / admin123</small>
        </div>
    </div>
</div>
</body>
</html>