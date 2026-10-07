<?php
require_once __DIR__ . '/../config/functions.php';
checkAuth();

// Set timezone Indonesia
date_default_timezone_set('Asia/Jakarta');

$userId = $_SESSION['user_id'];
$userName = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Dosen';
$userRole = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'dosen';

// ============================================
// 🛡️ HELPER: Pembaca Array Anti-Warning
// Fungsi ini JAMIN tidak pernah return warning
// ============================================
function safePick($row, $key, $default = 0) {
    if (!is_array($row)) return $default;
    if (!array_key_exists($key, $row)) return $default;
    return ($row[$key] === null) ? $default : $row[$key];
}

// ============================================
// 🎯 LAPISAN 1: Inisialisasi SEMUA key dulu
// Dengan ini, $stats JAMIN punya semua key
// ============================================
$stats = [
    'total_articles'      => 0,
    'articles_this_month' => 0,
    'articles_last_month' => 0,
    'total_views'         => 0,
    'views_this_month'    => 0,
    'views_last_month'    => 0,
    'total_comments'      => 0,
    'pending_comments'    => 0,
    'drafts'              => 0,
    'avg_views'           => 0,
    'engagement_rate'     => 0,
    'top_category'        => 'Belum ada',
];

// ============================================
// 🎯 LAPISAN 2: Query 1 - Statistik Artikel
// try-catch TERPISAH, jadi kalau error tidak 
// ganggu query lain
// ============================================
try {
    $stmt = db()->prepare("
        SELECT 
            COUNT(*) as total_articles,
            IFNULL(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END), 0) as drafts,
            IFNULL(SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') THEN 1 ELSE 0 END), 0) as articles_this_month,
            IFNULL(SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m') THEN 1 ELSE 0 END), 0) as articles_last_month,
            IFNULL(SUM(views), 0) as total_views,
            IFNULL(SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') THEN views ELSE 0 END), 0) as views_this_month,
            IFNULL(SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m') THEN views ELSE 0 END), 0) as views_last_month
        FROM articles 
        WHERE author_id = ?
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    
    // Pakai safePick, dijamin aman
    $stats['total_articles']      = (int)safePick($row, 'total_articles');
    $stats['drafts']              = (int)safePick($row, 'drafts');
    $stats['articles_this_month'] = (int)safePick($row, 'articles_this_month');
    $stats['articles_last_month'] = (int)safePick($row, 'articles_last_month');
    $stats['total_views']         = (int)safePick($row, 'total_views');
    $stats['views_this_month']    = (int)safePick($row, 'views_this_month');
    $stats['views_last_month']    = (int)safePick($row, 'views_last_month');
} catch (Exception $e) {
    // Query gagal → $stats tetap punya semua key (dari inisialisasi awal)
}

// ============================================
// 🎯 LAPISAN 2: Query 2 - Statistik Komentar
// ============================================
try {
    $stmt = db()->prepare("
        SELECT 
            COUNT(*) as total_comments,
            IFNULL(SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END), 0) as pending_comments
        FROM comments c 
        JOIN articles a ON c.article_id = a.id 
        WHERE a.author_id = ?
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    
    $stats['total_comments']   = (int)safePick($row, 'total_comments');
    $stats['pending_comments'] = (int)safePick($row, 'pending_comments');
} catch (Exception $e) {
    // Gagal → default sudah ada
}

// ============================================
// 🎯 Hitungan Turunan (aman karena key dijamin ada)
// ============================================
$stats['avg_views'] = $stats['total_articles'] > 0 
    ? round($stats['total_views'] / $stats['total_articles'], 1) 
    : 0;

$stats['engagement_rate'] = $stats['total_views'] > 0 
    ? round(($stats['total_comments'] / $stats['total_views']) * 100, 2) 
    : 0;

// ============================================
// 🎯 LAPISAN 2: Query 3 - Top Category
// ============================================
try {
    $stmt = db()->prepare("
        SELECT c.name, COUNT(a.id) as count 
        FROM articles a 
        JOIN categories c ON a.category_id = c.id 
        WHERE a.author_id = ? AND a.status = 'published'
        GROUP BY a.category_id 
        ORDER BY count DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $topCategory = $stmt->fetch();
    $stats['top_category'] = ($topCategory && isset($topCategory['name'])) 
        ? $topCategory['name'] 
        : 'Belum ada';
} catch (Exception $e) {
    // Default 'Belum ada' sudah ada
}

// ============================================
// 📈 PUBLISH TREND (30 HARI TERAKHIR)
// ============================================
$publishData = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $publishData[$date] = 0;
}

try {
    $stmt = db()->prepare("
        SELECT DATE(created_at) as date, COUNT(*) as count 
        FROM articles 
        WHERE author_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
    ");
    $stmt->execute([$userId]);
    while ($row = $stmt->fetch()) {
        if (isset($publishData[$row['date']])) {
            $publishData[$row['date']] = (int)$row['count'];
        }
    }
} catch (Exception $e) {}

$weekThis = array_slice(array_values($publishData), -7);
$weekLast = array_slice(array_values($publishData), -14, 7);
$sumThis = array_sum($weekThis);
$sumLast = array_sum($weekLast);
$publishTrend = $sumLast > 0 ? round((($sumThis - $sumLast) / $sumLast) * 100, 1) : 0;

// ============================================
// 🏆 TOP 5 ARTIKEL TERPOPULER
// ============================================
$topArticles = [];
try {
    $stmt = db()->prepare("
        SELECT a.*, c.name as category_name 
        FROM articles a 
        LEFT JOIN categories c ON a.category_id = c.id 
        WHERE a.author_id = ? AND a.status = 'published'
        ORDER BY a.views DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $topArticles = $stmt->fetchAll();
} catch (Exception $e) {}

// ============================================
// 📰 ARTIKEL TERBARU
// ============================================
$recent = [];
try {
    $stmt = db()->prepare("
        SELECT a.*, c.name as category_name,
        (SELECT COUNT(*) FROM comments WHERE article_id = a.id AND status = 'approved') as comment_count
        FROM articles a 
        LEFT JOIN categories c ON a.category_id = c.id 
        WHERE a.author_id = ? 
        ORDER BY a.created_at DESC LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recent = $stmt->fetchAll();
} catch (Exception $e) {}

// ============================================
// 📅 WRITING STREAK
// ============================================
$streakData = [];
$currentStreak = 0;
$longestStreak = 0;
$totalWritingDays = 0;

try {
    $stmt = db()->prepare("
        SELECT DISTINCT DATE(created_at) as date 
        FROM articles 
        WHERE author_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
        ORDER BY date DESC
    ");
    $stmt->execute([$userId]);
    $writingDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $writingDates = array_unique($writingDates);
    sort($writingDates);
    $totalWritingDays = count($writingDates);
    
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    if (in_array($today, $writingDates)) {
        $checkDate = new DateTime($today);
    } elseif (in_array($yesterday, $writingDates)) {
        $checkDate = new DateTime($yesterday);
    } else {
        $checkDate = null;
    }
    
    if ($checkDate) {
        while (in_array($checkDate->format('Y-m-d'), $writingDates)) {
            $currentStreak++;
            $checkDate->modify('-1 day');
        }
    }
    
    if (!empty($writingDates)) {
        $tempStreak = 1;
        $longestStreak = 1;
        for ($i = 1; $i < count($writingDates); $i++) {
            $d1 = new DateTime($writingDates[$i-1]);
            $d2 = new DateTime($writingDates[$i]);
            $diff = $d1->diff($d2)->days;
            if ($diff == 1) {
                $tempStreak++;
            } else {
                $tempStreak = 1;
            }
            $longestStreak = max($longestStreak, $tempStreak);
        }
    }
    
    for ($i = 139; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $streakData[$date] = in_array($date, $writingDates) ? 1 : 0;
    }
} catch (Exception $e) {}

// ============================================
// 💬 KOMENTAR TERBARU
// ============================================
$recentComments = [];
try {
    $stmt = db()->prepare("
        SELECT c.*, a.title as article_title, a.slug as article_slug
        FROM comments c 
        JOIN articles a ON c.article_id = a.id 
        WHERE a.author_id = ? 
        ORDER BY c.created_at DESC LIMIT 3
    ");
    $stmt->execute([$userId]);
    $recentComments = $stmt->fetchAll();
} catch (Exception $e) {}

// ============================================
// 🎯 GREETING
// ============================================
$hour = (int)date('H');
if ($hour < 11) {
    $greeting = 'Selamat Pagi';
    $greetingEmoji = '🌅';
    $greetingMsg = 'Semoga hari Anda produktif dan penuh inspirasi!';
} elseif ($hour < 15) {
    $greeting = 'Selamat Siang';
    $greetingEmoji = '☀️';
    $greetingMsg = 'Saatnya menulis dan berbagi ilmu!';
} elseif ($hour < 18) {
    $greeting = 'Selamat Sore';
    $greetingEmoji = '🌇';
    $greetingMsg = 'Mari selesaikan target hari ini!';
} else {
    $greeting = 'Selamat Malam';
    $greetingEmoji = '🌙';
    $greetingMsg = 'Waktu yang tepat untuk refleksi dan menulis.';
}

// ============================================
// 🏆 ACHIEVEMENTS
// ============================================
$achievements = [];

$achievementList = [
    ['min' => 1, 'icon' => '✍️', 'title' => 'Penulis Pemula', 'desc' => 'Menulis artikel pertama', 'check' => $stats['total_articles']],
    ['min' => 10, 'icon' => '📚', 'title' => 'Penulis Aktif', 'desc' => '10+ artikel dipublikasikan', 'check' => $stats['total_articles']],
    ['min' => 50, 'icon' => '🎓', 'title' => 'Penulis Produktif', 'desc' => '50+ artikel dipublikasikan', 'check' => $stats['total_articles']],
    ['min' => 1000, 'icon' => '👁️', 'title' => 'Viral Starter', 'desc' => '1000+ total views', 'check' => $stats['total_views']],
    ['min' => 10000, 'icon' => '🔥', 'title' => 'Content Creator Pro', 'desc' => '10.000+ total views', 'check' => $stats['total_views']],
    ['min' => 7, 'icon' => '⚡', 'title' => 'Konsisten Seminggu', 'desc' => 'Menulis 7 hari berturut-turut', 'check' => $currentStreak],
    ['min' => 50, 'icon' => '💬', 'title' => 'Community Builder', 'desc' => '50+ komentar dari pembaca', 'check' => $stats['total_comments']],
];

foreach ($achievementList as $ach) {
    if ($ach['check'] >= $ach['min']) {
        $achievements[] = [
            'icon' => $ach['icon'],
            'title' => $ach['title'],
            'desc' => $ach['desc'],
            'unlocked' => true
        ];
    }
}

$lockedAchievements = [
    ['icon' => '🔒', 'title' => 'Penulis Produktif', 'desc' => 'Tulis 50 artikel untuk unlock', 'target' => 50, 'progress' => $stats['total_articles']],
    ['icon' => '🔒', 'title' => 'Content Creator Pro', 'desc' => 'Raih 10.000 total views', 'target' => 10000, 'progress' => $stats['total_views']],
    ['icon' => '🔒', 'title' => 'Community Builder', 'desc' => 'Raih 50 komentar', 'target' => 50, 'progress' => $stats['total_comments']],
];

foreach ($lockedAchievements as $lock) {
    $alreadyUnlocked = false;
    foreach ($achievements as $unlocked) {
        if ($unlocked['title'] === $lock['title']) {
            $alreadyUnlocked = true;
            break;
        }
    }
    if (!$alreadyUnlocked && $lock['progress'] < $lock['target']) {
        $achievements[] = [
            'icon' => $lock['icon'],
            'title' => $lock['title'],
            'desc' => $lock['desc'],
            'unlocked' => false,
            'progress' => min($lock['progress'], $lock['target']),
            'target' => $lock['target']
        ];
    }
}

// ============================================
// 💡 DAILY TIPS
// ============================================
$tips = [
    ['icon' => '💡', 'title' => 'Tips Menulis', 'text' => 'Mulai menulis dari paragraf pembuka yang menarik. Pembaca memutuskan lanjut atau tidak dalam 7 detik pertama!'],
    ['icon' => '📊', 'title' => 'SEO Tip', 'text' => 'Gunakan judul yang mengandung kata kunci dan buat pembaca penasaran. Idealnya 50-60 karakter.'],
    ['icon' => '🎯', 'title' => 'Engagement', 'text' => 'Akhiri artikel dengan pertanyaan untuk memancing komentar pembaca.'],
    ['icon' => '⏰', 'title' => 'Best Time', 'text' => 'Publish artikel di pagi hari (07:00-09:00) untuk mendapatkan engagement maksimal.'],
    ['icon' => '🖼️', 'title' => 'Visual Content', 'text' => 'Artikel dengan gambar mendapat 94% lebih banyak views daripada artikel tanpa gambar.'],
    ['icon' => '📝', 'title' => 'Konsistensi', 'text' => 'Konsistensi lebih penting dari kuantitas. Satu artikel berkualitas per minggu lebih baik dari tujuh artikel asal-asalan.'],
    ['icon' => '🎓', 'title' => 'Tridharma', 'text' => 'Setiap artikel blog bisa menjadi bahan pengabdian masyarakat dan dokumentasi penelitian.'],
];
$dailyTip = $tips[array_rand($tips)];

// ============================================
// 🌤️ QUOTES
// ============================================
$quotes = [
    ['text' => 'Pendidikan adalah senjata paling mematikan di dunia, karena dengan pendidikan Anda dapat mengubah dunia.', 'author' => 'Nelson Mandela'],
    ['text' => 'Hiduplah seolah engkau mati besok. Belajarlah seolah engkau hidup selamanya.', 'author' => 'Mahatma Gandhi'],
    ['text' => 'Seorang guru biasa memberitahu. Guru yang baik menjelaskan. Guru ulung memperagakan. Guru hebat mengilhami.', 'author' => 'William A. Ward'],
    ['text' => 'Orang boleh pandai setinggi langit, tapi selama ia tidak menulis, ia akan hilang di dalam masyarakat dan dari sejarah.', 'author' => 'Pramoedya A. Toer'],
    ['text' => 'The beautiful thing about learning is that no one can take it away from you.', 'author' => 'B.B. King'],
];
$dailyQuote = $quotes[array_rand($quotes)];

// ============================================
// 🎯 AI INSIGHTS
// ============================================
$insights = [];

if ($stats['drafts'] > 0) {
    $insights[] = [
        'type' => 'warning',
        'icon' => '📝',
        'text' => "Anda punya <strong>" . $stats['drafts'] . " draft</strong> yang menunggu untuk dipublikasikan. Yuk selesaikan!",
        'link' => url('admin/articles.php')
    ];
}

if ($stats['pending_comments'] > 0) {
    $insights[] = [
        'type' => 'info',
        'icon' => '💬',
        'text' => "<strong>" . $stats['pending_comments'] . " komentar</strong> menunggu moderasi. Respon cepat meningkatkan engagement!",
        'link' => url('admin/comments.php')
    ];
}

if ($stats['articles_this_month'] < 2) {
    $insights[] = [
        'type' => 'motivation',
        'icon' => '🎯',
        'text' => 'Bulan ini baru menulis ' . $stats['articles_this_month'] . ' artikel. Ayo tingkatkan konsistensi!',
        'link' => url('admin/article-edit.php')
    ];
}

if ($stats['articles_this_month'] > $stats['articles_last_month'] && $stats['articles_last_month'] > 0) {
    $growth = round((($stats['articles_this_month'] - $stats['articles_last_month']) / $stats['articles_last_month']) * 100, 1);
    $insights[] = [
        'type' => 'success',
        'icon' => '🚀',
        'text' => "Produktivitas naik <strong>" . $growth . "%</strong> dari bulan lalu! Pertahankan momentum.",
        'link' => null
    ];
}

if ($stats['views_this_month'] > $stats['views_last_month'] && $stats['views_last_month'] > 0) {
    $growth = round((($stats['views_this_month'] - $stats['views_last_month']) / $stats['views_last_month']) * 100, 1);
    $insights[] = [
        'type' => 'success',
        'icon' => '📈',
        'text' => "Views bulan ini naik <strong>" . $growth . "%</strong>! Konten Anda makin diminati.",
        'link' => null
    ];
}

if (empty($insights)) {
    $insights[] = [
        'type' => 'success',
        'icon' => '✨',
        'text' => 'Semua berjalan lancar! Pertahankan konsistensi menulis Anda.',
        'link' => null
    ];
}

// ============================================
// 📊 KOMPARASI BULAN INI VS BULAN LALU
// ============================================
$articlesGrowth = $stats['articles_last_month'] > 0 
    ? round((($stats['articles_this_month'] - $stats['articles_last_month']) / $stats['articles_last_month']) * 100, 1)
    : ($stats['articles_this_month'] > 0 ? 100 : 0);

$viewsGrowth = $stats['views_last_month'] > 0 
    ? round((($stats['views_this_month'] - $stats['views_last_month']) / $stats['views_last_month']) * 100, 1)
    : ($stats['views_this_month'] > 0 ? 100 : 0);

$pageTitle = 'Dashboard';

// ============================================
// 🛡️ BACKUP statistik dashboard SEBELUM include
// (sidebar.php punya variabel $stats sendiri 
//  yang akan menimpa milik dashboard!)
// ============================================
$dashStats = $stats;

include __DIR__ . '/../includes/admin-header.php';
include __DIR__ . '/../includes/sidebar.php';

// ============================================
// ♻️ RESTORE statistik dashboard setelah sidebar
// selesai dirender, supaya HTML di bawah aman
// ============================================
$stats = $dashStats;
?>

<style>
/* ===== DASHBOARD ULTIMATE - PERFECTED ===== */
.dashboard-welcome {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 20px;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.dashboard-welcome::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 400px;
    height: 400px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    animation: float 6s ease-in-out infinite;
}

.dashboard-welcome::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: -5%;
    width: 300px;
    height: 300px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    animation: float 8s ease-in-out infinite reverse;
}

@keyframes float {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    50% { transform: translateY(-20px) rotate(5deg); }
}

.welcome-content {
    position: relative;
    z-index: 2;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.5rem;
}

.welcome-text h1 {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.welcome-text p {
    opacity: 0.9;
    font-size: 1.1rem;
}

.welcome-datetime {
    text-align: right;
    opacity: 0.9;
}

.welcome-datetime .date {
    font-size: 1.3rem;
    font-weight: 600;
    display: block;
    margin-bottom: 0.3rem;
}

.welcome-datetime .time {
    font-size: 1.5rem;
    font-weight: 700;
    font-family: 'Courier New', monospace;
}

/* ===== INSIGHTS BANNER ===== */
.insights-banner {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.insight-card {
    padding: 1.2rem;
    border-radius: 12px;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    font-size: 0.92rem;
    position: relative;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    color: inherit;
}

.insight-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
}

.insight-card.success {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    border-left: 4px solid #28a745;
}

.insight-card.warning {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    border-left: 4px solid #ffc107;
}

.insight-card.info {
    background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
    border-left: 4px solid #17a2b8;
}

.insight-card.motivation {
    background: linear-gradient(135deg, #e0c3fc 0%, #8ec5fc 100%);
    border-left: 4px solid #9b59b6;
}

.insight-icon {
    font-size: 1.8rem;
    flex-shrink: 0;
}

.insight-text {
    flex: 1;
    line-height: 1.5;
}

.insight-arrow {
    color: rgba(0,0,0,0.3);
    align-self: center;
    transition: transform 0.3s ease;
}

.insight-card:hover .insight-arrow {
    transform: translateX(3px);
}

/* ===== STATS GRID ===== */
.stats-grid-ultimate {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card-ultimate {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border-left: 4px solid;
}

.stat-card-ultimate:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.stat-card-ultimate::before {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 100px; height: 100px;
    background: radial-gradient(circle, rgba(0,0,0,0.02) 0%, transparent 70%);
    border-radius: 50%;
}

.stat-card-ultimate.articles { border-left-color: #3498db; }
.stat-card-ultimate.views { border-left-color: #2ecc71; }
.stat-card-ultimate.comments { border-left-color: #f39c12; }
.stat-card-ultimate.pending { border-left-color: #e74c3c; }
.stat-card-ultimate.drafts { border-left-color: #9b59b6; }
.stat-card-ultimate.engagement { border-left-color: #1abc9c; }
.stat-card-ultimate.growth { border-left-color: #e67e22; }

.stat-card-ultimate .stat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.stat-card-ultimate .stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    position: relative;
    z-index: 1;
}

.stat-card-ultimate.articles .stat-icon { background: linear-gradient(135deg, #3498db, #2980b9); }
.stat-card-ultimate.views .stat-icon { background: linear-gradient(135deg, #2ecc71, #27ae60); }
.stat-card-ultimate.comments .stat-icon { background: linear-gradient(135deg, #f39c12, #e67e22); }
.stat-card-ultimate.pending .stat-icon { background: linear-gradient(135deg, #e74c3c, #c0392b); }
.stat-card-ultimate.drafts .stat-icon { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
.stat-card-ultimate.engagement .stat-icon { background: linear-gradient(135deg, #1abc9c, #16a085); }
.stat-card-ultimate.growth .stat-icon { background: linear-gradient(135deg, #e67e22, #d35400); }

.stat-trend {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.3rem 0.7rem;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 600;
    position: relative;
    z-index: 1;
}

.stat-trend.up {
    background: #d4edda;
    color: #155724;
}

.stat-trend.down {
    background: #f8d7da;
    color: #721c24;
}

.stat-trend.neutral {
    background: #e2e3e5;
    color: #383d41;
}

.stat-card-ultimate .stat-value {
    font-size: 2.3rem;
    font-weight: 700;
    color: #1e3a5f;
    margin-bottom: 0.3rem;
    line-height: 1;
}

.stat-card-ultimate .stat-label {
    color: #555;
    font-size: 0.92rem;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.stat-card-ultimate .stat-sublabel {
    font-size: 0.78rem;
    color: #888;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

/* ===== DASHBOARD GRID ===== */
.dashboard-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.dashboard-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
}

.dashboard-card:hover {
    box-shadow: 0 6px 20px rgba(0,0,0,0.08);
}

.dashboard-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f0f0f0;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.dashboard-card-header h3 {
    color: #1e3a5f;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
}

.view-all {
    color: #3498db;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: color 0.3s ease;
}

.view-all:hover {
    color: #2980b9;
    text-decoration: underline;
}

/* ===== CHART ===== */
.chart-container {
    position: relative;
    height: 250px;
    display: flex;
    align-items: flex-end;
    gap: 4px;
    padding: 1rem 0;
}

.chart-bar {
    flex: 1;
    background: linear-gradient(to top, #3498db, #5dade2);
    border-radius: 4px 4px 0 0;
    position: relative;
    min-height: 4px;
    transition: all 0.3s ease;
    cursor: pointer;
    transform-origin: bottom;
    animation: barGrow 0.8s ease-out forwards;
    opacity: 0;
}

@keyframes barGrow {
    from { transform: scaleY(0); opacity: 0; }
    to { transform: scaleY(1); opacity: 1; }
}

.chart-bar:hover {
    background: linear-gradient(to top, #2874a6, #3498db);
    filter: brightness(1.1);
}

.chart-bar::after {
    content: attr(data-value);
    position: absolute;
    top: -30px;
    left: 50%;
    transform: translateX(-50%);
    background: #333;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
    z-index: 10;
}

.chart-bar:hover::after {
    opacity: 1;
}

.chart-labels {
    display: flex;
    justify-content: space-between;
    margin-top: 0.5rem;
    font-size: 0.75rem;
    color: #999;
}

.chart-legend {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: #666;
}

.chart-legend-box {
    width: 12px;
    height: 12px;
    background: linear-gradient(to top, #3498db, #5dade2);
    border-radius: 3px;
}

/* ===== TOP ARTICLES ===== */
.top-articles-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.top-article-item {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    border-radius: 10px;
    margin-bottom: 0.5rem;
    background: #f8f9fa;
    transition: all 0.3s ease;
    align-items: center;
}

.top-article-item:hover {
    background: #e9ecef;
    transform: translateX(5px);
}

.top-article-rank {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.1rem;
    color: white;
    flex-shrink: 0;
}

.top-article-rank.rank-1 { 
    background: linear-gradient(135deg, #ffd700, #ffed4e);
    box-shadow: 0 4px 10px rgba(255, 215, 0, 0.3);
}
.top-article-rank.rank-2 { 
    background: linear-gradient(135deg, #c0c0c0, #e5e5e5); 
    color: #333;
}
.top-article-rank.rank-3 { 
    background: linear-gradient(135deg, #cd7f32, #b87333); 
}
.top-article-rank.rank-other { 
    background: #95a5a6; 
}

.top-article-content {
    flex: 1;
    min-width: 0;
}

.top-article-title {
    font-weight: 600;
    color: #1e3a5f;
    margin-bottom: 0.3rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.top-article-title a {
    color: inherit;
    text-decoration: none;
    transition: color 0.2s ease;
}

.top-article-title a:hover {
    color: #f39c12;
}

.top-article-meta {
    display: flex;
    gap: 1rem;
    font-size: 0.82rem;
    color: #888;
    flex-wrap: wrap;
}

.top-article-meta span {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

/* ===== STREAK HEATMAP ===== */
.streak-heatmap {
    display: grid;
    grid-template-columns: repeat(20, 1fr);
    gap: 3px;
    margin: 1rem 0;
}

.heatmap-cell {
    aspect-ratio: 1;
    border-radius: 3px;
    background: #ebedf0;
    transition: all 0.2s ease;
    cursor: pointer;
}

.heatmap-cell.active {
    background: linear-gradient(135deg, #39d353, #28a745);
    box-shadow: 0 0 0 1px rgba(57, 211, 83, 0.3);
}

.heatmap-cell:hover {
    transform: scale(1.3);
    z-index: 2;
    position: relative;
}

.streak-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #f0f0f0;
}

.streak-stat {
    text-align: center;
    padding: 0.5rem;
}

.streak-stat .value {
    font-size: 1.8rem;
    font-weight: 700;
    color: #1e3a5f;
    line-height: 1;
    margin-bottom: 0.3rem;
}

.streak-stat .label {
    font-size: 0.82rem;
    color: #666;
}

/* ===== ACHIEVEMENTS ===== */
.achievements-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
    gap: 1rem;
}

.achievement-card {
    text-align: center;
    padding: 1.5rem 1rem;
    border-radius: 12px;
    background: #f8f9fa;
    transition: all 0.3s ease;
    position: relative;
    border: 2px solid transparent;
}

.achievement-card.unlocked {
    background: linear-gradient(135deg, #fff9e6 0%, #fff4d6 100%);
    border-color: #ffd700;
    box-shadow: 0 4px 15px rgba(255, 215, 0, 0.2);
}

.achievement-card.locked {
    opacity: 0.7;
    filter: grayscale(0.3);
}

.achievement-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
}

.achievement-icon {
    font-size: 2.8rem;
    margin-bottom: 0.5rem;
    display: block;
}

.achievement-title {
    font-weight: 600;
    color: #1e3a5f;
    margin-bottom: 0.3rem;
    font-size: 0.92rem;
}

.achievement-desc {
    font-size: 0.78rem;
    color: #666;
    margin-bottom: 0.5rem;
}

.achievement-progress {
    margin-top: 0.5rem;
    height: 5px;
    background: #e0e0e0;
    border-radius: 3px;
    overflow: hidden;
}

.achievement-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #3498db, #2980b9);
    transition: width 1s ease;
    border-radius: 3px;
}

.achievement-progress-text {
    display: block;
    font-size: 0.7rem;
    color: #999;
    margin-top: 0.3rem;
}

/* ===== DAILY TIP ===== */
.daily-tip {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.8rem;
    border-radius: 15px;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.daily-tip::before {
    content: '';
    position: absolute;
    top: -30%;
    right: -10%;
    width: 200px;
    height: 200px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    animation: float 8s ease-in-out infinite;
}

.tip-header {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 0.8rem;
    font-size: 1.15rem;
    font-weight: 700;
    position: relative;
    z-index: 1;
}

.tip-icon {
    font-size: 1.5rem;
}

.tip-text {
    font-size: 1rem;
    line-height: 1.7;
    opacity: 0.95;
    position: relative;
    z-index: 1;
}

/* ===== QUOTE CARD ===== */
.quote-card {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
    padding: 2.5rem 2rem;
    border-radius: 15px;
    text-align: center;
    font-style: italic;
    position: relative;
    overflow: hidden;
    margin-top: 2rem;
}

.quote-card::before {
    content: '"';
    position: absolute;
    top: -40px;
    left: 20px;
    font-size: 12rem;
    opacity: 0.15;
    font-family: Georgia, serif;
    line-height: 1;
}

.quote-text {
    font-size: 1.25rem;
    line-height: 1.7;
    margin-bottom: 1.2rem;
    position: relative;
    z-index: 1;
    max-width: 700px;
    margin-left: auto;
    margin-right: auto;
}

.quote-author {
    font-weight: 600;
    font-style: normal;
    opacity: 0.9;
    position: relative;
    z-index: 1;
    font-size: 0.95rem;
}

/* ===== RECENT COMMENTS ===== */
.recent-comments-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.recent-comment-item {
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 10px;
    margin-bottom: 0.8rem;
    border-left: 4px solid;
    transition: all 0.3s ease;
}

.recent-comment-item:hover {
    background: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}

.recent-comment-item.pending { border-left-color: #f39c12; }
.recent-comment-item.approved { border-left-color: #2ecc71; }
.recent-comment-item.rejected { border-left-color: #e74c3c; }

.comment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.comment-author {
    font-weight: 600;
    color: #1e3a5f;
}

.comment-status {
    font-size: 0.72rem;
    padding: 0.2rem 0.6rem;
    border-radius: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.comment-status.pending {
    background: #fff3cd;
    color: #856404;
}

.comment-status.approved {
    background: #d4edda;
    color: #155724;
}

.comment-status.rejected {
    background: #f8d7da;
    color: #721c24;
}

.comment-text {
    font-size: 0.88rem;
    color: #555;
    margin-bottom: 0.5rem;
    line-height: 1.5;
    font-style: italic;
}

.comment-article {
    font-size: 0.78rem;
    color: #888;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

.comment-article a {
    color: #3498db;
    text-decoration: none;
    font-weight: 500;
}

.comment-article a:hover {
    text-decoration: underline;
}

/* ===== SECTION TITLE ===== */
.section-title-ultimate {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.section-title-ultimate h2 {
    color: #1e3a5f;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
    font-size: 1.4rem;
}

/* ===== QUICK ACTIONS ===== */
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.quick-action-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 1.5rem 1rem;
    background: white;
    border-radius: 12px;
    text-decoration: none;
    color: #1e3a5f;
    transition: all 0.3s ease;
    border: 2px solid #f0f0f0;
}

.quick-action-card:hover {
    border-color: #3498db;
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(52, 152, 219, 0.15);
}

.quick-action-card strong {
    font-size: 0.95rem;
    text-align: center;
}

.quick-action-card small {
    font-size: 0.78rem;
    color: #888;
    text-align: center;
}

.quick-action-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: white;
    margin-bottom: 0.3rem;
}

.quick-action-icon.blue { background: linear-gradient(135deg, #3498db, #2980b9); }
.quick-action-icon.green { background: linear-gradient(135deg, #2ecc71, #27ae60); }
.quick-action-icon.orange { background: linear-gradient(135deg, #f39c12, #e67e22); }
.quick-action-icon.purple { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
.quick-action-icon.red { background: linear-gradient(135deg, #e74c3c, #c0392b); }

/* ===== TABLE ENHANCED ===== */
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.data-table thead {
    background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
    color: white;
}

.data-table th {
    padding: 0.9rem 1rem;
    text-align: left;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.data-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.2s ease;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

.data-table td {
    padding: 0.9rem 1rem;
    vertical-align: middle;
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.7rem;
    border-radius: 12px;
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-published {
    background: #d4edda;
    color: #155724;
}

.badge-draft {
    background: #fff3cd;
    color: #856404;
}

.badge-success {
    background: #d4edda;
    color: #155724;
}

.btn-small {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    color: white;
    text-decoration: none;
    transition: all 0.2s ease;
    margin-right: 0.3rem;
}

.btn-small:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
}

.btn-edit { background: linear-gradient(135deg, #f39c12, #e67e22); }
.btn-view { background: linear-gradient(135deg, #3498db, #2980b9); }
.btn-delete { background: linear-gradient(135deg, #e74c3c, #c0392b); }

/* ===== EMPTY STATE ===== */
.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: #999;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state h3 {
    color: #666;
    margin-bottom: 0.5rem;
}

.empty-state .btn-primary {
    margin-top: 1rem;
    display: inline-block;
    padding: 0.75rem 1.5rem;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    text-decoration: none;
    border-radius: 25px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.empty-state .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(243, 156, 18, 0.3);
}

/* ===== RESPONSIVE ===== */
@media (max-width: 992px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
    
    .welcome-content {
        flex-direction: column;
        text-align: center;
    }
    
    .welcome-datetime {
        text-align: center;
    }
}

@media (max-width: 768px) {
    .stats-grid-ultimate {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .welcome-text h1 {
        font-size: 1.5rem;
    }
    
    .stat-card-ultimate .stat-value {
        font-size: 1.8rem;
    }
    
    .streak-stats {
        grid-template-columns: 1fr;
    }
    
    .achievements-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .quick-actions-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 480px) {
    .stats-grid-ultimate {
        grid-template-columns: 1fr;
    }
    
    .achievements-grid {
        grid-template-columns: 1fr;
    }
    
    .quick-actions-grid {
        grid-template-columns: 1fr;
    }
    
    .dashboard-welcome {
        padding: 1.5rem 1rem;
    }
}

/* ===== DARK MODE ===== */
html[data-theme="dark"] .stat-card-ultimate,
html[data-theme="dark"] .dashboard-card,
html[data-theme="dark"] .quick-action-card {
    background: #1e2638;
    color: #e5e8ec;
}

html[data-theme="dark"] .stat-card-ultimate .stat-value,
html[data-theme="dark"] .dashboard-card-header h3,
html[data-theme="dark"] .section-title-ultimate h2,
html[data-theme="dark"] .top-article-title,
html[data-theme="dark"] .achievement-title,
html[data-theme="dark"] .comment-author,
html[data-theme="dark"] .streak-stat .value {
    color: #e5e8ec;
}

html[data-theme="dark"] .stat-card-ultimate .stat-label,
html[data-theme="dark"] .top-article-meta,
html[data-theme="dark"] .achievement-desc,
html[data-theme="dark"] .comment-text,
html[data-theme="dark"] .streak-stat .label {
    color: #a0a8b5;
}

html[data-theme="dark"] .top-article-item,
html[data-theme="dark"] .recent-comment-item,
html[data-theme="dark"] .achievement-card,
html[data-theme="dark"] .heatmap-cell {
    background: #25304a;
}

html[data-theme="dark"] .data-table tbody tr {
    border-color: #2a3550;
    color: #d5dae2;
}

html[data-theme="dark"] .data-table tbody tr:hover {
    background: #2a3550;
}

html[data-theme="dark"] .quick-action-card {
    border-color: #2a3550;
    color: #e5e8ec;
}
</style>

<main class="admin-main">
    <!-- ============================================ -->
    <!-- WELCOME BANNER -->
    <!-- ============================================ -->
    <div class="dashboard-welcome">
        <div class="welcome-content">
            <div class="welcome-text">
                <h1>
                    <?php echo $greetingEmoji; ?> <?php echo $greeting; ?>, 
                    <?php echo htmlspecialchars(explode(' ', $userName)[0]); ?>!
                </h1>
                <p><?php echo $greetingMsg; ?></p>
            </div>
            <div class="welcome-datetime">
                <span class="date" id="liveDate"></span>
                <span class="time" id="liveTime"></span>
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- AI INSIGHTS -->
    <!-- ============================================ -->
    <div class="insights-banner">
        <?php foreach ($insights as $insight): ?>
            <?php if (!empty($insight['link'])): ?>
                <a href="<?php echo $insight['link']; ?>" class="insight-card <?php echo $insight['type']; ?>">
            <?php else: ?>
                <div class="insight-card <?php echo $insight['type']; ?>">
            <?php endif; ?>
                <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                <div class="insight-text"><?php echo $insight['text']; ?></div>
                <?php if (!empty($insight['link'])): ?>
                    <i class="fas fa-arrow-right insight-arrow"></i>
                <?php endif; ?>
            <?php echo !empty($insight['link']) ? '</a>' : '</div>'; ?>
        <?php endforeach; ?>
    </div>

    <!-- ============================================ -->
    <!-- STATS GRID ULTIMATE -->
    <!-- ============================================ -->
    <div class="stats-grid-ultimate">
        <!-- Total Artikel -->
        <div class="stat-card-ultimate articles">
            <div class="stat-header">
                <div class="stat-icon">
                    <i class="fas fa-newspaper"></i>
                </div>
                <span class="stat-trend <?php echo $articlesGrowth >= 0 ? 'up' : 'down'; ?>">
                    <i class="fas fa-arrow-<?php echo $articlesGrowth >= 0 ? 'up' : 'down'; ?>"></i>
                    <?php echo $articlesGrowth >= 0 ? '+' : ''; ?><?php echo $articlesGrowth; ?>%
                </span>
            </div>
            <div class="stat-value animated-counter" data-value="<?php echo $stats['total_articles']; ?>">0</div>
            <div class="stat-label">Total Artikel</div>
            <div class="stat-sublabel">
                <i class="fas fa-check-circle"></i> 
                <?php echo $stats['total_articles'] - $stats['drafts']; ?> published, 
                <?php echo $stats['articles_this_month']; ?> bulan ini
            </div>
        </div>

        <!-- Total Views -->
        <div class="stat-card-ultimate views">
            <div class="stat-header">
                <div class="stat-icon">
                    <i class="fas fa-eye"></i>
                </div>
                <span class="stat-trend <?php echo $viewsGrowth >= 0 ? 'up' : 'down'; ?>">
                    <i class="fas fa-arrow-<?php echo $viewsGrowth >= 0 ? 'up' : 'down'; ?>"></i>
                    <?php echo $viewsGrowth >= 0 ? '+' : ''; ?><?php echo $viewsGrowth; ?>%
                </span>
            </div>
            <div class="stat-value animated-counter" data-value="<?php echo $stats['total_views']; ?>">0</div>
            <div class="stat-label">Total Views</div>
            <div class="stat-sublabel">
                <i class="fas fa-chart-line"></i> 
                Rata-rata <?php echo number_format($stats['avg_views'], 1); ?> per artikel
            </div>
        </div>

        <!-- Total Komentar -->
        <div class="stat-card-ultimate comments">
            <div class="stat-header">
                <div class="stat-icon">
                    <i class="fas fa-comments"></i>
                </div>
                <span class="stat-trend neutral">
                    <i class="fas fa-chart-pie"></i> <?php echo $stats['engagement_rate']; ?>%
                </span>
            </div>
            <div class="stat-value animated-counter" data-value="<?php echo $stats['total_comments']; ?>">0</div>
            <div class="stat-label">Total Komentar</div>
            <div class="stat-sublabel">
                <i class="fas fa-heart"></i> Engagement rate
            </div>
        </div>

        <!-- Pending Review -->
        <div class="stat-card-ultimate pending">
            <div class="stat-header">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <?php if ($stats['pending_comments'] > 0): ?>
                    <span class="stat-trend down">
                        <i class="fas fa-exclamation-circle"></i> Perlu aksi
                    </span>
                <?php else: ?>
                    <span class="stat-trend up">
                        <i class="fas fa-check"></i> Aman
                    </span>
                <?php endif; ?>
            </div>
            <div class="stat-value animated-counter" data-value="<?php echo $stats['pending_comments']; ?>">0</div>
            <div class="stat-label">Menunggu Review</div>
            <div class="stat-sublabel">
                <i class="fas fa-bell"></i> Komentar pending
            </div>
        </div>

        <!-- Draft Artikel -->
        <div class="stat-card-ultimate drafts">
            <div class="stat-header">
                <div class="stat-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <?php if ($stats['drafts'] > 0): ?>
                    <span class="stat-trend down">
                        <i class="fas fa-exclamation"></i> <?php echo $stats['drafts']; ?> draft
                    </span>
                <?php else: ?>
                    <span class="stat-trend up">
                        <i class="fas fa-check"></i> Bersih
                    </span>
                <?php endif; ?>
            </div>
            <div class="stat-value animated-counter" data-value="<?php echo $stats['drafts']; ?>">0</div>
            <div class="stat-label">Draft Artikel</div>
            <div class="stat-sublabel">
                <i class="fas fa-edit"></i> Siap dipublish
            </div>
        </div>

        <!-- Views Bulan Ini -->
        <div class="stat-card-ultimate growth">
            <div class="stat-header">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <span class="stat-trend <?php echo $viewsGrowth >= 0 ? 'up' : 'down'; ?>">
                    <i class="fas fa-arrow-<?php echo $viewsGrowth >= 0 ? 'up' : 'down'; ?>"></i>
                    <?php echo $viewsGrowth >= 0 ? '+' : ''; ?><?php echo $viewsGrowth; ?>%
                </span>
            </div>
            <div class="stat-value animated-counter" data-value="<?php echo $stats['views_this_month']; ?>">0</div>
            <div class="stat-label">Views Bulan Ini</div>
            <div class="stat-sublabel">
                <i class="fas fa-calendar"></i> 
                vs <?php echo number_format($stats['views_last_month']); ?> bulan lalu
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- MAIN GRID: Chart + Top Articles -->
    <!-- ============================================ -->
    <div class="dashboard-grid">
        <!-- Publish Trend Chart -->
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <h3><i class="fas fa-chart-area"></i> Tren Publish 30 Hari</h3>
                <div class="chart-legend">
                    <div class="chart-legend-box"></div>
                    <span>Artikel Dipublish</span>
                </div>
            </div>
            <div class="chart-container">
                <?php 
                $maxPublish = max(array_values($publishData));
                if ($maxPublish === 0) $maxPublish = 1;
                $dates = array_keys($publishData);
                $values = array_values($publishData);
                foreach ($values as $i => $val): 
                    $height = ($val / $maxPublish) * 100;
                    $date = date('d M', strtotime($dates[$i]));
                ?>
                    <div class="chart-bar" 
                         style="height: <?php echo max(2, $height); ?>%; animation-delay: <?php echo $i * 0.02; ?>s;" 
                         data-value="<?php echo $val; ?> artikel (<?php echo $date; ?>)"
                         title="<?php echo $date; ?>: <?php echo $val; ?> artikel">
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="chart-labels">
                <span><?php echo date('d M', strtotime('-29 days')); ?></span>
                <span>Hari Ini</span>
            </div>
        </div>

        <!-- Top Articles -->
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <h3><i class="fas fa-fire"></i> Top 5 Artikel</h3>
                <a href="<?php echo url('admin/articles.php'); ?>" class="view-all">Lihat Semua →</a>
            </div>
            
            <?php if (empty($topArticles)): ?>
                <div class="empty-state" style="padding:2rem;">
                    <i class="fas fa-inbox"></i>
                    <p>Belum ada artikel terpublikasi</p>
                </div>
            <?php else: ?>
                <ul class="top-articles-list">
                    <?php foreach ($topArticles as $i => $article): 
                        $rankClass = $i < 3 ? 'rank-' . ($i + 1) : 'rank-other';
                    ?>
                        <li class="top-article-item">
                            <div class="top-article-rank <?php echo $rankClass; ?>">
                                <?php echo $i + 1; ?>
                            </div>
                            <div class="top-article-content">
                                <div class="top-article-title">
                                    <a href="<?php echo url('article.php?slug=' . $article['slug']); ?>" 
                                       target="_blank">
                                        <?php echo htmlspecialchars($article['title']); ?>
                                    </a>
                                </div>
                                <div class="top-article-meta">
                                    <span><i class="fas fa-eye"></i> <?php echo number_format($article['views']); ?></span>
                                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($article['category_name'] ?? 'Umum'); ?></span>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- DAILY TIP -->
    <!-- ============================================ -->
    <div class="daily-tip">
        <div class="tip-header">
            <span class="tip-icon"><?php echo $dailyTip['icon']; ?></span>
            <span><?php echo $dailyTip['title']; ?> Hari Ini</span>
        </div>
        <div class="tip-text"><?php echo $dailyTip['text']; ?></div>
    </div>

    <!-- ============================================ -->
    <!-- SECOND GRID: Streak + Comments -->
    <!-- ============================================ -->
    <div class="dashboard-grid">
        <!-- Writing Streak -->
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <h3><i class="fas fa-fire-alt"></i> Writing Streak</h3>
                <span class="badge badge-success">
                    <i class="fas fa-bolt"></i> 
                    <?php echo $currentStreak > 0 ? 'Aktif' : 'Istirahat'; ?>
                </span>
            </div>
            
            <div class="streak-heatmap">
                <?php foreach ($streakData as $date => $active): ?>
                    <div class="heatmap-cell <?php echo $active ? 'active' : ''; ?>" 
                         title="<?php echo date('d M Y', strtotime($date)); ?><?php echo $active ? ' - Menulis!' : ''; ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="streak-stats">
                <div class="streak-stat">
                    <div class="value"><?php echo $currentStreak; ?></div>
                    <div class="label">🔥 Streak Saat Ini</div>
                </div>
                <div class="streak-stat">
                    <div class="value"><?php echo $longestStreak; ?></div>
                    <div class="label">🏆 Rekor Tertinggi</div>
                </div>
                <div class="streak-stat">
                    <div class="value"><?php echo $totalWritingDays; ?></div>
                    <div class="label">📅 Total Hari Menulis</div>
                </div>
            </div>
        </div>

        <!-- Recent Comments -->
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <h3><i class="fas fa-comments"></i> Komentar Terbaru</h3>
                <a href="<?php echo url('admin/comments.php'); ?>" class="view-all">Kelola →</a>
            </div>
            
            <?php if (empty($recentComments)): ?>
                <div class="empty-state" style="padding:2rem;">
                    <i class="fas fa-comment-slash"></i>
                    <p>Belum ada komentar</p>
                </div>
            <?php else: ?>
                <ul class="recent-comments-list">
                    <?php foreach ($recentComments as $comment): 
                        // 🛡️ Ekstrak semua field dengan fallback aman
                        $cStatus  = $comment['status'] ?? 'pending';
                        $cName    = $comment['nama'] ?? 'Anonim';
                        $cText    = $comment['comment'] ?? '';
                        $cSlug    = $comment['article_slug'] ?? '#';
                        $cTitle   = $comment['article_title'] ?? 'Artikel';
                        $cPreview = $cText !== '' ? excerpt($cText, 100) : '(komentar kosong)';
                    ?>
                        <li class="recent-comment-item <?php echo htmlspecialchars($cStatus); ?>">
                            <div class="comment-header">
                                <span class="comment-author">
                                    <?php echo htmlspecialchars($cName); ?>
                                </span>
                                <span class="comment-status <?php echo htmlspecialchars($cStatus); ?>">
                                    <?php echo ucfirst($cStatus); ?>
                                </span>
                            </div>
                            <div class="comment-text">
                                "<?php echo htmlspecialchars($cPreview); ?>"
                            </div>
                            <div class="comment-article">
                                <i class="fas fa-newspaper"></i>
                                <a href="<?php echo url('article.php?slug=' . $cSlug); ?>" target="_blank">
                                    <?php echo htmlspecialchars(excerpt($cTitle, 50)); ?>
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- ACHIEVEMENTS -->
    <!-- ============================================ -->
    <div class="section-title-ultimate">
        <h2><i class="fas fa-medal"></i> Pencapaian Saya</h2>
    </div>
    
    <div class="dashboard-card" style="margin-bottom:2rem;">
        <?php if (empty($achievements)): ?>
            <div class="empty-state" style="padding:2rem;">
                <i class="fas fa-trophy"></i>
                <h3>Belum Ada Pencapaian</h3>
                <p>Mulai menulis artikel untuk unlock achievements!</p>
            </div>
        <?php else: ?>
            <div class="achievements-grid">
                <?php foreach ($achievements as $achievement): ?>
                    <div class="achievement-card <?php echo $achievement['unlocked'] ? 'unlocked' : 'locked'; ?>">
                        <span class="achievement-icon"><?php echo $achievement['icon']; ?></span>
                        <div class="achievement-title"><?php echo $achievement['title']; ?></div>
                        <div class="achievement-desc"><?php echo $achievement['desc']; ?></div>
                        <?php if (!$achievement['unlocked'] && isset($achievement['target'])): 
                            $progressPercent = min(100, round(($achievement['progress'] / $achievement['target']) * 100));
                        ?>
                            <div class="achievement-progress">
                                <div class="achievement-progress-fill" 
                                     style="width: <?php echo $progressPercent; ?>%;">
                                </div>
                            </div>
                            <span class="achievement-progress-text">
                                <?php echo number_format($achievement['progress']); ?> / <?php echo number_format($achievement['target']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================ -->
    <!-- QUICK ACTIONS -->
    <!-- ============================================ -->
    <div class="section-title-ultimate">
        <h2><i class="fas fa-bolt"></i> Aksi Cepat</h2>
    </div>
    
    <div class="quick-actions-grid">
        <a href="<?php echo url('admin/article-edit.php'); ?>" class="quick-action-card">
            <div class="quick-action-icon blue">
                <i class="fas fa-pen"></i>
            </div>
            <strong>Tulis Artikel</strong>
            <small>Mulai menulis</small>
        </a>
        
        <a href="<?php echo url('admin/articles.php'); ?>" class="quick-action-card">
            <div class="quick-action-icon green">
                <i class="fas fa-list"></i>
            </div>
            <strong>Kelola Artikel</strong>
            <small><?php echo $stats['total_articles']; ?> artikel</small>
        </a>
        
        <a href="<?php echo url('admin/comments.php'); ?>" class="quick-action-card">
            <div class="quick-action-icon orange">
                <i class="fas fa-comments"></i>
            </div>
            <strong><?php echo $stats['pending_comments'] > 0 ? 'Review Komentar' : 'Komentar'; ?></strong>
            <small><?php echo $stats['pending_comments'] > 0 ? $stats['pending_comments'] . ' pending' : 'Semua bersih ✨'; ?></small>
        </a>
        
        <a href="<?php echo url('admin/profile.php'); ?>" class="quick-action-card">
            <div class="quick-action-icon purple">
                <i class="fas fa-user"></i>
            </div>
            <strong>Edit Profil</strong>
            <small>Update info</small>
        </a>
        
        <a href="<?php echo url(); ?>" target="_blank" class="quick-action-card">
            <div class="quick-action-icon red">
                <i class="fas fa-external-link-alt"></i>
            </div>
            <strong>Lihat Website</strong>
            <small>Buka di tab baru</small>
        </a>
    </div>

    <!-- ============================================ -->
    <!-- RECENT ARTICLES TABLE -->
    <!-- ============================================ -->
    <div class="section-title-ultimate">
        <h2><i class="fas fa-newspaper"></i> Artikel Terbaru Saya</h2>
        <a href="<?php echo url('admin/articles.php'); ?>" class="view-all">Lihat Semua →</a>
    </div>
    
    <div class="dashboard-card" style="margin-bottom:2rem;">
        <?php if (empty($recent)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>Belum ada artikel</h3>
                <p>Mulai menulis artikel pertama Anda!</p>
                <a href="<?php echo url('admin/article-edit.php'); ?>" class="btn-primary">
                    <i class="fas fa-pen"></i> Tulis Artikel Pertama
                </a>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Judul</th>
                            <th>Kategori</th>
                            <th>Views</th>
                            <th>Komentar</th>
                            <th>Status</th>
                            <th>Tanggal</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $a): 
                            // 🛡️ Ekstrak dengan fallback aman
                            $aTitle      = $a['title'] ?? '(Tanpa Judul)';
                            $aCat        = $a['category_name'] ?? '-';
                            $aViews      = (int)($a['views'] ?? 0);
                            $aComments   = (int)($a['comment_count'] ?? 0);
                            $aStatus     = $a['status'] ?? 'draft';
                            $aDate       = $a['created_at'] ?? date('Y-m-d H:i:s');
                            $aId         = (int)($a['id'] ?? 0);
                            $aSlug       = $a['slug'] ?? '#';
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($aTitle); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($aCat); ?></td>
                                <td>
                                    <i class="fas fa-eye" style="color:#3498db;"></i> 
                                    <?php echo number_format($aViews); ?>
                                </td>
                                <td>
                                    <i class="fas fa-comments" style="color:#f39c12;"></i> 
                                    <?php echo $aComments; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo htmlspecialchars($aStatus); ?>">
                                        <?php echo ucfirst($aStatus); ?>
                                    </span>
                                </td>
                                <td><?php echo formatDate($aDate); ?></td>
                                <td>
                                    <a href="<?php echo url('admin/article-edit.php?id=' . $aId); ?>" 
                                       class="btn-small btn-edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="<?php echo url('article.php?slug=' . $aSlug); ?>" 
                                       class="btn-small btn-view" target="_blank" title="Lihat">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================ -->
    <!-- QUOTE OF THE DAY -->
    <!-- ============================================ -->
    <div class="quote-card">
        <div class="quote-text">
            "<?php echo htmlspecialchars($dailyQuote['text']); ?>"
        </div>
        <div class="quote-author">
            — <?php echo htmlspecialchars($dailyQuote['author']); ?>
        </div>
    </div>

</main>

<script>
// ===== LIVE CLOCK DENGAN TIMEZONE INDONESIA =====
function updateClock() {
    const now = new Date();
    // Format dengan timezone Indonesia
    const dateOptions = { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric',
        timeZone: 'Asia/Jakarta'
    };
    const timeOptions = { 
        hour: '2-digit', 
        minute: '2-digit', 
        second: '2-digit',
        hour12: false,
        timeZone: 'Asia/Jakarta'
    };
    
    const dateStr = now.toLocaleDateString('id-ID', dateOptions);
    const timeStr = now.toLocaleTimeString('id-ID', timeOptions);
    
    const dateEl = document.getElementById('liveDate');
    const timeEl = document.getElementById('liveTime');
    
    if (dateEl) dateEl.textContent = dateStr;
    if (timeEl) timeEl.textContent = timeStr;
}

updateClock();
setInterval(updateClock, 1000);

// ===== ANIMATED COUNTERS (FIXED FOR INDONESIAN FORMAT) =====
function animateCounter(el) {
    const target = parseInt(el.getAttribute('data-value'));
    if (isNaN(target) || target === 0) {
        el.textContent = '0';
        return;
    }
    
    const duration = 1500;
    const steps = 60;
    const increment = target / steps;
    let current = 0;
    let step = 0;
    
    const timer = setInterval(function() {
        step++;
        current += increment;
        if (step >= steps) {
            el.textContent = target.toLocaleString('id-ID');
            clearInterval(timer);
        } else {
            el.textContent = Math.floor(current).toLocaleString('id-ID');
        }
    }, duration / steps);
}

// Apply animation dengan IntersectionObserver untuk performa
document.addEventListener('DOMContentLoaded', function() {
    var counters = document.querySelectorAll('.animated-counter');
    
    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting && !entry.target.dataset.animated) {
                    entry.target.dataset.animated = '1';
                    animateCounter(entry.target);
                }
            });
        }, { threshold: 0.3 });
        
        counters.forEach(function(el) {
            observer.observe(el);
        });
    } else {
        // Fallback
        counters.forEach(animateCounter);
    }
});

// ===== CHART BAR INTERACTION =====
document.querySelectorAll('.chart-bar').forEach(function(bar) {
    bar.addEventListener('mouseenter', function() {
        this.style.filter = 'brightness(1.2)';
    });
    bar.addEventListener('mouseleave', function() {
        this.style.filter = '';
    });
});

// ===== PROGRESS BAR ANIMATION =====
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.achievement-progress-fill').forEach(function(bar) {
        var width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(function() {
            bar.style.width = width;
        }, 500);
    });
});

console.log('%c🎓 Dashboard Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
console.log('%cTotal Articles: ' + <?php echo $stats['total_articles']; ?>, 'color:#3498db;');
console.log('%cTotal Views: ' + <?php echo $stats['total_views']; ?>, 'color:#2ecc71;');
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>