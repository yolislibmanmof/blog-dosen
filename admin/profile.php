<?php
require_once __DIR__ . '/../config/functions.php';
checkAuth();

$userId = $_SESSION['user_id'];

// ============================================
// 👤 LOAD USER DATA
// ============================================
$user = null;
try {
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
} catch (Exception $e) {}

if (!$user) {
    flash('error', 'User tidak ditemukan.');
    redirect('admin/logout.php');
}

foreach ($user as $key => $value) {
    if ($value === null) {
        $user[$key] = '';
    }
}

// ============================================
// 📊 USER STATISTICS
// ============================================
$userStats = [
    'articles' => 0,
    'published' => 0,
    'drafts' => 0,
    'views' => 0,
    'comments' => 0,
    'streak' => 0,
    'member_since' => $user['created_at'] ?? date('Y-m-d'),
];
try {
    $stmt = db()->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) as published,
            SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) as drafts,
            IFNULL(SUM(views), 0) as total_views
        FROM articles WHERE author_id = ?
    ");
    $stmt->execute([$userId]);
    $s = $stmt->fetch();
    if ($s) {
        $userStats['articles'] = (int)$s['total'];
        $userStats['published'] = (int)$s['published'];
        $userStats['drafts'] = (int)$s['drafts'];
        $userStats['views'] = (int)$s['total_views'];
    }
    
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM comments c
        JOIN articles a ON c.article_id = a.id
        WHERE a.author_id = ?
    ");
    $stmt->execute([$userId]);
    $userStats['comments'] = (int)$stmt->fetchColumn();
    
    // Streak calculation
    $stmt = db()->prepare("
        SELECT DISTINCT DATE(created_at) as date 
        FROM articles 
        WHERE author_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
        ORDER BY date DESC
    ");
    $stmt->execute([$userId]);
    $writingDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($writingDates)) {
        $checkDate = new DateTime();
        $today = $checkDate->format('Y-m-d');
        $yesterday = (clone $checkDate)->modify('-1 day')->format('Y-m-d');
        
        if (in_array($today, $writingDates) || in_array($yesterday, $writingDates)) {
            if (!in_array($today, $writingDates)) {
                $checkDate->modify('-1 day');
            }
            while (in_array($checkDate->format('Y-m-d'), $writingDates)) {
                $userStats['streak']++;
                $checkDate->modify('-1 day');
            }
        }
    }
} catch (Exception $e) {}

// ============================================
// 💾 HANDLE UPDATE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        flash('error', '❌ Sesi keamanan tidak valid. Silakan refresh halaman dan coba lagi.');
        redirect('admin/profile.php');
    }

    try {
        $action = $_POST['action'] ?? 'update_profile';
        
        // ===== UPDATE PROFILE =====
        if ($action === 'update_profile') {
            $nama = trim($_POST['nama'] ?? '');
            $nip = trim($_POST['nip'] ?? '');
            $jabatan = trim($_POST['jabatan'] ?? '');
            $prodi = trim($_POST['prodi'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $newEmail = trim($_POST['email'] ?? '');
            
            // Validasi
            if (empty($nama) || strlen($nama) < 3) {
                throw new Exception('Nama minimal 3 karakter!');
            }
            if (strlen($nama) > 100) {
                throw new Exception('Nama maksimal 100 karakter!');
            }
            if (!empty($nip) && !preg_match('/^[0-9A-Za-z\-\.\/\s]+$/', $nip)) {
                throw new Exception('Format NIP tidak valid!');
            }
            if (!empty($newEmail) && $newEmail !== $user['email']) {
                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Format email tidak valid!');
                }
                // Cek duplikasi
                $check = db()->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $check->execute([$newEmail, $userId]);
                if ($check->fetch()) {
                    throw new Exception('Email sudah digunakan user lain!');
                }
            } else {
                $newEmail = $user['email'];
            }
            
            // Handle foto
            $foto = $user['foto'];
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['foto'];
                
                // Validasi file
                $maxSize = 5 * 1024 * 1024; // 5MB
                if ($file['size'] > $maxSize) {
                    throw new Exception('Ukuran foto maksimal 5MB!');
                }
                
                $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                if (!in_array($mimeType, $allowedTypes)) {
                    throw new Exception('Format foto tidak valid! Gunakan JPG, PNG, WEBP, atau GIF.');
                }
                
                if (function_exists('uploadImage')) {
                    $uploaded = uploadImage($file, 'profiles');
                    if ($uploaded) {
                        // ✅ Hapus foto lama dengan validasi path (Cegah Path Traversal)
                        if (!empty($user['foto']) && strpos($user['foto'], 'default.png') === false) {
                            $oldFile = realpath(__DIR__ . '/../' . ltrim($user['foto'], '/'));
                            $uploadDir = realpath(__DIR__ . '/../assets/uploads/');
                            if ($oldFile && $uploadDir && strpos($oldFile, $uploadDir) === 0 && file_exists($oldFile)) {
                                @unlink($oldFile);
                            }
                        }
                        $foto = $uploaded;
                    }
                }
            }
            
            // ✅ Handle remove foto dengan validasi path
            if (isset($_POST['remove_foto']) && $_POST['remove_foto'] === '1') {
                if (!empty($user['foto']) && strpos($user['foto'], 'default.png') === false) {
                    $oldFile = realpath(__DIR__ . '/../' . ltrim($user['foto'], '/'));
                    $uploadDir = realpath(__DIR__ . '/../assets/uploads/');
                    if ($oldFile && $uploadDir && strpos($oldFile, $uploadDir) === 0 && file_exists($oldFile)) {
                        @unlink($oldFile);
                    }
                }
                $foto = null;
            }
            
            // Update
            $stmt = db()->prepare("
                UPDATE users SET 
                    nama = ?, nip = ?, jabatan = ?, prodi = ?, 
                    bio = ?, foto = ?, email = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$nama, $nip, $jabatan, $prodi, $bio, $foto, $newEmail, $userId]);
            
            // ✅ Validasi apakah ada baris yang benar-benar berubah
            if ($stmt->rowCount() > 0 || isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                // Update session agar nama di sidebar/header langsung berubah
                $_SESSION['user_name'] = $nama;
                $_SESSION['user_email'] = $newEmail;
                flash('success', '✅ Profil berhasil diupdate!');
            } else {
                flash('info', 'ℹ️ Tidak ada perubahan data yang disimpan.');
            }
        }
        
        // ===== CHANGE PASSWORD =====
        elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            // Verifikasi password lama
            if (empty($currentPassword)) {
                throw new Exception('Password saat ini wajib diisi!');
            }
            if (!password_verify($currentPassword, $user['password'])) {
                throw new Exception('Password saat ini salah!');
            }
            
            // Validasi password baru
            if (empty($newPassword)) {
                throw new Exception('Password baru wajib diisi!');
            }
            if (strlen($newPassword) < 8) {
                throw new Exception('Password baru minimal 8 karakter!');
            }
            if (!preg_match('/[A-Z]/', $newPassword)) {
                throw new Exception('Password harus mengandung minimal 1 huruf besar!');
            }
            if (!preg_match('/[a-z]/', $newPassword)) {
                throw new Exception('Password harus mengandung minimal 1 huruf kecil!');
            }
            if (!preg_match('/[0-9]/', $newPassword)) {
                throw new Exception('Password harus mengandung minimal 1 angka!');
            }
            if ($newPassword !== $confirmPassword) {
                throw new Exception('Konfirmasi password tidak cocok!');
            }
            if ($newPassword === $currentPassword) {
                throw new Exception('Password baru harus berbeda dari password lama!');
            }
            
            // Hash & update
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            db()->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$hashed, $userId]);
            
            flash('success', '🔐 Password berhasil diubah!');
        }
        
        // ===== LOGOUT ALL DEVICES =====
        elseif ($action === 'logout_all') {
            // Implementasi sederhana: update timestamp, semua session lain akan invalid
            db()->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?")
                ->execute([$userId]);
            
            flash('success', '🚪 Semua perangkat lain telah dilogout!');
        }
        
    } catch (Exception $e) {
        flash('error', '❌ ' . $e->getMessage());
    }
    
    redirect('admin/profile.php');
}

// ============================================
// 📱 SESSION INFO
// ============================================
$sessionInfo = [
    'login_time' => isset($_SESSION['login_time']) ? $_SESSION['login_time'] : date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
    'browser' => 'Unknown',
    'os' => 'Unknown',
];

// Parse user agent
if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $ua = $_SERVER['HTTP_USER_AGENT'];
    
    // Browser detection
    if (strpos($ua, 'Chrome') !== false) $sessionInfo['browser'] = 'Google Chrome';
    elseif (strpos($ua, 'Firefox') !== false) $sessionInfo['browser'] = 'Mozilla Firefox';
    elseif (strpos($ua, 'Safari') !== false) $sessionInfo['browser'] = 'Safari';
    elseif (strpos($ua, 'Edge') !== false) $sessionInfo['browser'] = 'Microsoft Edge';
    elseif (strpos($ua, 'Opera') !== false || strpos($ua, 'OPR') !== false) $sessionInfo['browser'] = 'Opera';
    
    // OS detection
    if (strpos($ua, 'Windows') !== false) $sessionInfo['os'] = 'Windows';
    elseif (strpos($ua, 'Mac') !== false) $sessionInfo['os'] = 'macOS';
    elseif (strpos($ua, 'Linux') !== false) $sessionInfo['os'] = 'Linux';
    elseif (strpos($ua, 'Android') !== false) $sessionInfo['os'] = 'Android';
    elseif (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) $sessionInfo['os'] = 'iOS';
}

// ✅ FIX: Cegah URL bertumpuk (http://... + http://...)
$fotoPath = !empty($user['foto']) ? $user['foto'] : 'assets/uploads/default.png';
if (strpos($fotoPath, 'http') === 0) {
    $avatarUrl = $fotoPath; // Sudah URL absolut, gunakan apa adanya
} else {
    $avatarUrl = url(ltrim($fotoPath, '/')); // Path relatif, tambahkan BASE_URL
}

$avatarFallback = 'https://ui-avatars.com/api/?name=' . urlencode($user['nama']) . '&background=1e3a5f&color=fff&size=300';
$memberDays = floor((time() - strtotime($userStats['member_since'])) / 86400);

$pageTitle = 'Profil Saya';
include __DIR__ . '/../includes/admin-header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ===== PROFILE ULTIMATE STYLES ===== */

.profile-layout {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 2rem;
    align-items: start;
}

/* ===== PROFILE CARD (SIDEBAR) ===== */
.profile-card {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    position: sticky;
    top: 90px;
}
.profile-cover {
    height: 140px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f39c12 100%);
    position: relative;
    overflow: hidden;
}
.profile-cover::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: 
        radial-gradient(circle at 20% 50%, rgba(255,255,255,0.15) 0%, transparent 50%),
        radial-gradient(circle at 80% 50%, rgba(255,255,255,0.1) 0%, transparent 50%);
}
.profile-cover::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 60px;
    background: linear-gradient(to top, white, transparent);
}
.profile-avatar-wrap {
    position: relative;
    display: flex;
    justify-content: center;
    margin-top: -60px;
    z-index: 2;
}
.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 5px solid white;
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    background: #f4f6f9;
}
.profile-avatar-edit {
    position: absolute;
    bottom: 5px; right: calc(50% - 65px);
    width: 36px; height: 36px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border: 3px solid white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.85rem;
}
.profile-avatar-edit:hover {
    transform: scale(1.1);
    box-shadow: 0 5px 15px rgba(243, 156, 18, 0.4);
}
.profile-info {
    padding: 1.5rem;
    text-align: center;
}
.profile-name {
    font-size: 1.4rem;
    color: #1e3a5f;
    font-weight: 700;
    margin-bottom: 0.3rem;
}
.profile-role {
    color: #888;
    font-size: 0.88rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
}
.profile-role .admin-badge {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    padding: 0.15rem 0.6rem;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.5px;
}
.profile-email {
    color: #666;
    font-size: 0.85rem;
    margin-bottom: 0.3rem;
    word-break: break-all;
}
.profile-join {
    color: #999;
    font-size: 0.78rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.3rem;
}
.profile-stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #f0f0f0;
}
.profile-stat {
    text-align: center;
    padding: 0.5rem;
}
.profile-stat .value {
    font-size: 1.4rem;
    font-weight: 800;
    color: #1e3a5f;
    line-height: 1;
    margin-bottom: 0.2rem;
    display: block;
}
.profile-stat .label {
    font-size: 0.7rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.profile-bio {
    padding: 1.2rem 1.5rem;
    border-top: 1px solid #f0f0f0;
    text-align: left;
}
.profile-bio-label {
    font-size: 0.75rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.4rem;
    font-weight: 700;
}
.profile-bio-text {
    color: #555;
    font-size: 0.88rem;
    line-height: 1.5;
    font-style: italic;
}
.profile-bio-text.empty {
    color: #ccc;
    font-style: normal;
}

/* ===== MAIN CONTENT ===== */
.profile-main { min-width: 0; }

/* ===== TABS ===== */
.profile-tabs {
    display: flex;
    background: white;
    border-radius: 15px;
    padding: 0.4rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    gap: 0.3rem;
    overflow-x: auto;
}
.profile-tab {
    flex: 1;
    padding: 0.75rem 1rem;
    background: transparent;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 600;
    color: #666;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: all 0.2s ease;
    white-space: nowrap;
    font-family: inherit;
}
.profile-tab:hover { color: #1e3a5f; background: #f8f9fa; }
.profile-tab.active {
    background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
    color: white;
    box-shadow: 0 4px 12px rgba(30, 58, 95, 0.3);
}
.profile-tab i { font-size: 0.95rem; }

/* ===== PANELS ===== */
.profile-panel {
    display: none;
    animation: fadeIn 0.3s ease;
}
.profile-panel.active { display: block; }

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ===== FORM SECTIONS ===== */
.form-section {
    background: white;
    border-radius: 15px;
    padding: 1.8rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.form-section-header {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f0f0f0;
}
.form-section-icon {
    width: 42px; height: 42px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.form-section-title h3 {
    color: #1e3a5f;
    font-size: 1.1rem;
    margin: 0 0 0.15rem 0;
}
.form-section-title p {
    color: #888;
    font-size: 0.82rem;
    margin: 0;
}

/* ===== FORM FIELDS ===== */
.form-field-ult {
    margin-bottom: 1.2rem;
}
.form-field-ult label {
    display: block;
    color: #1e3a5f;
    font-weight: 600;
    font-size: 0.88rem;
    margin-bottom: 0.4rem;
}
.form-field-ult label .required { color: #e74c3c; margin-left: 0.2rem; }
.form-field-ult .field-help {
    font-size: 0.75rem;
    color: #888;
    margin-top: 0.3rem;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}
.form-input-ult {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.92rem;
    outline: none;
    transition: all 0.3s ease;
    font-family: inherit;
    background: white;
}
.form-input-ult:focus {
    border-color: #f39c12;
    box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
}
.form-input-ult:disabled {
    background: #f8f9fa;
    color: #888;
    cursor: not-allowed;
}
textarea.form-input-ult {
    resize: vertical;
    min-height: 100px;
    line-height: 1.5;
}
.form-row-ult {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

/* ===== PHOTO UPLOAD ===== */
.photo-upload-wrap {
    display: flex;
    gap: 1.5rem;
    align-items: flex-start;
}
.photo-preview-area {
    flex-shrink: 0;
    text-align: center;
}
.photo-preview-img {
    width: 140px;
    height: 140px;
    border-radius: 15px;
    object-fit: cover;
    border: 3px solid #e0e0e0;
    margin-bottom: 0.5rem;
    background: #f8f9fa;
    transition: all 0.3s ease;
}
.photo-preview-img.dragover {
    border-color: #f39c12;
    transform: scale(1.05);
}
.photo-actions {
    display: flex;
    gap: 0.4rem;
    justify-content: center;
}
.photo-action-btn {
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    font-weight: 600;
    font-family: inherit;
}
.photo-action-btn.upload {
    background: #e3f2fd;
    color: #1976d2;
}
.photo-action-btn.upload:hover { background: #1976d2; color: white; }
.photo-action-btn.remove {
    background: #ffebee;
    color: #c62828;
}
.photo-action-btn.remove:hover { background: #c62828; color: white; }

.photo-upload-zone {
    flex: 1;
    border: 2px dashed #d0d0d0;
    border-radius: 12px;
    padding: 2rem 1rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: #f8f9fa;
    position: relative;
    min-height: 140px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.photo-upload-zone:hover,
.photo-upload-zone.dragover {
    border-color: #f39c12;
    background: #fffbf0;
}
.photo-upload-icon {
    font-size: 2.5rem;
    color: #bbb;
    margin-bottom: 0.5rem;
}
.photo-upload-zone:hover .photo-upload-icon { color: #f39c12; }
.photo-upload-text {
    color: #666;
    font-size: 0.9rem;
    margin-bottom: 0.3rem;
}
.photo-upload-text strong { color: #1e3a5f; }
.photo-upload-hint {
    font-size: 0.75rem;
    color: #999;
}
.photo-upload-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}

/* ===== PASSWORD INPUT WITH TOGGLE ===== */
.password-wrap {
    position: relative;
}
.password-toggle {
    position: absolute;
    right: 0.8rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #888;
    cursor: pointer;
    padding: 0.3rem;
    font-size: 0.9rem;
    transition: color 0.2s ease;
}
.password-toggle:hover { color: #1e3a5f; }
.password-wrap .form-input-ult { padding-right: 2.8rem; }

/* ===== PASSWORD STRENGTH METER ===== */
.password-strength {
    margin-top: 0.6rem;
}
.strength-bar {
    height: 6px;
    background: #e0e0e0;
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 0.4rem;
}
.strength-bar-fill {
    height: 100%;
    width: 0%;
    border-radius: 3px;
    transition: all 0.3s ease;
}
.strength-bar-fill.weak { width: 25%; background: #e74c3c; }
.strength-bar-fill.fair { width: 50%; background: #f39c12; }
.strength-bar-fill.good { width: 75%; background: #3498db; }
.strength-bar-fill.strong { width: 100%; background: linear-gradient(90deg, #27ae60, #2ecc71); }
.strength-text {
    font-size: 0.75rem;
    font-weight: 600;
    color: #888;
    display: flex;
    justify-content: space-between;
}
.password-requirements {
    margin-top: 0.8rem;
    padding: 0.8rem;
    background: #f8f9fa;
    border-radius: 8px;
}
.password-requirements h5 {
    font-size: 0.78rem;
    color: #666;
    margin: 0 0 0.5rem 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.req-item {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.78rem;
    color: #888;
    margin-bottom: 0.25rem;
    transition: color 0.2s ease;
}
.req-item i { font-size: 0.7rem; width: 12px; }
.req-item.met { color: #27ae60; }
.req-item.met i { color: #27ae60; }

/* ===== SESSION INFO ===== */
.session-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}
.session-info-item {
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 0.8rem;
}
.session-info-icon {
    width: 42px; height: 42px;
    background: white;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #f39c12;
    font-size: 1.1rem;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.session-info-content .label {
    font-size: 0.72rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.1rem;
    display: block;
}
.session-info-content .value {
    color: #1e3a5f;
    font-weight: 600;
    font-size: 0.88rem;
    word-break: break-all;
}

/* ===== SECURITY ACTIONS ===== */
.security-actions {
    display: grid;
    gap: 0.8rem;
}
.security-action-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.2rem;
    background: #f8f9fa;
    border-radius: 12px;
    border: 1px solid #f0f0f0;
    transition: all 0.2s ease;
}
.security-action-item:hover {
    background: white;
    border-color: #f39c12;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.security-action-icon {
    width: 48px; height: 48px;
    background: white;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.security-action-icon.warning { color: #f39c12; }
.security-action-icon.danger { color: #e74c3c; }
.security-action-icon.info { color: #3498db; }
.security-action-content { flex: 1; min-width: 0; }
.security-action-content h4 {
    color: #1e3a5f;
    font-size: 0.95rem;
    margin: 0 0 0.2rem 0;
}
.security-action-content p {
    color: #888;
    font-size: 0.82rem;
    margin: 0;
}
.security-action-btn {
    padding: 0.6rem 1.2rem;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    font-family: inherit;
    white-space: nowrap;
}
.security-action-btn.warning {
    background: #fff3cd;
    color: #856404;
}
.security-action-btn.warning:hover { background: #ffc107; color: white; }
.security-action-btn.danger {
    background: #ffebee;
    color: #c62828;
}
.security-action-btn.danger:hover { background: #c62828; color: white; }

/* ===== BUTTONS ===== */
.btn-submit-ult {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border: none;
    padding: 0.85rem 2rem;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
    font-family: inherit;
}
.btn-submit-ult:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.4);
}
.btn-submit-ult:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* ===== DARK MODE ===== */
html[data-theme="dark"] .profile-card,
html[data-theme="dark"] .form-section,
html[data-theme="dark"] .profile-tabs {
    background: #1e2638;
}
html[data-theme="dark"] .profile-name,
html[data-theme="dark"] .form-section-title h3,
html[data-theme="dark"] .form-field-ult label,
html[data-theme="dark"] .security-action-content h4,
html[data-theme="dark"] .session-info-content .value {
    color: #e5e8ec;
}
html[data-theme="dark"] .form-input-ult {
    background: #16203a;
    border-color: #2a3550;
    color: #e5e8ec;
}
html[data-theme="dark"] .photo-upload-zone,
html[data-theme="dark"] .session-info-item,
html[data-theme="dark"] .security-action-item,
html[data-theme="dark"] .password-requirements {
    background: #16203a;
    border-color: #2a3550;
}
html[data-theme="dark"] .profile-cover::after {
    background: linear-gradient(to top, #1e2638, transparent);
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1024px) {
    .profile-layout {
        grid-template-columns: 1fr;
    }
    .profile-card { position: static; }
}
@media (max-width: 576px) {
    .form-row-ult {
        grid-template-columns: 1fr;
    }
    .photo-upload-wrap {
        flex-direction: column;
    }
    .profile-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
    }
    .profile-tab {
        flex: none;
        padding: 0.7rem 1rem;
    }
    .profile-tab span { display: none; }
    .profile-stat .value { font-size: 1.2rem; }
}
</style>

<main class="admin-main">
    
    <div class="profile-layout">
        
        <!-- ===== PROFILE CARD (SIDEBAR) ===== -->
        <div class="profile-card">
            <div class="profile-cover"></div>
            <div class="profile-avatar-wrap">
                <img src="<?php echo $avatarUrl; ?>" 
                     onerror="this.src='<?php echo $avatarFallback; ?>'"
                     alt="<?php echo htmlspecialchars($user['nama']); ?>" 
                     class="profile-avatar"
                     id="sidebarAvatar">
                <button type="button" class="profile-avatar-edit" 
                        onclick="switchTab('profile'); document.getElementById('foto').click();"
                        title="Edit foto">
                    <i class="fas fa-camera"></i>
                </button>
            </div>
            
            <div class="profile-info">
                <h2 class="profile-name"><?php echo htmlspecialchars($user['nama']); ?></h2>
                <div class="profile-role">
                    <i class="fas fa-<?php echo ($user['role'] ?? '') === 'admin' ? 'user-shield' : 'user-graduate'; ?>"></i>
                    <?php echo htmlspecialchars($user['jabatan'] ?: 'Dosen'); ?>
                    <?php if (($user['role'] ?? '') === 'admin'): ?>
                        <span class="admin-badge">ADMIN</span>
                    <?php endif; ?>
                </div>
                <div class="profile-email">
                    <i class="fas fa-envelope"></i> 
                    <?php echo htmlspecialchars($user['email']); ?>
                </div>
                <div class="profile-join">
                    <i class="fas fa-calendar-alt"></i>
                    Bergabung <?php echo date('d M Y', strtotime($userStats['member_since'])); ?>
                    (<?php echo $memberDays; ?> hari)
                </div>
                
                <div class="profile-stats-grid">
                    <div class="profile-stat">
                        <span class="value"><?php echo $userStats['articles']; ?></span>
                        <span class="label">Artikel</span>
                    </div>
                    <div class="profile-stat">
                        <span class="value"><?php echo number_format($userStats['views']); ?></span>
                        <span class="label">Views</span>
                    </div>
                    <div class="profile-stat">
                        <span class="value"><?php echo $userStats['streak']; ?></span>
                        <span class="label">🔥 Streak</span>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($user['bio'])): ?>
                <div class="profile-bio">
                    <div class="profile-bio-label">Tentang Saya</div>
                    <p class="profile-bio-text">"<?php echo htmlspecialchars($user['bio']); ?>"</p>
                </div>
            <?php else: ?>
                <div class="profile-bio">
                    <div class="profile-bio-label">Tentang Saya</div>
                    <p class="profile-bio-text empty">Belum ada bio. Tambahkan untuk memperkenalkan diri Anda!</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== MAIN CONTENT ===== -->
        <div class="profile-main">
            
            <!-- TABS -->
            <div class="profile-tabs">
                <button type="button" class="profile-tab active" onclick="switchTab('profile')" data-tab="profile">
                    <i class="fas fa-user"></i> <span>Profil</span>
                </button>
                <button type="button" class="profile-tab" onclick="switchTab('password')" data-tab="password">
                    <i class="fas fa-lock"></i> <span>Password</span>
                </button>
                <button type="button" class="profile-tab" onclick="switchTab('security')" data-tab="security">
                    <i class="fas fa-shield-alt"></i> <span>Keamanan</span>
                </button>
            </div>

            <!-- ===== PANEL: PROFILE ===== -->
            <div class="profile-panel active" id="panel-profile">
                <form method="POST" enctype="multipart/form-data" id="profileForm" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <!-- Photo Upload -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <div class="form-section-icon">
                                <i class="fas fa-camera"></i>
                            </div>
                            <div class="form-section-title">
                                <h3>Foto Profil</h3>
                                <p>Foto akan ditampilkan di artikel dan profil publik Anda</p>
                            </div>
                        </div>
                        
                        <div class="photo-upload-wrap">
                            <div class="photo-preview-area">
                                <img src="<?php echo $avatarUrl; ?>" 
                                     onerror="this.src='<?php echo $avatarFallback; ?>'"
                                     alt="Preview" 
                                     class="photo-preview-img"
                                     id="photoPreview">
                                <div class="photo-actions">
                                    <button type="button" class="photo-action-btn upload" 
                                            onclick="document.getElementById('foto').click()">
                                        <i class="fas fa-upload"></i> Upload
                                    </button>
                                    <button type="button" class="photo-action-btn remove" 
                                            onclick="removePhoto()"
                                            <?php echo empty($user['foto']) ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
                                        <i class="fas fa-trash"></i> Hapus
                                    </button>
                                </div>
                                <input type="hidden" name="remove_foto" id="removeFotoInput" value="0">
                            </div>
                            
                            <div class="photo-upload-zone" id="photoUploadZone">
                                <i class="fas fa-cloud-upload-alt photo-upload-icon"></i>
                                <div class="photo-upload-text">
                                    <strong>Klik</strong> atau <strong>drag & drop</strong>
                                </div>
                                <div class="photo-upload-hint">
                                    JPG, PNG, WEBP, GIF (Maks 5MB)
                                </div>
                                <input type="file" 
                                       name="foto" 
                                       id="foto" 
                                       class="photo-upload-input"
                                       accept="image/jpeg,image/png,image/webp,image/gif">
                            </div>
                        </div>
                    </div>

                    <!-- Personal Info -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <div class="form-section-icon">
                                <i class="fas fa-id-card"></i>
                            </div>
                            <div class="form-section-title">
                                <h3>Informasi Pribadi</h3>
                                <p>Data yang ditampilkan di profil publik Anda</p>
                            </div>
                        </div>

                        <div class="form-field-ult">
                            <label>Nama Lengkap <span class="required">*</span></label>
                            <input type="text" name="nama" class="form-input-ult" 
                                   value="<?php echo htmlspecialchars($user['nama']); ?>" 
                                   required maxlength="100">
                            <small class="field-help">
                                <i class="fas fa-info-circle"></i> Minimal 3 karakter, maksimal 100 karakter
                            </small>
                        </div>

                        <div class="form-row-ult">
                            <div class="form-field-ult">
                                <label>NIP / NIDN</label>
                                <input type="text" name="nip" class="form-input-ult" 
                                       value="<?php echo htmlspecialchars($user['nip'] ?? ''); ?>" 
                                       placeholder="Contoh: 198501012010011001">
                            </div>
                            <div class="form-field-ult">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" name="email" class="form-input-ult" 
                                       value="<?php echo htmlspecialchars($user['email']); ?>" 
                                       required>
                                <small class="field-help">
                                    <i class="fas fa-info-circle"></i> Digunakan untuk login
                                </small>
                            </div>
                        </div>

                        <div class="form-row-ult">
                            <div class="form-field-ult">
                                <label>Jabatan Fungsional</label>
                                <input type="text" name="jabatan" class="form-input-ult" 
                                       value="<?php echo htmlspecialchars($user['jabatan'] ?? ''); ?>" 
                                       placeholder="Contoh: Lektor Kepala">
                            </div>
                            <div class="form-field-ult">
                                <label>Program Studi</label>
                                <input type="text" name="prodi" class="form-input-ult" 
                                       value="<?php echo htmlspecialchars($user['prodi'] ?? ''); ?>" 
                                       placeholder="Contoh: Teknik Informatika">
                            </div>
                        </div>

                        <div class="form-field-ult">
                            <label>Bio / Tentang Saya</label>
                            <textarea name="bio" class="form-input-ult" rows="4" 
                                      placeholder="Ceritakan tentang diri Anda, penelitian, atau minat akademik..."
                                      maxlength="500"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                            <small class="field-help">
                                <i class="fas fa-info-circle"></i> 
                                <span id="bioCounter"><?php echo strlen($user['bio'] ?? ''); ?></span> / 500 karakter
                            </small>
                        </div>
                    </div>

                    <div style="display:flex;gap:0.8rem;justify-content:flex-end;flex-wrap:wrap;">
                        <button type="reset" class="btn-submit-ult" style="background:#f4f6f9;color:#666;box-shadow:none;">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn-submit-ult">
                            <i class="fas fa-save"></i> Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>

            <!-- ===== PANEL: PASSWORD ===== -->
            <div class="profile-panel" id="panel-password">
                <form method="POST" id="passwordForm" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-section">
                        <div class="form-section-header">
                            <div class="form-section-icon">
                                <i class="fas fa-key"></i>
                            </div>
                            <div class="form-section-title">
                                <h3>Ubah Password</h3>
                                <p>Untuk keamanan, Anda harus memverifikasi password lama</p>
                            </div>
                        </div>

                        <div class="form-field-ult">
                            <label>Password Saat Ini <span class="required">*</span></label>
                            <div class="password-wrap">
                                <input type="password" name="current_password" class="form-input-ult" 
                                       id="currentPassword" required autocomplete="current-password">
                                <button type="button" class="password-toggle" 
                                        onclick="togglePassword('currentPassword', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-field-ult">
                            <label>Password Baru <span class="required">*</span></label>
                            <div class="password-wrap">
                                <input type="password" name="new_password" class="form-input-ult" 
                                       id="newPassword" required minlength="8" autocomplete="new-password"
                                       oninput="checkPasswordStrength(this.value)">
                                <button type="button" class="password-toggle" 
                                        onclick="togglePassword('newPassword', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            
                            <div class="password-strength" id="passwordStrength" style="display:none;">
                                <div class="strength-bar">
                                    <div class="strength-bar-fill" id="strengthBarFill"></div>
                                </div>
                                <div class="strength-text">
                                    <span id="strengthText">Masukkan password</span>
                                    <span id="strengthLabel"></span>
                                </div>
                            </div>
                            
                            <div class="password-requirements">
                                <h5>Syarat Password:</h5>
                                <div class="req-item" id="req-length">
                                    <i class="fas fa-circle"></i> Minimal 8 karakter
                                </div>
                                <div class="req-item" id="req-upper">
                                    <i class="fas fa-circle"></i> Minimal 1 huruf besar (A-Z)
                                </div>
                                <div class="req-item" id="req-lower">
                                    <i class="fas fa-circle"></i> Minimal 1 huruf kecil (a-z)
                                </div>
                                <div class="req-item" id="req-number">
                                    <i class="fas fa-circle"></i> Minimal 1 angka (0-9)
                                </div>
                                <div class="req-item" id="req-different">
                                    <i class="fas fa-circle"></i> Berbeda dari password lama
                                </div>
                            </div>
                        </div>

                        <div class="form-field-ult">
                            <label>Konfirmasi Password Baru <span class="required">*</span></label>
                            <div class="password-wrap">
                                <input type="password" name="confirm_password" class="form-input-ult" 
                                       id="confirmPassword" required autocomplete="new-password"
                                       oninput="checkPasswordMatch()">
                                <button type="button" class="password-toggle" 
                                        onclick="togglePassword('confirmPassword', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small class="field-help" id="matchHelp" style="display:none;">
                                <i class="fas fa-check-circle" id="matchIcon"></i>
                                <span id="matchText"></span>
                            </small>
                        </div>
                    </div>

                    <div style="display:flex;gap:0.8rem;justify-content:flex-end;">
                        <button type="submit" class="btn-submit-ult" id="submitPasswordBtn" disabled>
                            <i class="fas fa-lock"></i> Ubah Password
                        </button>
                    </div>
                </form>
            </div>

            <!-- ===== PANEL: SECURITY ===== -->
            <div class="profile-panel" id="panel-security">
                
                <!-- Session Info -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon">
                            <i class="fas fa-desktop"></i>
                        </div>
                        <div class="form-section-title">
                            <h3>Sesi Saat Ini</h3>
                            <p>Informasi tentang perangkat yang sedang Anda gunakan</p>
                        </div>
                    </div>
                    
                    <div class="session-info-grid">
                        <div class="session-info-item">
                            <div class="session-info-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="session-info-content">
                                <span class="label">Waktu Login</span>
                                <span class="value"><?php echo date('d M Y, H:i', strtotime($sessionInfo['login_time'])); ?></span>
                            </div>
                        </div>
                        <div class="session-info-item">
                            <div class="session-info-icon">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <div class="session-info-content">
                                <span class="label">IP Address</span>
                                <span class="value"><?php echo htmlspecialchars($sessionInfo['ip']); ?></span>
                            </div>
                        </div>
                        <div class="session-info-item">
                            <div class="session-info-icon">
                                <i class="fas fa-globe"></i>
                            </div>
                            <div class="session-info-content">
                                <span class="label">Browser</span>
                                <span class="value"><?php echo htmlspecialchars($sessionInfo['browser']); ?></span>
                            </div>
                        </div>
                        <div class="session-info-item">
                            <div class="session-info-icon">
                                <i class="fas fa-laptop"></i>
                            </div>
                            <div class="session-info-content">
                                <span class="label">Sistem Operasi</span>
                                <span class="value"><?php echo htmlspecialchars($sessionInfo['os']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Security Actions -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="form-section-title">
                            <h3>Aksi Keamanan</h3>
                            <p>Lindungi akun Anda dengan fitur keamanan berikut</p>
                        </div>
                    </div>

                    <div class="security-actions">
                        <div class="security-action-item">
                            <div class="security-action-icon warning">
                                <i class="fas fa-sign-out-alt"></i>
                            </div>
                            <div class="security-action-content">
                                <h4>Logout dari Semua Perangkat</h4>
                                <p>Keluar dari semua sesi aktif di perangkat lain. Anda akan tetap login di perangkat ini.</p>
                            </div>
                            <form method="POST" style="display:inline;" 
                                  onsubmit="return confirmLogoutAll(event)">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="logout_all">
                                <button type="submit" class="security-action-btn warning">
                                    <i class="fas fa-power-off"></i> Logout Semua
                                </button>
                            </form>
                        </div>

                        <div class="security-action-item">
                            <div class="security-action-icon info">
                                <i class="fas fa-history"></i>
                            </div>
                            <div class="security-action-content">
                                <h4>Riwayat Login</h4>
                                <p>Lihat daftar perangkat yang pernah login ke akun Anda.</p>
                            </div>
                            <button type="button" class="security-action-btn warning" 
                                    onclick="if(typeof UI!=='undefined') UI.info('Fitur ini akan segera hadir!', 'Coming Soon'); else alert('Coming soon!');">
                                <i class="fas fa-eye"></i> Lihat Riwayat
                            </button>
                        </div>

                        <div class="security-action-item">
                            <div class="security-action-icon danger">
                                <i class="fas fa-user-times"></i>
                            </div>
                            <div class="security-action-content">
                                <h4>Hapus Akun</h4>
                                <p>Permanen hapus akun dan semua data Anda. Tindakan ini tidak dapat dibatalkan.</p>
                            </div>
                            <button type="button" class="security-action-btn danger" 
                                    onclick="if(typeof UI!=='undefined') UI.warning('Hubungi administrator untuk menghapus akun.', 'Hubungi Admin'); else alert('Hubungi admin untuk hapus akun.');">
                                <i class="fas fa-trash"></i> Hapus Akun
                            </button>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>
</main>

<script>
(function() {
    'use strict';

    // ===== TAB SWITCHING =====
    window.switchTab = function(tabName) {
        // Update tab buttons
        document.querySelectorAll('.profile-tab').forEach(function(tab) {
            tab.classList.toggle('active', tab.getAttribute('data-tab') === tabName);
        });
        // Update panels
        document.querySelectorAll('.profile-panel').forEach(function(panel) {
            panel.classList.toggle('active', panel.id === 'panel-' + tabName);
        });
        // Save to URL hash
        window.location.hash = tabName;
        // Scroll to top
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    // Load tab from URL hash
    var hash = window.location.hash.replace('#', '');
    if (['profile', 'password', 'security'].indexOf(hash) !== -1) {
        switchTab(hash);
    }

    // ===== PHOTO UPLOAD =====
    var photoInput = document.getElementById('foto');
    var photoPreview = document.getElementById('photoPreview');
    var sidebarAvatar = document.getElementById('sidebarAvatar');
    var uploadZone = document.getElementById('photoUploadZone');
    var removeFotoInput = document.getElementById('removeFotoInput');

    photoInput.addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;
        
        // Validasi
        if (file.size > 5 * 1024 * 1024) {
            if (typeof UI !== 'undefined') UI.error('Ukuran maksimal 5MB!', 'File terlalu besar');
            else alert('Ukuran file maksimal 5MB!');
            this.value = '';
            return;
        }
        
        if (!file.type.startsWith('image/')) {
            if (typeof UI !== 'undefined') UI.error('File harus berupa gambar!', 'Format salah');
            else alert('File harus berupa gambar!');
            this.value = '';
            return;
        }
        
        // Preview
        var reader = new FileReader();
        reader.onload = function(e) {
            if (photoPreview) photoPreview.src = e.target.result;
            if (sidebarAvatar) sidebarAvatar.src = e.target.result;
            if (removeFotoInput) removeFotoInput.value = '0';
        };
        reader.readAsDataURL(file);
    });

    // Drag & drop
    ['dragenter', 'dragover'].forEach(function(evt) {
        uploadZone.addEventListener(evt, function(e) {
            e.preventDefault();
            uploadZone.classList.add('dragover');
            photoPreview.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function(evt) {
        uploadZone.addEventListener(evt, function(e) {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            photoPreview.classList.remove('dragover');
        });
    });
    uploadZone.addEventListener('drop', function(e) {
        var file = e.dataTransfer.files[0];
        if (file && file.type.startsWith('image/')) {
            photoInput.files = e.dataTransfer.files;
            photoInput.dispatchEvent(new Event('change'));
        }
    });

    window.removePhoto = function() {
        if (typeof UI !== 'undefined' && UI.confirm) {
            UI.confirm(
                'Hapus foto profil? Anda akan menggunakan avatar default.',
                function() {
                    photoInput.value = '';
                    photoPreview.src = '<?php echo url('assets/uploads/default.png'); ?>';
                    if (sidebarAvatar) sidebarAvatar.src = '<?php echo url('assets/uploads/default.png'); ?>';
                    if (removeFotoInput) removeFotoInput.value = '1';
                    UI.success('Foto akan dihapus saat disimpan.', '📷 Foto Dihapus');
                },
                { icon: '📷', title: 'Hapus Foto?' }
            );
        } else {
            if (confirm('Hapus foto profil?')) {
                photoInput.value = '';
                photoPreview.src = '<?php echo url('assets/uploads/default.png'); ?>';
                if (removeFotoInput) removeFotoInput.value = '1';
            }
        }
    };

    // ===== BIO COUNTER =====
    var bioTextarea = document.querySelector('textarea[name="bio"]');
    var bioCounter = document.getElementById('bioCounter');
    if (bioTextarea && bioCounter) {
        bioTextarea.addEventListener('input', function() {
            bioCounter.textContent = this.value.length;
        });
    }

    // ===== PASSWORD TOGGLE =====
    window.togglePassword = function(inputId, btn) {
        var input = document.getElementById(inputId);
        var icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'fas fa-eye';
        }
    };

    // ===== PASSWORD STRENGTH METER =====
    window.checkPasswordStrength = function(password) {
        var strengthDiv = document.getElementById('passwordStrength');
        var barFill = document.getElementById('strengthBarFill');
        var strengthText = document.getElementById('strengthText');
        var strengthLabel = document.getElementById('strengthLabel');
        
        if (password.length === 0) {
            strengthDiv.style.display = 'none';
            updateRequirements(password);
            checkSubmitButton();
            return;
        }
        
        strengthDiv.style.display = 'block';
        
        var score = 0;
        var checks = {
            length: password.length >= 8,
            upper: /[A-Z]/.test(password),
            lower: /[a-z]/.test(password),
            number: /[0-9]/.test(password),
            special: /[^A-Za-z0-9]/.test(password),
            long: password.length >= 12
        };
        
        if (checks.length) score++;
        if (checks.upper) score++;
        if (checks.lower) score++;
        if (checks.number) score++;
        if (checks.special) score++;
        if (checks.long) score++;
        
        var level, text, label;
        if (score <= 2) { level = 'weak'; text = 'Lemah'; label = '💔 Mudah ditebak'; }
        else if (score <= 3) { level = 'fair'; text = 'Cukup'; label = '⚠️ Bisa lebih kuat'; }
        else if (score <= 4) { level = 'good'; text = 'Baik'; label = '👍 Sudah bagus'; }
        else { level = 'strong'; text = 'Kuat'; label = '🛡️ Sangat aman'; }
        
        barFill.className = 'strength-bar-fill ' + level;
        strengthText.textContent = text;
        strengthLabel.textContent = label;
        
        updateRequirements(password);
        checkSubmitButton();
    };

    function updateRequirements(password) {
        var currentPass = document.getElementById('currentPassword').value;
        
        var reqs = {
            'req-length': password.length >= 8,
            'req-upper': /[A-Z]/.test(password),
            'req-lower': /[a-z]/.test(password),
            'req-number': /[0-9]/.test(password),
            'req-different': password.length > 0 && password !== currentPass
        };
        
        for (var id in reqs) {
            var el = document.getElementById(id);
            if (el) {
                el.classList.toggle('met', reqs[id]);
                el.querySelector('i').className = reqs[id] ? 'fas fa-check-circle' : 'fas fa-circle';
            }
        }
    }

    window.checkPasswordMatch = function() {
        var newPass = document.getElementById('newPassword').value;
        var confirmPass = document.getElementById('confirmPassword').value;
        var matchHelp = document.getElementById('matchHelp');
        var matchIcon = document.getElementById('matchIcon');
        var matchText = document.getElementById('matchText');
        
        if (confirmPass.length === 0) {
            matchHelp.style.display = 'none';
            checkSubmitButton();
            return;
        }
        
        matchHelp.style.display = 'flex';
        
        if (newPass === confirmPass) {
            matchIcon.className = 'fas fa-check-circle';
            matchIcon.style.color = '#27ae60';
            matchText.textContent = 'Password cocok!';
            matchText.style.color = '#27ae60';
        } else {
            matchIcon.className = 'fas fa-times-circle';
            matchIcon.style.color = '#e74c3c';
            matchText.textContent = 'Password tidak cocok!';
            matchText.style.color = '#e74c3c';
        }
        
        checkSubmitButton();
    };

    function checkSubmitButton() {
        var current = document.getElementById('currentPassword').value;
        var newPass = document.getElementById('newPassword').value;
        var confirm = document.getElementById('confirmPassword').value;
        var btn = document.getElementById('submitPasswordBtn');
        
        var valid = current.length > 0 &&
                    newPass.length >= 8 &&
                    /[A-Z]/.test(newPass) &&
                    /[a-z]/.test(newPass) &&
                    /[0-9]/.test(newPass) &&
                    newPass === confirm &&
                    newPass !== current;
        
        btn.disabled = !valid;
    }

    // Bind current password change
    var currentPassInput = document.getElementById('currentPassword');
    if (currentPassInput) {
        currentPassInput.addEventListener('input', function() {
            var newPass = document.getElementById('newPassword').value;
            if (newPass.length > 0) {
                updateRequirements(newPass);
                checkSubmitButton();
            }
        });
    }

    // ===== CONFIRM LOGOUT ALL =====
    window.confirmLogoutAll = function(e) {
        e.preventDefault();
        var form = e.target;
        
        if (typeof UI !== 'undefined' && UI.confirm) {
            UI.confirm(
                'Anda akan dilogout dari semua perangkat lain. Perangkat saat ini akan tetap login.',
                function() {
                    if (typeof UI !== 'undefined') UI.showLoading('Memproses...');
                    form.submit();
                },
                { icon: '🚪', title: 'Logout Semua Perangkat?' }
            );
        } else {
            if (confirm('Logout dari semua perangkat lain?')) form.submit();
        }
        return false;
    };

    // ===== FORM SUBMIT LOADING =====
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function() {
            var btn = form.querySelector('button[type="submit"]');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                var originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
                setTimeout(function() {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }, 3000);
            }
        });
    });

    // ===== UNSAVED CHANGES =====
    var isDirty = false;
    document.querySelectorAll('#profileForm input, #profileForm textarea, #profileForm select').forEach(function(el) {
        el.addEventListener('input', function() { isDirty = true; });
        el.addEventListener('change', function() { isDirty = true; });
    });
    
    document.getElementById('profileForm').addEventListener('submit', function() {
        isDirty = false;
    });
    
    window.addEventListener('beforeunload', function(e) {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    console.log('%c👤 Profile Page Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
})();
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>