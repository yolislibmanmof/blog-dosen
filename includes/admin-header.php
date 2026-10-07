<?php
// ============================================
// 🎓 ADMIN HEADER - ULTIMATE EDITION
// ============================================
if (!function_exists('url')) {
    require_once __DIR__ . '/../config/functions.php';
}

// ============================================
// 🔒 SESSION SAFETY
// ============================================
$adminUserId  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$adminIsAdmin = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');
$pageTitleFinal = isset($pageTitle) ? $pageTitle : 'Admin Panel';

// ============================================
// 👤 DATA USER (dengan fallback aman)
// ============================================
$adminUser = [
    'nama'    => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'User',
    'foto'    => null,
    'jabatan' => 'Dosen',
    'email'   => '',
    'prodi'   => '',
];
try {
    if ($adminUserId > 0) {
        $st = db()->prepare("SELECT nama, foto, jabatan, email, prodi FROM users WHERE id = ?");
        $st->execute([$adminUserId]);
        $row = $st->fetch();
        if ($row) $adminUser = array_merge($adminUser, $row);
    }
} catch (Exception $e) {}

// ============================================
// 🔔 NOTIFIKASI (dengan grouping yang lebih kaya)
// ============================================
$adminPending     = 0;
$adminDrafts      = 0;
$adminUnread      = 0;
$adminTotalArticles = 0;
$adminNotifItems  = [];

try {
    // Komentar pending
    $st = db()->prepare("
        SELECT COUNT(*) FROM comments c 
        JOIN articles a ON c.article_id = a.id 
        WHERE a.author_id = ? AND c.status = 'pending'
    ");
    $st->execute([$adminUserId]);
    $adminPending = (int)$st->fetchColumn();
    
    // Draft
    $st = db()->prepare("SELECT COUNT(*) FROM articles WHERE author_id = ? AND status = 'draft'");
    $st->execute([$adminUserId]);
    $adminDrafts = (int)$st->fetchColumn();
    
    // Total artikel
    $st = db()->prepare("SELECT COUNT(*) FROM articles WHERE author_id = ?");
    $st->execute([$adminUserId]);
    $adminTotalArticles = (int)$st->fetchColumn();
    
    // Notifikasi detail (untuk dropdown)
    if ($adminPending > 0) {
        $adminNotifItems[] = [
            'icon' => '💬',
            'text' => $adminPending . ' komentar menunggu moderasi',
            'link' => url('admin/comments.php'),
            'type' => 'warning',
            'time' => 'Baru saja'
        ];
    }
    if ($adminDrafts > 0) {
        $adminNotifItems[] = [
            'icon' => '📝',
            'text' => $adminDrafts . ' draft belum dipublikasikan',
            'link' => url('admin/articles.php?status=draft'),
            'type' => 'info',
            'time' => 'Perlu review'
        ];
    }
} catch (Exception $e) {}

$adminNotifTotal = $adminPending + $adminDrafts;

// Avatar
$adminAvatarUrl = fotoUrl($adminUser['foto'] ?? '');
$adminAvatarFallback = 'https://ui-avatars.com/api/?name=' . urlencode($adminUser['nama']) . '&background=1e3a5f&color=fff&size=80';
$adminFirstName = explode(' ', $adminUser['nama'])[0];

// ============================================
// 🧭 BREADCRUMB DINAMIS
// ============================================
$currentPage = basename($_SERVER['PHP_SELF']);
$breadcrumbMap = [
    'index.php'          => ['icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
    'articles.php'       => ['icon' => 'fa-newspaper',      'label' => 'Artikel Saya'],
    'article-edit.php'   => ['icon' => 'fa-pen-fancy',      'label' => 'Editor Artikel'],
    'comments.php'       => ['icon' => 'fa-comments',       'label' => 'Komentar'],
    'profile.php'        => ['icon' => 'fa-user-edit',      'label' => 'Profil Saya'],
    'categories.php'     => ['icon' => 'fa-tags',           'label' => 'Kategori'],
    'settings.php'       => ['icon' => 'fa-cog',            'label' => 'Pengaturan'],
];
$currentBreadcrumb = isset($breadcrumbMap[$currentPage]) 
    ? $breadcrumbMap[$currentPage] 
    : ['icon' => 'fa-file', 'label' => $pageTitleFinal];

// ============================================
// ⏰ LIVE INFO
// ============================================
$todayDate = date('d M Y');
$todayDay = date('l'); // Hari dalam bahasa Inggris
$dayMap = [
    'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
    'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu'
];
$todayDayID = isset($dayMap[$todayDay]) ? $dayMap[$todayDay] : $todayDay;
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#1e3a5f">
    <meta name="author" content="<?php echo htmlspecialchars($adminUser['nama']); ?>">
    
    <title><?php echo htmlspecialchars($pageTitleFinal); ?> - Admin Blog Dosen</title>

    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo asset('css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('css/admin.css'); ?>">
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">
</head>
<body class="admin-body">

<!-- ============================================ -->
<!-- 📢 FLASH MESSAGES (Enhanced dengan auto-dismiss) -->
<!-- ============================================ -->
<?php 
$flashTypes = ['success', 'error', 'warning', 'info'];
foreach ($flashTypes as $type): 
    $msg = flash($type);
    if ($msg): 
        $icons = [
            'success' => 'check-circle',
            'error'   => 'exclamation-circle',
            'warning' => 'exclamation-triangle',
            'info'    => 'info-circle'
        ];
?>
    <div class="alert alert-<?php echo $type; ?>" data-auto-dismiss="5000">
        <i class="fas fa-<?php echo $icons[$type]; ?>"></i>
        <span><?php echo htmlspecialchars($msg); ?></span>
        <button type="button" class="alert-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    </div>
<?php 
    endif;
endforeach; 
?>

<!-- ============================================ -->
<!-- 🎯 ADMIN TOPBAR (Enhanced) -->
<!-- ============================================ -->
<div class="admin-topbar">
    
    <!-- Burger Menu (Mobile) -->
    <button class="topbar-btn topbar-burger" onclick="topbarToggleMobileSidebar()" title="Menu (Ctrl+B)">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Brand -->
    <a href="<?php echo url('admin/'); ?>" class="topbar-brand">
        <div class="topbar-brand-icon">
            <i class="fas fa-graduation-cap"></i>
        </div>
        <div class="topbar-brand-text">
            <strong>Blog Dosen</strong>
            <small>Admin Panel</small>
        </div>
    </a>

    <!-- Breadcrumb (desktop only) -->
    <div class="topbar-breadcrumb">
        <a href="<?php echo url('admin/'); ?>"><i class="fas fa-home"></i></a>
        <i class="fas fa-chevron-right separator"></i>
        <span class="current">
            <i class="fas <?php echo $currentBreadcrumb['icon']; ?>"></i>
            <?php echo htmlspecialchars($currentBreadcrumb['label']); ?>
        </span>
    </div>

    <div class="topbar-spacer"></div>

    <!-- Live Clock -->
    <div class="topbar-clock" id="topbarClock" title="<?php echo $todayDayID; ?>, <?php echo $todayDate; ?>">
        <i class="fas fa-clock"></i>
        <span id="topbarTime">--:--</span>
    </div>

    <!-- Search Trigger (Command Palette) -->
    <button class="topbar-btn topbar-search-trigger" onclick="topbarOpenSearch()" title="Cari (Ctrl+K)">
        <i class="fas fa-search"></i>
        <kbd>⌘K</kbd>
    </button>

    <!-- View Website -->
    <a class="topbar-btn" href="<?php echo url(); ?>" target="_blank" title="Lihat Website">
        <i class="fas fa-globe"></i>
    </a>

    <!-- Dark Mode Toggle -->
    <button class="topbar-btn" onclick="topbarToggleDarkMode()" title="Mode Gelap/Terang">
        <i class="fas fa-moon" id="adminThemeIcon"></i>
    </button>

    <!-- Notifications -->
    <div class="topbar-drop-wrap">
        <button class="topbar-btn" onclick="topbarToggleDrop('adminNotifDrop')" title="Notifikasi">
            <i class="fas fa-bell"></i>
            <?php if ($adminNotifTotal > 0): ?>
                <span class="topbar-badge"><?php echo $adminNotifTotal; ?></span>
            <?php endif; ?>
        </button>
        <div class="admin-dropdown admin-dropdown-wide" id="adminNotifDrop">
            <div class="admin-dropdown-head">
                <span>🔔 Notifikasi</span>
                <?php if ($adminNotifTotal > 0): ?>
                    <span class="notif-count-badge"><?php echo $adminNotifTotal; ?></span>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($adminNotifItems)): ?>
                <div class="admin-dropdown-body">
                    <?php foreach ($adminNotifItems as $item): ?>
                        <a class="admin-dropdown-item notif-item notif-<?php echo $item['type']; ?>" 
                           href="<?php echo $item['link']; ?>">
                            <div class="notif-icon"><?php echo $item['icon']; ?></div>
                            <div class="notif-content">
                                <div class="notif-text"><?php echo htmlspecialchars($item['text']); ?></div>
                                <div class="notif-time">
                                    <i class="fas fa-clock"></i> <?php echo $item['time']; ?>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right notif-arrow"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="admin-dropdown-foot">
                    <a href="<?php echo url('admin/'); ?>">
                        <i class="fas fa-tachometer-alt"></i> Lihat Dashboard
                    </a>
                </div>
            <?php else: ?>
                <div class="admin-dropdown-empty">
                    <div class="empty-icon">🎉</div>
                    <strong>Semua beres!</strong>
                    <p>Tidak ada notifikasi saat ini.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- User Menu -->
    <div class="topbar-drop-wrap">
        <button class="topbar-user" onclick="topbarToggleDrop('adminUserDrop')">
            <img src="<?php echo $adminAvatarUrl; ?>" 
                 onerror="this.src='<?php echo $adminAvatarFallback; ?>'" 
                 alt="Avatar"
                 class="topbar-user-avatar">
            <span class="topbar-username"><?php echo htmlspecialchars($adminFirstName); ?></span>
            <?php if ($adminIsAdmin): ?>
                <span class="topbar-role-badge">ADMIN</span>
            <?php endif; ?>
            <i class="fas fa-chevron-down"></i>
        </button>
        <div class="admin-dropdown admin-dropdown-wide" id="adminUserDrop">
            <div class="admin-dropdown-head user-head">
                <img src="<?php echo $adminAvatarUrl; ?>" 
                     onerror="this.src='<?php echo $adminAvatarFallback; ?>'" 
                     alt="Avatar">
                <div>
                    <strong><?php echo htmlspecialchars($adminUser['nama']); ?></strong>
                    <small><?php echo htmlspecialchars($adminUser['email']); ?></small>
                    <div class="user-role-tag">
                        <i class="fas fa-<?php echo $adminIsAdmin ? 'user-shield' : 'user-graduate'; ?>"></i>
                        <?php echo htmlspecialchars($adminUser['jabatan']); ?>
                    </div>
                </div>
            </div>
            
            <div class="admin-dropdown-body">
                <a class="admin-dropdown-item" href="<?php echo url('admin/profile.php'); ?>">
                    <i class="fas fa-user"></i> 
                    <span>Profil Saya</span>
                </a>
                <a class="admin-dropdown-item" href="<?php echo url('admin/articles.php'); ?>">
                    <i class="fas fa-newspaper"></i> 
                    <span>Artikel Saya</span>
                    <span class="item-count"><?php echo $adminTotalArticles; ?></span>
                </a>
                <a class="admin-dropdown-item" href="<?php echo url('admin/article-edit.php'); ?>">
                    <i class="fas fa-pen-fancy"></i> 
                    <span>Tulis Artikel Baru</span>
                </a>
                
                <?php if ($adminIsAdmin): ?>
                    <div class="dropdown-divider"></div>
                    <div class="dropdown-section-title">ADMINISTRATOR</div>
                    <a class="admin-dropdown-item" href="<?php echo url('admin/categories.php'); ?>">
                        <i class="fas fa-tags"></i> 
                        <span>Kelola Kategori</span>
                    </a>
                    <a class="admin-dropdown-item" href="<?php echo url('admin/settings.php'); ?>">
                        <i class="fas fa-cog"></i> 
                        <span>Pengaturan Sistem</span>
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="admin-dropdown-foot">
                <a href="<?php echo url('admin/logout.php'); ?>" 
                   class="logout-link" 
                   onclick="return confirm('Yakin ingin logout?');">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- 🔍 SEARCH MODAL (Command Palette) -->
<!-- ============================================ -->
<div class="topbar-search-modal" id="topbarSearchModal">
    <div class="search-modal-backdrop" onclick="topbarCloseSearch()"></div>
    <div class="search-modal-content">
        <div class="search-modal-header">
            <i class="fas fa-search"></i>
            <input type="text" 
                   id="topbarSearchInput" 
                   placeholder="Cari artikel, menu, atau aksi..." 
                   autocomplete="off">
            <kbd>ESC</kbd>
        </div>
        
        <div class="search-modal-body" id="topbarSearchResults">
            <!-- Quick Actions (default) -->
            <div class="search-section">
                <div class="search-section-title">⚡ Aksi Cepat</div>
                <a href="<?php echo url('admin/article-edit.php'); ?>" class="search-result-item">
                    <i class="fas fa-pen-fancy"></i>
                    <span>Tulis Artikel Baru</span>
                    <kbd>Ctrl+N</kbd>
                </a>
                <a href="<?php echo url('admin/'); ?>" class="search-result-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                    <kbd>Ctrl+D</kbd>
                </a>
                <a href="<?php echo url('admin/articles.php'); ?>" class="search-result-item">
                    <i class="fas fa-newspaper"></i>
                    <span>Lihat Semua Artikel</span>
                </a>
                <a href="<?php echo url('admin/comments.php'); ?>" class="search-result-item">
                    <i class="fas fa-comments"></i>
                    <span>Kelola Komentar</span>
                </a>
                <a href="<?php echo url('admin/profile.php'); ?>" class="search-result-item">
                    <i class="fas fa-user-edit"></i>
                    <span>Edit Profil</span>
                </a>
                <a href="<?php echo url(); ?>" target="_blank" class="search-result-item">
                    <i class="fas fa-external-link-alt"></i>
                    <span>Buka Website Publik</span>
                </a>
            </div>
            
            <!-- Articles Search Results (dynamic) -->
            <div class="search-section" id="searchArticlesSection" style="display:none;">
                <div class="search-section-title">📄 Hasil Pencarian</div>
                <div id="searchArticlesList"></div>
            </div>
        </div>
        
        <div class="search-modal-footer">
            <span><kbd>↑↓</kbd> Navigasi</span>
            <span><kbd>↵</kbd> Pilih</span>
            <span><kbd>ESC</kbd> Tutup</span>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- 📱 MOBILE OVERLAY -->
<!-- ============================================ -->
<div class="admin-mobile-overlay" id="adminMobileOverlay" onclick="topbarToggleMobileSidebar()"></div>

<!-- ============================================ -->
<!-- 🎨 TOPBAR ENHANCED STYLES -->
<!-- ============================================ -->
<style>
/* ===== BREADCRUMB ===== */
.topbar-breadcrumb {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-left: 1.5rem;
    padding-left: 1.5rem;
    border-left: 1px solid rgba(255,255,255,0.15);
    color: rgba(255,255,255,0.85);
    font-size: 0.88rem;
}
.topbar-breadcrumb a {
    color: rgba(255,255,255,0.7);
    text-decoration: none;
    transition: color 0.2s ease;
}
.topbar-breadcrumb a:hover { color: #f39c12; }
.topbar-breadcrumb .separator {
    font-size: 0.65rem;
    color: rgba(255,255,255,0.4);
}
.topbar-breadcrumb .current {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    color: white;
    font-weight: 600;
}
.topbar-breadcrumb .current i {
    color: #f39c12;
    font-size: 0.85rem;
}

/* ===== BRAND ENHANCED ===== */
.topbar-brand {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    text-decoration: none;
    color: white;
}
.topbar-brand-icon {
    width: 38px;
    height: 38px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    box-shadow: 0 4px 12px rgba(243, 156, 18, 0.3);
}
.topbar-brand-text {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
}
.topbar-brand-text strong {
    font-size: 1rem;
    font-weight: 700;
}
.topbar-brand-text small {
    font-size: 0.65rem;
    opacity: 0.7;
    letter-spacing: 1.5px;
    text-transform: uppercase;
}

/* ===== LIVE CLOCK ===== */
.topbar-clock {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: rgba(255,255,255,0.08);
    padding: 0.4rem 0.9rem;
    border-radius: 20px;
    color: white;
    font-size: 0.88rem;
    font-family: 'Courier New', monospace;
    font-weight: 600;
    border: 1px solid rgba(255,255,255,0.1);
}
.topbar-clock i {
    color: #f39c12;
    font-size: 0.85rem;
}

/* ===== SEARCH TRIGGER ===== */
.topbar-search-trigger {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0 1rem !important;
    width: auto !important;
}
.topbar-search-trigger kbd {
    background: rgba(255,255,255,0.15);
    padding: 0.1rem 0.5rem;
    border-radius: 4px;
    font-size: 0.7rem;
    font-family: 'Courier New', monospace;
}

/* ===== USER BADGE ===== */
.topbar-user {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.3rem 0.8rem 0.3rem 0.3rem !important;
}
.topbar-user-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #f39c12;
}
.topbar-role-badge {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    font-size: 0.6rem;
    font-weight: 800;
    padding: 0.15rem 0.5rem;
    border-radius: 8px;
    letter-spacing: 1px;
}

/* ===== ENHANCED DROPDOWNS ===== */
.admin-dropdown-wide {
    width: 340px;
}
.admin-dropdown-head.user-head {
    display: flex;
    gap: 0.8rem;
    align-items: center;
}
.admin-dropdown-head.user-head img {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #f39c12;
}
.admin-dropdown-head.user-head strong {
    display: block;
    color: #1e3a5f;
    font-size: 0.95rem;
    margin-bottom: 0.1rem;
}
.admin-dropdown-head.user-head small {
    display: block;
    color: #888;
    font-size: 0.78rem;
    margin-bottom: 0.3rem;
}
.user-role-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    background: #f4f6f9;
    color: #1e3a5f;
    padding: 0.2rem 0.6rem;
    border-radius: 10px;
    font-size: 0.72rem;
    font-weight: 600;
}

.admin-dropdown-body {
    max-height: 300px;
    overflow-y: auto;
}
.admin-dropdown-foot {
    background: #f8f9fa;
    padding: 0.6rem 1rem;
    border-top: 1px solid #e5e8ec;
    text-align: center;
}
.admin-dropdown-foot a {
    color: #1e3a5f;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    transition: color 0.2s ease;
}
.admin-dropdown-foot a:hover { color: #f39c12; }
.admin-dropdown-foot .logout-link {
    color: #e74c3c;
}
.admin-dropdown-foot .logout-link:hover { color: #c0392b; }

.dropdown-divider {
    height: 1px;
    background: #e5e8ec;
    margin: 0.5rem 0;
}
.dropdown-section-title {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: #999;
    font-weight: 700;
    padding: 0.5rem 1rem 0.3rem;
}

.admin-dropdown-item {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.7rem 1rem;
    color: #333;
    text-decoration: none;
    transition: background 0.2s ease;
    font-size: 0.88rem;
}
.admin-dropdown-item:hover {
    background: #f4f6f9;
}
.admin-dropdown-item i {
    width: 18px;
    color: #1e3a5f;
}
.admin-dropdown-item span {
    flex: 1;
}
.item-count {
    background: #f4f6f9;
    color: #1e3a5f;
    padding: 0.1rem 0.5rem;
    border-radius: 10px;
    font-size: 0.72rem;
    font-weight: 700;
}

/* ===== NOTIFICATION ITEMS ===== */
.notif-item {
    padding: 0.8rem 1rem;
    border-left: 3px solid transparent;
}
.notif-item.notif-warning { border-left-color: #f39c12; background: #fffbf0; }
.notif-item.notif-info { border-left-color: #3498db; background: #f0f8ff; }
.notif-item.notif-success { border-left-color: #27ae60; background: #f0fff4; }
.notif-item.notif-danger { border-left-color: #e74c3c; background: #fff5f5; }
.notif-item:hover {
    background: #f4f6f9 !important;
}
.notif-icon {
    font-size: 1.3rem;
    flex-shrink: 0;
}
.notif-content {
    flex: 1;
    min-width: 0;
}
.notif-text {
    font-size: 0.88rem;
    color: #333;
    margin-bottom: 0.2rem;
    line-height: 1.4;
}
.notif-time {
    font-size: 0.72rem;
    color: #888;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}
.notif-arrow {
    color: #ccc;
    font-size: 0.7rem;
    transition: transform 0.2s ease;
}
.notif-item:hover .notif-arrow {
    transform: translateX(3px);
    color: #f39c12;
}
.notif-count-badge {
    background: #e74c3c;
    color: white;
    padding: 0.1rem 0.5rem;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 700;
}
.admin-dropdown-empty {
    padding: 2.5rem 1rem;
    text-align: center;
}
.admin-dropdown-empty .empty-icon {
    font-size: 3rem;
    margin-bottom: 0.8rem;
    display: block;
}
.admin-dropdown-empty strong {
    display: block;
    color: #1e3a5f;
    font-size: 1rem;
    margin-bottom: 0.3rem;
}
.admin-dropdown-empty p {
    color: #888;
    font-size: 0.85rem;
    margin: 0;
}

/* ===== ENHANCED FLASH ALERTS ===== */
.alert {
    position: fixed;
    top: 80px;
    right: 20px;
    padding: 1rem 1.5rem;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    display: flex;
    align-items: center;
    gap: 0.8rem;
    font-size: 0.92rem;
    font-weight: 500;
    z-index: 9999;
    max-width: 400px;
    animation: slideInRight 0.3s ease;
    border-left: 4px solid;
}
.alert i:first-child {
    font-size: 1.2rem;
}
.alert-success {
    background: #d4edda;
    color: #155724;
    border-left-color: #28a745;
}
.alert-error {
    background: #f8d7da;
    color: #721c24;
    border-left-color: #dc3545;
}
.alert-warning {
    background: #fff3cd;
    color: #856404;
    border-left-color: #ffc107;
}
.alert-info {
    background: #d1ecf1;
    color: #0c5460;
    border-left-color: #17a2b8;
}
.alert-close {
    background: transparent;
    border: none;
    color: inherit;
    opacity: 0.6;
    cursor: pointer;
    padding: 0.2rem 0.5rem;
    margin-left: auto;
    transition: opacity 0.2s ease;
}
.alert-close:hover { opacity: 1; }

@keyframes slideInRight {
    from {
        transform: translateX(400px);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}
@keyframes slideOutRight {
    to {
        transform: translateX(400px);
        opacity: 0;
    }
}

/* ===== SEARCH MODAL (Command Palette) ===== */
.topbar-search-modal {
    position: fixed;
    inset: 0;
    z-index: 10000;
    display: none;
    align-items: flex-start;
    justify-content: center;
    padding-top: 10vh;
}
.topbar-search-modal.show {
    display: flex;
    animation: modalFadeIn 0.2s ease;
}
@keyframes modalFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.search-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
}
.search-modal-content {
    position: relative;
    width: 90%;
    max-width: 600px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 25px 60px rgba(0,0,0,0.3);
    overflow: hidden;
    animation: modalSlideDown 0.3s ease;
}
@keyframes modalSlideDown {
    from {
        transform: translateY(-30px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}
.search-modal-header {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 1.2rem 1.5rem;
    border-bottom: 1px solid #e5e8ec;
    background: #f8f9fa;
}
.search-modal-header i {
    color: #1e3a5f;
    font-size: 1.2rem;
}
.search-modal-header input {
    flex: 1;
    border: none;
    background: transparent;
    outline: none;
    font-size: 1.05rem;
    color: #333;
}
.search-modal-header kbd {
    background: #e9ecef;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.72rem;
    color: #666;
    font-family: 'Courier New', monospace;
}
.search-modal-body {
    max-height: 50vh;
    overflow-y: auto;
    padding: 0.5rem;
}
.search-section {
    padding: 0.5rem 0;
}
.search-section-title {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: #888;
    font-weight: 700;
    padding: 0.5rem 1rem;
}
.search-result-item {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 0.8rem 1rem;
    color: #333;
    text-decoration: none;
    border-radius: 10px;
    transition: background 0.15s ease;
}
.search-result-item:hover,
.search-result-item.active {
    background: #f4f6f9;
}
.search-result-item i {
    width: 20px;
    color: #1e3a5f;
    font-size: 1rem;
}
.search-result-item span {
    flex: 1;
    font-size: 0.95rem;
}
.search-result-item kbd {
    background: #e9ecef;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    font-size: 0.7rem;
    color: #666;
    font-family: 'Courier New', monospace;
}
.search-modal-footer {
    display: flex;
    justify-content: center;
    gap: 1.5rem;
    padding: 0.8rem;
    background: #f8f9fa;
    border-top: 1px solid #e5e8ec;
    font-size: 0.78rem;
    color: #888;
}
.search-modal-footer kbd {
    background: white;
    padding: 0.1rem 0.4rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-family: 'Courier New', monospace;
    border: 1px solid #e0e0e0;
    margin-right: 0.2rem;
}

/* ===== MOBILE OVERLAY ===== */
.admin-mobile-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 999;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
}
.admin-mobile-overlay.show {
    opacity: 1;
    pointer-events: auto;
}

/* ===== DARK MODE ===== */
html[data-theme="dark"] .topbar-breadcrumb {
    border-left-color: rgba(255,255,255,0.1);
}
html[data-theme="dark"] .admin-dropdown,
html[data-theme="dark"] .search-modal-content {
    background: #1e2638;
    color: #e5e8ec;
}
html[data-theme="dark"] .admin-dropdown-head,
html[data-theme="dark"] .admin-dropdown-foot,
html[data-theme="dark"] .search-modal-header,
html[data-theme="dark"] .search-modal-footer {
    background: #16203a;
    border-color: #2a3550;
    color: #e5e8ec;
}
html[data-theme="dark"] .admin-dropdown-item,
html[data-theme="dark"] .search-result-item {
    color: #d5dae2;
}
html[data-theme="dark"] .admin-dropdown-item:hover,
html[data-theme="dark"] .search-result-item:hover {
    background: #25304a;
}
html[data-theme="dark"] .admin-dropdown-head.user-head strong,
html[data-theme="dark"] .admin-dropdown-empty strong,
html[data-theme="dark"] .search-modal-header input,
html[data-theme="dark"] .search-result-item span {
    color: #e5e8ec;
}
html[data-theme="dark"] .notif-item.notif-warning { background: #2d2416; }
html[data-theme="dark"] .notif-item.notif-info { background: #162636; }

/* ===== RESPONSIVE ===== */
@media (max-width: 992px) {
    .topbar-breadcrumb,
    .topbar-clock,
    .topbar-search-trigger kbd,
    .topbar-username,
    .topbar-role-badge {
        display: none;
    }
    .topbar-user {
        padding: 0.3rem !important;
    }
    .admin-dropdown-wide {
        position: fixed;
        right: 10px;
        left: 10px;
        width: auto;
    }
}
@media (max-width: 576px) {
    .topbar-brand-text {
        display: none;
    }
    .alert {
        left: 10px;
        right: 10px;
        max-width: none;
    }
}
</style>

<!-- Sidebar otomatis termuat -->
<?php include __DIR__ . '/sidebar.php'; ?>

<!-- ============================================ -->
<!-- ⚡ TOPBAR SCRIPTS -->
<!-- ============================================ -->
<script>
// ===== NAMESPACE: Semua fungsi topbar di-prefix 'topbar' =====
// untuk menghindari konflik dengan fungsi di file lain

// ===== DROPDOWN TOGGLE =====
function topbarToggleDrop(id) {
    var drops = document.querySelectorAll('.admin-dropdown');
    for (var i = 0; i < drops.length; i++) {
        if (drops[i].id !== id) drops[i].classList.remove('show');
    }
    var el = document.getElementById(id);
    if (el) el.classList.toggle('show');
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.topbar-drop-wrap') && !e.target.closest('.admin-dropdown')) {
        var drops = document.querySelectorAll('.admin-dropdown');
        for (var i = 0; i < drops.length; i++) drops[i].classList.remove('show');
    }
});

// ===== MOBILE SIDEBAR =====
function topbarToggleMobileSidebar() {
    var sb = document.getElementById('adminSidebar');
    var ov = document.getElementById('adminMobileOverlay');
    if (sb) {
        sb.classList.toggle('mobile-open');
        if (ov) ov.classList.toggle('show');
    }
}

// ===== DARK MODE =====
function topbarToggleDarkMode() {
    var html = document.documentElement;
    var isDark = html.getAttribute('data-theme') === 'dark';
    html.setAttribute('data-theme', isDark ? 'light' : 'dark');
    localStorage.setItem('adminTheme', isDark ? 'light' : 'dark');
    topbarUpdateThemeIcon();
}

function topbarUpdateThemeIcon() {
    var icon = document.getElementById('adminThemeIcon');
    if (!icon) return;
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
}

// Init theme
(function() {
    if (localStorage.getItem('adminTheme') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
    topbarUpdateThemeIcon();
})();

// ===== LIVE CLOCK =====
function topbarUpdateClock() {
    var el = document.getElementById('topbarTime');
    if (!el) return;
    var now = new Date();
    var h = String(now.getHours()).padStart(2, '0');
    var m = String(now.getMinutes()).padStart(2, '0');
    var s = String(now.getSeconds()).padStart(2, '0');
    el.textContent = h + ':' + m + ':' + s;
}
topbarUpdateClock();
setInterval(topbarUpdateClock, 1000);

// ===== SEARCH MODAL =====
function topbarOpenSearch() {
    var modal = document.getElementById('topbarSearchModal');
    if (modal) {
        modal.classList.add('show');
        setTimeout(function() {
            var input = document.getElementById('topbarSearchInput');
            if (input) input.focus();
        }, 100);
    }
}

function topbarCloseSearch() {
    var modal = document.getElementById('topbarSearchModal');
    if (modal) {
        modal.classList.remove('show');
        var input = document.getElementById('topbarSearchInput');
        if (input) input.value = '';
    }
}

// ===== AUTO-DISMISS ALERTS =====
(function() {
    var alerts = document.querySelectorAll('.alert[data-auto-dismiss]');
    alerts.forEach(function(alert) {
        var duration = parseInt(alert.getAttribute('data-auto-dismiss'));
        setTimeout(function() {
            alert.style.animation = 'slideOutRight 0.3s ease forwards';
            setTimeout(function() { alert.remove(); }, 300);
        }, duration);
    });
})();

// ===== KEYBOARD SHORTCUTS =====
document.addEventListener('keydown', function(e) {
    // Ctrl+K atau Cmd+K : open search
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        topbarOpenSearch();
    }
    // Ctrl+B atau Cmd+B : toggle sidebar mobile
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
        e.preventDefault();
        topbarToggleMobileSidebar();
    }
    // ESC : close search modal
    if (e.key === 'Escape') {
        topbarCloseSearch();
        var drops = document.querySelectorAll('.admin-dropdown');
        for (var i = 0; i < drops.length; i++) drops[i].classList.remove('show');
    }
});

// ===== SEARCH NAVIGATION =====
(function() {
    var input = document.getElementById('topbarSearchInput');
    if (!input) return;
    
    input.addEventListener('keydown', function(e) {
        var items = document.querySelectorAll('.search-result-item');
        var active = document.querySelector('.search-result-item.active');
        var idx = -1;
        
        items.forEach(function(item, i) {
            if (item === active) idx = i;
        });
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (active) active.classList.remove('active');
            idx = (idx + 1) % items.length;
            if (items[idx]) {
                items[idx].classList.add('active');
                items[idx].scrollIntoView({ block: 'nearest' });
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (active) active.classList.remove('active');
            idx = idx <= 0 ? items.length - 1 : idx - 1;
            if (items[idx]) {
                items[idx].classList.add('active');
                items[idx].scrollIntoView({ block: 'nearest' });
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (active) {
                active.click();
            } else if (items.length > 0) {
                items[0].click();
            }
        }
    });
})();

console.log('%c🎓 Admin Header Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
console.log('%cShortcuts: ⌘K Search | ⌘B Sidebar | ESC Close', 'font-size:11px;color:#888;');
</script>