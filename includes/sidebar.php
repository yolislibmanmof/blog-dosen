<?php
// ============================================
// 🎓 ADMIN SIDEBAR - CLEAN ULTIMATE EDITION
// Hanya menampilkan menu yang filenya ADA
// ============================================

$currentPage = basename($_SERVER['PHP_SELF']);
$isAdmin = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// ============================================
// 📊 LIVE STATISTICS (optimal: 3 query saja)
// ============================================
// ✅ RENAMED: $stats -> $sidebarStats (mencegah tabrakan dengan variabel halaman lain)
$sidebarStats = [
    'total_articles' => 0,
    'published' => 0,
    'drafts' => 0,
    'total_views' => 0,
    'pending_comments' => 0,
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
    $as = $stmt->fetch();
    if ($as) {
        $sidebarStats['total_articles'] = (int)$as['total'];
        $sidebarStats['published'] = (int)$as['published'];
        $sidebarStats['drafts'] = (int)$as['drafts'];
        $sidebarStats['total_views'] = (int)$as['total_views'];
    }
    
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM comments c
        JOIN articles a ON c.article_id = a.id
        WHERE a.author_id = ? AND c.status = 'pending'
    ");
    $stmt->execute([$userId]);
    $sidebarStats['pending_comments'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// ============================================
// 📅 AKTIVITAS TERAKHIR (3 terakhir saja)
// ============================================
$recentActivities = [];
try {
    $stmt = db()->prepare("
        (SELECT 'article' as type, title as text, created_at as time, slug as link 
         FROM articles WHERE author_id = ? ORDER BY created_at DESC LIMIT 3)
        UNION ALL
        (SELECT 'comment' as type, CONCAT('Komentar dari ', c.nama) as text, 
                c.created_at as time, a.slug as link
         FROM comments c JOIN articles a ON c.article_id = a.id 
         WHERE a.author_id = ? ORDER BY c.created_at DESC LIMIT 2)
        ORDER BY time DESC LIMIT 3
    ");
    $stmt->execute([$userId, $userId]);
    $recentActivities = $stmt->fetchAll();
} catch (Exception $e) {}

// ============================================
// 💾 STORAGE USAGE (dengan cache 5 menit)
// ============================================
$storageUsedMB = 0;
$cacheKey = 'storage_usage_cache';
$cacheTime = 300; // 5 menit

// ✅ Cek apakah cache masih valid
if (isset($_SESSION[$cacheKey]) && 
    isset($_SESSION[$cacheKey . '_time']) && 
    (time() - $_SESSION[$cacheKey . '_time']) < $cacheTime) {
    $storageUsedMB = $_SESSION[$cacheKey];
} else {
    // Hitung ulang jika cache expired
    try {
        $uploadPath = __DIR__ . '/../assets/uploads/';
        if (is_dir($uploadPath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadPath, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            $totalSize = 0;
            foreach ($iterator as $file) {
                if ($file->isFile()) $totalSize += $file->getSize();
            }
            $storageUsedMB = round($totalSize / 1024 / 1024, 2);
            
            // Simpan ke cache
            $_SESSION[$cacheKey] = $storageUsedMB;
            $_SESSION[$cacheKey . '_time'] = time();
        }
    } catch (Exception $e) {}
}

$storageLimit = 100;
$storagePercent = min(100, (int)round(($storageUsedMB / $storageLimit) * 100));

// ============================================
// 🎯 PROGRES TARGET MINGGUAN
// ============================================
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weeklyArticles = 0;
try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM articles WHERE author_id = ? AND DATE(created_at) >= ?");
    $stmt->execute([$userId, $weekStart]);
    $weeklyArticles = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
$weeklyTarget = 3;
$weeklyProgress = min(100, (int)round(($weeklyArticles / $weeklyTarget) * 100));

// ============================================
// 👤 USER INFO
// ============================================
$userInfo = [
    'nama' => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'User',
    'foto' => '/assets/uploads/default.png',
    'jabatan' => 'Dosen',
];
try {
    $stmt = db()->prepare("SELECT nama, foto, jabatan FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if ($u) {
        $userInfo['nama'] = $u['nama'];
        $userInfo['foto'] = $u['foto'] ?: '/assets/uploads/default.png';
        $userInfo['jabatan'] = $u['jabatan'] ?: 'Dosen';
    }
} catch (Exception $e) {}

// ============================================
// 💡 QUOTE OF THE DAY
// ============================================
$quotes = [
    "Menulis adalah bekerja untuk keabadian.",
    "Ilmu tanpa amal bagai pohon tanpa buah.",
    "Setiap tulisan adalah jejak untuk masa depan.",
    "Penelitian tanpa publikasi adalah kesia-siaan.",
    "Jadilah dosen yang menginspirasi.",
    "Kualitas lebih penting dari kuantitas.",
    "Tridharma adalah panggilan jiwa.",
];
$todayQuote = $quotes[array_rand($quotes)];

// Helper functions
$isAct = function($pages) use ($currentPage) {
    $pages = is_array($pages) ? $pages : [$pages];
    return in_array($currentPage, $pages) ? 'active' : '';
};
$isSecOpen = function($secId) {
    // Default state: sections terbuka
    return '';
};

// Helper timeAgo
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $time = strtotime($datetime);
        $diff = time() - $time;
        if ($diff < 60) return 'Baru saja';
        if ($diff < 3600) return floor($diff/60) . 'm lalu';
        if ($diff < 86400) return floor($diff/3600) . 'j lalu';
        if ($diff < 604800) return floor($diff/86400) . 'h lalu';
        return date('d M', $time);
    }
}
?>

<aside class="admin-sidebar" id="adminSidebar">
    
    <!-- ============ USER PROFILE CARD ============ -->
    <div class="sidebar-header">
        <div class="user-profile">
            <div class="user-avatar-wrapper">
                <img src="<?php echo url(ltrim($userInfo['foto'], '/')); ?>" 
                     alt="Avatar" 
                     class="user-avatar"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($userInfo['nama']); ?>&background=1e3a5f&color=fff&size=100'">
                <span class="status-indicator" title="Online"></span>
            </div>
            <div class="user-info">
                <h3><?php echo htmlspecialchars($userInfo['nama']); ?></h3>
                <p class="user-role">
                    <i class="fas fa-<?php echo $isAdmin ? 'user-shield' : 'user-graduate'; ?>"></i>
                    <?php echo htmlspecialchars($userInfo['jabatan']); ?>
                </p>
                <?php if ($isAdmin): ?>
                    <span class="badge badge-admin">
                        <i class="fas fa-crown"></i> ADMIN
                    </span>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="quick-stats-mini">
            <div class="mini-stat" title="Total Views">
                <i class="fas fa-eye"></i>
                <span><?php echo number_format($sidebarStats['total_views']); ?></span>
                <small>Views</small>
            </div>
            <div class="mini-stat" title="Total Artikel">
                <i class="fas fa-newspaper"></i>
                <span><?php echo $sidebarStats['total_articles']; ?></span>
                <small>Artikel</small>
            </div>
            <div class="mini-stat <?php echo $sidebarStats['pending_comments'] > 0 ? 'mini-stat-alert' : ''; ?>" 
                 title="Komentar Pending">
                <i class="fas fa-comments"></i>
                <span><?php echo $sidebarStats['pending_comments']; ?></span>
                <small>Pending</small>
            </div>
        </div>
    </div>

    <!-- ============ QUICK SEARCH ============ -->
    <div class="sidebar-search">
        <form action="<?php echo url('search.php'); ?>" method="GET">
            <div class="search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" name="q" placeholder="Cari artikel..." autocomplete="off">
                <kbd>⌘K</kbd>
            </div>
        </form>
    </div>

    <!-- ============ MAIN NAVIGATION ============ -->
    <nav class="sidebar-nav">
        
        <!-- SECTION: UTAMA -->
        <div class="nav-section" data-section="main">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <span class="nav-section-title">UTAMA</span>
                <i class="fas fa-chevron-down section-toggle"></i>
            </div>
            <div class="nav-section-content">
                <a href="<?php echo url('admin/'); ?>" class="<?php echo $isAct('index.php'); ?>">
                    <div class="nav-icon"><i class="fas fa-tachometer-alt"></i></div>
                    <span class="nav-text">Dashboard</span>
                </a>
                
                <a href="<?php echo url('admin/articles.php'); ?>" class="<?php echo $isAct('articles.php'); ?>">
                    <div class="nav-icon"><i class="fas fa-newspaper"></i></div>
                    <span class="nav-text">Artikel Saya</span>
                    <?php if ($sidebarStats['drafts'] > 0): ?>
                        <span class="nav-badge badge-warning" title="<?php echo $sidebarStats['drafts']; ?> draft">
                            <?php echo $sidebarStats['drafts']; ?>
                        </span>
                    <?php endif; ?>
                </a>
                
                <a href="<?php echo url('admin/article-edit.php'); ?>" class="<?php echo $isAct('article-edit.php'); ?> nav-highlight">
                    <div class="nav-icon"><i class="fas fa-pen-fancy"></i></div>
                    <span class="nav-text">Tulis Artikel</span>
                </a>
                
                <a href="<?php echo url('admin/comments.php'); ?>" class="<?php echo $isAct('comments.php'); ?>">
                    <div class="nav-icon"><i class="fas fa-comments"></i></div>
                    <span class="nav-text">Komentar</span>
                    <?php if ($sidebarStats['pending_comments'] > 0): ?>
                        <span class="nav-badge badge-danger pulse">
                            <?php echo $sidebarStats['pending_comments']; ?>
                        </span>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <!-- SECTION: ADMINISTRATOR (khusus admin) -->
        <div class="nav-section nav-section-admin" data-section="admin">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <span class="nav-section-title">
                    <i class="fas fa-crown" style="color:#f39c12;"></i> ADMINISTRATOR
                </span>
                <i class="fas fa-chevron-down section-toggle"></i>
            </div>
            <div class="nav-section-content">
                <a href="<?php echo url('admin/categories.php'); ?>" class="<?php echo $isAct('categories.php'); ?>">
                    <div class="nav-icon"><i class="fas fa-tags"></i></div>
                    <span class="nav-text">Kategori</span>
                </a>
                
                <a href="<?php echo url('admin/settings.php'); ?>" class="<?php echo $isAct('settings.php'); ?>">
                    <div class="nav-icon"><i class="fas fa-cog"></i></div>
                    <span class="nav-text">Pengaturan</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- SECTION: AKUN -->
        <div class="nav-section" data-section="account">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <span class="nav-section-title">AKUN</span>
                <i class="fas fa-chevron-down section-toggle"></i>
            </div>
            <div class="nav-section-content">
                <a href="<?php echo url('admin/profile.php'); ?>" class="<?php echo $isAct('profile.php'); ?>">
                    <div class="nav-icon"><i class="fas fa-user-edit"></i></div>
                    <span class="nav-text">Profil Saya</span>
                </a>
                
                <a href="<?php echo url(); ?>" target="_blank">
                    <div class="nav-icon"><i class="fas fa-globe"></i></div>
                    <span class="nav-text">Lihat Website</span>
                    <i class="fas fa-external-link-alt nav-external"></i>
                </a>
            </div>
        </div>

    </nav>

    <!-- ============ WIDGET: TARGET MINGGUAN ============ -->
    <div class="sidebar-widget">
        <div class="widget-title">
            <i class="fas fa-bullseye"></i> Target Mingguan
        </div>
        <div class="progress-info">
            <span><strong><?php echo $weeklyArticles; ?></strong>/<?php echo $weeklyTarget; ?> artikel</span>
            <span><?php echo $weeklyProgress; ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill progress-primary" style="width: 0%;" data-target="<?php echo $weeklyProgress; ?>%"></div>
        </div>
        <div class="widget-footer">
            <small><i class="fas fa-redo"></i> Reset setiap Senin</small>
        </div>
    </div>

    <!-- ============ WIDGET: AKTIVITAS TERAKHIR ============ -->
    <?php if (!empty($recentActivities)): ?>
    <div class="sidebar-widget">
        <div class="widget-title">
            <i class="fas fa-clock"></i> Aktivitas Terakhir
        </div>
        <ul class="activity-list">
            <?php foreach ($recentActivities as $act): ?>
                <li class="activity-item">
                    <div class="activity-icon <?php echo $act['type']; ?>">
                        <i class="fas fa-<?php echo $act['type'] === 'article' ? 'newspaper' : 'comment'; ?>"></i>
                    </div>
                    <div class="activity-content">
                        <a href="<?php echo url('article.php?slug=' . $act['link']); ?>" target="_blank">
                            <?php echo htmlspecialchars(excerpt($act['text'], 45)); ?>
                        </a>
                        <span class="activity-time"><?php echo timeAgo($act['time']); ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ============ WIDGET: STORAGE ============ -->
    <div class="sidebar-widget">
        <div class="widget-title">
            <i class="fas fa-hdd"></i> Penyimpanan Server
        </div>
        <div class="progress-info">
            <span><?php echo $storageUsedMB; ?> MB</span>
            <span><?php echo $storageLimit; ?> MB</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill 
                <?php 
                    echo $storagePercent > 80 ? 'progress-danger' : 
                         ($storagePercent > 60 ? 'progress-warning' : 'progress-success'); 
                ?>"
                style="width: 0%;" 
                data-target="<?php echo $storagePercent; ?>%">
            </div>
        </div>
        <div class="widget-footer">
            <small>
                <i class="fas fa-<?php echo $storagePercent > 80 ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo $storagePercent > 80 ? 'Hampir penuh!' : 'Ruang cukup'; ?>
            </small>
        </div>
    </div>

    <!-- ============ WIDGET: QUOTE ============ -->
    <div class="sidebar-widget quote-widget">
        <div class="widget-title">
            <i class="fas fa-lightbulb"></i> Quote Hari Ini
        </div>
        <p class="quote-text" id="quoteText">"<?php echo htmlspecialchars($todayQuote); ?>"</p>
        <button onclick="refreshQuote()" class="btn-refresh-quote" title="Quote Baru">
            <i class="fas fa-sync-alt"></i>
        </button>
    </div>

    <!-- ============ QUICK ACTIONS ============ -->
    <div class="quick-actions">
        <button class="quick-action-btn" title="Tulis Artikel (Ctrl+N)" 
                onclick="window.location='<?php echo url('admin/article-edit.php'); ?>'">
            <i class="fas fa-pen"></i>
        </button>
        <button class="quick-action-btn" title="Lihat Website" 
                onclick="window.open('<?php echo url(); ?>', '_blank')">
            <i class="fas fa-globe"></i>
        </button>
        <button class="quick-action-btn" title="Mode Gelap/Terang" 
                onclick="toggleDarkMode()">
            <i class="fas fa-moon" id="darkModeIcon"></i>
        </button>
        <button class="quick-action-btn" title="Ciutkan Sidebar" 
                onclick="toggleSidebar()">
            <i class="fas fa-compress-alt"></i>
        </button>
    </div>

    <!-- ============ FOOTER ============ -->
    <div class="sidebar-footer">
        <a href="<?php echo url('admin/logout.php'); ?>" class="logout-btn-full" 
           onclick="return confirm('Yakin ingin logout?');">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
        <div class="sidebar-footer-info">
            <small>Blog Dosen v2.0</small>
            <small><i class="fas fa-circle online-dot"></i> Online</small>
        </div>
    </div>
</aside>

<script>
// ===== DATA =====
var sidebarQuotes = <?php echo json_encode($quotes); ?>;

// ===== TOGGLE SECTION =====
function toggleSection(header) {
    var section = header.parentElement;
    section.classList.toggle('collapsed');
    
    // Save state
    var states = JSON.parse(localStorage.getItem('sidebarSections') || '{}');
    var id = section.getAttribute('data-section');
    states[id] = section.classList.contains('collapsed');
    localStorage.setItem('sidebarSections', JSON.stringify(states));
}

// Restore section states
(function() {
    var states = JSON.parse(localStorage.getItem('sidebarSections') || '{}');
    document.querySelectorAll('.nav-section[data-section]').forEach(function(s) {
        var id = s.getAttribute('data-section');
        if (states[id]) s.classList.add('collapsed');
    });
})();

// ===== REFRESH QUOTE =====
function refreshQuote() {
    var el = document.getElementById('quoteText');
    if (!el) return;
    el.style.opacity = '0';
    setTimeout(function() {
        var q = sidebarQuotes[Math.floor(Math.random() * sidebarQuotes.length)];
        el.textContent = '"' + q + '"';
        el.style.opacity = '1';
    }, 300);
}

// ===== DARK MODE =====
function toggleDarkMode() {
    var html = document.documentElement;
    var isDark = html.getAttribute('data-theme') === 'dark';
    html.setAttribute('data-theme', isDark ? 'light' : 'dark');
    localStorage.setItem('adminTheme', isDark ? 'light' : 'dark');
    updateDarkIcon();
}

function updateDarkIcon() {
    var icon = document.getElementById('darkModeIcon');
    if (!icon) return;
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
}

if (localStorage.getItem('adminTheme') === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
}
updateDarkIcon();

// ===== TOGGLE SIDEBAR =====
function toggleSidebar() {
    var sb = document.getElementById('adminSidebar');
    sb.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sb.classList.contains('collapsed') ? '1' : '0');
}

if (localStorage.getItem('sidebarCollapsed') === '1') {
    document.getElementById('adminSidebar').classList.add('collapsed');
}

// ===== KEYBOARD SHORTCUTS =====
document.addEventListener('keydown', function(e) {
    if (e.target.matches('input, textarea')) return;
    
    // Ctrl+/ atau Cmd+/ : focus search
    if ((e.ctrlKey || e.metaKey) && e.key === '/') {
        e.preventDefault();
        var search = document.querySelector('.sidebar-search input');
        if (search) search.focus();
    }
    // Ctrl+N : new article
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'n') {
        e.preventDefault();
        window.location = '<?php echo url('admin/article-edit.php'); ?>';
    }
    // Ctrl+D : dashboard
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd') {
        e.preventDefault();
        window.location = '<?php echo url('admin/'); ?>';
    }
});

// ===== ANIMATE PROGRESS BARS =====
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.progress-fill[data-target]').forEach(function(bar) {
        setTimeout(function() {
            bar.style.width = bar.getAttribute('data-target');
        }, 400);
    });
});

console.log('%c🎓 Admin Sidebar Clean Ultimate', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
console.log('%cShortcuts: Ctrl+/ Search | Ctrl+N New | Ctrl+D Dashboard', 'font-size:11px;color:#888;');
</script>

<style>
/* ===== COLLAPSIBLE SECTIONS ===== */
.nav-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    padding: 0 0.5rem;
    margin-bottom: 0.5rem;
    user-select: none;
    transition: opacity 0.2s ease;
}
.nav-section-header:hover { opacity: 0.8; }
.nav-section-title {
    margin: 0;
    pointer-events: none;
}
.section-toggle {
    font-size: 0.65rem;
    color: rgba(255,255,255,0.5);
    transition: transform 0.3s ease;
}
.nav-section.collapsed .section-toggle {
    transform: rotate(-90deg);
}
.nav-section-content {
    max-height: 1000px;
    overflow: hidden;
    transition: max-height 0.3s ease, opacity 0.3s ease;
}
.nav-section.collapsed .nav-section-content {
    max-height: 0;
    opacity: 0;
}

/* ===== MINI STAT ENHANCED ===== */
.mini-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.1rem;
    font-size: 0.75rem;
    color: rgba(255,255,255,0.7);
}
.mini-stat i {
    color: #f39c12;
    font-size: 0.95rem;
    margin-bottom: 0.1rem;
}
.mini-stat span {
    font-size: 1rem;
    font-weight: 700;
    color: #fff;
}
.mini-stat small {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.7;
}
.mini-stat-alert span {
    color: #e74c3c !important;
}

/* ===== EXTERNAL LINK ICON ===== */
.nav-external {
    font-size: 0.6rem !important;
    opacity: 0.5;
    margin-left: auto;
    width: auto !important;
}

/* ===== PROGRESS VARIANTS ===== */
.progress-fill.progress-primary {
    background: linear-gradient(90deg, #3498db, #2980b9);
}
.progress-fill.progress-success {
    background: linear-gradient(90deg, #27ae60, #229954);
}
.progress-fill.progress-warning {
    background: linear-gradient(90deg, #f39c12, #e67e22);
}
.progress-fill.progress-danger {
    background: linear-gradient(90deg, #e74c3c, #c0392b);
}

/* ===== ACTIVITY LIST IMPROVED ===== */
.activity-content a {
    color: rgba(255,255,255,0.9);
    text-decoration: none;
    font-size: 0.82rem;
    line-height: 1.4;
    display: block;
    transition: color 0.2s ease;
}
.activity-content a:hover {
    color: #f39c12;
}
.activity-time {
    display: block;
    font-size: 0.7rem;
    color: rgba(255,255,255,0.4);
    margin-top: 0.15rem;
}

/* ===== LOGOUT FULL BUTTON ===== */
.logout-btn-full {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    background: rgba(231, 76, 60, 0.15);
    color: #e74c3c;
    border: 1px solid rgba(231, 76, 60, 0.3);
    padding: 0.7rem;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.88rem;
    transition: all 0.3s ease;
    margin-bottom: 0.8rem;
}
.logout-btn-full:hover {
    background: #e74c3c;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
}
.sidebar-footer-info {
    display: flex;
    justify-content: space-between;
    font-size: 0.7rem;
    color: rgba(255,255,255,0.4);
}
.online-dot {
    font-size: 0.5rem;
    color: #2ecc71;
    vertical-align: middle;
    animation: onlinePulse 2s infinite;
}
@keyframes onlinePulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}

/* ===== ADMIN SECTION SPECIAL ===== */
.nav-section-admin {
    background: linear-gradient(135deg, rgba(243, 156, 18, 0.08), rgba(243, 156, 18, 0.02));
    border: 1px solid rgba(243, 156, 18, 0.15);
    border-radius: 12px;
    padding: 0.8rem 0.5rem;
    margin: 1rem 0;
}
.nav-section-admin .nav-section-title {
    color: #f39c12 !important;
}

/* ===== DARK MODE ===== */
html[data-theme="dark"] .admin-sidebar {
    background: linear-gradient(180deg, #0f1419 0%, #162032 100%);
}
html[data-theme="dark"] .sidebar-widget {
    background: rgba(255,255,255,0.04);
}
html[data-theme="dark"] .admin-main {
    background: #0f1419;
    color: #e5e8ec;
}
</style>