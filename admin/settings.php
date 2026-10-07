<?php
require_once __DIR__ . '/../config/functions.php';
checkAuth();

if (!isAdmin()) {
    flash('error', 'Akses ditolak! Hanya admin yang bisa mengubah pengaturan.');
    redirect('admin/');
}

// ============================================
// 💾 HANDLE UPDATE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✅ VALIDASI KEAMANAN CSRF
    if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        flash('error', '❌ Sesi keamanan tidak valid. Silakan refresh halaman dan coba lagi.');
        redirect('admin/settings.php');
    }
    
    try {
        $action = $_POST['action'] ?? 'save_general';
        
        // ===== SAVE SETTINGS =====
        if (in_array($action, ['save_general', 'save_contact', 'save_social', 'save_seo', 'save_appearance', 'save_quote', 'save_advanced'])) {
            $fieldsMap = [
                'save_general' => ['nama_kampus', 'tagline', 'short_description'],
                'save_contact' => ['email_contact', 'phone', 'address', 'working_hours'],
                'save_social'  => ['facebook', 'twitter', 'instagram', 'youtube', 'linkedin', 'github', 'tiktok'],
                'save_seo'     => ['meta_title', 'meta_description', 'meta_keywords', 'google_analytics', 'google_search_console'],
                'save_appearance' => ['primary_color', 'accent_color', 'theme_mode'],
                'save_quote'   => ['quote', 'quote_author', 'banner_style'],
                'save_advanced' => ['maintenance_mode', 'maintenance_message', 'allow_registration', 'comment_moderation', 'posts_per_page'],
            ];
            
            $fields = $fieldsMap[$action] ?? [];
            $sets = [];
            $params = [];
            
            foreach ($fields as $field) {
                $sets[] = "$field = ?";
                $value = trim($_POST[$field] ?? '');
                
                // Validasi khusus
                if (in_array($field, ['email_contact']) && !empty($value)) {
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Format email tidak valid untuk $field!");
                    }
                }
                if (in_array($field, ['facebook', 'twitter', 'instagram', 'youtube', 'linkedin', 'github', 'tiktok']) && !empty($value)) {
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        throw new Exception("URL tidak valid untuk $field!");
                    }
                }
                if ($field === 'primary_color' || $field === 'accent_color') {
                    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                        $value = $field === 'primary_color' ? '#1e3a5f' : '#f39c12';
                    }
                }
                if ($field === 'posts_per_page') {
                    $value = max(1, min(50, (int)$value));
                }
                
                $params[] = $value;
            }
            
            // Handle logo upload
            if ($action === 'save_appearance' && isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['logo'];
                if ($file['size'] > 2 * 1024 * 1024) {
                    throw new Exception('Logo maksimal 2MB!');
                }
                $allowedTypes = ['image/jpeg', 'image/png', 'image/svg+xml', 'image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                if (!in_array($mimeType, $allowedTypes)) {
                    throw new Exception('Format logo tidak valid! Gunakan JPG, PNG, SVG, atau WEBP.');
                }
                if (function_exists('uploadImage')) {
                    $uploaded = uploadImage($file, 'settings');
                    if ($uploaded) {
                        $sets[] = "logo = ?";
                        $params[] = $uploaded;
                    }
                }
            }
            
            // Handle favicon upload
            if ($action === 'save_appearance' && isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['favicon'];
                if ($file['size'] > 1 * 1024 * 1024) {
                    throw new Exception('Favicon maksimal 1MB!');
                }
                if (function_exists('uploadImage')) {
                    $uploaded = uploadImage($file, 'settings');
                    if ($uploaded) {
                        $sets[] = "favicon = ?";
                        $params[] = $uploaded;
                    }
                }
            }
            
            // ✅ FIX: Checkbox handling yang lebih aman
            $checkboxFields = ['maintenance_mode', 'allow_registration', 'comment_moderation'];
            foreach ($checkboxFields as $cb) {
                if (in_array($cb, $fields)) {
                    $fieldIndex = array_search($cb, $fields);
                    if ($fieldIndex !== false) {
                        if (!isset($_POST[$cb])) {
                            $params[$fieldIndex] = '0';
                        } else {
                            $params[$fieldIndex] = '1';
                        }
                    }
                }
            }
            
            if (empty($sets)) {
                throw new Exception('Tidak ada data yang diupdate.');
            }
            
            $sql = "UPDATE settings SET " . implode(', ', $sets) . " WHERE id = 1";
            db()->prepare($sql)->execute($params);
            
            $labels = [
                'save_general' => 'Informasi Umum',
                'save_contact' => 'Kontak',
                'save_social' => 'Social Media',
                'save_seo' => 'SEO',
                'save_appearance' => 'Tampilan',
                'save_quote' => 'Quote Banner',
                'save_advanced' => 'Advanced',
            ];
            flash('success', '✅ Pengaturan ' . ($labels[$action] ?? '') . ' berhasil disimpan!');
        }
        
        // ===== CLEAR CACHE =====
        elseif ($action === 'clear_cache') {
            // Implementasi sederhana: hapus file cache di folder cache/
            $cacheDir = __DIR__ . '/../cache';
            $count = 0;
            if (is_dir($cacheDir)) {
                $files = glob($cacheDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file) && basename($file) !== '.gitkeep') {
                        @unlink($file);
                        $count++;
                    }
                }
            }
            flash('success', "🧹 Cache berhasil dibersihkan! ($count file dihapus)");
        }
        
        // ===== RESET TO DEFAULT =====
        elseif ($action === 'reset_settings') {
            $defaults = [
                'nama_kampus' => 'Blog Dosen',
                'tagline' => 'Berbagi Ilmu, Menebar Inspirasi',
                'short_description' => 'Platform blog untuk para dosen berbagi ilmu dan pengalaman.',
                'email_contact' => 'info@blogdosen.com',
                'phone' => '',
                'address' => '',
                'working_hours' => 'Senin - Jumat, 08:00 - 16:00',
                'facebook' => '', 'twitter' => '', 'instagram' => '', 'youtube' => '',
                'linkedin' => '', 'github' => '', 'tiktok' => '',
                'meta_title' => 'Blog Dosen - Berbagi Ilmu & Inspirasi',
                'meta_description' => 'Platform blog untuk para dosen berbagi ilmu, penelitian, dan pengalaman.',
                'meta_keywords' => 'blog dosen, akademik, penelitian, pendidikan',
                'primary_color' => '#1e3a5f',
                'accent_color' => '#f39c12',
                'quote' => 'Pendidikan adalah senjata paling mematikan di dunia, karena dengan pendidikan Anda dapat mengubah dunia.',
                'quote_author' => 'Nelson Mandela',
                'maintenance_mode' => '0',
                'allow_registration' => '0',
                'comment_moderation' => '1',
                'posts_per_page' => '12',
            ];
            
            $sets = [];
            $params = [];
            foreach ($defaults as $k => $v) {
                $sets[] = "$k = ?";
                $params[] = $v;
            }
            
            $sql = "UPDATE settings SET " . implode(', ', $sets) . " WHERE id = 1";
            db()->prepare($sql)->execute($params);
            
            flash('success', '🔄 Semua pengaturan berhasil direset ke default!');
        }
        
    } catch (Exception $e) {
        flash('error', '❌ ' . $e->getMessage());
    }
    
    redirect('admin/settings.php' . (isset($_POST['tab']) ? '#' . $_POST['tab'] : ''));
}

// ============================================
// 📊 LOAD SETTINGS
// ============================================
$settings = getSettings();
if (!$settings) {
    $settings = [];
}

// ✅ DEFAULT VALUES (Fallback jika key tidak ada)
$defaultSettings = [
    'nama_kampus' => '', 'tagline' => '', 'short_description' => '',
    'email_contact' => '', 'phone' => '', 'address' => '', 'working_hours' => '',
    'facebook' => '', 'twitter' => '', 'instagram' => '', 'youtube' => '',
    'linkedin' => '', 'github' => '', 'tiktok' => '',
    'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '',
    'google_analytics' => '', 'google_search_console' => '',
    'logo' => '', 'favicon' => '',
    'primary_color' => '#1e3a5f', 'accent_color' => '#f39c12', 'theme_mode' => 'light',
    'quote' => '', 'quote_author' => '', 'banner_style' => 'gradient',
    'maintenance_mode' => '0', 'maintenance_message' => '',
    'allow_registration' => '0', 'comment_moderation' => '1', 'posts_per_page' => '12',
];

// Gabungkan default dengan data dari database
$settings = array_merge($defaultSettings, $settings);

// ✅ SANITASI MASSAL: Ubah semua nilai NULL menjadi string kosong ''
// Ini mencegah error "htmlspecialchars(): Passing null" di PHP 8.1+
foreach ($settings as $key => $value) {
    if ($value === null) {
        $settings[$key] = '';
    }
}

// ============================================
// 📊 SYSTEM INFO
// ============================================
$sysInfo = [
    'php_version' => PHP_VERSION,
    'mysql_version' => 'Unknown',
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'max_upload' => ini_get('upload_max_filesize'),
    'max_post' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution' => ini_get('max_execution_time') . 's',
    'os' => PHP_OS,
];
try {
    $sysInfo['mysql_version'] = db()->query("SELECT VERSION()")->fetchColumn();
} catch (Exception $e) {}

// Database size (optional, mungkin butuh privilege)
$dbSize = 'N/A';
try {
    // ✅ Ambil nama database langsung dari MySQL
    $dbName = db()->query("SELECT DATABASE()")->fetchColumn();
    if ($dbName) {
        $stmt = db()->prepare("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
            FROM information_schema.TABLES 
            WHERE table_schema = ?
        ");
        $stmt->execute([$dbName]);
        $size = $stmt->fetchColumn();
        if ($size) $dbSize = number_format($size, 2) . ' MB';
    }
} catch (Exception $e) {}

// Count records
$recordCounts = ['articles' => 0, 'users' => 0, 'categories' => 0, 'comments' => 0];
try {
    // ✅ Satu query untuk semua counts (lebih efisien)
    $stmt = db()->query("
        SELECT 
            (SELECT COUNT(*) FROM articles) as articles,
            (SELECT COUNT(*) FROM users) as users,
            (SELECT COUNT(*) FROM categories) as categories,
            (SELECT COUNT(*) FROM comments) as comments
    ");
    $row = $stmt->fetch();
    if ($row) {
        $recordCounts['articles'] = (int)$row['articles'];
        $recordCounts['users'] = (int)$row['users'];
        $recordCounts['categories'] = (int)$row['categories'];
        $recordCounts['comments'] = (int)$row['comments'];
    }
} catch (Exception $e) {}

// Logo & Favicon URLs
$logoUrl = !empty($settings['logo']) ? url($settings['logo']) : url('assets/logo.png');
$faviconUrl = !empty($settings['favicon']) ? url($settings['favicon']) : '';

$pageTitle = 'Pengaturan Website';
include __DIR__ . '/../includes/admin-header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ===== SETTINGS ULTIMATE STYLES ===== */

.settings-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}
.settings-header h1 {
    color: #1e3a5f;
    font-size: 1.8rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
.settings-header h1 i { color: #f39c12; }
.settings-header-sub {
    color: #666;
    font-size: 0.9rem;
    margin-top: 0.3rem;
}

.header-actions {
    display: flex;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.btn-action {
    padding: 0.6rem 1.2rem;
    border: none;
    border-radius: 10px;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    text-decoration: none;
    font-family: inherit;
}
.btn-action.primary {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    box-shadow: 0 4px 12px rgba(243, 156, 18, 0.3);
}
.btn-action.primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(243, 156, 18, 0.4);
}
.btn-action.secondary {
    background: #f4f6f9;
    color: #1e3a5f;
}
.btn-action.secondary:hover { background: #e9ecef; }
.btn-action.danger {
    background: #ffebee;
    color: #c62828;
}
.btn-action.danger:hover { background: #c62828; color: white; }

/* ===== TABS ===== */
.settings-tabs {
    display: flex;
    background: white;
    border-radius: 15px;
    padding: 0.4rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    gap: 0.3rem;
    overflow-x: auto;
}
.settings-tab {
    padding: 0.75rem 1.1rem;
    background: transparent;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-size: 0.88rem;
    font-weight: 600;
    color: #666;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s ease;
    white-space: nowrap;
    font-family: inherit;
}
.settings-tab:hover { color: #1e3a5f; background: #f8f9fa; }
.settings-tab.active {
    background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
    color: white;
    box-shadow: 0 4px 12px rgba(30, 58, 95, 0.3);
}
.settings-tab i { font-size: 0.9rem; }

/* ===== PANELS ===== */
.settings-panel { display: none; animation: fadeIn 0.3s ease; }
.settings-panel.active { display: block; }

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ===== LAYOUT ===== */
.settings-layout {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 1.5rem;
    align-items: start;
}
.settings-main { min-width: 0; }
.settings-sidebar { display: flex; flex-direction: column; gap: 1rem; }

/* ===== FORM SECTIONS ===== */
.settings-section {
    background: white;
    border-radius: 15px;
    padding: 1.8rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.section-header {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f0f0f0;
}
.section-icon {
    width: 45px; height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: white;
    flex-shrink: 0;
}
.section-icon.general { background: linear-gradient(135deg, #3498db, #2980b9); }
.section-icon.contact { background: linear-gradient(135deg, #27ae60, #229954); }
.section-icon.social { background: linear-gradient(135deg, #e74c3c, #c0392b); }
.section-icon.seo { background: linear-gradient(135deg, #f39c12, #e67e22); }
.section-icon.appearance { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
.section-icon.quote { background: linear-gradient(135deg, #1abc9c, #16a085); }
.section-icon.advanced { background: linear-gradient(135deg, #e67e22, #d35400); }
.section-icon.system { background: linear-gradient(135deg, #34495e, #2c3e50); }
.section-title h3 {
    color: #1e3a5f;
    font-size: 1.15rem;
    margin: 0 0 0.2rem 0;
}
.section-title p {
    color: #888;
    font-size: 0.82rem;
    margin: 0;
}

/* ===== FORM FIELDS ===== */
.field-group {
    margin-bottom: 1.3rem;
}
.field-group label {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    color: #1e3a5f;
    font-weight: 600;
    font-size: 0.88rem;
    margin-bottom: 0.4rem;
}
.field-group label .required { color: #e74c3c; }
.field-group label i { color: #f39c12; font-size: 0.85rem; }
.field-group .field-help {
    font-size: 0.75rem;
    color: #888;
    margin-top: 0.3rem;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}
.field-input {
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
.field-input:focus {
    border-color: #f39c12;
    box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
}
.field-input:disabled {
    background: #f8f9fa;
    color: #888;
    cursor: not-allowed;
}
textarea.field-input {
    resize: vertical;
    min-height: 90px;
    line-height: 1.5;
}
.field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

/* ===== COLOR PICKER ===== */
.color-picker-wrap {
    display: flex;
    gap: 0.8rem;
    align-items: center;
}
.color-preview {
    width: 50px; height: 50px;
    border-radius: 10px;
    border: 2px solid #e0e0e0;
    flex-shrink: 0;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
    cursor: pointer;
    transition: transform 0.2s ease;
}
.color-preview:hover { transform: scale(1.05); }
.color-input-wrap {
    flex: 1;
    display: flex;
    gap: 0.5rem;
}
.color-input-wrap input[type="color"] {
    width: 50px;
    height: 42px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    cursor: pointer;
    padding: 2px;
    background: white;
}
.color-input-wrap input[type="text"] {
    flex: 1;
    font-family: 'Courier New', monospace;
    font-weight: 600;
    text-transform: uppercase;
}
.color-presets {
    display: flex;
    gap: 0.4rem;
    margin-top: 0.5rem;
    flex-wrap: wrap;
}
.color-preset {
    width: 28px; height: 28px;
    border-radius: 50%;
    cursor: pointer;
    border: 2px solid white;
    box-shadow: 0 0 0 1px #e0e0e0;
    transition: all 0.2s ease;
    padding: 0;
}
.color-preset:hover { transform: scale(1.15); box-shadow: 0 0 0 2px #f39c12; }

/* ===== SOCIAL INPUT ===== */
.social-input-wrap {
    position: relative;
}
.social-input-wrap .social-icon-prefix {
    position: absolute;
    left: 0.9rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 1.1rem;
    width: 20px;
    text-align: center;
}
.social-input-wrap .field-input {
    padding-left: 2.8rem;
}
.social-icon-prefix.facebook { color: #1877f2; }
.social-icon-prefix.twitter { color: #1da1f2; }
.social-icon-prefix.instagram { color: #e4405f; }
.social-icon-prefix.youtube { color: #ff0000; }
.social-icon-prefix.linkedin { color: #0a66c2; }
.social-icon-prefix.github { color: #333; }
.social-icon-prefix.tiktok { color: #000; }

/* ===== LOGO UPLOAD ===== */
.logo-upload-wrap {
    display: flex;
    gap: 1.5rem;
    align-items: flex-start;
}
.logo-preview-area {
    flex-shrink: 0;
    text-align: center;
}
.logo-preview-box {
    width: 140px;
    height: 140px;
    border-radius: 15px;
    border: 2px dashed #d0d0d0;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.5rem;
    overflow: hidden;
    position: relative;
}
.logo-preview-box img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 10px;
}
.logo-preview-box.empty {
    color: #ccc;
    font-size: 2.5rem;
}
.favicon-preview-box {
    width: 60px; height: 60px;
    border-radius: 10px;
    border: 2px dashed #d0d0d0;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.5rem;
    overflow: hidden;
}
.favicon-preview-box img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 5px;
}
.upload-btn-mini {
    padding: 0.4rem 0.8rem;
    background: #e3f2fd;
    color: #1976d2;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.78rem;
    font-weight: 600;
    transition: all 0.2s ease;
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    margin: 0.1rem;
}
.upload-btn-mini:hover { background: #1976d2; color: white; }
.upload-btn-mini.danger { background: #ffebee; color: #c62828; }
.upload-btn-mini.danger:hover { background: #c62828; color: white; }

/* ===== LIVE PREVIEW ===== */
.live-preview-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    position: sticky;
    top: 90px;
}
.live-preview-card h4 {
    color: #1e3a5f;
    margin: 0 0 1rem 0;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-bottom: 0.8rem;
    border-bottom: 2px solid #f0f0f0;
}
.live-preview-card h4 i { color: #f39c12; }

.preview-box {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 1rem;
    margin-bottom: 1rem;
}
.preview-label {
    font-size: 0.72rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.4rem;
    font-weight: 700;
    display: block;
}
.preview-value {
    color: #1e3a5f;
    font-weight: 600;
    font-size: 0.92rem;
    word-break: break-word;
    line-height: 1.4;
}
.preview-value.empty {
    color: #ccc;
    font-style: italic;
    font-weight: 400;
}

/* SEO Preview */
.seo-preview-box {
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 1rem;
    font-family: Arial, sans-serif;
}
.seo-preview-url {
    color: #202124;
    font-size: 0.78rem;
    margin-bottom: 0.3rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.seo-preview-url .favicon-mini {
    width: 16px; height: 16px;
    border-radius: 50%;
    background: #1e3a5f;
}
.seo-preview-title {
    color: #1a0dab;
    font-size: 1.05rem;
    line-height: 1.3;
    margin-bottom: 0.3rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.seo-preview-desc {
    color: #4d5156;
    font-size: 0.82rem;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Quote Preview */
.quote-preview-box {
    background: linear-gradient(135deg, var(--preview-primary, #1e3a5f), #2c5f8d);
    color: white;
    padding: 1.5rem;
    border-radius: 12px;
    position: relative;
    overflow: hidden;
    min-height: 120px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.quote-preview-box::before {
    content: '"';
    position: absolute;
    top: -20px; left: 10px;
    font-size: 6rem;
    opacity: 0.15;
    font-family: Georgia, serif;
    line-height: 1;
}
.quote-preview-text {
    font-size: 0.95rem;
    font-style: italic;
    line-height: 1.5;
    margin-bottom: 0.5rem;
    position: relative;
    z-index: 1;
}
.quote-preview-author {
    font-size: 0.82rem;
    opacity: 0.9;
    font-weight: 600;
    position: relative;
    z-index: 1;
}

/* ===== SWITCH TOGGLE ===== */
.switch-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 10px;
    margin-bottom: 0.8rem;
}
.switch-row-info h5 {
    color: #1e3a5f;
    margin: 0 0 0.2rem 0;
    font-size: 0.95rem;
}
.switch-row-info p {
    color: #888;
    margin: 0;
    font-size: 0.78rem;
}
.switch {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 26px;
    flex-shrink: 0;
}
.switch input { opacity: 0; width: 0; height: 0; }
.switch-slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background: #ccc;
    border-radius: 26px;
    transition: 0.3s;
}
.switch-slider::before {
    content: '';
    position: absolute;
    height: 20px; width: 20px;
    left: 3px; bottom: 3px;
    background: white;
    border-radius: 50%;
    transition: 0.3s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
.switch input:checked + .switch-slider {
    background: linear-gradient(135deg, #f39c12, #e67e22);
}
.switch input:checked + .switch-slider::before {
    transform: translateX(22px);
}

/* ===== SYSTEM INFO ===== */
.sys-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.8rem;
}
.sys-info-item {
    padding: 0.9rem;
    background: #f8f9fa;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 0.7rem;
}
.sys-info-icon {
    width: 38px; height: 38px;
    background: white;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #f39c12;
    font-size: 1rem;
    flex-shrink: 0;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}
.sys-info-content .label {
    font-size: 0.7rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 0.1rem;
}
.sys-info-content .value {
    color: #1e3a5f;
    font-weight: 600;
    font-size: 0.85rem;
    word-break: break-all;
}

/* ===== RECORD STATS ===== */
.record-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 0.8rem;
}
.record-stat-card {
    padding: 1rem;
    background: linear-gradient(135deg, var(--rc-color, #3498db), var(--rc-color-dark, #2980b9));
    color: white;
    border-radius: 12px;
    text-align: center;
}
.record-stat-card .icon {
    font-size: 1.5rem;
    margin-bottom: 0.3rem;
    display: block;
    opacity: 0.9;
}
.record-stat-card .value {
    font-size: 1.6rem;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 0.2rem;
    display: block;
}
.record-stat-card .label {
    font-size: 0.72rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ===== SUBMIT BAR ===== */
.submit-bar {
    display: flex;
    justify-content: flex-end;
    gap: 0.8rem;
    padding: 1.5rem 1.8rem;
    background: white;
    border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-top: 1rem;
    flex-wrap: wrap;
}
.btn-submit {
    padding: 0.85rem 2rem;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-family: inherit;
}
.btn-submit.primary {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
}
.btn-submit.primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.4);
}
.btn-submit.secondary {
    background: #f4f6f9;
    color: #1e3a5f;
}
.btn-submit.secondary:hover { background: #e9ecef; }

/* ===== DARK MODE ===== */
html[data-theme="dark"] .settings-section,
html[data-theme="dark"] .live-preview-card,
html[data-theme="dark"] .settings-tabs,
html[data-theme="dark"] .submit-bar {
    background: #1e2638;
}
html[data-theme="dark"] .settings-header h1,
html[data-theme="dark"] .section-title h3,
html[data-theme="dark"] .field-group label,
html[data-theme="dark"] .live-preview-card h4,
html[data-theme="dark"] .sys-info-content .value,
html[data-theme="dark"] .switch-row-info h5 {
    color: #e5e8ec;
}
html[data-theme="dark"] .field-input {
    background: #16203a;
    border-color: #2a3550;
    color: #e5e8ec;
}
html[data-theme="dark"] .switch-row,
html[data-theme="dark"] .sys-info-item,
html[data-theme="dark"] .preview-box {
    background: #16203a;
}
html[data-theme="dark"] .sys-info-icon {
    background: #25304a;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1024px) {
    .settings-layout {
        grid-template-columns: 1fr;
    }
    .live-preview-card {
        position: static;
    }
}
@media (max-width: 768px) {
    .field-row {
        grid-template-columns: 1fr;
    }
    .logo-upload-wrap {
        flex-direction: column;
    }
    .settings-tab span { display: none; }
    .settings-tab { padding: 0.7rem; }
}
@media (max-width: 576px) {
    .sys-info-grid,
    .record-stats-grid {
        grid-template-columns: 1fr;
    }
    .submit-bar {
        flex-direction: column;
    }
    .btn-submit { width: 100%; justify-content: center; }
}
</style>

<main class="admin-main">
    
    <!-- ===== HEADER ===== -->
    <div class="settings-header">
        <div>
            <h1><i class="fas fa-cog"></i> Pengaturan Website</h1>
            <div class="settings-header-sub">
                Kelola konfigurasi website, tampilan, SEO, dan fitur sistem
            </div>
        </div>
        <div class="header-actions">
            <a href="<?php echo url(); ?>" target="_blank" class="btn-action secondary">
                <i class="fas fa-external-link-alt"></i> Lihat Website
            </a>
            <form method="POST" style="display:inline;" onsubmit="return confirmReset(event)">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="reset_settings">
                <button type="submit" class="btn-action danger">
                    <i class="fas fa-undo"></i> Reset Default
                </button>
            </form>
        </div>
    </div>

    <!-- ===== TABS ===== -->
    <div class="settings-tabs">
        <button type="button" class="settings-tab active" onclick="switchTab('general')" data-tab="general">
            <i class="fas fa-info-circle"></i> <span>Umum</span>
        </button>
        <button type="button" class="settings-tab" onclick="switchTab('contact')" data-tab="contact">
            <i class="fas fa-address-book"></i> <span>Kontak</span>
        </button>
        <button type="button" class="settings-tab" onclick="switchTab('social')" data-tab="social">
            <i class="fas fa-share-alt"></i> <span>Social Media</span>
        </button>
        <button type="button" class="settings-tab" onclick="switchTab('seo')" data-tab="seo">
            <i class="fas fa-search"></i> <span>SEO</span>
        </button>
        <button type="button" class="settings-tab" onclick="switchTab('appearance')" data-tab="appearance">
            <i class="fas fa-palette"></i> <span>Tampilan</span>
        </button>
        <button type="button" class="settings-tab" onclick="switchTab('quote')" data-tab="quote">
            <i class="fas fa-quote-right"></i> <span>Quote</span>
        </button>
        <button type="button" class="settings-tab" onclick="switchTab('advanced')" data-tab="advanced">
            <i class="fas fa-sliders-h"></i> <span>Advanced</span>
        </button>
        <button type="button" class="settings-tab" onclick="switchTab('system')" data-tab="system">
            <i class="fas fa-server"></i> <span>Sistem</span>
        </button>
    </div>

    <div class="settings-layout">
        
        <!-- ===== MAIN FORM AREA ===== -->
        <div class="settings-main">
            
            <!-- ===== PANEL: GENERAL ===== -->
            <div class="settings-panel active" id="panel-general">
                <form method="POST" data-form="general">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="save_general">
                    <input type="hidden" name="tab" value="general">
                    
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon general">
                                <i class="fas fa-university"></i>
                            </div>
                            <div class="section-title">
                                <h3>Informasi Kampus</h3>
                                <p>Identitas dasar yang tampil di seluruh website</p>
                            </div>
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-heading"></i> Nama Kampus <span class="required">*</span></label>
                            <input type="text" name="nama_kampus" class="field-input" 
                                   value="<?php echo htmlspecialchars($settings['nama_kampus']); ?>" 
                                   required maxlength="100"
                                   oninput="updatePreview('previewNama', this.value)">
                            <small class="field-help">
                                <i class="fas fa-info-circle"></i> Ditampilkan di header, footer, dan meta tags
                            </small>
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-tag"></i> Tagline</label>
                            <input type="text" name="tagline" class="field-input" 
                                   value="<?php echo htmlspecialchars($settings['tagline']); ?>" 
                                   maxlength="100"
                                   oninput="updatePreview('previewTagline', this.value)">
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-align-left"></i> Deskripsi Singkat</label>
                            <textarea name="short_description" class="field-input" rows="3"
                                      maxlength="200"
                                      oninput="updatePreview('previewDesc', this.value); document.getElementById('counterDesc').textContent = this.value.length"><?php echo htmlspecialchars($settings['short_description'] ?? ''); ?></textarea>
                            <small class="field-help">
                                <i class="fas fa-info-circle"></i> 
                                <span id="counterDesc"><?php echo strlen($settings['short_description'] ?? ''); ?></span> / 200 karakter
                            </small>
                        </div>
                    </div>

                    <div class="submit-bar">
                        <button type="reset" class="btn-submit secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn-submit primary">
                            <i class="fas fa-save"></i> Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>

            <!-- ===== PANEL: CONTACT ===== -->
            <div class="settings-panel" id="panel-contact">
                <form method="POST" data-form="contact">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="save_contact">
                    <input type="hidden" name="tab" value="contact">
                    
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon contact">
                                <i class="fas fa-phone"></i>
                            </div>
                            <div class="section-title">
                                <h3>Informasi Kontak</h3>
                                <p>Data kontak yang ditampilkan di halaman kontak dan footer</p>
                            </div>
                        </div>

                        <div class="field-row">
                            <div class="field-group">
                                <label><i class="fas fa-envelope"></i> Email Kontak</label>
                                <input type="email" name="email_contact" class="field-input" 
                                       value="<?php echo htmlspecialchars($settings['email_contact']); ?>"
                                       placeholder="info@kampus.ac.id">
                            </div>
                            <div class="field-group">
                                <label><i class="fas fa-phone"></i> Nomor Telepon</label>
                                <input type="text" name="phone" class="field-input" 
                                       value="<?php echo htmlspecialchars($settings['phone']); ?>"
                                       placeholder="+62 21 1234 5678">
                            </div>
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-map-marker-alt"></i> Alamat</label>
                            <textarea name="address" class="field-input" rows="3"
                                      placeholder="Jl. Contoh No. 123, Kota, Provinsi"><?php echo htmlspecialchars($settings['address']); ?></textarea>
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-clock"></i> Jam Operasional</label>
                            <input type="text" name="working_hours" class="field-input" 
                                   value="<?php echo htmlspecialchars($settings['working_hours'] ?? ''); ?>"
                                   placeholder="Senin - Jumat, 08:00 - 16:00">
                        </div>
                    </div>

                    <div class="submit-bar">
                        <button type="reset" class="btn-submit secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn-submit primary">
                            <i class="fas fa-save"></i> Simpan Kontak
                        </button>
                    </div>
                </form>
            </div>

            <!-- ===== PANEL: SOCIAL MEDIA ===== -->
            <div class="settings-panel" id="panel-social">
                <form method="POST" data-form="social">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="save_social">
                    <input type="hidden" name="tab" value="social">
                    
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon social">
                                <i class="fas fa-share-alt"></i>
                            </div>
                            <div class="section-title">
                                <h3>Social Media</h3>
                                <p>Link akun media sosial yang ditampilkan di footer dan halaman kontak</p>
                            </div>
                        </div>

                        <div class="field-group">
                            <label>Facebook</label>
                            <div class="social-input-wrap">
                                <i class="fab fa-facebook social-icon-prefix facebook"></i>
                                <input type="url" name="facebook" class="field-input" 
                                       value="<?php echo htmlspecialchars($settings['facebook']); ?>"
                                       placeholder="https://facebook.com/username">
                            </div>
                        </div>

                        <div class="field-group">
                            <label>Twitter / X</label>
                            <div class="social-input-wrap">
                                <i class="fab fa-twitter social-icon-prefix twitter"></i>
                                <input type="url" name="twitter" class="field-input" 
                                       value="<?php echo htmlspecialchars($settings['twitter']); ?>"
                                       placeholder="https://twitter.com/username">
                            </div>
                        </div>

                        <div class="field-group">
                            <label>Instagram</label>
                            <div class="social-input-wrap">
                                <i class="fab fa-instagram social-icon-prefix instagram"></i>
                                <input type="url" name="instagram" class="field-input" 
                                       value="<?php echo htmlspecialchars($settings['instagram']); ?>"
                                       placeholder="https://instagram.com/username">
                            </div>
                        </div>

                        <div class="field-group">
                            <label>YouTube</label>
                            <div class="social-input-wrap">
                                <i class="fab fa-youtube social-icon-prefix youtube"></i>
                                <input type="url" name="youtube" class="field-input" 
                                       value="<?php echo htmlspecialchars($settings['youtube']); ?>"
                                       placeholder="https://youtube.com/@channel">
                            </div>
                        </div>

                        <div class="field-row">
                            <div class="field-group">
                                <label>LinkedIn</label>
                                <div class="social-input-wrap">
                                    <i class="fab fa-linkedin social-icon-prefix linkedin"></i>
                                    <input type="url" name="linkedin" class="field-input" 
                                           value="<?php echo htmlspecialchars($settings['linkedin'] ?? ''); ?>"
                                           placeholder="https://linkedin.com/in/username">
                                </div>
                            </div>
                            <div class="field-group">
                                <label>GitHub</label>
                                <div class="social-input-wrap">
                                    <i class="fab fa-github social-icon-prefix github"></i>
                                    <input type="url" name="github" class="field-input" 
                                           value="<?php echo htmlspecialchars($settings['github'] ?? ''); ?>"
                                           placeholder="https://github.com/username">
                                </div>
                            </div>
                        </div>

                        <div class="field-group">
                            <label>TikTok</label>
                            <div class="social-input-wrap">
                                <i class="fab fa-tiktok social-icon-prefix tiktok"></i>
                                <input type="url" name="tiktok" class="field-input" 
                                       value="<?php echo htmlspecialchars($settings['tiktok'] ?? ''); ?>"
                                       placeholder="https://tiktok.com/@username">
                            </div>
                        </div>
                    </div>

                    <div class="submit-bar">
                        <button type="reset" class="btn-submit secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn-submit primary">
                            <i class="fas fa-save"></i> Simpan Social Media
                        </button>
                    </div>
                </form>
            </div>

            <!-- ===== PANEL: SEO ===== -->
            <div class="settings-panel" id="panel-seo">
                <form method="POST" data-form="seo">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="save_seo">
                    <input type="hidden" name="tab" value="seo">
                    
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon seo">
                                <i class="fas fa-search"></i>
                            </div>
                            <div class="section-title">
                                <h3>SEO & Meta Tags</h3>
                                <p>Optimasi untuk mesin pencari (Google, Bing, dll)</p>
                            </div>
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-heading"></i> Meta Title</label>
                            <input type="text" name="meta_title" class="field-input" 
                                   value="<?php echo htmlspecialchars($settings['meta_title'] ?? ''); ?>"
                                   maxlength="70"
                                   oninput="updateSeoPreview(); document.getElementById('counterMetaTitle').textContent = this.value.length">
                            <small class="field-help">
                                <i class="fas fa-info-circle"></i> 
                                <span id="counterMetaTitle"><?php echo strlen($settings['meta_title'] ?? ''); ?></span> / 70 karakter (ideal: 50-60)
                            </small>
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-align-left"></i> Meta Description</label>
                            <textarea name="meta_description" class="field-input" rows="3"
                                      maxlength="160"
                                      oninput="updateSeoPreview(); document.getElementById('counterMetaDesc').textContent = this.value.length"><?php echo htmlspecialchars($settings['meta_description'] ?? ''); ?></textarea>
                            <small class="field-help">
                                <i class="fas fa-info-circle"></i> 
                                <span id="counterMetaDesc"><?php echo strlen($settings['meta_description'] ?? ''); ?></span> / 160 karakter (ideal: 150-160)
                            </small>
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-tags"></i> Meta Keywords</label>
                            <input type="text" name="meta_keywords" class="field-input" 
                                   value="<?php echo htmlspecialchars($settings['meta_keywords'] ?? ''); ?>"
                                   placeholder="blog dosen, akademik, penelitian, pendidikan">
                            <small class="field-help">
                                <i class="fas fa-info-circle"></i> Pisahkan dengan koma (kurang penting untuk SEO modern)
                            </small>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon seo" style="background: linear-gradient(135deg, #3498db, #2980b9);">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="section-title">
                                <h3>Analytics & Verification</h3>
                                <p>Kode pelacakan dan verifikasi website</p>
                            </div>
                        </div>

                        <div class="field-group">
                            <label><i class="fab fa-google"></i> Google Analytics ID</label>
                            <input type="text" name="google_analytics" class="field-input" 
                                   value="<?php echo htmlspecialchars($settings['google_analytics'] ?? ''); ?>"
                                   placeholder="G-XXXXXXXXXX atau UA-XXXXXXXX-X">
                            <small class="field-help">
                                <i class="fas fa-info-circle"></i> ID pelacakan Google Analytics 4 atau Universal Analytics
                            </small>
                        </div>

                        <div class="field-group">
                            <label><i class="fab fa-google"></i> Google Search Console</label>
                            <input type="text" name="google_search_console" class="field-input" 
                                   value="<?php echo htmlspecialchars($settings['google_search_console'] ?? ''); ?>"
                                   placeholder="Kode verifikasi (misal: abc123xyz)">
                            <small class="field-help">
                                <i class="fas fa-info-circle"></i> Kode verifikasi meta tag Google Search Console
                            </small>
                        </div>
                    </div>

                    <div class="submit-bar">
                        <button type="reset" class="btn-submit secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn-submit primary">
                            <i class="fas fa-save"></i> Simpan SEO
                        </button>
                    </div>
                </form>
            </div>

            <!-- ===== PANEL: APPEARANCE ===== -->
            <div class="settings-panel" id="panel-appearance">
                <form method="POST" enctype="multipart/form-data" data-form="appearance">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="save_appearance">
                    <input type="hidden" name="tab" value="appearance">
                    
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon appearance">
                                <i class="fas fa-image"></i>
                            </div>
                            <div class="section-title">
                                <h3>Logo & Favicon</h3>
                                <p>Identitas visual website Anda</p>
                            </div>
                        </div>

                        <div class="logo-upload-wrap">
                            <!-- Logo -->
                            <div class="logo-preview-area">
                                <div class="logo-preview-box <?php echo empty($settings['logo']) ? 'empty' : ''; ?>" id="logoPreviewBox">
                                    <?php if (!empty($settings['logo'])): ?>
                                        <img src="<?php echo url($settings['logo']); ?>" alt="Logo" id="logoPreview">
                                    <?php else: ?>
                                        <i class="fas fa-image"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <button type="button" class="upload-btn-mini" onclick="document.getElementById('logoInput').click()">
                                        <i class="fas fa-upload"></i> Upload
                                    </button>
                                </div>
                                <input type="file" name="logo" id="logoInput" accept="image/*" style="display:none"
                                       onchange="previewImage(this, 'logoPreview', 'logoPreviewBox')">
                                <small class="field-help" style="display:block;text-align:center;margin-top:0.4rem;">
                                    Logo (PNG, JPG, SVG, WEBP, Maks 2MB)
                                </small>
                            </div>

                            <!-- Favicon -->
                            <div class="logo-preview-area">
                                <div class="favicon-preview-box <?php echo empty($settings['favicon']) ? 'empty' : ''; ?>" id="faviconPreviewBox">
                                    <?php if (!empty($settings['favicon'])): ?>
                                        <img src="<?php echo url($settings['favicon']); ?>" alt="Favicon" id="faviconPreview">
                                    <?php else: ?>
                                        <span style="color:#ccc;font-size:1.5rem;"><i class="fas fa-star"></i></span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <button type="button" class="upload-btn-mini" onclick="document.getElementById('faviconInput').click()">
                                        <i class="fas fa-upload"></i> Upload
                                    </button>
                                </div>
                                <input type="file" name="favicon" id="faviconInput" accept="image/*" style="display:none"
                                       onchange="previewImage(this, 'faviconPreview', 'faviconPreviewBox')">
                                <small class="field-help" style="display:block;text-align:center;margin-top:0.4rem;">
                                    Favicon (PNG, ICO, Maks 1MB)
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon appearance">
                                <i class="fas fa-palette"></i>
                            </div>
                            <div class="section-title">
                                <h3>Warna Tema</h3>
                                <p>Kustomisasi warna utama dan aksen website</p>
                            </div>
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-fill-drip"></i> Warna Utama (Primary)</label>
                            <div class="color-picker-wrap">
                                <div class="color-preview" id="primaryColorPreview" 
                                     style="background: <?php echo htmlspecialchars($settings['primary_color']); ?>;"
                                     onclick="document.getElementById('primaryColorPicker').click()"></div>
                                <div class="color-input-wrap">
                                    <input type="color" id="primaryColorPicker" 
                                           value="<?php echo htmlspecialchars($settings['primary_color']); ?>"
                                           oninput="syncColor('primary_color', this.value)">
                                    <input type="text" name="primary_color" id="primary_color" class="field-input"
                                           value="<?php echo htmlspecialchars($settings['primary_color']); ?>"
                                           oninput="syncColor('primary_color', this.value, true)"
                                           pattern="#[0-9a-fA-F]{6}">
                                </div>
                            </div>
                            <div class="color-presets">
                                <button type="button" class="color-preset" style="background:#1e3a5f" onclick="setColor('primary_color', '#1e3a5f')" title="Navy"></button>
                                <button type="button" class="color-preset" style="background:#2c3e50" onclick="setColor('primary_color', '#2c3e50')" title="Dark Blue"></button>
                                <button type="button" class="color-preset" style="background:#3498db" onclick="setColor('primary_color', '#3498db')" title="Blue"></button>
                                <button type="button" class="color-preset" style="background:#27ae60" onclick="setColor('primary_color', '#27ae60')" title="Green"></button>
                                <button type="button" class="color-preset" style="background:#8e44ad" onclick="setColor('primary_color', '#8e44ad')" title="Purple"></button>
                                <button type="button" class="color-preset" style="background:#c0392b" onclick="setColor('primary_color', '#c0392b')" title="Red"></button>
                                <button type="button" class="color-preset" style="background:#16a085" onclick="setColor('primary_color', '#16a085')" title="Teal"></button>
                                <button type="button" class="color-preset" style="background:#d35400" onclick="setColor('primary_color', '#d35400')" title="Orange"></button>
                            </div>
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-fill-drip"></i> Warna Aksen (Accent)</label>
                            <div class="color-picker-wrap">
                                <div class="color-preview" id="accentColorPreview" 
                                     style="background: <?php echo htmlspecialchars($settings['accent_color']); ?>;"
                                     onclick="document.getElementById('accentColorPicker').click()"></div>
                                <div class="color-input-wrap">
                                    <input type="color" id="accentColorPicker" 
                                           value="<?php echo htmlspecialchars($settings['accent_color']); ?>"
                                           oninput="syncColor('accent_color', this.value)">
                                    <input type="text" name="accent_color" id="accent_color" class="field-input"
                                           value="<?php echo htmlspecialchars($settings['accent_color']); ?>"
                                           oninput="syncColor('accent_color', this.value, true)"
                                           pattern="#[0-9a-fA-F]{6}">
                                </div>
                            </div>
                            <div class="color-presets">
                                <button type="button" class="color-preset" style="background:#f39c12" onclick="setColor('accent_color', '#f39c12')" title="Orange"></button>
                                <button type="button" class="color-preset" style="background:#e74c3c" onclick="setColor('accent_color', '#e74c3c')" title="Red"></button>
                                <button type="button" class="color-preset" style="background:#e67e22" onclick="setColor('accent_color', '#e67e22')" title="Carrot"></button>
                                <button type="button" class="color-preset" style="background:#f1c40f" onclick="setColor('accent_color', '#f1c40f')" title="Yellow"></button>
                                <button type="button" class="color-preset" style="background:#9b59b6" onclick="setColor('accent_color', '#9b59b6')" title="Purple"></button>
                                <button type="button" class="color-preset" style="background:#1abc9c" onclick="setColor('accent_color', '#1abc9c')" title="Turquoise"></button>
                                <button type="button" class="color-preset" style="background:#34495e" onclick="setColor('accent_color', '#34495e')" title="Wet Asphalt"></button>
                                <button type="button" class="color-preset" style="background:#2ecc71" onclick="setColor('accent_color', '#2ecc71')" title="Emerald"></button>
                            </div>
                        </div>
                    </div>

                    <div class="submit-bar">
                        <button type="reset" class="btn-submit secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn-submit primary">
                            <i class="fas fa-save"></i> Simpan Tampilan
                        </button>
                    </div>
                </form>
            </div>

            <!-- ===== PANEL: QUOTE ===== -->
            <div class="settings-panel" id="panel-quote">
                <form method="POST" data-form="quote">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="save_quote">
                    <input type="hidden" name="tab" value="quote">
                    
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon quote">
                                <i class="fas fa-quote-right"></i>
                            </div>
                            <div class="section-title">
                                <h3>Quote Banner</h3>
                                <p>Kutipan inspiratif yang tampil di banner halaman utama</p>
                            </div>
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-quote-left"></i> Quote</label>
                            <textarea name="quote" class="field-input" rows="4"
                                      maxlength="300"
                                      oninput="updateQuotePreview(); document.getElementById('counterQuote').textContent = this.value.length"><?php echo htmlspecialchars($settings['quote']); ?></textarea>
                            <small class="field-help">
                                <i class="fas fa-info-circle"></i> 
                                <span id="counterQuote"><?php echo strlen($settings['quote']); ?></span> / 300 karakter
                            </small>
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-user"></i> Penulis Quote</label>
                            <input type="text" name="quote_author" class="field-input" 
                                   value="<?php echo htmlspecialchars($settings['quote_author']); ?>"
                                   maxlength="100"
                                   oninput="updateQuotePreview()">
                        </div>

                        <div class="field-group">
                            <label><i class="fas fa-paint-brush"></i> Style Banner</label>
                            <select name="banner_style" class="field-input">
                                <option value="gradient" <?php echo ($settings['banner_style'] ?? 'gradient') === 'gradient' ? 'selected' : ''; ?>>🌈 Gradient (Default)</option>
                                <option value="solid" <?php echo ($settings['banner_style'] ?? '') === 'solid' ? 'selected' : ''; ?>>🎨 Solid Color</option>
                                <option value="image" <?php echo ($settings['banner_style'] ?? '') === 'image' ? 'selected' : ''; ?>>🖼️ Dengan Background Image</option>
                            </select>
                        </div>
                    </div>

                    <div class="submit-bar">
                        <button type="reset" class="btn-submit secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn-submit primary">
                            <i class="fas fa-save"></i> Simpan Quote
                        </button>
                    </div>
                </form>
            </div>

            <!-- ===== PANEL: ADVANCED ===== -->
            <div class="settings-panel" id="panel-advanced">
                <form method="POST" data-form="advanced">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="save_advanced">
                    <input type="hidden" name="tab" value="advanced">
                    
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon advanced">
                                <i class="fas fa-tools"></i>
                            </div>
                            <div class="section-title">
                                <h3>Fitur Website</h3>
                                <p>Aktifkan atau nonaktifkan fitur-fitur website</p>
                            </div>
                        </div>

                        <div class="switch-row">
                            <div class="switch-row-info">
                                <h5>🔧 Mode Maintenance</h5>
                                <p>Website akan menampilkan halaman maintenance untuk pengunjung (admin tetap bisa akses)</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="maintenance_mode" value="1" 
                                       <?php echo !empty($settings['maintenance_mode']) ? 'checked' : ''; ?>>
                                <span class="switch-slider"></span>
                            </label>
                        </div>

                        <div class="field-group" style="margin-top:1rem;">
                            <label><i class="fas fa-comment-alt"></i> Pesan Maintenance</label>
                            <textarea name="maintenance_message" class="field-input" rows="2"
                                      placeholder="Website sedang dalam perbaikan. Silakan kembali nanti."><?php echo htmlspecialchars($settings['maintenance_message'] ?? ''); ?></textarea>
                        </div>

                        <div class="switch-row">
                            <div class="switch-row-info">
                                <h5>👥 Izinkan Pendaftaran</h5>
                                <p>User baru dapat mendaftar sendiri sebagai dosen/penulis</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="allow_registration" value="1"
                                       <?php echo !empty($settings['allow_registration']) ? 'checked' : ''; ?>>
                                <span class="switch-slider"></span>
                            </label>
                        </div>

                        <div class="switch-row">
                            <div class="switch-row-info">
                                <h5>💬 Moderasi Komentar</h5>
                                <p>Komentar baru harus disetujui admin sebelum tampil di publik</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="comment_moderation" value="1"
                                       <?php echo !empty($settings['comment_moderation']) ? 'checked' : ''; ?>>
                                <span class="switch-slider"></span>
                            </label>
                        </div>

                        <div class="field-group" style="margin-top:1.5rem;">
                            <label><i class="fas fa-list"></i> Jumlah Artikel per Halaman</label>
                            <input type="number" name="posts_per_page" class="field-input" 
                                   value="<?php echo (int)($settings['posts_per_page'] ?? 12); ?>"
                                   min="1" max="50" style="max-width:200px;">
                            <small class="field-help">
                                <i class="fas fa-info-circle"></i> Jumlah artikel yang tampil per halaman di blog/list (1-50)
                            </small>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon advanced" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
                                <i class="fas fa-broom"></i>
                            </div>
                            <div class="section-title">
                                <h3>Maintenance</h3>
                                <p>Aksi untuk membersihkan sistem</p>
                            </div>
                        </div>

                        <div class="switch-row">
                            <div class="switch-row-info">
                                <h5>🧹 Bersihkan Cache</h5>
                                <p>Hapus semua file cache untuk memuat ulang data fresh</p>
                            </div>
                            <button type="button" class="btn-action secondary" onclick="clearCache()">
                                <i class="fas fa-broom"></i> Clear Cache
                            </button>
                        </div>
                    </div>

                    <div class="submit-bar">
                        <button type="reset" class="btn-submit secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn-submit primary">
                            <i class="fas fa-save"></i> Simpan Advanced
                        </button>
                    </div>
                </form>
            </div>

            <!-- ===== PANEL: SYSTEM ===== -->
            <div class="settings-panel" id="panel-system">
                
                <div class="settings-section">
                    <div class="section-header">
                        <div class="section-icon system">
                            <i class="fas fa-server"></i>
                        </div>
                        <div class="section-title">
                            <h3>Informasi Sistem</h3>
                            <p>Detail teknis server dan lingkungan aplikasi</p>
                        </div>
                    </div>

                    <div class="sys-info-grid">
                        <div class="sys-info-item">
                            <div class="sys-info-icon"><i class="fab fa-php"></i></div>
                            <div class="sys-info-content">
                                <span class="label">PHP Version</span>
                                <span class="value"><?php echo htmlspecialchars($sysInfo['php_version']); ?></span>
                            </div>
                        </div>
                        <div class="sys-info-item">
                            <div class="sys-info-icon"><i class="fas fa-database"></i></div>
                            <div class="sys-info-content">
                                <span class="label">MySQL Version</span>
                                <span class="value"><?php echo htmlspecialchars($sysInfo['mysql_version']); ?></span>
                            </div>
                        </div>
                        <div class="sys-info-item">
                            <div class="sys-info-icon"><i class="fas fa-hdd"></i></div>
                            <div class="sys-info-content">
                                <span class="label">Database Size</span>
                                <span class="value"><?php echo htmlspecialchars($dbSize); ?></span>
                            </div>
                        </div>
                        <div class="sys-info-item">
                            <div class="sys-info-icon"><i class="fas fa-globe"></i></div>
                            <div class="sys-info-content">
                                <span class="label">Server</span>
                                <span class="value"><?php echo htmlspecialchars(explode(' ', $sysInfo['server_software'])[0]); ?></span>
                            </div>
                        </div>
                        <div class="sys-info-item">
                            <div class="sys-info-icon"><i class="fas fa-upload"></i></div>
                            <div class="sys-info-content">
                                <span class="label">Max Upload</span>
                                <span class="value"><?php echo htmlspecialchars($sysInfo['max_upload']); ?></span>
                            </div>
                        </div>
                        <div class="sys-info-item">
                            <div class="sys-info-icon"><i class="fas fa-envelope"></i></div>
                            <div class="sys-info-content">
                                <span class="label">Max POST</span>
                                <span class="value"><?php echo htmlspecialchars($sysInfo['max_post']); ?></span>
                            </div>
                        </div>
                        <div class="sys-info-item">
                            <div class="sys-info-icon"><i class="fas fa-memory"></i></div>
                            <div class="sys-info-content">
                                <span class="label">Memory Limit</span>
                                <span class="value"><?php echo htmlspecialchars($sysInfo['memory_limit']); ?></span>
                            </div>
                        </div>
                        <div class="sys-info-item">
                            <div class="sys-info-icon"><i class="fas fa-clock"></i></div>
                            <div class="sys-info-content">
                                <span class="label">Max Execution</span>
                                <span class="value"><?php echo htmlspecialchars($sysInfo['max_execution']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="settings-section">
                    <div class="section-header">
                        <div class="section-icon system" style="background: linear-gradient(135deg, #27ae60, #229954);">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="section-title">
                            <h3>Statistik Database</h3>
                            <p>Jumlah data di setiap tabel</p>
                        </div>
                    </div>

                    <div class="record-stats-grid">
                        <div class="record-stat-card" style="--rc-color:#3498db;--rc-color-dark:#2980b9;">
                            <i class="fas fa-newspaper icon"></i>
                            <span class="value"><?php echo number_format($recordCounts['articles']); ?></span>
                            <span class="label">Artikel</span>
                        </div>
                        <div class="record-stat-card" style="--rc-color:#27ae60;--rc-color-dark:#229954;">
                            <i class="fas fa-users icon"></i>
                            <span class="value"><?php echo number_format($recordCounts['users']); ?></span>
                            <span class="label">Users</span>
                        </div>
                        <div class="record-stat-card" style="--rc-color:#9b59b6;--rc-color-dark:#8e44ad;">
                            <i class="fas fa-tags icon"></i>
                            <span class="value"><?php echo number_format($recordCounts['categories']); ?></span>
                            <span class="label">Kategori</span>
                        </div>
                        <div class="record-stat-card" style="--rc-color:#f39c12;--rc-color-dark:#e67e22;">
                            <i class="fas fa-comments icon"></i>
                            <span class="value"><?php echo number_format($recordCounts['comments']); ?></span>
                            <span class="label">Komentar</span>
                        </div>
                    </div>
                </div>

                <div class="settings-section">
                    <div class="section-header">
                        <div class="section-icon system" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="section-title">
                            <h3>Danger Zone</h3>
                            <p>Aksi berisiko tinggi - berhati-hatilah!</p>
                        </div>
                    </div>

                    <div class="switch-row">
                        <div class="switch-row-info">
                            <h5>🔄 Reset Semua Pengaturan</h5>
                            <p>Kembalikan semua pengaturan ke nilai default awal. Data artikel & user tidak terpengaruh.</p>
                        </div>
                        <button type="button" class="btn-action danger" onclick="resetSettings()">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </div>

            </div>

        </div>

        <!-- ===== SIDEBAR: LIVE PREVIEW ===== -->
        <div class="settings-sidebar">
            
            <!-- Live Preview Card -->
            <div class="live-preview-card">
                <h4><i class="fas fa-eye"></i> Live Preview</h4>

                <!-- Nama Kampus -->
                <div class="preview-box">
                    <span class="preview-label">Nama Kampus</span>
                    <div class="preview-value" id="previewNama">
                        <?php echo htmlspecialchars($settings['nama_kampus'] ?: '(belum diisi)'); ?>
                    </div>
                </div>

                <!-- Tagline -->
                <div class="preview-box">
                    <span class="preview-label">Tagline</span>
                    <div class="preview-value" id="previewTagline">
                        <?php echo htmlspecialchars($settings['tagline'] ?: '(belum diisi)'); ?>
                    </div>
                </div>

                <!-- Deskripsi -->
                <div class="preview-box">
                    <span class="preview-label">Deskripsi</span>
                    <div class="preview-value" id="previewDesc">
                        <?php echo htmlspecialchars(($settings['short_description'] ?? '') ?: '(belum diisi)'); ?>
                    </div>
                </div>

                <!-- SEO Preview -->
                <div class="preview-box" style="padding:0;">
                    <div style="padding:0.8rem 1rem 0.4rem;">
                        <span class="preview-label">Tampilan di Google</span>
                    </div>
                    <div class="seo-preview-box" id="seoPreviewBox">
                        <div class="seo-preview-url">
                            <div class="favicon-mini"></div>
                            <span><?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'blog-dosen.com'); ?></span>
                        </div>
                        <div class="seo-preview-title" id="seoTitle">
                            <?php echo htmlspecialchars($settings['meta_title'] ?: $settings['nama_kampus'] ?: 'Judul Website'); ?>
                        </div>
                        <div class="seo-preview-desc" id="seoDesc">
                            <?php echo htmlspecialchars($settings['meta_description'] ?: ($settings['short_description'] ?? 'Deskripsi website akan muncul di sini...')); ?>
                        </div>
                    </div>
                </div>

                <!-- Quote Preview -->
                <div class="preview-box" style="padding:0;">
                    <div style="padding:0.8rem 1rem 0.4rem;">
                        <span class="preview-label">Banner Quote</span>
                    </div>
                    <div class="quote-preview-box" id="quotePreviewBox" 
                         style="--preview-primary: <?php echo htmlspecialchars($settings['primary_color']); ?>;">
                        <div class="quote-preview-text" id="quoteText">
                            <?php echo htmlspecialchars($settings['quote'] ?: 'Kutipan inspiratif akan tampil di sini...'); ?>
                        </div>
                        <div class="quote-preview-author" id="quoteAuthor">
                            — <?php echo htmlspecialchars($settings['quote_author'] ?: 'Penulis'); ?>
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
        document.querySelectorAll('.settings-tab').forEach(function(tab) {
            tab.classList.toggle('active', tab.getAttribute('data-tab') === tabName);
        });
        document.querySelectorAll('.settings-panel').forEach(function(panel) {
            panel.classList.toggle('active', panel.id === 'panel-' + tabName);
        });
        window.location.hash = tabName;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    // Load tab from URL hash
    var hash = window.location.hash.replace('#', '');
    var validTabs = ['general', 'contact', 'social', 'seo', 'appearance', 'quote', 'advanced', 'system'];
    if (validTabs.indexOf(hash) !== -1) {
        switchTab(hash);
    }

    // ===== UPDATE PREVIEW =====
    window.updatePreview = function(elementId, value) {
        var el = document.getElementById(elementId);
        if (!el) return;
        if (value && value.trim()) {
            el.textContent = value;
            el.classList.remove('empty');
        } else {
            el.textContent = '(belum diisi)';
            el.classList.add('empty');
        }
    };

    // ===== SEO PREVIEW =====
    window.updateSeoPreview = function() {
        var title = document.querySelector('input[name="meta_title"]').value || 
                    document.querySelector('input[name="nama_kampus"]')?.value || 
                    'Judul Website';
        var desc = document.querySelector('textarea[name="meta_description"]').value || 
                   document.querySelector('textarea[name="short_description"]')?.value || 
                   'Deskripsi website akan muncul di sini...';
        
        var titleEl = document.getElementById('seoTitle');
        var descEl = document.getElementById('seoDesc');
        if (titleEl) titleEl.textContent = title;
        if (descEl) descEl.textContent = desc;
    };

    // ===== QUOTE PREVIEW =====
    window.updateQuotePreview = function() {
        var quote = document.querySelector('textarea[name="quote"]').value || 'Kutipan inspiratif akan tampil di sini...';
        var author = document.querySelector('input[name="quote_author"]').value || 'Penulis';
        
        var textEl = document.getElementById('quoteText');
        var authorEl = document.getElementById('quoteAuthor');
        if (textEl) textEl.textContent = quote;
        if (authorEl) authorEl.textContent = '— ' + author;
    };

    // ===== COLOR PICKER =====
    window.syncColor = function(name, value, fromText) {
        var picker = document.getElementById(name + 'Picker') || document.getElementById(name.replace('_', '') + 'Picker');
        var text = document.getElementById(name);
        var preview = document.getElementById(name.replace('_', '') + 'Preview') || 
                     document.getElementById(name.charAt(0).toUpperCase() + name.slice(1).replace('_', '') + 'Preview');
        
        // Format validation
        if (!/^#[0-9a-fA-F]{6}$/.test(value)) return;
        
        if (fromText && picker) {
            picker.value = value;
        } else if (text) {
            text.value = value.toUpperCase();
        }
        
        // Update preview
        var previewId = name === 'primary_color' ? 'primaryColorPreview' : 'accentColorPreview';
        var previewEl = document.getElementById(previewId);
        if (previewEl) previewEl.style.background = value;
        
        // Update quote preview primary color
        if (name === 'primary_color') {
            var quoteBox = document.getElementById('quotePreviewBox');
            if (quoteBox) quoteBox.style.setProperty('--preview-primary', value);
        }
    };

    window.setColor = function(name, color) {
        syncColor(name, color, false);
        var text = document.getElementById(name);
        if (text) text.value = color.toUpperCase();
    };

    // ===== IMAGE PREVIEW =====
    window.previewImage = function(input, previewId, boxId) {
        var file = input.files[0];
        if (!file) return;
        
        // Validation
        if (file.size > 5 * 1024 * 1024) {
            if (typeof UI !== 'undefined') UI.error('Ukuran file terlalu besar!');
            input.value = '';
            return;
        }
        
        var reader = new FileReader();
        reader.onload = function(e) {
            var box = document.getElementById(boxId);
            if (box) {
                box.classList.remove('empty');
                box.innerHTML = '<img src="' + e.target.result + '" alt="Preview" id="' + previewId + '">';
            }
        };
        reader.readAsDataURL(file);
    };

    // ===== RESET SETTINGS =====
    window.resetSettings = function() {
        if (typeof UI !== 'undefined' && UI.confirm) {
            UI.confirm(
                'Reset semua pengaturan ke default? Data artikel, user, dan kategori TIDAK terpengaruh.',
                function() {
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = '<input type="hidden" name="action" value="reset_settings">';
                    document.body.appendChild(form);
                    if (typeof UI !== 'undefined') UI.showLoading('Mereset pengaturan...');
                    form.submit();
                },
                { danger: true, icon: '🔄', title: 'Reset Semua Pengaturan?' }
            );
        } else {
            if (confirm('Reset semua pengaturan ke default?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="reset_settings">';
                document.body.appendChild(form);
                form.submit();
            }
        }
    };

    window.confirmReset = function(e) {
        e.preventDefault();
        resetSettings();
        return false;
    };

    // ===== CLEAR CACHE =====
    window.clearCache = function() {
        if (typeof UI !== 'undefined' && UI.confirm) {
            UI.confirm(
                'Bersihkan semua cache? Website akan memuat ulang data fresh.',
                function() {
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = '<input type="hidden" name="action" value="clear_cache">';
                    document.body.appendChild(form);
                    if (typeof UI !== 'undefined') UI.showLoading('Membersihkan cache...');
                    form.submit();
                },
                { icon: '🧹', title: 'Bersihkan Cache?' }
            );
        } else {
            if (confirm('Bersihkan cache?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="clear_cache">';
                document.body.appendChild(form);
                form.submit();
            }
        }
    };

    // ===== FORM SUBMIT LOADING =====
    document.querySelectorAll('form[data-form]').forEach(function(form) {
        form.addEventListener('submit', function() {
            var btn = form.querySelector('.btn-submit.primary');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
            }
        });
    });

    // ===== UNSAVED CHANGES =====
    var isDirty = false;
    document.querySelectorAll('form[data-form] input, form[data-form] textarea, form[data-form] select').forEach(function(el) {
        el.addEventListener('input', function() { isDirty = true; });
        el.addEventListener('change', function() { isDirty = true; });
    });
    document.querySelectorAll('form[data-form]').forEach(function(form) {
        form.addEventListener('submit', function() { isDirty = false; });
    });
    window.addEventListener('beforeunload', function(e) {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    console.log('%c⚙️ Settings Page Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
})();
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>