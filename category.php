<?php
require_once __DIR__ . '/config/functions.php';

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 9;
$offset = ($page - 1) * $limit;
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'grid';
$searchInCat = isset($_GET['q']) ? trim($_GET['q']) : '';

// Validasi sort
$allowedSort = ['newest', 'popular', 'oldest', 'az'];
if (!in_array($sortBy, $allowedSort)) $sortBy = 'newest';

// ============================================
// 📊 SEMUA KATEGORI DENGAN COUNT (1 query saja!)
// ============================================
$categories = [];
try {
    $categories = db()->query("
        SELECT c.*, 
               COUNT(a.id) as article_count,
               IFNULL(SUM(a.views), 0) as total_views
        FROM categories c
        LEFT JOIN articles a ON a.category_id = c.id AND a.status = 'published'
        GROUP BY c.id
        ORDER BY article_count DESC, c.name ASC
    ")->fetchAll();
} catch (Exception $e) {}

// ============================================
// 🎯 KATEGORI AKTIF (jika ada slug)
// ============================================
$currentCat = null;
$articles = [];
$totalArticles = 0;
$totalPages = 0;
$featuredArticle = null;
$topAuthors = [];
$relatedCategories = [];

if ($slug) {
    // Ambil data kategori
    try {
        $stmt = db()->prepare("SELECT * FROM categories WHERE slug = ?");
        $stmt->execute([$slug]);
        $currentCat = $stmt->fetch();
    } catch (Exception $e) {}
    
    if (!$currentCat) {
        http_response_code(404);
        include __DIR__ . '/404.php';
        exit;
    }
    
    // ===== Count total artikel (dengan filter search) =====
    try {
        $countQuery = "SELECT COUNT(*) FROM articles WHERE category_id = ? AND status = 'published'";
        $countParams = [$currentCat['id']];
        
        if ($searchInCat) {
            $countQuery .= " AND (title LIKE ? OR content LIKE ?)";
            $countParams[] = "%$searchInCat%";
            $countParams[] = "%$searchInCat%";
        }
        
        $stmt = db()->prepare($countQuery);
        $stmt->execute($countParams);
        $totalArticles = (int)$stmt->fetchColumn();
        $totalPages = ceil($totalArticles / $limit);
    } catch (Exception $e) {}
    
    // ===== ORDER BY berdasarkan sort =====
    $orderBy = "ORDER BY ";
    $queryParams = [$currentCat['id']];
    
    if ($searchInCat) {
        $queryParams[] = "%$searchInCat%";
        $queryParams[] = "%$searchInCat%";
    }
    
    switch ($sortBy) {
        case 'popular':
            $orderBy .= "a.views DESC, a.created_at DESC";
            break;
        case 'oldest':
            $orderBy .= "a.created_at ASC";
            break;
        case 'az':
            $orderBy .= "a.title ASC";
            break;
        case 'newest':
        default:
            $orderBy .= "a.created_at DESC";
            break;
    }
    
    // ===== Ambil artikel dengan pagination =====
    try {
        $searchClause = $searchInCat ? " AND (a.title LIKE ? OR a.content LIKE ?)" : '';
        $query = "
            SELECT a.*, u.nama as author_name, u.foto as author_foto, u.jabatan,
                   (SELECT COUNT(*) FROM comments WHERE article_id = a.id AND status = 'approved') as comment_count
            FROM articles a 
            JOIN users u ON a.author_id = u.id 
            WHERE a.category_id = ? AND a.status = 'published'$searchClause
            $orderBy
            LIMIT $limit OFFSET $offset
        ";
        $stmt = db()->prepare($query);
        $stmt->execute($queryParams);
        $articles = $stmt->fetchAll();
    } catch (Exception $e) {}
    
    // ===== Featured Article (artikel terpopuler di kategori ini) =====
    if ($page === 1 && empty($searchInCat)) {
        try {
            $stmt = db()->prepare("
                SELECT a.*, u.nama as author_name, u.foto as author_foto
                FROM articles a 
                JOIN users u ON a.author_id = u.id 
                WHERE a.category_id = ? AND a.status = 'published' AND a.featured_image IS NOT NULL AND a.featured_image != ''
                ORDER BY a.views DESC
                LIMIT 1
            ");
            $stmt->execute([$currentCat['id']]);
            $featuredArticle = $stmt->fetch();
        } catch (Exception $e) {}
    }
    
    // ===== Top Authors di kategori ini =====
    try {
        $topAuthors = db()->prepare("
            SELECT u.id, u.nama, u.foto, u.jabatan,
                   COUNT(a.id) as article_count,
                   IFNULL(SUM(a.views), 0) as total_views
            FROM users u
            JOIN articles a ON a.author_id = u.id
            WHERE a.category_id = ? AND a.status = 'published'
            GROUP BY u.id
            ORDER BY article_count DESC
            LIMIT 5
        ");
        $topAuthors->execute([$currentCat['id']]);
        $topAuthors = $topAuthors->fetchAll();
    } catch (Exception $e) { $topAuthors = []; }
    
    // ===== Related Categories (kategori lain) =====
    try {
        $stmt = db()->prepare("
            SELECT c.*, 
                   COUNT(a.id) as article_count
            FROM categories c
            LEFT JOIN articles a ON a.category_id = c.id AND a.status = 'published'
            WHERE c.id != ?
            GROUP BY c.id
            HAVING article_count > 0
            ORDER BY article_count DESC
            LIMIT 5
        ");
        $stmt->execute([$currentCat['id']]);
        $relatedCategories = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// ============================================
// 🎯 PAGE TITLE & META
// ============================================
if ($currentCat) {
    $pageTitle = $currentCat['name'] . ' - Kategori';
    $pageDescription = $currentCat['description'] ?? 'Artikel kategori ' . $currentCat['name'];
} else {
    $pageTitle = 'Semua Kategori';
    $pageDescription = 'Jelajahi semua kategori artikel di Blog Dosen';
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ===== CATEGORY ULTIMATE STYLES ===== */

/* Breadcrumb */
.category-breadcrumb {
    padding: 1.5rem 0;
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
}
.breadcrumb-list {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    list-style: none;
    padding: 0;
    margin: 0;
    font-size: 0.88rem;
    flex-wrap: wrap;
}
.breadcrumb-list li {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #666;
}
.breadcrumb-list a {
    color: #1e3a5f;
    text-decoration: none;
    transition: color 0.2s ease;
}
.breadcrumb-list a:hover {
    color: #f39c12;
}
.breadcrumb-list .separator {
    color: #ccc;
}
.breadcrumb-list .current {
    color: #f39c12;
    font-weight: 600;
}

/* Category Hero (when viewing specific category) */
.category-hero {
    background: linear-gradient(135deg, #1e3a5f 0%, #2c5f8d 100%);
    color: white;
    padding: 3.5rem 0;
    position: relative;
    overflow: hidden;
}
.category-hero::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(243, 156, 18, 0.15), transparent 70%);
    border-radius: 50%;
}
.category-hero::after {
    content: '';
    position: absolute;
    bottom: -60%; left: -5%;
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(52, 152, 219, 0.15), transparent 70%);
    border-radius: 50%;
}
.category-hero-content {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 2rem;
    align-items: center;
}
.category-hero-text h1 {
    font-size: 2.5rem;
    margin-bottom: 0.8rem;
    display: flex;
    align-items: center;
    gap: 0.8rem;
    font-weight: 800;
}
.category-hero-text h1 .cat-icon {
    width: 60px; height: 60px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    border-radius: 15px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    box-shadow: 0 5px 15px rgba(243, 156, 18, 0.4);
}
.category-hero-text p {
    opacity: 0.9;
    font-size: 1.1rem;
    max-width: 600px;
    line-height: 1.6;
    margin-bottom: 1.5rem;
}
.category-hero-stats {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
}
.cat-stat {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: rgba(255,255,255,0.1);
    padding: 0.5rem 1rem;
    border-radius: 25px;
    font-size: 0.88rem;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.15);
}
.cat-stat i { color: #f39c12; }
.cat-stat strong { font-weight: 700; }

/* Categories Grid (when no slug - showing all) */
.categories-showcase {
    padding: 3rem 0;
}
.categories-showcase-header {
    text-align: center;
    margin-bottom: 3rem;
}
.categories-showcase-header h2 {
    font-size: 2.2rem;
    color: #1e3a5f;
    margin-bottom: 0.5rem;
}
.categories-showcase-header p {
    color: #666;
    font-size: 1.05rem;
}
.categories-grid-ultimate {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
}
.category-card-ultimate {
    background: white;
    border-radius: 18px;
    padding: 2rem;
    text-decoration: none;
    color: #333;
    transition: all 0.4s ease;
    position: relative;
    overflow: hidden;
    border: 2px solid transparent;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    display: flex;
    flex-direction: column;
}
.category-card-ultimate::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 4px;
    background: linear-gradient(90deg, #f39c12, #e67e22);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.4s ease;
}
.category-card-ultimate:hover {
    border-color: #f39c12;
    transform: translateY(-8px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.12);
}
.category-card-ultimate:hover::before {
    transform: scaleX(1);
}
.category-card-icon {
    width: 65px; height: 65px;
    background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
    color: white;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    margin-bottom: 1.2rem;
    transition: all 0.4s ease;
    box-shadow: 0 5px 15px rgba(30, 58, 95, 0.2);
}
.category-card-ultimate:hover .category-card-icon {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    transform: rotate(-8deg) scale(1.05);
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.3);
}
.category-card-title {
    font-size: 1.3rem;
    color: #1e3a5f;
    margin-bottom: 0.5rem;
    font-weight: 700;
}
.category-card-desc {
    color: #666;
    font-size: 0.9rem;
    line-height: 1.6;
    margin-bottom: 1.2rem;
    flex: 1;
    min-height: 3.2rem;
}
.category-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 1rem;
    border-top: 1px solid #f0f0f0;
}
.category-card-count {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: #f4f6f9;
    color: #1e3a5f;
    padding: 0.35rem 0.9rem;
    border-radius: 20px;
    font-size: 0.82rem;
    font-weight: 600;
    transition: all 0.3s ease;
}
.category-card-ultimate:hover .category-card-count {
    background: #f39c12;
    color: white;
}
.category-card-arrow {
    width: 32px; height: 32px;
    background: #f4f6f9;
    color: #1e3a5f;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}
.category-card-ultimate:hover .category-card-arrow {
    background: #1e3a5f;
    color: white;
    transform: translateX(3px);
}

/* Featured Article (single category view) */
.featured-category-article {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    margin-bottom: 2.5rem;
    box-shadow: 0 10px 40px rgba(0,0,0,0.08);
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    min-height: 320px;
    transition: all 0.3s ease;
}
.featured-category-article:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 50px rgba(0,0,0,0.12);
}
.featured-category-image {
    background-size: cover;
    background-position: center;
    position: relative;
    min-height: 320px;
}
.featured-category-image::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(30, 58, 95, 0.3), transparent);
}
.featured-category-badge {
    position: absolute;
    top: 20px; left: 20px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    padding: 0.4rem 1rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 1px;
    z-index: 2;
    box-shadow: 0 4px 10px rgba(243, 156, 18, 0.4);
}
.featured-category-content {
    padding: 2.5rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.featured-category-content .label {
    color: #f39c12;
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 0.8rem;
}
.featured-category-content h2 {
    font-size: 1.8rem;
    color: #1e3a5f;
    line-height: 1.3;
    margin-bottom: 1rem;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.featured-category-content h2 a {
    color: inherit;
    text-decoration: none;
    transition: color 0.2s ease;
}
.featured-category-content h2 a:hover {
    color: #f39c12;
}
.featured-category-excerpt {
    color: #666;
    line-height: 1.6;
    margin-bottom: 1.5rem;
    font-size: 0.95rem;
}
.featured-category-meta {
    display: flex;
    gap: 1.2rem;
    font-size: 0.85rem;
    color: #888;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.featured-category-meta span {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

/* Category Layout (Main + Sidebar) */
.category-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 2.5rem;
    padding: 3rem 0;
}

/* Control Bar */
.category-controls {
    background: white;
    padding: 1.2rem 1.5rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}
.category-controls-left {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex: 1;
    min-width: 250px;
}
.search-in-category {
    flex: 1;
    display: flex;
    background: #f4f6f9;
    border-radius: 25px;
    padding: 0.3rem;
    border: 2px solid transparent;
    transition: all 0.3s ease;
}
.search-in-category:focus-within {
    border-color: #f39c12;
    background: white;
}
.search-in-category input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 0.5rem 1rem;
    outline: none;
    font-size: 0.9rem;
    color: #333;
}
.search-in-category button {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border: none;
    width: 38px; height: 38px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}
.search-in-category button:hover {
    transform: scale(1.05);
}
.category-controls-right {
    display: flex;
    align-items: center;
    gap: 0.8rem;
}
.sort-select {
    padding: 0.6rem 1rem;
    border: 2px solid #e0e0e0;
    border-radius: 25px;
    background: white;
    color: #333;
    font-size: 0.88rem;
    cursor: pointer;
    outline: none;
    transition: border 0.3s ease;
    font-weight: 500;
}
.sort-select:focus { border-color: #f39c12; }
.view-toggle-btn {
    width: 40px; height: 40px;
    background: #f4f6f9;
    border: none;
    border-radius: 10px;
    color: #888;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}
.view-toggle-btn.active {
    background: #1e3a5f;
    color: white;
}
.view-toggle-btn:hover:not(.active) {
    background: #e9ecef;
    color: #1e3a5f;
}

/* Article List in Category */
.category-articles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
}
.category-article-card {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.06);
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    border: 1px solid #f0f0f0;
}
.category-article-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
    border-color: #f39c12;
}
.category-article-image {
    height: 200px;
    background-size: cover;
    background-position: center;
    position: relative;
}
.category-article-image::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, transparent 60%, rgba(0,0,0,0.3));
}
.category-article-content {
    padding: 1.5rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.category-article-title {
    font-size: 1.15rem;
    color: #1e3a5f;
    margin-bottom: 0.8rem;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.category-article-title a {
    color: inherit;
    text-decoration: none;
    transition: color 0.2s ease;
}
.category-article-title a:hover {
    color: #f39c12;
}
.category-article-excerpt {
    color: #666;
    font-size: 0.88rem;
    line-height: 1.6;
    margin-bottom: 1rem;
    flex: 1;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.category-article-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 1rem;
    border-top: 1px solid #f0f0f0;
    font-size: 0.82rem;
    color: #888;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.category-article-author {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: #1e3a5f;
}
.category-article-author img {
    width: 28px; height: 28px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #f39c12;
}
.category-article-stats {
    display: flex;
    gap: 0.8rem;
}
.category-article-stats span {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

/* List View */
body.category-view-list .category-articles-grid {
    grid-template-columns: 1fr;
}
body.category-view-list .category-article-card {
    flex-direction: row;
}
body.category-view-list .category-article-image {
    width: 280px;
    height: auto;
    min-height: 200px;
    flex-shrink: 0;
}
body.category-view-list .category-article-content {
    padding: 1.8rem;
}
body.category-view-list .category-article-title {
    font-size: 1.3rem;
}
body.category-view-list .category-article-excerpt {
    -webkit-line-clamp: 2;
}

/* Category Sidebar */
.category-sidebar {
    position: sticky;
    top: 90px;
    align-self: start;
    max-height: calc(100vh - 110px);
    overflow-y: auto;
}
.sidebar-widget-cat {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}
.sidebar-widget-cat h4 {
    color: #1e3a5f;
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.8rem;
    border-bottom: 2px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.sidebar-widget-cat h4 i {
    color: #f39c12;
}

/* Top Authors Widget */
.top-authors-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.top-author-item {
    display: flex;
    gap: 0.8rem;
    padding: 0.8rem;
    border-radius: 10px;
    margin-bottom: 0.5rem;
    transition: background 0.2s ease;
    align-items: center;
}
.top-author-item:hover {
    background: #f8f9fa;
}
.top-author-item img {
    width: 40px; height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #f39c12;
    flex-shrink: 0;
}
.top-author-info {
    flex: 1;
    min-width: 0;
}
.top-author-info strong {
    display: block;
    color: #1e3a5f;
    font-size: 0.9rem;
    margin-bottom: 0.2rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.top-author-info small {
    color: #888;
    font-size: 0.78rem;
    display: flex;
    gap: 0.8rem;
}
.top-author-rank {
    width: 26px; height: 26px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.8rem;
    color: white;
    flex-shrink: 0;
}
.top-author-rank.rank-1 { background: linear-gradient(135deg, #ffd700, #ffed4e); color: #333; }
.top-author-rank.rank-2 { background: linear-gradient(135deg, #c0c0c0, #e5e5e5); color: #333; }
.top-author-rank.rank-3 { background: linear-gradient(135deg, #cd7f32, #b87333); }
.top-author-rank.rank-other { background: #95a5a6; }

/* Related Categories Widget */
.related-cat-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.related-cat-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.7rem 0;
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.2s ease;
}
.related-cat-item:last-child { border-bottom: none; }
.related-cat-item:hover { padding-left: 5px; }
.related-cat-item a {
    color: #1e3a5f;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: color 0.2s ease;
    flex: 1;
    min-width: 0;
}
.related-cat-item a:hover { color: #f39c12; }
.related-cat-item a i { color: #f39c12; font-size: 0.8rem; }
.related-cat-count {
    background: #f4f6f9;
    color: #1e3a5f;
    padding: 0.2rem 0.6rem;
    border-radius: 10px;
    font-size: 0.75rem;
    font-weight: 700;
    flex-shrink: 0;
}
.related-cat-item:hover .related-cat-count {
    background: #f39c12;
    color: white;
}

/* Back button */
.back-to-categories {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: white;
    color: #1e3a5f;
    padding: 0.7rem 1.5rem;
    border-radius: 25px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    margin-top: 1.5rem;
}
.back-to-categories:hover {
    background: #1e3a5f;
    color: white;
    transform: translateX(-3px);
}

/* Empty State Ultimate */
.empty-category-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}
.empty-category-state .empty-icon {
    width: 120px; height: 120px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3.5rem;
    margin: 0 auto 1.5rem;
    box-shadow: 0 10px 30px rgba(243, 156, 18, 0.3);
}
.empty-category-state h3 {
    color: #1e3a5f;
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}
.empty-category-state p {
    color: #666;
    margin-bottom: 2rem;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}

/* Category Stats Bar (all categories view) */
.categories-stats-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 3rem;
}
.cat-stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 15px;
    text-align: center;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    border-left: 4px solid;
}
.cat-stat-card:nth-child(1) { border-color: #3498db; }
.cat-stat-card:nth-child(2) { border-color: #2ecc71; }
.cat-stat-card:nth-child(3) { border-color: #f39c12; }
.cat-stat-card:nth-child(4) { border-color: #9b59b6; }
.cat-stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}
.cat-stat-card .icon {
    width: 50px; height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    color: white;
    margin: 0 auto 0.8rem;
}
.cat-stat-card:nth-child(1) .icon { background: linear-gradient(135deg, #3498db, #2980b9); }
.cat-stat-card:nth-child(2) .icon { background: linear-gradient(135deg, #2ecc71, #27ae60); }
.cat-stat-card:nth-child(3) .icon { background: linear-gradient(135deg, #f39c12, #e67e22); }
.cat-stat-card:nth-child(4) .icon { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
.cat-stat-card .value {
    font-size: 2rem;
    font-weight: 800;
    color: #1e3a5f;
    line-height: 1;
    margin-bottom: 0.3rem;
}
.cat-stat-card .label {
    color: #888;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Pagination Ultimate */
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
    background: white;
    font-weight: 600;
    transition: all 0.3s ease;
    padding: 0 1rem;
    border: 1px solid #e0e0e0;
}
.page-btn:hover:not(.disabled):not(.active) {
    background: #1e3a5f;
    color: white;
    border-color: #1e3a5f;
    transform: translateY(-2px);
}
.page-btn.active {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border-color: transparent;
    box-shadow: 0 5px 15px rgba(243, 156, 18, 0.3);
}
.page-btn.disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.page-info {
    color: #888;
    font-size: 0.88rem;
    margin: 0 1rem;
}

/* Responsive */
@media (max-width: 992px) {
    .category-layout {
        grid-template-columns: 1fr;
    }
    .category-sidebar {
        position: static;
        max-height: none;
    }
    .featured-category-article {
        grid-template-columns: 1fr;
    }
    .featured-category-image {
        min-height: 250px;
    }
    .category-hero-text h1 {
        font-size: 1.8rem;
    }
}
@media (max-width: 768px) {
    .category-hero-content {
        grid-template-columns: 1fr;
    }
    .category-hero {
        padding: 2rem 0;
    }
    .category-hero-text h1 {
        font-size: 1.5rem;
    }
    body.category-view-list .category-article-card {
        flex-direction: column;
    }
    body.category-view-list .category-article-image {
        width: 100%;
        height: 200px;
    }
    .category-controls {
        flex-direction: column;
        align-items: stretch;
    }
    .category-controls-right {
        justify-content: space-between;
    }
}
@media (max-width: 480px) {
    .categories-grid-ultimate {
        grid-template-columns: 1fr;
    }
    .category-articles-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- ============================================ -->
<!-- 🧭 BREADCRUMB -->
<!-- ============================================ -->
<div class="category-breadcrumb">
    <div class="container">
        <ul class="breadcrumb-list">
            <li><a href="<?php echo url(); ?>"><i class="fas fa-home"></i> Beranda</a></li>
            <li class="separator">/</li>
            <?php if ($currentCat): ?>
                <li><a href="<?php echo url('category.php'); ?>">Kategori</a></li>
                <li class="separator">/</li>
                <li class="current"><?php echo htmlspecialchars($currentCat['name']); ?></li>
            <?php else: ?>
                <li class="current">Semua Kategori</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<?php if (!$currentCat): ?>
<!-- ============================================ -->
<!-- 📚 VIEW: ALL CATEGORIES -->
<!-- ============================================ -->
<section class="categories-showcase">
    <div class="container">
        <div class="categories-showcase-header">
            <h2>📚 Jelajahi Semua Kategori</h2>
            <p>Temukan artikel berdasarkan bidang keahlian yang Anda minati</p>
        </div>

        <?php if (!empty($categories)): 
            // Calculate global stats
            $totalCats = count($categories);
            $totalArticles = array_sum(array_column($categories, 'article_count'));
            $totalViews = array_sum(array_column($categories, 'total_views'));
            $activeCats = count(array_filter($categories, function($c) { return $c['article_count'] > 0; }));
        ?>
            <!-- Stats Bar -->
            <div class="categories-stats-bar">
                <div class="cat-stat-card">
                    <div class="icon"><i class="fas fa-tags"></i></div>
                    <div class="value"><?php echo $totalCats; ?></div>
                    <div class="label">Total Kategori</div>
                </div>
                <div class="cat-stat-card">
                    <div class="icon"><i class="fas fa-newspaper"></i></div>
                    <div class="value"><?php echo number_format($totalArticles); ?></div>
                    <div class="label">Total Artikel</div>
                </div>
                <div class="cat-stat-card">
                    <div class="icon"><i class="fas fa-eye"></i></div>
                    <div class="value"><?php echo number_format($totalViews); ?></div>
                    <div class="label">Total Views</div>
                </div>
                <div class="cat-stat-card">
                    <div class="icon"><i class="fas fa-fire"></i></div>
                    <div class="value"><?php echo $activeCats; ?></div>
                    <div class="label">Kategori Aktif</div>
                </div>
            </div>

            <!-- Category Icons Map -->
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
                'politik' => 'fa-landmark',
                'olahraga' => 'fa-running',
            ];
            ?>

            <!-- Categories Grid -->
            <div class="categories-grid-ultimate">
                <?php foreach ($categories as $cat): 
                    $icon = isset($catIcons[$cat['slug']]) ? $catIcons[$cat['slug']] : 'fa-tag';
                ?>
                    <a href="<?php echo url('category.php?slug=' . $cat['slug']); ?>" class="category-card-ultimate">
                        <div class="category-card-icon">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <h3 class="category-card-title"><?php echo htmlspecialchars($cat['name']); ?></h3>
                        <p class="category-card-desc">
                            <?php echo htmlspecialchars($cat['description'] ?? 'Kumpulan artikel menarik seputar ' . $cat['name']); ?>
                        </p>
                        <div class="category-card-footer">
                            <span class="category-card-count">
                                <i class="fas fa-newspaper"></i> 
                                <?php echo $cat['article_count']; ?> Artikel
                            </span>
                            <span class="category-card-arrow">
                                <i class="fas fa-arrow-right"></i>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-category-state">
                <div class="empty-icon"><i class="fas fa-folder-open"></i></div>
                <h3>Belum Ada Kategori</h3>
                <p>Kategori akan muncul setelah admin menambahkannya ke sistem.</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php else: ?>
<!-- ============================================ -->
<!-- 🎯 VIEW: SPECIFIC CATEGORY -->
<!-- ============================================ -->

<!-- Category Hero -->
<section class="category-hero">
    <div class="container">
        <div class="category-hero-content">
            <div class="category-hero-text">
                <h1>
                    <span class="cat-icon"><i class="fas fa-tag"></i></span>
                    <?php echo htmlspecialchars($currentCat['name']); ?>
                </h1>
                <p><?php echo htmlspecialchars($currentCat['description'] ?? 'Kumpulan artikel menarik di kategori ' . $currentCat['name']); ?></p>
                <div class="category-hero-stats">
                    <div class="cat-stat">
                        <i class="fas fa-newspaper"></i>
                        <strong><?php echo $totalArticles; ?></strong> Artikel
                    </div>
                    <div class="cat-stat">
                        <i class="fas fa-users"></i>
                        <strong><?php echo count($topAuthors); ?></strong> Penulis
                    </div>
                    <div class="cat-stat">
                        <i class="fas fa-eye"></i>
                        <strong><?php echo number_format(array_sum(array_column($articles, 'views') ?: [0])); ?></strong> Views Halaman Ini
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Category Layout: Main + Sidebar -->
<section class="category-layout-wrap">
    <div class="container">
        <div class="category-layout">
            
            <!-- ===== MAIN CONTENT ===== -->
            <div class="category-main">
                
                <!-- Featured Article (hanya di halaman 1 tanpa search) -->
                <?php if ($featuredArticle && $page === 1 && empty($searchInCat)): 
                    $fAvatar = url(ltrim(!empty($featuredArticle['author_foto']) ? $featuredArticle['author_foto'] : 'assets/uploads/default.png', '/'));
                ?>
                    <div class="featured-category-article">
                        <div class="featured-category-image" style="background-image: url('<?php echo htmlspecialchars($featuredArticle['featured_image']); ?>');">
                            <span class="featured-category-badge">⭐ UNGGULAN</span>
                        </div>
                        <div class="featured-category-content">
                            <div class="label">ARTIKEL UNGGULAN</div>
                            <h2>
                                <a href="<?php echo url('article.php?slug=' . $featuredArticle['slug']); ?>">
                                    <?php echo htmlspecialchars($featuredArticle['title']); ?>
                                </a>
                            </h2>
                            <p class="featured-category-excerpt">
                                <?php echo htmlspecialchars(excerpt($featuredArticle['content'], 200)); ?>
                            </p>
                            <div class="featured-category-meta">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($featuredArticle['author_name']); ?></span>
                                <span><i class="fas fa-eye"></i> <?php echo number_format($featuredArticle['views']); ?> views</span>
                                <span><i class="fas fa-calendar"></i> <?php echo formatDate($featuredArticle['created_at']); ?></span>
                            </div>
                            <a href="<?php echo url('article.php?slug=' . $featuredArticle['slug']); ?>" 
                               class="btn-primary" 
                               style="display:inline-flex;align-items:center;gap:0.5rem;background:linear-gradient(135deg,#f39c12,#e67e22);color:white;padding:0.8rem 1.8rem;border-radius:25px;text-decoration:none;font-weight:600;">
                                Baca Selengkapnya <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Controls Bar -->
                <div class="category-controls">
                    <div class="category-controls-left">
                        <form action="<?php echo url('category.php'); ?>" method="GET" class="search-in-category" style="flex:1;">
                            <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug); ?>">
                            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
                            <input type="text" 
                                   name="q" 
                                   placeholder="Cari di kategori <?php echo htmlspecialchars($currentCat['name']); ?>..." 
                                   value="<?php echo htmlspecialchars($searchInCat); ?>">
                            <button type="submit"><i class="fas fa-search"></i></button>
                        </form>
                    </div>
                    <div class="category-controls-right">
                        <select class="sort-select" onchange="changeCatSort(this.value)">
                            <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>🆕 Terbaru</option>
                            <option value="popular" <?php echo $sortBy === 'popular' ? 'selected' : ''; ?>>🔥 Terpopuler</option>
                            <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>📅 Terlama</option>
                            <option value="az" <?php echo $sortBy === 'az' ? 'selected' : ''; ?>>🔤 A - Z</option>
                        </select>
                        <button type="button" class="view-toggle-btn <?php echo $viewMode === 'grid' ? 'active' : ''; ?>" 
                                onclick="changeCatView('grid')" title="Tampilan Grid">
                            <i class="fas fa-th-large"></i>
                        </button>
                        <button type="button" class="view-toggle-btn <?php echo $viewMode === 'list' ? 'active' : ''; ?>" 
                                onclick="changeCatView('list')" title="Tampilan List">
                            <i class="fas fa-list"></i>
                        </button>
                    </div>
                </div>

                <!-- Info Search Active -->
                <?php if ($searchInCat): ?>
                    <div style="background:#fff3cd;padding:1rem 1.5rem;border-radius:10px;margin-bottom:1.5rem;border-left:4px solid #ffc107;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.8rem;">
                        <div>
                            <i class="fas fa-search" style="color:#856404;"></i>
                            Hasil pencarian: "<strong><?php echo htmlspecialchars($searchInCat); ?></strong>" 
                            — <?php echo $totalArticles; ?> artikel ditemukan
                        </div>
                        <a href="<?php echo url('category.php?slug=' . $slug . '&sort=' . $sortBy); ?>" 
                           style="color:#856404;text-decoration:none;font-weight:600;font-size:0.88rem;">
                            <i class="fas fa-times-circle"></i> Reset
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Articles -->
                <?php if (empty($articles)): ?>
                    <div class="empty-category-state">
                        <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                        <h3>
                            <?php echo $searchInCat ? 'Tidak Ada Hasil' : 'Belum Ada Artikel'; ?>
                        </h3>
                        <p>
                            <?php if ($searchInCat): ?>
                                Tidak ada artikel yang cocok dengan pencarian "<?php echo htmlspecialchars($searchInCat); ?>" di kategori ini.
                            <?php else: ?>
                                Kategori <?php echo htmlspecialchars($currentCat['name']); ?> belum memiliki artikel. Jadilah yang pertama menulis!
                            <?php endif; ?>
                        </p>
                        <?php if (isLoggedIn() && !$searchInCat): ?>
                            <a href="<?php echo url('admin/article-edit.php'); ?>" 
                               style="display:inline-flex;align-items:center;gap:0.5rem;background:linear-gradient(135deg,#f39c12,#e67e22);color:white;padding:0.9rem 2rem;border-radius:25px;text-decoration:none;font-weight:600;">
                                <i class="fas fa-pen"></i> Tulis Artikel Pertama
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div style="margin-bottom:1rem;color:#666;font-size:0.9rem;">
                        Menampilkan <strong><?php echo count($articles); ?></strong> dari <strong><?php echo $totalArticles; ?></strong> artikel
                    </div>

                    <div class="category-articles-grid">
                        <?php foreach ($articles as $article): 
                            $avatarUrl = url(ltrim(!empty($article['author_foto']) ? $article['author_foto'] : 'assets/uploads/default.png', '/'));
                        ?>
                            <article class="category-article-card">
                                <div class="category-article-image" style="background-image: url('<?php echo htmlspecialchars($article['featured_image'] ?: 'https://images.unsplash.com/photo-1499209974431-9dddcece7f88?w=400'); ?>');"></div>
                                <div class="category-article-content">
                                    <h3 class="category-article-title">
                                        <a href="<?php echo url('article.php?slug=' . $article['slug']); ?>">
                                            <?php echo htmlspecialchars($article['title']); ?>
                                        </a>
                                    </h3>
                                    <p class="category-article-excerpt">
                                        <?php echo htmlspecialchars($article['excerpt'] ?: excerpt($article['content'], 150)); ?>
                                    </p>
                                    <div class="category-article-meta">
                                        <div class="category-article-author">
                                            <img src="<?php echo $avatarUrl; ?>" 
                                                 onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($article['author_name']); ?>&background=1e3a5f&color=fff&size=56'"
                                                 alt="<?php echo htmlspecialchars($article['author_name']); ?>">
                                            <span><?php echo htmlspecialchars(explode(' ', $article['author_name'])[0]); ?></span>
                                        </div>
                                        <div class="category-article-stats">
                                            <span><i class="fas fa-eye"></i> <?php echo number_format($article['views']); ?></span>
                                            <span><i class="fas fa-comments"></i> <?php echo (int)($article['comment_count'] ?? 0); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination-ultimate">
                            <?php 
                            $baseUrl = 'category.php?slug=' . urlencode($slug) 
                                     . '&sort=' . $sortBy 
                                     . '&view=' . $viewMode
                                     . ($searchInCat ? '&q=' . urlencode($searchInCat) : '');
                            
                            if ($page > 1): ?>
                                <a href="<?php echo url($baseUrl . '&page=' . ($page - 1)); ?>" class="page-btn">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-btn disabled"><i class="fas fa-chevron-left"></i></span>
                            <?php endif;
                            
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            
                            if ($start > 1): ?>
                                <a href="<?php echo url($baseUrl . '&page=1'); ?>" class="page-btn">1</a>
                                <?php if ($start > 2): ?>
                                    <span style="padding:0 0.5rem;color:#999;">...</span>
                                <?php endif;
                            endif;
                            
                            for ($i = $start; $i <= $end; $i++): 
                                if ($i === $page): ?>
                                    <span class="page-btn active"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo url($baseUrl . '&page=' . $i); ?>" class="page-btn"><?php echo $i; ?></a>
                                <?php endif;
                            endfor;
                            
                            if ($end < $totalPages): 
                                if ($end < $totalPages - 1): ?>
                                    <span style="padding:0 0.5rem;color:#999;">...</span>
                                <?php endif; ?>
                                <a href="<?php echo url($baseUrl . '&page=' . $totalPages); ?>" class="page-btn"><?php echo $totalPages; ?></a>
                            <?php endif;
                            
                            if ($page < $totalPages): ?>
                                <a href="<?php echo url($baseUrl . '&page=' . ($page + 1)); ?>" class="page-btn">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-btn disabled"><i class="fas fa-chevron-right"></i></span>
                            <?php endif; ?>
                            
                            <span class="page-info">Halaman <?php echo $page; ?> / <?php echo $totalPages; ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <a href="<?php echo url('category.php'); ?>" class="back-to-categories">
                    <i class="fas fa-arrow-left"></i> Lihat Semua Kategori
                </a>
            </div>

            <!-- ===== SIDEBAR ===== -->
            <aside class="category-sidebar">
                
                <!-- Top Authors Widget -->
                <?php if (!empty($topAuthors)): ?>
                    <div class="sidebar-widget-cat">
                        <h4><i class="fas fa-user-graduate"></i> Penulis Teraktif</h4>
                        <ul class="top-authors-list">
                            <?php foreach ($topAuthors as $i => $author): 
                                $rankClass = ($i + 1) <= 3 ? 'rank-' . ($i + 1) : 'rank-other';
                                $avatarUrl = url(ltrim(!empty($author['foto']) ? $author['foto'] : 'assets/uploads/default.png', '/'));
                            ?>
                                <li class="top-author-item">
                                    <div class="top-author-rank <?php echo $rankClass; ?>"><?php echo $i + 1; ?></div>
                                    <img src="<?php echo $avatarUrl; ?>" 
                                         onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($author['nama']); ?>&background=1e3a5f&color=fff&size=80'"
                                         alt="<?php echo htmlspecialchars($author['nama']); ?>">
                                    <div class="top-author-info">
                                        <strong><?php echo htmlspecialchars($author['nama']); ?></strong>
                                        <small>
                                            <span><i class="fas fa-newspaper"></i> <?php echo $author['article_count']; ?></span>
                                            <span><i class="fas fa-eye"></i> <?php echo number_format($author['total_views']); ?></span>
                                        </small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Related Categories Widget -->
                <?php if (!empty($relatedCategories)): ?>
                    <div class="sidebar-widget-cat">
                        <h4><i class="fas fa-tags"></i> Kategori Terkait</h4>
                        <ul class="related-cat-list">
                            <?php foreach ($relatedCategories as $rc): ?>
                                <li class="related-cat-item">
                                    <a href="<?php echo url('category.php?slug=' . $rc['slug']); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                        <?php echo htmlspecialchars($rc['name']); ?>
                                    </a>
                                    <span class="related-cat-count"><?php echo $rc['article_count']; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- CTA Widget -->
                <div class="sidebar-widget-cat" style="background:linear-gradient(135deg, #1e3a5f, #2c5f8d);color:white;">
                    <h4 style="color:white;border-bottom-color:rgba(255,255,255,0.15);">
                        <i class="fas fa-pen-fancy" style="color:#f39c12;"></i> Jadi Penulis
                    </h4>
                    <p style="color:rgba(255,255,255,0.85);font-size:0.88rem;line-height:1.6;margin-bottom:1.2rem;">
                        Bagikan ilmu Anda melalui tulisan. Ribuan pembaca menunggu karya terbaik Anda!
                    </p>
                    <?php if (isLoggedIn()): ?>
                        <a href="<?php echo url('admin/article-edit.php'); ?>" 
                           style="display:inline-flex;align-items:center;gap:0.5rem;background:linear-gradient(135deg,#f39c12,#e67e22);color:white;padding:0.7rem 1.3rem;border-radius:25px;text-decoration:none;font-weight:600;font-size:0.88rem;width:100%;justify-content:center;">
                            <i class="fas fa-pen"></i> Tulis Artikel
                        </a>
                    <?php else: ?>
                        <a href="<?php echo url('admin/login.php'); ?>" 
                           style="display:inline-flex;align-items:center;gap:0.5rem;background:linear-gradient(135deg,#f39c12,#e67e22);color:white;padding:0.7rem 1.3rem;border-radius:25px;text-decoration:none;font-weight:600;font-size:0.88rem;width:100%;justify-content:center;">
                            <i class="fas fa-sign-in-alt"></i> Login untuk Menulis
                        </a>
                    <?php endif; ?>
                </div>

            </aside>

        </div>
    </div>
</section>
<?php endif; ?>

<script>
// ===== SORT & VIEW HANDLERS =====
function changeCatSort(value) {
    var url = new URL(window.location.href);
    url.searchParams.set('sort', value);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

function changeCatView(mode) {
    var url = new URL(window.location.href);
    url.searchParams.set('view', mode);
    window.location.href = url.toString();
}

// Apply view mode from URL parameter
(function () {
    var params = new URLSearchParams(window.location.search);
    var view = params.get('view') || 'grid';
    document.body.classList.add('category-view-' + view);
})();

// ===== SMOOTH SCROLL FOR ANCHORS =====
document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
    anchor.addEventListener('click', function (e) {
        var target = document.querySelector(this.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

// ===== CARD HOVER 3D EFFECT =====
document.querySelectorAll('.category-article-card, .category-card-ultimate').forEach(function(card) {
    card.addEventListener('mousemove', function (e) {
        var rect = this.getBoundingClientRect();
        var x = e.clientX - rect.left;
        var y = e.clientY - rect.top;
        var centerX = rect.width / 2;
        var centerY = rect.height / 2;
        var rotateX = (y - centerY) / 40;
        var rotateY = (centerX - x) / 40;
        this.style.transform = 'translateY(-8px) perspective(1000px) rotateX(' + rotateX + 'deg) rotateY(' + rotateY + 'deg)';
    });
    card.addEventListener('mouseleave', function () {
        this.style.transform = '';
    });
});

console.log('%c🏷️ Category Page Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>