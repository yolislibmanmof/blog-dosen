<?php
require_once __DIR__ . '/config/functions.php';
$pageTitle = 'Beranda';

// ============================================
// 📊 DATA UNTUK HOMEPAGE
// ============================================

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 9;
$offset = ($page - 1) * $limit;

// Filter & Search
$search = isset($_GET['q']) ? $_GET['q'] : '';
$categorySlug = isset($_GET['category']) ? $_GET['category'] : '';

// ============================================
// 1. FEATURED ARTICLES (3 artikel pilihan)
// ============================================
$featuredArticles = [];
try {
    $stmt = db()->query("
        SELECT a.*, u.nama as author_name, u.foto as author_foto, 
               u.jabatan, c.name as category_name, c.slug as category_slug
        FROM articles a 
        JOIN users u ON a.author_id = u.id 
        LEFT JOIN categories c ON a.category_id = c.id 
        WHERE a.status = 'published' AND a.featured_image IS NOT NULL AND a.featured_image != ''
        ORDER BY a.views DESC, a.created_at DESC 
        LIMIT 3
    ");
    $featuredArticles = $stmt->fetchAll();
} catch (Exception $e) {}

// ============================================
// 2. TRENDING ARTICLES (5 paling populer)
// ============================================
$trendingArticles = [];
try {
    $stmt = db()->query("
        SELECT a.id, a.title, a.slug, a.views, a.featured_image,
               u.nama as author_name, c.name as category_name
        FROM articles a 
        JOIN users u ON a.author_id = u.id 
        LEFT JOIN categories c ON a.category_id = c.id 
        WHERE a.status = 'published'
        ORDER BY a.views DESC 
        LIMIT 5
    ");
    $trendingArticles = $stmt->fetchAll();
} catch (Exception $e) {}

// ============================================
// 3. TOP AUTHORS (5 dosen teraktif)
// ============================================
$topAuthors = [];
try {
    $stmt = db()->query("
        SELECT u.id, u.nama, u.foto, u.jabatan, u.prodi,
               COUNT(a.id) as article_count,
               IFNULL(SUM(a.views), 0) as total_views
        FROM users u 
        JOIN articles a ON a.author_id = u.id 
        WHERE a.status = 'published'
        GROUP BY u.id 
        ORDER BY article_count DESC, total_views DESC
        LIMIT 5
    ");
    $topAuthors = $stmt->fetchAll();
} catch (Exception $e) {}

// ============================================
// 4. CATEGORIES DENGAN JUMLAH ARTIKEL
// ============================================
$categoriesWithCount = [];
try {
    $stmt = db()->query("
        SELECT c.id, c.name, c.slug, c.description,
               COUNT(a.id) as article_count
        FROM categories c
        LEFT JOIN articles a ON a.category_id = c.id AND a.status = 'published'
        GROUP BY c.id
        ORDER BY article_count DESC, c.name ASC
    ");
    $categoriesWithCount = $stmt->fetchAll();
} catch (Exception $e) {}

// ============================================
// 5. GLOBAL STATS (counter homepage)
// ============================================
$globalStats = [
    'total_articles' => 0,
    'total_authors' => 0,
    'total_views' => 0,
    'total_comments' => 0,
    'total_categories' => 0,
];
try {
    $globalStats['total_articles'] = (int)db()->query("SELECT COUNT(*) FROM articles WHERE status='published'")->fetchColumn();
    $globalStats['total_authors'] = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
    $globalStats['total_views'] = (int)db()->query("SELECT IFNULL(SUM(views), 0) FROM articles")->fetchColumn();
    $globalStats['total_comments'] = (int)db()->query("SELECT COUNT(*) FROM comments WHERE status='approved'")->fetchColumn();
    $globalStats['total_categories'] = count($categoriesWithCount);
} catch (Exception $e) {}

// ============================================
// 6. ARTIKEL UTAMA (dengan filter & pagination)
// ============================================
$query = "SELECT a.*, u.nama as author_name, u.foto as author_foto, c.name as category_name 
          FROM articles a 
          JOIN users u ON a.author_id = u.id 
          LEFT JOIN categories c ON a.category_id = c.id 
          WHERE a.status = 'published'";
$params = [];

if ($search) {
    $query .= " AND (a.title LIKE ? OR a.content LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($categorySlug) {
    $query .= " AND c.slug = ?";
    $params[] = $categorySlug;
}

$countStmt = db()->prepare(str_replace('SELECT a.*, u.nama as author_name, u.foto as author_foto, c.name as category_name', 'SELECT COUNT(*)', $query));
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $limit);

$query .= " ORDER BY a.created_at DESC LIMIT $limit OFFSET $offset";
$stmt = db()->prepare($query);
$stmt->execute($params);
$articles = $stmt->fetchAll();

// Kategori untuk filter
$categories = db()->query("SELECT * FROM categories")->fetchAll();

$settings = getSettings();

// ============================================
// 7. TESTIMONIAL / QUOTES DOSEN
// ============================================
$testimonials = [
    [
        'quote' => 'Menulis adalah cara terbaik untuk berbagi ilmu. Melalui blog ini, saya bisa menjangkau lebih banyak pembaca.',
        'nama' => 'Dr. Ahmad Fauzi, M.Pd',
        'jabatan' => 'Lektor Kepala',
        'foto' => 'https://ui-avatars.com/api/?name=Ahmad+Fauzi&background=1e3a5f&color=fff&size=120'
    ],
    [
        'quote' => 'Blog dosen adalah jembatan antara akademisi dan masyarakat. Setiap artikel adalah sumbangsih nyata.',
        'nama' => 'Prof. Siti Rahayu, Ph.D',
        'jabatan' => 'Guru Besar',
        'foto' => 'https://ui-avatars.com/api/?name=Siti+Rahayu&background=f39c12&color=fff&size=120'
    ],
    [
        'quote' => 'Melalui tulisan, penelitian saya bisa dibaca oleh siapa saja di seluruh dunia. Ini adalah revolusi.',
        'nama' => 'Dr. Budi Santoso, M.Si',
        'jabatan' => 'Kepala Pusat Penelitian',
        'foto' => 'https://ui-avatars.com/api/?name=Budi+Santoso&background=2c5f8d&color=fff&size=120'
    ]
];

include __DIR__ . '/includes/header.php';
?>

<!-- ============================================ -->
<!-- 🎨 HOMEPAGE ULTIMATE STYLES -->
<!-- ============================================ -->
<style>
/* ===== HERO ENHANCED ===== */
.hero-ultimate {
    position: relative;
    background: linear-gradient(135deg, #16324f 0%, #1e3a5f 50%, #2c5f8d 100%);
    color: white;
    padding: 6rem 0 8rem;
    overflow: hidden;
    min-height: 600px;
    display: flex;
    align-items: center;
}
.hero-ultimate::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: 
        radial-gradient(circle at 20% 50%, rgba(243, 156, 18, 0.15) 0%, transparent 50%),
        radial-gradient(circle at 80% 80%, rgba(52, 152, 219, 0.15) 0%, transparent 50%);
    z-index: 0;
}
.hero-ultimate::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 100px;
    background: linear-gradient(to top, #f8f9fa, transparent);
    z-index: 1;
}
.hero-content {
    position: relative;
    z-index: 2;
    text-align: center;
    max-width: 900px;
    margin: 0 auto;
}
.hero-badge {
    display: inline-block;
    background: rgba(243, 156, 18, 0.2);
    color: #f39c12;
    padding: 0.4rem 1.2rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 1.5rem;
    border: 1px solid rgba(243, 156, 18, 0.3);
    letter-spacing: 1px;
}
.hero-title {
    font-size: 4rem;
    font-weight: 800;
    margin-bottom: 0.5rem;
    letter-spacing: 4px;
    text-shadow: 0 4px 20px rgba(0,0,0,0.3);
}
.hero-subtitle {
    font-size: 1.3rem;
    font-weight: 300;
    margin-bottom: 2rem;
    opacity: 0.95;
    letter-spacing: 2px;
}
.hero-typing {
    font-family: 'Poppins', sans-serif;
    font-size: 1.4rem;
    margin-bottom: 2rem;
    min-height: 2.5rem;
    color: #f39c12;
    font-weight: 500;
}
.hero-typing::after {
    content: '|';
    animation: blink 1s infinite;
}
@keyframes blink {
    0%, 50% { opacity: 1; }
    51%, 100% { opacity: 0; }
}
.hero-quote {
    max-width: 750px;
    margin: 0 auto 2.5rem;
    font-family: 'Merriweather', serif;
    font-style: italic;
    font-size: 1.1rem;
    line-height: 1.8;
    opacity: 0.95;
    padding: 1.5rem 2rem;
    background: rgba(255,255,255,0.05);
    border-radius: 15px;
    border-left: 4px solid #f39c12;
}
.hero-quote cite {
    display: block;
    margin-top: 1rem;
    font-style: normal;
    font-size: 0.9rem;
    opacity: 0.8;
    text-align: right;
}
.hero-cta-group {
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: 3rem;
}
.hero-cta-primary {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white !important;
    padding: 1rem 2.5rem;
    border-radius: 50px;
    text-decoration: none;
    font-weight: 700;
    font-size: 1rem;
    transition: all 0.3s ease;
    box-shadow: 0 8px 25px rgba(243, 156, 18, 0.4);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.hero-cta-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 30px rgba(243, 156, 18, 0.5);
}
.hero-cta-secondary {
    background: rgba(255,255,255,0.1);
    color: white !important;
    padding: 1rem 2.5rem;
    border-radius: 50px;
    text-decoration: none;
    font-weight: 600;
    font-size: 1rem;
    transition: all 0.3s ease;
    border: 2px solid rgba(255,255,255,0.3);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    backdrop-filter: blur(10px);
}
.hero-cta-secondary:hover {
    background: rgba(255,255,255,0.2);
    border-color: white;
    transform: translateY(-3px);
}

/* ===== HERO STATS FLOATING ===== */
.hero-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
    max-width: 800px;
    margin: 0 auto;
}
.hero-stat-item {
    text-align: center;
    padding: 1rem;
    background: rgba(255,255,255,0.08);
    border-radius: 12px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.1);
    transition: all 0.3s ease;
}
.hero-stat-item:hover {
    transform: translateY(-5px);
    background: rgba(255,255,255,0.12);
}
.hero-stat-icon {
    font-size: 2rem;
    color: #f39c12;
    margin-bottom: 0.3rem;
}
.hero-stat-value {
    font-size: 2rem;
    font-weight: 800;
    display: block;
    line-height: 1;
}
.hero-stat-label {
    font-size: 0.8rem;
    opacity: 0.8;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-top: 0.3rem;
    display: block;
}

/* ===== SECTION TITLE ULTIMATE ===== */
.section-title-ultimate {
    text-align: center;
    margin-bottom: 3rem;
}
.section-title-ultimate h2 {
    font-size: 2.5rem;
    color: #1e3a5f;
    margin-bottom: 0.5rem;
    position: relative;
    display: inline-block;
}
.section-title-ultimate h2::after {
    content: '';
    position: absolute;
    bottom: -10px;
    left: 50%;
    transform: translateX(-50%);
    width: 60px;
    height: 4px;
    background: linear-gradient(90deg, #f39c12, #e67e22);
    border-radius: 2px;
}
.section-title-ultimate p {
    color: #666;
    font-size: 1.05rem;
    max-width: 600px;
    margin: 1.5rem auto 0;
}

/* ===== FEATURED ARTICLES CAROUSEL ===== */
.featured-section {
    padding: 5rem 0;
    background: #f8f9fa;
    margin-top: -60px;
    position: relative;
    z-index: 3;
}
.featured-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2rem;
    margin-top: 2rem;
}
.featured-card {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 10px 40px rgba(0,0,0,0.08);
    transition: all 0.4s ease;
    position: relative;
}
.featured-card:hover {
    transform: translateY(-10px);
    box-shadow: 0 20px 50px rgba(0,0,0,0.15);
}
.featured-image {
    height: 250px;
    background-size: cover;
    background-position: center;
    position: relative;
}
.featured-image::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 60%;
    background: linear-gradient(to top, rgba(0,0,0,0.7), transparent);
}
.featured-badge {
    position: absolute;
    top: 15px;
    left: 15px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    padding: 0.3rem 0.9rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 1px;
    z-index: 2;
    box-shadow: 0 4px 10px rgba(243, 156, 18, 0.4);
}
.featured-category {
    position: absolute;
    top: 15px;
    right: 15px;
    background: rgba(255,255,255,0.95);
    color: #1e3a5f;
    padding: 0.3rem 0.9rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    z-index: 2;
}
.featured-content {
    padding: 1.5rem;
}
.featured-title {
    font-size: 1.3rem;
    color: #1e3a5f;
    margin-bottom: 0.8rem;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.featured-title a {
    color: inherit;
    text-decoration: none;
    transition: color 0.3s ease;
}
.featured-title a:hover {
    color: #f39c12;
}
.featured-author {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #f0f0f0;
}
.featured-author img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #f39c12;
}
.featured-author-info strong {
    display: block;
    color: #1e3a5f;
    font-size: 0.9rem;
}
.featured-author-info small {
    color: #888;
    font-size: 0.75rem;
}
.featured-stats {
    display: flex;
    gap: 1rem;
    margin-top: 0.8rem;
    font-size: 0.8rem;
    color: #666;
}
.featured-stats span {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

/* ===== TRENDING SECTION ===== */
.trending-section {
    padding: 5rem 0;
    background: white;
}
.trending-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 3rem;
    margin-top: 2rem;
}
.trending-main {
    position: relative;
    border-radius: 20px;
    overflow: hidden;
    height: 500px;
    background-size: cover;
    background-position: center;
}
.trending-main::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0.3) 60%, transparent 100%);
}
.trending-main-content {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 2rem;
    color: white;
    z-index: 2;
}
.trending-main .featured-badge {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
}
.trending-main-title {
    font-size: 2rem;
    margin-bottom: 1rem;
    line-height: 1.3;
}
.trending-main-title a {
    color: white;
    text-decoration: none;
}
.trending-main-title a:hover {
    color: #f39c12;
}
.trending-main-meta {
    display: flex;
    gap: 1.5rem;
    font-size: 0.9rem;
    opacity: 0.9;
    flex-wrap: wrap;
}
.trending-main-meta span {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.trending-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.trending-item {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 12px;
    transition: all 0.3s ease;
    text-decoration: none;
    color: #333;
}
.trending-item:hover {
    background: white;
    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
    transform: translateX(5px);
}
.trending-rank {
    font-size: 2.5rem;
    font-weight: 800;
    color: #f39c12;
    line-height: 1;
    min-width: 40px;
}
.trending-rank.rank-1 { color: #f39c12; }
.trending-rank.rank-2 { color: #95a5a6; }
.trending-rank.rank-3 { color: #cd7f32; }
.trending-rank.rank-other { color: #bdc3c7; }
.trending-info h4 {
    font-size: 1rem;
    color: #1e3a5f;
    margin-bottom: 0.4rem;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.trending-info small {
    color: #888;
    display: flex;
    align-items: center;
    gap: 0.8rem;
    font-size: 0.8rem;
}

/* ===== CATEGORIES SHOWCASE ===== */
.categories-section {
    padding: 5rem 0;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}
.categories-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.5rem;
    margin-top: 2rem;
}
.category-card {
    background: white;
    padding: 2rem 1.5rem;
    border-radius: 15px;
    text-align: center;
    text-decoration: none;
    color: #333;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border: 2px solid transparent;
}
.category-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 4px;
    background: linear-gradient(90deg, #f39c12, #e67e22);
    transition: left 0.4s ease;
}
.category-card:hover::before { left: 0; }
.category-card:hover {
    border-color: #f39c12;
    transform: translateY(-8px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
}
.category-icon {
    width: 70px;
    height: 70px;
    background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
    color: white;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    margin: 0 auto 1rem;
    transition: all 0.4s ease;
}
.category-card:hover .category-icon {
    transform: rotate(-10deg) scale(1.1);
    background: linear-gradient(135deg, #f39c12, #e67e22);
}
.category-card h3 {
    font-size: 1.15rem;
    color: #1e3a5f;
    margin-bottom: 0.4rem;
}
.category-card p {
    font-size: 0.85rem;
    color: #888;
    margin-bottom: 0.8rem;
    min-height: 2.5rem;
}
.category-count {
    display: inline-block;
    background: #f0f0f0;
    color: #1e3a5f;
    padding: 0.3rem 0.9rem;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: 600;
}
.category-card:hover .category-count {
    background: #f39c12;
    color: white;
}

/* ===== TOP AUTHORS ===== */
.authors-section {
    padding: 5rem 0;
    background: white;
}
.authors-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 2rem;
    margin-top: 2rem;
}
.author-card {
    text-align: center;
    padding: 2rem 1.5rem;
    background: #f8f9fa;
    border-radius: 20px;
    transition: all 0.3s ease;
    position: relative;
}
.author-card:hover {
    background: white;
    box-shadow: 0 15px 40px rgba(0,0,0,0.1);
    transform: translateY(-8px);
}
.author-rank {
    position: absolute;
    top: 15px;
    right: 15px;
    width: 35px;
    height: 35px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.95rem;
    color: white;
}
.author-rank.rank-1 { background: linear-gradient(135deg, #ffd700, #ffed4e); }
.author-rank.rank-2 { background: linear-gradient(135deg, #c0c0c0, #e5e5e5); color: #333; }
.author-rank.rank-3 { background: linear-gradient(135deg, #cd7f32, #b87333); }
.author-rank.rank-other { background: #95a5a6; }
.author-avatar {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    margin-bottom: 1rem;
    border: 4px solid #f39c12;
    transition: transform 0.3s ease;
}
.author-card:hover .author-avatar {
    transform: scale(1.1) rotate(5deg);
}
.author-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e3a5f;
    margin-bottom: 0.3rem;
}
.author-role {
    font-size: 0.85rem;
    color: #888;
    margin-bottom: 1rem;
}
.author-stats {
    display: flex;
    justify-content: space-around;
    padding-top: 1rem;
    border-top: 1px solid #e0e0e0;
    gap: 0.5rem;
}
.author-stat {
    text-align: center;
}
.author-stat strong {
    display: block;
    font-size: 1.3rem;
    color: #1e3a5f;
    font-weight: 800;
}
.author-stat small {
    font-size: 0.7rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* ===== STATS COUNTER SECTION ===== */
.stats-counter-section {
    padding: 5rem 0;
    background: linear-gradient(135deg, #1e3a5f 0%, #2c5f8d 100%);
    color: white;
    position: relative;
    overflow: hidden;
}
.stats-counter-section::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.5;
}
.stats-counter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 2rem;
    position: relative;
    z-index: 1;
}
.counter-item {
    text-align: center;
    padding: 2rem 1rem;
}
.counter-icon {
    width: 80px;
    height: 80px;
    background: rgba(255,255,255,0.1);
    color: #f39c12;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    margin: 0 auto 1rem;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.1);
}
.counter-value {
    font-size: 3.5rem;
    font-weight: 800;
    color: #f39c12;
    line-height: 1;
    margin-bottom: 0.5rem;
}
.counter-value span {
    display: inline-block;
}
.counter-suffix {
    font-size: 2rem;
    color: #f39c12;
    opacity: 0.7;
}
.counter-label {
    font-size: 1rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 2px;
}

/* ===== TESTIMONIAL SECTION ===== */
.testimonial-section {
    padding: 5rem 0;
    background: #f8f9fa;
}
.testimonial-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 2rem;
    margin-top: 2rem;
}
.testimonial-card {
    background: white;
    padding: 2rem;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
    position: relative;
    transition: all 0.3s ease;
}
.testimonial-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
}
.testimonial-card::before {
    content: '"';
    position: absolute;
    top: -20px;
    left: 20px;
    font-size: 6rem;
    color: #f39c12;
    font-family: Georgia, serif;
    line-height: 1;
    opacity: 0.3;
}
.testimonial-quote {
    font-style: italic;
    color: #555;
    line-height: 1.7;
    margin-bottom: 1.5rem;
    position: relative;
    z-index: 1;
}
.testimonial-author {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding-top: 1.5rem;
    border-top: 1px solid #f0f0f0;
}
.testimonial-author img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #f39c12;
}
.testimonial-author-info strong {
    display: block;
    color: #1e3a5f;
    font-size: 1rem;
}
.testimonial-author-info small {
    color: #888;
    font-size: 0.85rem;
}

/* ===== NEWSLETTER SECTION ===== */
.newsletter-section {
    padding: 5rem 0;
    background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
    color: white;
    position: relative;
    overflow: hidden;
}
.newsletter-section::before {
    content: '📧';
    position: absolute;
    font-size: 20rem;
    top: -50px;
    right: -50px;
    opacity: 0.1;
    transform: rotate(-15deg);
}
.newsletter-content {
    max-width: 700px;
    margin: 0 auto;
    text-align: center;
    position: relative;
    z-index: 1;
}
.newsletter-content h2 {
    font-size: 2.5rem;
    margin-bottom: 1rem;
}
.newsletter-content p {
    font-size: 1.1rem;
    opacity: 0.95;
    margin-bottom: 2rem;
}
.newsletter-form {
    display: flex;
    max-width: 500px;
    margin: 0 auto;
    background: white;
    border-radius: 50px;
    padding: 5px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}
.newsletter-form input {
    flex: 1;
    border: none;
    padding: 0.9rem 1.5rem;
    font-size: 1rem;
    border-radius: 50px;
    outline: none;
    color: #333;
}
.newsletter-form button {
    background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
    color: white;
    border: none;
    padding: 0.9rem 2rem;
    border-radius: 50px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.newsletter-form button:hover {
    background: linear-gradient(135deg, #16324f, #1e3a5f);
    transform: translateX(3px);
}

/* ===== CTA BANNER ===== */
.cta-banner-section {
    padding: 5rem 0;
    background: white;
}
.cta-banner {
    background: linear-gradient(135deg, #1e3a5f 0%, #2c5f8d 100%);
    border-radius: 25px;
    padding: 4rem 3rem;
    color: white;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 2rem;
    align-items: center;
    position: relative;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(30, 58, 95, 0.3);
}
.cta-banner::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(243, 156, 18, 0.2), transparent 70%);
    border-radius: 50%;
}
.cta-banner-content h2 {
    font-size: 2.5rem;
    margin-bottom: 1rem;
    position: relative;
}
.cta-banner-content p {
    font-size: 1.1rem;
    opacity: 0.9;
    position: relative;
}
.cta-banner-btn {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white !important;
    padding: 1.2rem 3rem;
    border-radius: 50px;
    text-decoration: none;
    font-weight: 700;
    font-size: 1.1rem;
    transition: all 0.3s ease;
    box-shadow: 0 10px 25px rgba(243, 156, 18, 0.4);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    position: relative;
    white-space: nowrap;
}
.cta-banner-btn:hover {
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 15px 35px rgba(243, 156, 18, 0.6);
}

/* ===== ENHANCED BLOG SECTION ===== */
.blog-section-ultimate {
    padding: 5rem 0;
    background: white;
}
.blog-grid-ultimate {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 2rem;
    margin-bottom: 3rem;
}
.blog-card-ultimate {
    background: white;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 5px 20px rgba(0,0,0,0.06);
    transition: all 0.4s ease;
    display: flex;
    flex-direction: column;
    border: 1px solid #f0f0f0;
}
.blog-card-ultimate:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.12);
    border-color: #f39c12;
}
.blog-image-ultimate {
    height: 220px;
    background-size: cover;
    background-position: center;
    position: relative;
}
.blog-image-ultimate::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, transparent 60%, rgba(0,0,0,0.4));
}
.blog-content-ultimate {
    padding: 1.8rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.category-ultimate {
    display: inline-block;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    padding: 0.3rem 1rem;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 700;
    margin-bottom: 0.8rem;
    align-self: flex-start;
    letter-spacing: 0.5px;
}
.blog-title-ultimate {
    font-size: 1.25rem;
    margin-bottom: 1rem;
    color: #1e3a5f;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.blog-title-ultimate a {
    color: inherit;
    text-decoration: none;
    transition: color 0.3s ease;
}
.blog-title-ultimate a:hover {
    color: #f39c12;
}
.blog-meta-ultimate {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
    font-size: 0.82rem;
    color: #888;
    flex-wrap: wrap;
}
.blog-meta-ultimate span {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}
.blog-author-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #f39c12;
}
.excerpt-ultimate {
    color: #666;
    margin-bottom: 1.5rem;
    flex: 1;
    font-size: 0.9rem;
    line-height: 1.6;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.read-more-ultimate {
    color: #1e3a5f;
    text-decoration: none;
    font-weight: 700;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding-top: 1rem;
    border-top: 1px solid #f0f0f0;
}
.read-more-ultimate:hover {
    color: #f39c12;
    gap: 1rem;
}

/* ===== ENHANCED PAGINATION ===== */
.pagination-ultimate {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    margin-top: 3rem;
    flex-wrap: wrap;
}
.page-btn {
    min-width: 42px;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    text-decoration: none;
    color: #666;
    background: #f8f9fa;
    font-weight: 600;
    transition: all 0.3s ease;
    padding: 0 1rem;
    border: 1px solid transparent;
}
.page-btn:hover {
    background: #1e3a5f;
    color: white;
    transform: translateY(-2px);
}
.page-btn.active {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    box-shadow: 0 5px 15px rgba(243, 156, 18, 0.3);
}
.page-btn.disabled {
    opacity: 0.4;
    cursor: not-allowed;
    pointer-events: none;
}
.page-info-ultimate {
    color: #888;
    font-size: 0.9rem;
    margin: 0 1rem;
}

/* ===== EMPTY STATE ULTIMATE ===== */
.empty-state-ultimate {
    text-align: center;
    padding: 5rem 2rem;
    background: #f8f9fa;
    border-radius: 20px;
    border: 2px dashed #ddd;
}
.empty-state-ultimate i {
    font-size: 5rem;
    color: #bdc3c7;
    margin-bottom: 1.5rem;
}
.empty-state-ultimate h3 {
    color: #1e3a5f;
    font-size: 1.8rem;
    margin-bottom: 0.5rem;
}
.empty-state-ultimate p {
    color: #888;
    margin-bottom: 2rem;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 992px) {
    .hero-title { font-size: 2.8rem; }
    .hero-stats { grid-template-columns: repeat(2, 1fr); gap: 1rem; }
    .featured-grid { grid-template-columns: 1fr; }
    .trending-grid { grid-template-columns: 1fr; }
    .cta-banner { grid-template-columns: 1fr; text-align: center; }
    .counter-value { font-size: 2.5rem; }
}
@media (max-width: 576px) {
    .hero-ultimate { padding: 4rem 0 6rem; }
    .hero-title { font-size: 2.2rem; letter-spacing: 2px; }
    .hero-subtitle { font-size: 1rem; }
    .hero-typing { font-size: 1.1rem; }
    .hero-cta-group { flex-direction: column; align-items: stretch; }
    .newsletter-form { flex-direction: column; border-radius: 15px; }
    .newsletter-form input,
    .newsletter-form button { border-radius: 10px; }
    .section-title-ultimate h2 { font-size: 1.8rem; }
}
</style>

<!-- ============================================ -->
<!-- 🎯 HERO ULTIMATE -->
<!-- ============================================ -->
<section class="hero-ultimate" id="home">
    <div class="container hero-content">
        <div class="hero-badge">
            <i class="fas fa-sparkles"></i> PLATFORM BERBAGAI ILMU
        </div>
        <h1 class="hero-title">BLOG DOSEN</h1>
        <h2 class="hero-subtitle"><?= htmlspecialchars($settings['nama_kampus']) ?></h2>
        
        <div class="hero-typing" id="heroTyping"></div>
        
        <blockquote class="hero-quote">
            <p>"<?= htmlspecialchars($settings['quote']) ?>"</p>
            <cite>— <?= htmlspecialchars($settings['quote_author']) ?></cite>
        </blockquote>
        
        <div class="hero-cta-group">
            <?php if (isLoggedIn()): ?>
                <a href="<?= url('admin/article-edit.php') ?>" class="hero-cta-primary">
                    <i class="fas fa-pen"></i> TULIS ARTIKEL
                </a>
                <a href="<?= url('admin/') ?>" class="hero-cta-secondary">
                    <i class="fas fa-tachometer-alt"></i> DASHBOARD
                </a>
            <?php else: ?>
                <a href="<?= url('admin/login.php') ?>" class="hero-cta-primary">
                    <i class="fas fa-pen"></i> BUAT BLOG
                </a>
                <a href="#blog" class="hero-cta-secondary">
                    <i class="fas fa-book-open"></i> JELAJAHI ARTIKEL
                </a>
            <?php endif; ?>
        </div>

        <!-- Stats Floating -->
        <div class="hero-stats">
            <div class="hero-stat-item">
                <div class="hero-stat-icon"><i class="fas fa-newspaper"></i></div>
                <span class="hero-stat-value counter" data-target="<?= $globalStats['total_articles'] ?>">0</span>
                <span class="hero-stat-label">Artikel</span>
            </div>
            <div class="hero-stat-item">
                <div class="hero-stat-icon"><i class="fas fa-users"></i></div>
                <span class="hero-stat-value counter" data-target="<?= $globalStats['total_authors'] ?>">0</span>
                <span class="hero-stat-label">Dosen</span>
            </div>
            <div class="hero-stat-item">
                <div class="hero-stat-icon"><i class="fas fa-eye"></i></div>
                <span class="hero-stat-value counter" data-target="<?= $globalStats['total_views'] ?>">0</span>
                <span class="hero-stat-label">Views</span>
            </div>
            <div class="hero-stat-item">
                <div class="hero-stat-icon"><i class="fas fa-comments"></i></div>
                <span class="hero-stat-value counter" data-target="<?= $globalStats['total_comments'] ?>">0</span>
                <span class="hero-stat-label">Komentar</span>
            </div>
        </div>
    </div>
</section>

<!-- ============================================ -->
<!-- ⭐ FEATURED ARTICLES -->
<!-- ============================================ -->
<?php if (!empty($featuredArticles)): ?>
<section class="featured-section">
    <div class="container">
        <div class="section-title-ultimate">
            <h2>⭐ Artikel Pilihan</h2>
            <p>Artikel terbaik yang direkomendasikan untuk Anda baca</p>
        </div>
        
        <div class="featured-grid">
            <?php foreach ($featuredArticles as $article): ?>
                <article class="featured-card">
                    <div class="featured-image" style="background-image: url('<?= htmlspecialchars($article['featured_image']) ?>');">
                        <span class="featured-badge">⭐ FEATURED</span>
                        <span class="featured-category"><?= htmlspecialchars($article['category_name'] ?? 'Umum') ?></span>
                    </div>
                    <div class="featured-content">
                        <h3 class="featured-title">
                            <a href="<?= url('article.php?slug=' . $article['slug']) ?>">
                                <?= htmlspecialchars($article['title']) ?>
                            </a>
                        </h3>
                        <p style="color:#666;font-size:0.9rem;line-height:1.6;margin-bottom:0.5rem;">
                            <?= htmlspecialchars(excerpt($article['content'], 120)) ?>
                        </p>
                        <div class="featured-author">
                            <img src="<?= url(ltrim(!empty($article['author_foto']) ? $article['author_foto'] : 'assets/uploads/default.png', '/')) ?>" 
                                 onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($article['author_name']) ?>&background=1e3a5f&color=fff&size=80'"
                                 alt="<?= htmlspecialchars($article['author_name']) ?>">
                            <div class="featured-author-info">
                                <strong><?= htmlspecialchars($article['author_name']) ?></strong>
                                <small><?= htmlspecialchars($article['jabatan'] ?? 'Dosen') ?></small>
                            </div>
                        </div>
                        <div class="featured-stats">
                            <span><i class="fas fa-eye"></i> <?= number_format($article['views']) ?> views</span>
                            <span><i class="fas fa-calendar"></i> <?= formatDate($article['created_at']) ?></span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============================================ -->
<!-- 🔥 TRENDING ARTICLES -->
<!-- ============================================ -->
<?php if (!empty($trendingArticles)): ?>
<section class="trending-section">
    <div class="container">
        <div class="section-title-ultimate">
            <h2>🔥 Artikel Terpopuler</h2>
            <p>Artikel yang paling banyak dibaca minggu ini</p>
        </div>
        
        <div class="trending-grid">
            <?php 
            $firstTrending = $trendingArticles[0];
            $otherTrending = array_slice($trendingArticles, 1, 4);
            ?>
            
            <!-- Trending Main (Artikel Teratas) -->
            <div class="trending-main" style="background-image: url('<?= htmlspecialchars($firstTrending['featured_image'] ?: 'https://images.unsplash.com/photo-1499209974431-9dddcece7f88?w=800') ?>');">
                <span class="featured-badge" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
                    🔥 #1 TRENDING
                </span>
                <div class="trending-main-content">
                    <h2 class="trending-main-title">
                        <a href="<?= url('article.php?slug=' . $firstTrending['slug']) ?>">
                            <?= htmlspecialchars($firstTrending['title']) ?>
                        </a>
                    </h2>
                    <div class="trending-main-meta">
                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($firstTrending['author_name']) ?></span>
                        <span><i class="fas fa-eye"></i> <?= number_format($firstTrending['views']) ?> views</span>
                        <span><i class="fas fa-tag"></i> <?= htmlspecialchars($firstTrending['category_name'] ?? 'Umum') ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Trending List (4 artikel lainnya) -->
            <div class="trending-list">
                <?php foreach ($otherTrending as $index => $trending): 
                    $rank = $index + 2;
                    $rankClass = $rank <= 3 ? 'rank-' . $rank : 'rank-other';
                ?>
                    <a href="<?= url('article.php?slug=' . $trending['slug']) ?>" class="trending-item">
                        <div class="trending-rank <?= $rankClass ?>"><?= str_pad($rank, 2, '0', STR_PAD_LEFT) ?></div>
                        <div class="trending-info">
                            <h4><?= htmlspecialchars($trending['title']) ?></h4>
                            <small>
                                <span><i class="fas fa-user"></i> <?= htmlspecialchars(explode(' ', $trending['author_name'])[0]) ?></span>
                                <span><i class="fas fa-eye"></i> <?= number_format($trending['views']) ?></span>
                            </small>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============================================ -->
<!-- 🏷️ CATEGORIES SHOWCASE -->
<!-- ============================================ -->
<?php if (!empty($categoriesWithCount)): ?>
<section class="categories-section">
    <div class="container">
        <div class="section-title-ultimate">
            <h2>📚 Jelajahi Kategori</h2>
            <p>Temukan artikel berdasarkan bidang keahlian</p>
        </div>
        
        <div class="categories-grid">
            <?php 
            $catIcons = [
                'psikologi' => 'fa-brain',
                'pendidikan' => 'fa-graduation-cap',
                'teknologi' => 'fa-microchip',
                'sains' => 'fa-flask',
                'lingkungan' => 'fa-leaf',
                'kesehatan' => 'fa-heartbeat',
                'sosial-budaya' => 'fa-users',
                'ekonomi' => 'fa-chart-line',
                'hukum' => 'fa-gavel',
                'seni' => 'fa-palette',
            ];
            foreach ($categoriesWithCount as $cat): 
                $icon = isset($catIcons[$cat['slug']]) ? $catIcons[$cat['slug']] : 'fa-tag';
            ?>
                <a href="<?= url('category.php?slug=' . $cat['slug']) ?>" class="category-card">
                    <div class="category-icon">
                        <i class="fas <?= $icon ?>"></i>
                    </div>
                    <h3><?= htmlspecialchars($cat['name']) ?></h3>
                    <p><?= htmlspecialchars(excerpt($cat['description'] ?? 'Kumpulan artikel menarik', 60)) ?></p>
                    <span class="category-count"><?= $cat['article_count'] ?> Artikel</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============================================ -->
<!-- 🏆 TOP AUTHORS -->
<!-- ============================================ -->
<?php if (!empty($topAuthors)): ?>
<section class="authors-section">
    <div class="container">
        <div class="section-title-ultimate">
            <h2>🏆 Dosen Teraktif</h2>
            <p>Penulis yang paling rajin berbagi ilmu melalui blog</p>
        </div>
        
        <div class="authors-grid">
            <?php foreach ($topAuthors as $index => $author): 
                $rank = $index + 1;
                $rankClass = $rank <= 3 ? 'rank-' . $rank : 'rank-other';
                $avatarUrl = url(ltrim(!empty($author['foto']) ? $author['foto'] : 'assets/uploads/default.png', '/'));
            ?>
                <div class="author-card">
                    <div class="author-rank <?= $rankClass ?>">#<?= $rank ?></div>
                    <img src="<?= $avatarUrl ?>" 
                         onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($author['nama']) ?>&background=1e3a5f&color=fff&size=200'"
                         alt="<?= htmlspecialchars($author['nama']) ?>" 
                         class="author-avatar">
                    <h4 class="author-name"><?= htmlspecialchars($author['nama']) ?></h4>
                    <p class="author-role"><?= htmlspecialchars($author['jabatan'] ?? 'Dosen') ?></p>
                    <?php if (!empty($author['prodi'])): ?>
                        <small style="color:#888;display:block;margin-bottom:0.8rem;"><?= htmlspecialchars($author['prodi']) ?></small>
                    <?php endif; ?>
                    <div class="author-stats">
                        <div class="author-stat">
                            <strong><?= $author['article_count'] ?></strong>
                            <small>Artikel</small>
                        </div>
                        <div class="author-stat">
                            <strong><?= number_format($author['total_views']) ?></strong>
                            <small>Views</small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============================================ -->
<!-- 📊 STATS COUNTER SECTION -->
<!-- ============================================ -->
<section class="stats-counter-section">
    <div class="container">
        <div class="stats-counter-grid">
            <div class="counter-item">
                <div class="counter-icon"><i class="fas fa-newspaper"></i></div>
                <div class="counter-value">
                    <span class="counter" data-target="<?= $globalStats['total_articles'] ?>">0</span>
                    <span class="counter-suffix">+</span>
                </div>
                <div class="counter-label">Artikel Terpublikasi</div>
            </div>
            <div class="counter-item">
                <div class="counter-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="counter-value">
                    <span class="counter" data-target="<?= $globalStats['total_authors'] ?>">0</span>
                    <span class="counter-suffix">+</span>
                </div>
                <div class="counter-label">Dosen Aktif</div>
            </div>
            <div class="counter-item">
                <div class="counter-icon"><i class="fas fa-eye"></i></div>
                <div class="counter-value">
                    <span class="counter" data-target="<?= $globalStats['total_views'] ?>">0</span>
                    <span class="counter-suffix">+</span>
                </div>
                <div class="counter-label">Total Pembaca</div>
            </div>
            <div class="counter-item">
                <div class="counter-icon"><i class="fas fa-tags"></i></div>
                <div class="counter-value">
                    <span class="counter" data-target="<?= $globalStats['total_categories'] ?>">0</span>
                </div>
                <div class="counter-label">Kategori</div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================ -->
<!-- 📰 ARTIKEL TERBARU (Utama dengan Filter) -->
<!-- ============================================ -->
<section class="blog-section-ultimate" id="blog">
    <div class="container">
        <div class="section-title-ultimate">
            <h2>📰 Artikel Terbaru</h2>
            <p>Baca tulisan terbaru dari para dosen kami</p>
        </div>
        
        <!-- Filter Bar -->
        <div style="background:#f8f9fa;padding:1.5rem;border-radius:15px;margin-bottom:2.5rem;box-shadow:0 2px 10px rgba(0,0,0,0.04);">
            <form action="<?= url() ?>" method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;">
                <input type="text" 
                       name="q" 
                       placeholder="🔍 Cari artikel, topik, atau kata kunci..." 
                       value="<?= htmlspecialchars($search) ?>" 
                       style="flex:1;min-width:250px;padding:0.8rem 1.2rem;border:2px solid #e0e0e0;border-radius:10px;font-size:0.95rem;outline:none;transition:border 0.3s ease;"
                       onfocus="this.style.borderColor='#f39c12'"
                       onblur="this.style.borderColor='#e0e0e0'">
                <select name="category" 
                        onchange="this.form.submit()" 
                        style="padding:0.8rem 1.2rem;border:2px solid #e0e0e0;border-radius:10px;font-size:0.95rem;outline:none;background:white;cursor:pointer;min-width:180px;">
                    <option value="">📁 Semua Kategori</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['slug'] ?>" <?= $categorySlug === $cat['slug'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" style="padding:0.8rem 1.8rem;background:linear-gradient(135deg,#1e3a5f,#2c5f8d);color:white;border:none;border-radius:10px;font-weight:600;cursor:pointer;transition:all 0.3s ease;">
                    <i class="fas fa-search"></i> Cari
                </button>
            </form>
            <?php if ($search || $categorySlug): ?>
                <div style="margin-top:1rem;font-size:0.9rem;color:#666;">
                    <?php if ($search): ?>
                        🔎 Mencari: <strong><?= htmlspecialchars($search) ?></strong>
                    <?php endif; ?>
                    <?php if ($categorySlug): 
                        $catName = '';
                        foreach ($categories as $c) {
                            if ($c['slug'] === $categorySlug) { $catName = $c['name']; break; }
                        }
                    ?>
                        | Kategori: <strong><?= htmlspecialchars($catName) ?></strong>
                    <?php endif; ?>
                    <a href="<?= url() ?>" style="margin-left:1rem;color:#e74c3c;text-decoration:none;">
                        <i class="fas fa-times-circle"></i> Reset Filter
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (empty($articles)): ?>
            <div class="empty-state-ultimate">
                <i class="fas fa-newspaper"></i>
                <h3>Belum Ada Artikel</h3>
                <p>Jadilah yang pertama menulis artikel di platform ini!</p>
                <?php if (isLoggedIn()): ?>
                    <a href="<?= url('admin/article-edit.php') ?>" class="btn-primary" style="background:linear-gradient(135deg,#f39c12,#e67e22);padding:1rem 2rem;border-radius:50px;text-decoration:none;color:white;font-weight:700;display:inline-block;">
                        <i class="fas fa-pen"></i> Tulis Artikel Pertama
                    </a>
                <?php else: ?>
                    <a href="<?= url('admin/login.php') ?>" class="btn-primary" style="background:linear-gradient(135deg,#1e3a5f,#2c5f8d);padding:1rem 2rem;border-radius:50px;text-decoration:none;color:white;font-weight:700;display:inline-block;">
                        <i class="fas fa-sign-in-alt"></i> Login untuk Menulis
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="margin-bottom:1rem;color:#666;font-size:0.9rem;">
                Menampilkan <strong><?= count($articles) ?></strong> dari <strong><?= $total ?></strong> artikel
            </div>
            
            <div class="blog-grid-ultimate">
                <?php foreach ($articles as $article): 
                    $avatarUrl = url(ltrim(!empty($article['author_foto']) ? $article['author_foto'] : 'assets/uploads/default.png', '/'));
                ?>
                    <article class="blog-card-ultimate">
                        <div class="blog-image-ultimate" style="background-image: url('<?= htmlspecialchars($article['featured_image'] ?: 'https://images.unsplash.com/photo-1499209974431-9dddcece7f88?w=400') ?>');"></div>
                        <div class="blog-content-ultimate">
                            <span class="category-ultimate"><?= htmlspecialchars($article['category_name'] ?? 'Umum') ?></span>
                            <h3 class="blog-title-ultimate">
                                <a href="<?= url('article.php?slug=' . $article['slug']) ?>">
                                    <?= htmlspecialchars($article['title']) ?>
                                </a>
                            </h3>
                            <div class="blog-meta-ultimate">
                                <span>
                                    <img src="<?= $avatarUrl ?>" 
                                         onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($article['author_name']) ?>&background=1e3a5f&color=fff&size=56'"
                                         class="blog-author-avatar" 
                                         alt="<?= htmlspecialchars($article['author_name']) ?>">
                                    <?= htmlspecialchars($article['author_name']) ?>
                                </span>
                                <span><i class="fas fa-calendar"></i> <?= formatDate($article['created_at']) ?></span>
                                <span><i class="fas fa-eye"></i> <?= number_format($article['views']) ?></span>
                            </div>
                            <p class="excerpt-ultimate">
                                <?= htmlspecialchars($article['excerpt'] ?: excerpt($article['content'], 150)) ?>
                            </p>
                            <a href="<?= url('article.php?slug=' . $article['slug']) ?>" class="read-more-ultimate">
                                Baca Selengkapnya <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- Enhanced Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination-ultimate">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&category=<?= urlencode($categorySlug) ?>" class="page-btn">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-btn disabled"><i class="fas fa-chevron-left"></i></span>
                    <?php endif; ?>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    if ($start > 1): ?>
                        <a href="?page=1&q=<?= urlencode($search) ?>&category=<?= urlencode($categorySlug) ?>" class="page-btn">1</a>
                        <?php if ($start > 2): ?><span style="padding:0 0.5rem;color:#999;">...</span><?php endif; ?>
                    <?php endif;
                    
                    for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="page-btn active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&category=<?= urlencode($categorySlug) ?>" class="page-btn"><?= $i ?></a>
                        <?php endif;
                    endfor;
                    
                    if ($end < $totalPages): 
                        if ($end < $totalPages - 1): ?><span style="padding:0 0.5rem;color:#999;">...</span><?php endif; ?>
                        <a href="?page=<?= $totalPages ?>&q=<?= urlencode($search) ?>&category=<?= urlencode($categorySlug) ?>" class="page-btn"><?= $totalPages ?></a>
                    <?php endif; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&category=<?= urlencode($categorySlug) ?>" class="page-btn">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-btn disabled"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                    
                    <span class="page-info-ultimate">Halaman <?= $page ?> / <?= $totalPages ?></span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- ============================================ -->
<!-- 💬 TESTIMONIALS -->
<!-- ============================================ -->
<section class="testimonial-section">
    <div class="container">
        <div class="section-title-ultimate">
            <h2>💬 Kata Mereka</h2>
            <p>Pengalaman para dosen dalam berbagi ilmu melalui blog</p>
        </div>
        
        <div class="testimonial-grid">
            <?php foreach ($testimonials as $t): ?>
                <div class="testimonial-card">
                    <p class="testimonial-quote">"<?= htmlspecialchars($t['quote']) ?>"</p>
                    <div class="testimonial-author">
                        <img src="<?= $t['foto'] ?>" alt="<?= htmlspecialchars($t['nama']) ?>">
                        <div class="testimonial-author-info">
                            <strong><?= htmlspecialchars($t['nama']) ?></strong>
                            <small><?= htmlspecialchars($t['jabatan']) ?></small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================ -->
<!-- 📧 NEWSLETTER -->
<!-- ============================================ -->
<section class="newsletter-section">
    <div class="container">
        <div class="newsletter-content">
            <h2>📬 Tetap Terupdate</h2>
            <p>Dapatkan artikel terbaru langsung di inbox Anda. Gratis, tanpa spam!</p>
            <form class="newsletter-form" onsubmit="event.preventDefault(); alert('✅ Terima kasih! Anda akan menerima artikel terbaru.');">
                <input type="email" placeholder="email@anda.com" required>
                <button type="submit">
                    <i class="fas fa-paper-plane"></i> Subscribe
                </button>
            </form>
        </div>
    </div>
</section>

<!-- ============================================ -->
<!-- 🎯 CTA BANNER -->
<!-- ============================================ -->
<section class="cta-banner-section">
    <div class="container">
        <div class="cta-banner">
            <div class="cta-banner-content">
                <h2>✨ Jadilah Penulis</h2>
                <p>Bagikan ilmu dan pengalaman Anda melalui blog. Ribuan pembaca sudah menunggu karya terbaik Anda.</p>
            </div>
            <?php if (isLoggedIn()): ?>
                <a href="<?= url('admin/article-edit.php') ?>" class="cta-banner-btn">
                    <i class="fas fa-rocket"></i> Mulai Menulis
                </a>
            <?php else: ?>
                <a href="<?= url('admin/login.php') ?>" class="cta-banner-btn">
                    <i class="fas fa-sign-in-alt"></i> Daftar Sekarang
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ============================================ -->
<!-- 📜 ORIGINAL SECTIONS (dipertahankan) -->
<!-- ============================================ -->
<section class="services-section" id="layanan">
    <div class="container">
        <h2 class="section-title">LAYANAN</h2>
        <div class="services-grid">
            <a href="#" class="service-card">
                <div class="service-icon"><i class="fas fa-university"></i></div>
                <h3>Sistem Informasi Akademik</h3>
                <p>Kelola data akademik mahasiswa, jadwal kuliah, dan nilai</p>
            </a>
            <a href="#" class="service-card">
                <div class="service-icon"><i class="fas fa-flask"></i></div>
                <h3>Sistem Informasi Penelitian</h3>
                <p>Platform pengelolaan dan publikasi hasil penelitian dosen</p>
            </a>
            <a href="#" class="service-card">
                <div class="service-icon"><i class="fas fa-hands-helping"></i></div>
                <h3>Sistem Informasi Pengabdian</h3>
                <p>Dokumentasi kegiatan pengabdian kepada masyarakat</p>
            </a>
        </div>
    </div>
</section>

<section class="about-section">
    <div class="container">
        <div class="about-content">
            <h2>DOSEN</h2>
            <p>Ilmu pengetahuan yang didapat digunakan untuk mengabdi kepada masyarakat di berbagai bidang kehidupan. Sedangkan untuk melakukan itu semua maka seorang dosen harus punya kemampuan menulis yang baik.</p>
        </div>
    </div>
</section>

<!-- ============================================ -->
<!-- ⚡ JAVASCRIPT ULTIMATE -->
<!-- ============================================ -->
<script>
// ===== TYPING ANIMATION =====
(function () {
    const texts = [
        'Berbagi Ilmu untuk Negeri 🇮🇩',
        'Menulis adalah Bekerja untuk Keabadian ✨',
        'Tridharma Perguruan Tinggi 🎓',
        'Penelitian • Pengabdian • Pengajaran 📚',
        'Jembatan Akademisi dan Masyarakat 🌉'
    ];
    const typingEl = document.getElementById('heroTyping');
    if (!typingEl) return;
    
    let textIndex = 0, charIndex = 0, isDeleting = false;
    
    function type() {
        const current = texts[textIndex];
        
        if (isDeleting) {
            typingEl.textContent = current.substring(0, charIndex - 1);
            charIndex--;
        } else {
            typingEl.textContent = current.substring(0, charIndex + 1);
            charIndex++;
        }
        
        let speed = isDeleting ? 30 : 60;
        
        if (!isDeleting && charIndex === current.length) {
            speed = 2000;
            isDeleting = true;
        } else if (isDeleting && charIndex === 0) {
            isDeleting = false;
            textIndex = (textIndex + 1) % texts.length;
            speed = 500;
        }
        
        setTimeout(type, speed);
    }
    type();
})();

// ===== COUNTER ANIMATION (dengan Intersection Observer) =====
(function () {
    const counters = document.querySelectorAll('.counter');
    const observerOptions = { threshold: 0.3 };
    
    const animateCounter = (el) => {
        const target = parseInt(el.getAttribute('data-target'));
        if (isNaN(target)) return;
        
        const duration = 2000;
        const steps = 60;
        const increment = target / steps;
        let current = 0;
        let step = 0;
        
        const timer = setInterval(() => {
            step++;
            current += increment;
            if (step >= steps) {
                el.textContent = target.toLocaleString('id-ID');
                clearInterval(timer);
            } else {
                el.textContent = Math.floor(current).toLocaleString('id-ID');
            }
        }, duration / steps);
    };
    
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !entry.target.dataset.animated) {
                    entry.target.dataset.animated = '1';
                    animateCounter(entry.target);
                }
            });
        }, observerOptions);
        
        counters.forEach(c => observer.observe(c));
    } else {
        counters.forEach(animateCounter);
    }
})();

// ===== SMOOTH SCROLL =====
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (href === '#' || href.length < 2) return;
        const target = document.querySelector(href);
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

// ===== CARD HOVER EFFECTS =====
document.querySelectorAll('.blog-card-ultimate, .featured-card, .category-card, .author-card').forEach(card => {
    card.addEventListener('mousemove', function (e) {
        const rect = this.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        const centerX = rect.width / 2;
        const centerY = rect.height / 2;
        const rotateX = (y - centerY) / 30;
        const rotateY = (centerX - x) / 30;
        this.style.transform = `translateY(-8px) perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
    });
    card.addEventListener('mouseleave', function () {
        this.style.transform = '';
    });
});

console.log('%c🎓 Homepage Ultimate Loaded!', 'font-size:16px;color:#1e3a5f;font-weight:bold;');
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>