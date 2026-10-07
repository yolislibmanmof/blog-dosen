<?php
require_once __DIR__ . '/config/functions.php';

// ============================================
// PARAMETER & VALIDASI
// ============================================
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 9;
$offset = ($page - 1) * $limit;

// Filter
$filterCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
$filterAuthor = isset($_GET['author']) ? (int)$_GET['author'] : 0;
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'relevance'; // relevance, newest, popular, oldest
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'grid'; // grid, list

// Validasi sort
$allowedSort = ['relevance', 'newest', 'popular', 'oldest'];
if (!in_array($sortBy, $allowedSort)) $sortBy = 'relevance';

// ============================================
// FUNGSI HIGHLIGHT KEYWORD
// ============================================
function highlightKeyword($text, $keyword) {
    if (empty($keyword) || empty($text)) return $text;
    $text = htmlspecialchars($text);
    $keyword = preg_quote(htmlspecialchars($keyword), '/');
    return preg_replace('/(' . $keyword . ')/iu', '<mark class="search-highlight">$1</mark>', $text);
}

// ============================================
// DATA UNTUK SEARCH PAGE
// ============================================

// 1. Semua kategori dengan jumlah artikel
$searchCategories = [];
try {
    $searchCategories = db()->query("
        SELECT c.id, c.name, c.slug, COUNT(a.id) as total
        FROM categories c
        LEFT JOIN articles a ON a.category_id = c.id AND a.status = 'published'
        GROUP BY c.id
        ORDER BY c.name ASC
    ")->fetchAll();
} catch (Exception $e) {}

// 2. Top authors (untuk filter)
$searchAuthors = [];
try {
    $searchAuthors = db()->query("
        SELECT u.id, u.nama, u.foto, COUNT(a.id) as total
        FROM users u
        JOIN articles a ON a.author_id = u.id
        WHERE a.status = 'published'
        GROUP BY u.id
        ORDER BY total DESC
        LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {}

// 3. Trending searches (dari artikel populer)
$trendingKeywords = [];
try {
    $stmt = db()->query("
        SELECT title FROM articles 
        WHERE status = 'published'
        ORDER BY views DESC
        LIMIT 6
    ");
    $rawTitles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Ekstrak kata kunci umum dari judul (simple)
    $stopwords = ['yang', 'dan', 'di', 'ke', 'dari', 'pada', 'untuk', 'dengan', 'adalah', 'ini', 'itu', 'juga', 'atau', 'dalam', 'akan', 'telah', 'sudah', 'bisa', 'tidak', 'ada', 'saya', 'kita', 'kami', 'mereka', 'anda', 'sebuah', 'satu', 'antara', 'atas', 'bagi', 'oleh', 'serta', 'tapi', 'namun', 'jika', 'saat', 'ketika', 'karena', 'sehingga', 'agar', 'supaya'];
    $allWords = [];
    foreach ($rawTitles as $title) {
        $words = preg_split('/[\s,.\-:;!?()]+/', strtolower($title));
        foreach ($words as $w) {
            $w = trim($w);
            if (strlen($w) >= 4 && !in_array($w, $stopwords) && !is_numeric($w)) {
                $allWords[] = $w;
            }
        }
    }
    $wordCount = array_count_values($allWords);
    arsort($wordCount);
    $trendingKeywords = array_slice(array_keys($wordCount), 0, 8);
} catch (Exception $e) {}

// ============================================
// HASIL PENCARIAN
// ============================================
$articles = [];
$total = 0;
$totalPages = 0;
$searchTime = 0;
$categoryCounts = [];

if ($q) {
    $startTime = microtime(true);
    
    // Build WHERE clause
    $where = "WHERE a.status = 'published' AND (a.title LIKE ? OR a.content LIKE ? OR a.excerpt LIKE ?)";
    $params = ["%$q%", "%$q%", "%$q%"];
    
    if ($filterCategory) {
        $where .= " AND c.slug = ?";
        $params[] = $filterCategory;
    }
    
    if ($filterAuthor > 0) {
        $where .= " AND a.author_id = ?";
        $params[] = $filterAuthor;
    }
    
    // Hitung total
    $countQuery = "SELECT COUNT(*) FROM articles a 
                   LEFT JOIN categories c ON a.category_id = c.id 
                   $where";
    $stmt = db()->prepare($countQuery);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $totalPages = ceil($total / $limit);
    
    // Hitung jumlah per kategori (untuk sidebar filter)
    $catCountQuery = "SELECT c.slug, c.name, COUNT(a.id) as cnt
                      FROM articles a
                      LEFT JOIN categories c ON a.category_id = c.id
                      $where
                      GROUP BY c.id
                      ORDER BY cnt DESC";
    $stmt = db()->prepare($catCountQuery);
    $stmt->execute($params);
    $categoryCounts = $stmt->fetchAll();
    
    // Build ORDER BY
    $orderBy = "ORDER BY ";
    switch ($sortBy) {
        case 'newest':
            $orderBy .= "a.created_at DESC";
            break;
        case 'oldest':
            $orderBy .= "a.created_at ASC";
            break;
        case 'popular':
            $orderBy .= "a.views DESC";
            break;
        case 'relevance':
        default:
            // Relevance: judul match duluan, lalu views
            $orderBy .= "CASE WHEN a.title LIKE ? THEN 1 ELSE 0 END DESC, a.views DESC, a.created_at DESC";
            array_unshift($params, "%$q%");
            break;
    }
    
    // Ambil hasil
    $query = "SELECT a.*, u.nama as author_name, u.foto as author_foto, u.jabatan,
                     c.name as category_name, c.slug as category_slug
              FROM articles a
              JOIN users u ON a.author_id = u.id
              LEFT JOIN categories c ON a.category_id = c.id
              $where
              $orderBy
              LIMIT $limit OFFSET $offset";
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $articles = $stmt->fetchAll();
    
    $searchTime = round((microtime(true) - $startTime) * 1000, 2); // milliseconds
}

$pageTitle = $q ? "Hasil Pencarian: $q" : 'Pencarian';
include __DIR__ . '/includes/header.php';
?>

<style>
/* ===== SEARCH HERO ===== */
.search-hero {
    background: linear-gradient(135deg, #16324f 0%, #1e3a5f 50%, #2c5f8d 100%);
    color: white;
    padding: 4rem 0 6rem;
    position: relative;
    overflow: hidden;
}
.search-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: 
        radial-gradient(circle at 15% 50%, rgba(243, 156, 18, 0.15), transparent 50%),
        radial-gradient(circle at 85% 20%, rgba(52, 152, 219, 0.15), transparent 50%);
}
.search-hero-content {
    position: relative;
    z-index: 2;
    max-width: 900px;
    margin: 0 auto;
    text-align: center;
}
.search-hero-badge {
    display: inline-block;
    background: rgba(243, 156, 18, 0.2);
    color: #f39c12;
    padding: 0.35rem 1rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 1px;
    margin-bottom: 1rem;
    border: 1px solid rgba(243, 156, 18, 0.3);
}
.search-hero h1 {
    font-size: 2.8rem;
    margin-bottom: 0.8rem;
    font-weight: 800;
}
.search-hero p {
    opacity: 0.9;
    font-size: 1.05rem;
    margin-bottom: 2rem;
}

/* Search Bar ULTIMATE */
.search-box-ultimate {
    position: relative;
    max-width: 700px;
    margin: 0 auto;
}
.search-form-ult {
    display: flex;
    background: white;
    border-radius: 50px;
    padding: 6px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.25);
    transition: all 0.3s ease;
}
.search-form-ult:focus-within {
    box-shadow: 0 20px 60px rgba(243, 156, 18, 0.4);
    transform: translateY(-2px);
}
.search-icon-wrap {
    display: flex;
    align-items: center;
    padding: 0 1rem 0 1.3rem;
    color: #1e3a5f;
    font-size: 1.2rem;
}
.search-input-ult {
    flex: 1;
    border: none;
    outline: none;
    padding: 1rem 0.5rem;
    font-size: 1.05rem;
    color: #333;
    background: transparent;
    min-width: 0;
}
.search-input-ult::placeholder {
    color: #aaa;
}
.search-clear-btn {
    background: #f0f0f0;
    border: none;
    color: #666;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    cursor: pointer;
    align-self: center;
    margin-right: 0.5rem;
    transition: all 0.2s ease;
    display: none;
}
.search-clear-btn.show { display: flex; align-items: center; justify-content: center; }
.search-clear-btn:hover { background: #e74c3c; color: white; }
.search-submit-ult {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border: none;
    padding: 0 2rem;
    border-radius: 50px;
    font-weight: 700;
    cursor: pointer;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    white-space: nowrap;
}
.search-submit-ult:hover {
    background: linear-gradient(135deg, #e67e22, #d35400);
    transform: scale(1.02);
}

/* Search Suggestions Dropdown */
.search-suggestions-ult {
    position: absolute;
    top: calc(100% + 10px);
    left: 0;
    right: 0;
    background: white;
    border-radius: 15px;
    box-shadow: 0 15px 40px rgba(0,0,0,0.2);
    padding: 1rem;
    display: none;
    z-index: 100;
    max-height: 400px;
    overflow-y: auto;
}
.search-suggestions-ult.show { display: block; animation: slideDownSearch 0.3s ease; }
@keyframes slideDownSearch { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.suggestion-group-title {
    font-size: 0.72rem;
    text-transform: uppercase;
    color: #999;
    letter-spacing: 1.5px;
    padding: 0.5rem 0.5rem 0.3rem;
    font-weight: 700;
}
.suggestion-item-ult {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 0.6rem 0.5rem;
    color: #333;
    text-decoration: none;
    border-radius: 8px;
    transition: background 0.2s ease;
    cursor: pointer;
}
.suggestion-item-ult:hover { background: #f4f6f9; }
.suggestion-item-ult i { color: #f39c12; width: 18px; }
.suggestion-item-ult strong { color: #1e3a5f; }
.suggestion-item-ult small { color: #999; font-size: 0.78rem; }

/* Quick Keywords */
.quick-keywords {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 1.5rem;
    max-width: 700px;
    margin-left: auto;
    margin-right: auto;
}
.quick-keyword {
    background: rgba(255,255,255,0.12);
    color: white;
    padding: 0.4rem 1rem;
    border-radius: 20px;
    text-decoration: none;
    font-size: 0.85rem;
    border: 1px solid rgba(255,255,255,0.2);
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}
.quick-keyword:hover {
    background: #f39c12;
    border-color: #f39c12;
    transform: translateY(-2px);
}
.quick-keyword i { font-size: 0.75rem; opacity: 0.8; }

/* ===== RESULTS WRAPPER ===== */
.search-results-wrapper {
    padding: 3rem 0;
    background: #f8f9fa;
    min-height: 500px;
}

/* Search Stats Bar */
.search-stats-bar {
    background: white;
    padding: 1rem 1.5rem;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 2rem;
}
.search-stats-left {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    flex-wrap: wrap;
}
.search-stat-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    color: #666;
}
.search-stat-item strong { color: #1e3a5f; font-size: 1.1rem; }
.search-stat-item i { color: #f39c12; }
.search-stats-right {
    display: flex;
    align-items: center;
    gap: 1rem;
}
.sort-select-ult {
    padding: 0.5rem 1rem;
    border: 2px solid #e0e0e0;
    border-radius: 25px;
    background: white;
    color: #333;
    font-size: 0.9rem;
    cursor: pointer;
    outline: none;
    transition: border 0.3s ease;
}
.sort-select-ult:focus { border-color: #f39c12; }
.view-toggle {
    display: flex;
    background: #f0f0f0;
    border-radius: 8px;
    padding: 3px;
}
.view-btn {
    width: 36px;
    height: 36px;
    background: transparent;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    color: #666;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}
.view-btn.active { background: white; color: #1e3a5f; box-shadow: 0 2px 6px rgba(0,0,0,0.08); }

/* ===== GRID LAYOUT (with sidebar filters) ===== */
.search-layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 2rem;
}
.search-sidebar {
    position: sticky;
    top: 90px;
    align-self: start;
    max-height: calc(100vh - 110px);
    overflow-y: auto;
}
.filter-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
}
.filter-card-title {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: #888;
    font-weight: 700;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.filter-card-title i { color: #f39c12; }
.filter-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.filter-list li { margin-bottom: 0.3rem; }
.filter-list a {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0.7rem;
    color: #555;
    text-decoration: none;
    border-radius: 8px;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}
.filter-list a:hover { background: #f4f6f9; color: #1e3a5f; }
.filter-list a.active {
    background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
    color: white;
}
.filter-list a.active .filter-count { background: #f39c12; color: white; }
.filter-count {
    background: #f0f0f0;
    color: #666;
    padding: 0.15rem 0.5rem;
    border-radius: 10px;
    font-size: 0.72rem;
    font-weight: 700;
    min-width: 24px;
    text-align: center;
}
.filter-list a.show-all {
    color: #f39c12;
    font-weight: 600;
    border-top: 1px solid #f0f0f0;
    padding-top: 0.8rem;
    margin-top: 0.5rem;
}
.filter-reset-btn {
    display: block;
    text-align: center;
    padding: 0.7rem;
    background: #f8f9fa;
    color: #e74c3c;
    text-decoration: none;
    border-radius: 8px;
    font-size: 0.88rem;
    font-weight: 600;
    transition: all 0.2s ease;
}
.filter-reset-btn:hover { background: #e74c3c; color: white; }

/* ===== SEARCH RESULTS ===== */
.search-results-area { min-width: 0; }

/* Did You Mean */
.did-you-mean {
    background: linear-gradient(135deg, #fff3cd, #ffeaa7);
    padding: 1rem 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    border-left: 4px solid #f39c12;
}
.did-you-mean i { color: #f39c12; font-size: 1.3rem; }
.did-you-mean a { color: #1e3a5f; font-weight: 700; }

/* Active Filters Chips */
.active-filters {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-bottom: 1.5rem;
}
.filter-chip {
    background: white;
    border: 1px solid #e0e0e0;
    color: #333;
    padding: 0.4rem 0.9rem;
    border-radius: 20px;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
    transition: all 0.2s ease;
}
.filter-chip:hover { background: #e74c3c; color: white; border-color: #e74c3c; }
.filter-chip i { font-size: 0.75rem; }
.filter-chip strong { color: #1e3a5f; font-weight: 700; }
.filter-chip:hover strong { color: white; }

/* Search Highlight */
mark.search-highlight {
    background: linear-gradient(120deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
    padding: 0.1rem 0.3rem;
    border-radius: 4px;
    font-weight: 600;
}

/* Result Card - Grid View */
.results-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
}
.result-card-ult {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    border: 1px solid #f0f0f0;
}
.result-card-ult:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
    border-color: #f39c12;
}
.result-image-ult {
    height: 180px;
    background-size: cover;
    background-position: center;
    position: relative;
}
.result-badge-ult {
    position: absolute;
    top: 10px;
    left: 10px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    padding: 0.25rem 0.7rem;
    border-radius: 15px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.5px;
}
.result-content-ult {
    padding: 1.3rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.result-category-ult {
    color: #f39c12;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 0.5rem;
}
.result-title-ult {
    font-size: 1.15rem;
    color: #1e3a5f;
    margin-bottom: 0.8rem;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.result-title-ult a { color: inherit; text-decoration: none; transition: color 0.2s ease; }
.result-title-ult a:hover { color: #f39c12; }
.result-excerpt-ult {
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
.result-meta-ult {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 0.8rem;
    border-top: 1px solid #f0f0f0;
    font-size: 0.8rem;
    color: #888;
}
.result-author-ult {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.result-author-ult img {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #f39c12;
}
.result-stats-ult {
    display: flex;
    gap: 0.8rem;
}
.result-stats-ult span {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

/* Result Card - List View */
.results-list { display: none; flex-direction: column; gap: 1rem; }
.results-list .result-card-list {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    display: grid;
    grid-template-columns: 180px 1fr;
    gap: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    border: 1px solid #f0f0f0;
}
.results-list .result-card-list:hover {
    transform: translateX(5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.08);
    border-color: #f39c12;
}
.result-list-image {
    height: 140px;
    border-radius: 10px;
    background-size: cover;
    background-position: center;
}
.result-list-content { display: flex; flex-direction: column; }
.result-list-content .result-title-ult {
    font-size: 1.25rem;
    margin-bottom: 0.5rem;
    -webkit-line-clamp: 2;
}
.result-list-content .result-excerpt-ult {
    -webkit-line-clamp: 2;
    margin-bottom: 0.8rem;
}

/* Body toggle: list active */
body.search-view-list .results-grid { display: none; }
body.search-view-list .results-list { display: flex; }

/* Empty State Ultimate */
.empty-search-ult {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
}
.empty-search-ult .empty-icon {
    width: 120px;
    height: 120px;
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
.empty-search-ult h3 { color: #1e3a5f; font-size: 1.8rem; margin-bottom: 0.5rem; }
.empty-search-ult p { color: #888; margin-bottom: 2rem; font-size: 1rem; }
.empty-suggestions {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 12px;
    margin-top: 2rem;
    text-align: left;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
}
.empty-suggestions h4 { color: #1e3a5f; margin-bottom: 1rem; }
.empty-suggestions ul { list-style: none; padding: 0; }
.empty-suggestions li {
    padding: 0.5rem 0;
    color: #555;
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}
.empty-suggestions li i { color: #f39c12; margin-top: 0.3rem; }

/* Initial State (belum search) */
.initial-search-ult {
    text-align: center;
    padding: 3rem 2rem;
    background: white;
    border-radius: 20px;
}
.initial-search-ult .init-icon {
    font-size: 5rem;
    color: #1e3a5f;
    margin-bottom: 1rem;
    opacity: 0.3;
}
.initial-search-ult h3 { color: #1e3a5f; font-size: 1.6rem; margin-bottom: 0.5rem; }
.initial-search-ult p { color: #888; margin-bottom: 2rem; }
.popular-searches {
    max-width: 600px;
    margin: 0 auto;
}
.popular-searches h4 {
    color: #1e3a5f;
    font-size: 1rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}
.popular-searches h4 i { color: #f39c12; }
.popular-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    justify-content: center;
}
.popular-chip {
    background: #f4f6f9;
    color: #1e3a5f;
    padding: 0.5rem 1.2rem;
    border-radius: 25px;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    transition: all 0.2s ease;
    border: 2px solid transparent;
}
.popular-chip:hover {
    background: #1e3a5f;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(30, 58, 95, 0.2);
}

/* Pagination Ultimate */
.search-pagination-ult {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    margin-top: 3rem;
    flex-wrap: wrap;
}
.page-btn-ult {
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
    cursor: pointer;
}
.page-btn-ult:hover:not(.disabled):not(.active) {
    background: #1e3a5f;
    color: white;
    border-color: #1e3a5f;
    transform: translateY(-2px);
}
.page-btn-ult.active {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border-color: transparent;
    box-shadow: 0 5px 15px rgba(243, 156, 18, 0.3);
}
.page-btn-ult.disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.page-info-ult {
    color: #888;
    font-size: 0.88rem;
    margin: 0 1rem;
}

/* Loading Skeleton */
.skeleton-card {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    padding-bottom: 1rem;
}
.skeleton-img {
    height: 180px;
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: skeletonShimmer 1.5s infinite;
}
.skeleton-content { padding: 1rem 1.3rem; }
.skeleton-line {
    height: 14px;
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: skeletonShimmer 1.5s infinite;
    border-radius: 4px;
    margin-bottom: 0.6rem;
}
.skeleton-line.short { width: 60%; }
.skeleton-line.tiny { width: 40%; height: 10px; }
@keyframes skeletonShimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* Responsive */
@media (max-width: 992px) {
    .search-layout { grid-template-columns: 1fr; }
    .search-sidebar { position: static; max-height: none; }
    .search-hero h1 { font-size: 2rem; }
    .results-list .result-card-list { grid-template-columns: 1fr; }
    .result-list-image { height: 200px; }
}
@media (max-width: 576px) {
    .search-hero { padding: 2.5rem 0 4rem; }
    .search-hero h1 { font-size: 1.7rem; }
    .search-form-ult { flex-direction: column; border-radius: 20px; padding: 1rem; }
    .search-icon-wrap { display: none; }
    .search-submit-ult { width: 100%; justify-content: center; padding: 0.9rem; }
    .search-stats-bar { flex-direction: column; align-items: flex-start; }
    .results-grid { grid-template-columns: 1fr; }
}
</style>

<!-- ============================================ -->
<!-- 🎯 SEARCH HERO -->
<!-- ============================================ -->
<section class="search-hero">
    <div class="container search-hero-content">
        <span class="search-hero-badge">🔍 SMART SEARCH ENGINE</span>
        <h1>Cari Artikel, Ilmu, dan Inspirasi</h1>
        <p>Temukan ribuan artikel berkualitas dari para dosen ahli di bidangnya</p>
        
        <div class="search-box-ultimate">
            <form action="<?php echo url('search.php'); ?>" method="GET" class="search-form-ult" id="searchFormUlt">
                <div class="search-icon-wrap">
                    <i class="fas fa-search"></i>
                </div>
                <input type="text" 
                       name="q" 
                       class="search-input-ult" 
                       id="searchInputUlt"
                       placeholder="Ketik kata kunci, topik, atau nama dosen..." 
                       value="<?php echo htmlspecialchars($q); ?>"
                       autocomplete="off"
                       autofocus>
                <button type="button" class="search-clear-btn" id="clearSearchBtn" title="Bersihkan">
                    <i class="fas fa-times"></i>
                </button>
                <button type="submit" class="search-submit-ult">
                    <i class="fas fa-search"></i> Cari
                </button>
            </form>

            <!-- Search Suggestions Dropdown -->
            <div class="search-suggestions-ult" id="searchSuggestionsUlt">
                <?php if (!empty($trendingKeywords)): ?>
                    <div class="suggestion-group-title">
                        <i class="fas fa-fire"></i> Kata Kunci Populer
                    </div>
                    <?php foreach (array_slice($trendingKeywords, 0, 5) as $kw): ?>
                        <div class="suggestion-item-ult" onclick="submitSearch('<?php echo htmlspecialchars($kw, ENT_QUOTES); ?>')">
                            <i class="fas fa-fire"></i>
                            <span><strong><?php echo htmlspecialchars($kw); ?></strong></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if (!empty($searchAuthors)): ?>
                    <div class="suggestion-group-title" style="margin-top:0.8rem;">
                        <i class="fas fa-user-graduate"></i> Dosen Populer
                    </div>
                    <?php foreach (array_slice($searchAuthors, 0, 3) as $au): ?>
                        <a href="<?php echo url('search.php?q=&author=' . $au['id']); ?>" class="suggestion-item-ult">
                            <i class="fas fa-user"></i>
                            <span>
                                <strong><?php echo htmlspecialchars($au['nama']); ?></strong>
                                <small><?php echo $au['total']; ?> artikel</small>
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Keywords -->
        <?php if (!empty($trendingKeywords)): ?>
            <div class="quick-keywords">
                <span style="opacity:0.8;font-size:0.85rem;">Trending:</span>
                <?php foreach (array_slice($trendingKeywords, 0, 5) as $kw): ?>
                    <a href="<?php echo url('search.php?q=' . urlencode($kw)); ?>" class="quick-keyword">
                        <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($kw); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============================================ -->
<!-- 📊 RESULTS SECTION -->
<!-- ============================================ -->
<section class="search-results-wrapper">
    <div class="container">

        <?php if ($q): ?>
            <!-- Stats Bar -->
            <div class="search-stats-bar">
                <div class="search-stats-left">
                    <div class="search-stat-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Ditemukan <strong><?php echo number_format($total); ?></strong> hasil</span>
                    </div>
                    <div class="search-stat-item">
                        <i class="fas fa-stopwatch"></i>
                        <span>Dalam <strong><?php echo $searchTime; ?></strong> ms</span>
                    </div>
                    <div class="search-stat-item">
                        <i class="fas fa-list"></i>
                        <span>Halaman <strong><?php echo $page; ?></strong> dari <strong><?php echo $totalPages ?: 1; ?></strong></span>
                    </div>
                </div>
                <div class="search-stats-right">
                    <select class="sort-select-ult" onchange="changeSort(this.value)" id="sortSelect">
                        <option value="relevance" <?php echo $sortBy === 'relevance' ? 'selected' : ''; ?>>🎯 Relevansi</option>
                        <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>🆕 Terbaru</option>
                        <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>📅 Terlama</option>
                        <option value="popular" <?php echo $sortBy === 'popular' ? 'selected' : ''; ?>>🔥 Terpopuler</option>
                    </select>
                    <div class="view-toggle">
                        <button type="button" class="view-btn <?php echo $viewMode === 'grid' ? 'active' : ''; ?>" onclick="changeView('grid')" title="Tampilan Grid">
                            <i class="fas fa-th-large"></i>
                        </button>
                        <button type="button" class="view-btn <?php echo $viewMode === 'list' ? 'active' : ''; ?>" onclick="changeView('list')" title="Tampilan List">
                            <i class="fas fa-list"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Active Filters Chips -->
            <?php if ($filterCategory || $filterAuthor > 0): ?>
                <div class="active-filters">
                    <?php if ($filterCategory): 
                        $catName = '';
                        foreach ($searchCategories as $c) {
                            if ($c['slug'] === $filterCategory) { $catName = $c['name']; break; }
                        }
                    ?>
                        <a href="<?php echo url('search.php?q=' . urlencode($q) . '&sort=' . $sortBy . ($filterAuthor ? '&author=' . $filterAuthor : '')); ?>" class="filter-chip" title="Hapus filter kategori">
                            <i class="fas fa-tag"></i> Kategori: <strong><?php echo htmlspecialchars($catName); ?></strong>
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($filterAuthor > 0): 
                        $authorName = '';
                        foreach ($searchAuthors as $a) {
                            if ($a['id'] == $filterAuthor) { $authorName = $a['nama']; break; }
                        }
                    ?>
                        <a href="<?php echo url('search.php?q=' . urlencode($q) . '&sort=' . $sortBy . ($filterCategory ? '&category=' . $filterCategory : '')); ?>" class="filter-chip" title="Hapus filter penulis">
                            <i class="fas fa-user"></i> Penulis: <strong><?php echo htmlspecialchars($authorName); ?></strong>
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                    
                    <a href="<?php echo url('search.php?q=' . urlencode($q)); ?>" class="filter-chip" style="background:#fff3cd;color:#856404;border-color:#ffc107;" title="Reset semua filter">
                        <i class="fas fa-redo"></i> Reset Semua
                    </a>
                </div>
            <?php endif; ?>

            <!-- Did You Mean (sederhana: cek kalau hasil 0) -->
            <?php if ($total === 0 && !empty($trendingKeywords)): ?>
                <div class="did-you-mean">
                    <i class="fas fa-lightbulb"></i>
                    <div>
                        Mungkin Anda mencari: 
                        <?php foreach (array_slice($trendingKeywords, 0, 3) as $i => $s): ?>
                            <a href="<?php echo url('search.php?q=' . urlencode($s)); ?>"><?php echo htmlspecialchars($s); ?></a><?php echo $i < 2 ? ', ' : ''; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Layout: Sidebar + Results -->
            <?php if ($total > 0 || !empty($categoryCounts)): ?>
                <div class="search-layout">
                    <!-- Sidebar Filters -->
                    <aside class="search-sidebar">
                        <!-- Filter Kategori -->
                        <div class="filter-card">
                            <div class="filter-card-title">
                                <i class="fas fa-tag"></i> Kategori
                            </div>
                            <?php if (!empty($categoryCounts)): ?>
                                <ul class="filter-list">
                                    <li>
                                        <a href="<?php echo url('search.php?q=' . urlencode($q) . '&sort=' . $sortBy . ($filterAuthor ? '&author=' . $filterAuthor : '')); ?>" class="<?php echo !$filterCategory ? 'active' : ''; ?>">
                                            <span>Semua Kategori</span>
                                            <span class="filter-count"><?php echo $total; ?></span>
                                        </a>
                                    </li>
                                    <?php foreach ($categoryCounts as $cc): ?>
                                        <li>
                                            <a href="<?php echo url('search.php?q=' . urlencode($q) . '&category=' . $cc['slug'] . '&sort=' . $sortBy . ($filterAuthor ? '&author=' . $filterAuthor : '')); ?>" 
                                               class="<?php echo $filterCategory === $cc['slug'] ? 'active' : ''; ?>">
                                                <span><?php echo htmlspecialchars($cc['name']); ?></span>
                                                <span class="filter-count"><?php echo $cc['cnt']; ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p style="color:#888;font-size:0.85rem;">Tidak ada kategori tersedia</p>
                            <?php endif; ?>
                        </div>

                        <!-- Filter Penulis -->
                        <?php if (!empty($searchAuthors)): ?>
                            <div class="filter-card">
                                <div class="filter-card-title">
                                    <i class="fas fa-user-graduate"></i> Penulis
                                </div>
                                <ul class="filter-list">
                                    <?php foreach (array_slice($searchAuthors, 0, 6) as $au): ?>
                                        <li>
                                            <a href="<?php echo url('search.php?q=' . urlencode($q) . '&author=' . $au['id'] . '&sort=' . $sortBy . ($filterCategory ? '&category=' . $filterCategory : '')); ?>" 
                                               class="<?php echo $filterAuthor == $au['id'] ? 'active' : ''; ?>">
                                                <span><?php echo htmlspecialchars(explode(' ', $au['nama'])[0]); ?></span>
                                                <span class="filter-count"><?php echo $au['total']; ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <!-- Reset Filters -->
                        <a href="<?php echo url('search.php'); ?>" class="filter-reset-btn">
                            <i class="fas fa-redo"></i> Reset Semua Filter
                        </a>
                    </aside>

                    <!-- Results Area -->
                    <div class="search-results-area">
                        <?php if (empty($articles)): ?>
                            <!-- Empty Results State -->
                            <div class="empty-search-ult">
                                <div class="empty-icon"><i class="fas fa-search"></i></div>
                                <h3>Tidak Ada Hasil Ditemukan</h3>
                                <p>Pencarian "<strong><?php echo htmlspecialchars($q); ?></strong>" tidak cocok dengan artikel manapun.</p>
                                <div class="empty-suggestions">
                                    <h4>💡 Coba saran berikut:</h4>
                                    <ul>
                                        <li><i class="fas fa-check"></i> Periksa ejaan kata kunci Anda</li>
                                        <li><i class="fas fa-check"></i> Gunakan kata kunci yang lebih umum</li>
                                        <li><i class="fas fa-check"></i> Kurangi jumlah kata dalam pencarian</li>
                                        <li><i class="fas fa-check"></i> Hapus filter kategori atau penulis</li>
                                    </ul>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Grid View -->
                            <div class="results-grid">
                                <?php foreach ($articles as $article): 
                                    $avatarUrl = url(ltrim(!empty($article['author_foto']) ? $article['author_foto'] : 'assets/uploads/default.png', '/'));
                                ?>
                                    <article class="result-card-ult">
                                        <div class="result-image-ult" style="background-image: url('<?php echo htmlspecialchars($article['featured_image'] ?: 'https://images.unsplash.com/photo-1499209974431-9dddcece7f88?w=400'); ?>');">
                                            <?php if ($article['views'] > 100): ?>
                                                <span class="result-badge-ult">🔥 POPULER</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="result-content-ult">
                                            <span class="result-category-ult">
                                                <?php echo htmlspecialchars($article['category_name'] ?: 'Umum'); ?>
                                            </span>
                                            <h3 class="result-title-ult">
                                                <a href="<?php echo url('article.php?slug=' . $article['slug']); ?>">
                                                    <?php echo highlightKeyword($article['title'], $q); ?>
                                                </a>
                                            </h3>
                                            <p class="result-excerpt-ult">
                                                <?php echo highlightKeyword($article['excerpt'] ?: excerpt($article['content'], 180), $q); ?>
                                            </p>
                                            <div class="result-meta-ult">
                                                <div class="result-author-ult">
                                                    <img src="<?php echo $avatarUrl; ?>" 
                                                         onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($article['author_name']); ?>&background=1e3a5f&color=fff&size=56'"
                                                         alt="">
                                                    <span><?php echo highlightKeyword($article['author_name'], $q); ?></span>
                                                </div>
                                                <div class="result-stats-ult">
                                                    <span><i class="fas fa-eye"></i> <?php echo number_format($article['views']); ?></span>
                                                    <span><i class="fas fa-calendar"></i> <?php echo formatDate($article['created_at']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <!-- List View -->
                            <div class="results-list">
                                <?php foreach ($articles as $article): 
                                    $avatarUrl = url(ltrim(!empty($article['author_foto']) ? $article['author_foto'] : 'assets/uploads/default.png', '/'));
                                ?>
                                    <article class="result-card-list">
                                        <div class="result-list-image" style="background-image: url('<?php echo htmlspecialchars($article['featured_image'] ?: 'https://images.unsplash.com/photo-1499209974431-9dddcece7f88?w=400'); ?>');"></div>
                                        <div class="result-list-content">
                                            <span class="result-category-ult">
                                                <?php echo htmlspecialchars($article['category_name'] ?: 'Umum'); ?>
                                            </span>
                                            <h3 class="result-title-ult">
                                                <a href="<?php echo url('article.php?slug=' . $article['slug']); ?>">
                                                    <?php echo highlightKeyword($article['title'], $q); ?>
                                                </a>
                                            </h3>
                                            <p class="result-excerpt-ult">
                                                <?php echo highlightKeyword($article['excerpt'] ?: excerpt($article['content'], 200), $q); ?>
                                            </p>
                                            <div class="result-meta-ult">
                                                <div class="result-author-ult">
                                                    <img src="<?php echo $avatarUrl; ?>" 
                                                         onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($article['author_name']); ?>&background=1e3a5f&color=fff&size=56'"
                                                         alt="">
                                                    <span><?php echo highlightKeyword($article['author_name'], $q); ?></span>
                                                </div>
                                                <div class="result-stats-ult">
                                                    <span><i class="fas fa-eye"></i> <?php echo number_format($article['views']); ?></span>
                                                    <span><i class="fas fa-calendar"></i> <?php echo formatDate($article['created_at']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <div class="search-pagination-ult">
                                    <?php 
                                    $baseUrl = 'search.php?q=' . urlencode($q) 
                                             . ($filterCategory ? '&category=' . urlencode($filterCategory) : '')
                                             . ($filterAuthor ? '&author=' . $filterAuthor : '')
                                             . '&sort=' . $sortBy
                                             . '&view=' . $viewMode;
                                    
                                    // Prev button
                                    if ($page > 1): ?>
                                        <a href="<?php echo url($baseUrl . '&page=' . ($page - 1)); ?>" class="page-btn-ult">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-btn-ult disabled"><i class="fas fa-chevron-left"></i></span>
                                    <?php endif;
                                    
                                    // Page numbers dengan ellipsis
                                    $start = max(1, $page - 2);
                                    $end = min($totalPages, $page + 2);
                                    
                                    if ($start > 1): ?>
                                        <a href="<?php echo url($baseUrl . '&page=1'); ?>" class="page-btn-ult">1</a>
                                        <?php if ($start > 2): ?>
                                            <span style="padding:0 0.5rem;color:#999;">...</span>
                                        <?php endif;
                                    endif;
                                    
                                    for ($i = $start; $i <= $end; $i++): 
                                        if ($i === $page): ?>
                                            <span class="page-btn-ult active"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a href="<?php echo url($baseUrl . '&page=' . $i); ?>" class="page-btn-ult"><?php echo $i; ?></a>
                                        <?php endif;
                                    endfor;
                                    
                                    if ($end < $totalPages): 
                                        if ($end < $totalPages - 1): ?>
                                            <span style="padding:0 0.5rem;color:#999;">...</span>
                                        <?php endif; ?>
                                        <a href="<?php echo url($baseUrl . '&page=' . $totalPages); ?>" class="page-btn-ult"><?php echo $totalPages; ?></a>
                                    <?php endif;
                                    
                                    // Next button
                                    if ($page < $totalPages): ?>
                                        <a href="<?php echo url($baseUrl . '&page=' . ($page + 1)); ?>" class="page-btn-ult">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-btn-ult disabled"><i class="fas fa-chevron-right"></i></span>
                                    <?php endif; ?>
                                    
                                    <span class="page-info-ult">Halaman <?php echo $page; ?> / <?php echo $totalPages; ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Empty state tanpa sidebar -->
                <div class="empty-search-ult">
                    <div class="empty-icon"><i class="fas fa-search"></i></div>
                    <h3>Tidak Ada Hasil Ditemukan</h3>
                    <p>Pencarian "<strong><?php echo htmlspecialchars($q); ?></strong>" tidak cocok dengan artikel manapun.</p>
                    <div class="empty-suggestions">
                        <h4>💡 Coba saran berikut:</h4>
                        <ul>
                            <li><i class="fas fa-check"></i> Periksa ejaan kata kunci Anda</li>
                            <li><i class="fas fa-check"></i> Gunakan kata kunci yang lebih umum</li>
                            <li><i class="fas fa-check"></i> Kurangi jumlah kata dalam pencarian</li>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- INITIAL STATE (belum search) -->
            <div class="initial-search-ult">
                <div class="init-icon"><i class="fas fa-search"></i></div>
                <h3>Mulai Mencari Sekarang</h3>
                <p>Ketik kata kunci di atas untuk menemukan artikel yang Anda butuhkan</p>
                
                <?php if (!empty($trendingKeywords)): ?>
                    <div class="popular-searches">
                        <h4><i class="fas fa-fire"></i> Pencarian Populer</h4>
                        <div class="popular-chips">
                            <?php foreach ($trendingKeywords as $kw): ?>
                                <a href="<?php echo url('search.php?q=' . urlencode($kw)); ?>" class="popular-chip">
                                    <?php echo htmlspecialchars($kw); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($searchCategories)): ?>
                    <div class="popular-searches" style="margin-top:2rem;">
                        <h4><i class="fas fa-tag"></i> Jelajahi Kategori</h4>
                        <div class="popular-chips">
                            <?php foreach (array_slice($searchCategories, 0, 8) as $cat): ?>
                                <a href="<?php echo url('search.php?category=' . urlencode($cat['slug'])); ?>" class="popular-chip">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                    <small style="opacity:0.7;margin-left:0.3rem;">(<?php echo $cat['total']; ?>)</small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</section>

<script>
// ===== SEARCH SUGGESTIONS =====
(function () {
    var input = document.getElementById('searchInputUlt');
    var sugg = document.getElementById('searchSuggestionsUlt');
    var clearBtn = document.getElementById('clearSearchBtn');
    
    if (!input || !sugg) return;
    
    input.addEventListener('focus', function () {
        sugg.classList.add('show');
    });
    
    input.addEventListener('input', function () {
        clearBtn.classList.toggle('show', this.value.length > 0);
    });
    
    // Initial state clear button
    if (input.value.length > 0) clearBtn.classList.add('show');
    
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.search-box-ultimate')) {
            sugg.classList.remove('show');
        }
    });
    
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            input.value = '';
            input.focus();
            clearBtn.classList.remove('show');
        });
    }
})();

// ===== SUBMIT SEARCH FROM SUGGESTION =====
function submitSearch(keyword) {
    var input = document.getElementById('searchInputUlt');
    if (input) input.value = keyword;
    document.getElementById('searchFormUlt').submit();
}

// ===== CHANGE SORT =====
function changeSort(value) {
    var url = new URL(window.location.href);
    url.searchParams.set('sort', value);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

// ===== CHANGE VIEW (Grid/List) =====
function changeView(mode) {
    var url = new URL(window.location.href);
    url.searchParams.set('view', mode);
    window.location.href = url.toString();
}

// Apply view mode from URL parameter
(function () {
    var params = new URLSearchParams(window.location.search);
    var view = params.get('view') || 'grid';
    document.body.classList.add('search-view-' + view);
})();

// ===== KEYBOARD SHORTCUTS =====
document.addEventListener('keydown', function (e) {
    // Ctrl+/ to focus search
    if ((e.ctrlKey || e.metaKey) && e.key === '/') {
        e.preventDefault();
        var input = document.getElementById('searchInputUlt');
        if (input) input.focus();
    }
    // ESC to blur search
    if (e.key === 'Escape') {
        document.activeElement.blur();
        var sugg = document.getElementById('searchSuggestionsUlt');
        if (sugg) sugg.classList.remove('show');
    }
});

// ===== HIGHLIGHT ON SCROLL (auto-focus on first result) =====
(function () {
    var firstCard = document.querySelector('.result-card-ult, .result-card-list');
    if (firstCard && window.location.hash === '#results') {
        firstCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstCard.style.animation = 'pulseHighlight 2s ease';
    }
})();

console.log('%c🔍 Search Page Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>