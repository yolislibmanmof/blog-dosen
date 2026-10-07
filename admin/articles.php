<?php
require_once __DIR__ . '/../config/functions.php';
checkAuth();

$userId = $_SESSION['user_id'];

// ============================================
// 🗑️ HANDLE DELETE (POST METHOD - AMAN!)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        
        // Single delete
        if ($action === 'delete' && isset($_POST['article_id'])) {
            $id = (int)$_POST['article_id'];
            $stmt = db()->prepare("DELETE FROM articles WHERE id = ? AND author_id = ?");
            $stmt->execute([$id, $userId]);
            if ($stmt->rowCount() > 0) {
                flash('success', '🗑️ Artikel berhasil dihapus!');
            } else {
                flash('error', 'Artikel tidak ditemukan atau tidak boleh dihapus.');
            }
        }
        
        // Bulk delete
        elseif ($action === 'bulk_delete' && !empty($_POST['selected_ids'])) {
            $ids = array_map('intval', $_POST['selected_ids']);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("DELETE FROM articles WHERE id IN ($placeholders) AND author_id = ?");
            $stmt->execute(array_merge($ids, [$userId]));
            $deleted = $stmt->rowCount();
            flash('success', "🗑️ $deleted artikel berhasil dihapus!");
        }
        
        // Bulk status change
        elseif (in_array($action, ['bulk_publish', 'bulk_draft']) && !empty($_POST['selected_ids'])) {
            $ids = array_map('intval', $_POST['selected_ids']);
            $newStatus = $action === 'bulk_publish' ? 'published' : 'draft';
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("UPDATE articles SET status = ? WHERE id IN ($placeholders) AND author_id = ?");
            $stmt->execute(array_merge([$newStatus], $ids, [$userId]));
            $updated = $stmt->rowCount();
            $statusLabel = $newStatus === 'published' ? 'dipublikasikan' : 'disimpan sebagai draft';
            flash('success', "✨ $updated artikel berhasil $statusLabel!");
        }
        
    } catch (Exception $e) {
        flash('error', 'Terjadi kesalahan: ' . $e->getMessage());
    }
    
    // Preserve filters saat redirect
    $params = [];
    if (!empty($_GET['q'])) $params[] = 'q=' . urlencode($_GET['q']);
    if (!empty($_GET['status'])) $params[] = 'status=' . urlencode($_GET['status']);
    if (!empty($_GET['category'])) $params[] = 'category=' . urlencode($_GET['category']);
    if (!empty($_GET['sort'])) $params[] = 'sort=' . urlencode($_GET['sort']);
    $query = $params ? '?' . implode('&', $params) : '';
    
    header('Location: ' . url('admin/articles.php' . $query));
    exit;
}

// ============================================
// 🎛️ FILTERS & SORTING
// ============================================
$search     = isset($_GET['q']) ? trim($_GET['q']) : '';
$status     = isset($_GET['status']) ? $_GET['status'] : '';
$category   = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$sortBy     = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$viewMode   = isset($_GET['view']) ? $_GET['view'] : 'table';
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit      = 12;
$offset     = ($page - 1) * $limit;

$allowedSort = ['newest', 'oldest', 'popular', 'az', 'za', 'most_commented'];
if (!in_array($sortBy, $allowedSort)) $sortBy = 'newest';

$allowedView = ['table', 'grid', 'list'];
if (!in_array($viewMode, $allowedView)) $viewMode = 'table';

// ============================================
// 📊 STATISTIK (1 query untuk semua stats)
// ============================================
$stats = [
    'total' => 0,
    'published' => 0,
    'draft' => 0,
    'total_views' => 0,
    'total_comments' => 0,
];
try {
    $stmt = db()->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
            IFNULL(SUM(views), 0) as total_views
        FROM articles WHERE author_id = ?
    ");
    $stmt->execute([$userId]);
    $s = $stmt->fetch();
    if ($s) {
        $stats['total'] = (int)$s['total'];
        $stats['published'] = (int)$s['published'];
        $stats['draft'] = (int)$s['draft'];
        $stats['total_views'] = (int)$s['total_views'];
    }
    
    // Total comments
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM comments c 
        JOIN articles a ON c.article_id = a.id 
        WHERE a.author_id = ?
    ");
    $stmt->execute([$userId]);
    $stats['total_comments'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// ============================================
// 📚 LOAD CATEGORIES (untuk filter dropdown)
// ============================================
$categories = [];
try {
    $categories = db()->query("
        SELECT c.id, c.name, COUNT(a.id) as article_count
        FROM categories c
        LEFT JOIN articles a ON a.category_id = c.id AND a.author_id = $userId
        GROUP BY c.id
        HAVING article_count > 0
        ORDER BY c.name ASC
    ")->fetchAll();
} catch (Exception $e) {}

// ============================================
// 🔍 BUILD QUERY DENGAN FILTERS
// ============================================
$where = "WHERE a.author_id = ?";
$params = [$userId];

if (!empty($search)) {
    $where .= " AND (a.title LIKE ? OR a.excerpt LIKE ? OR a.content LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if (!empty($status) && in_array($status, ['published', 'draft'])) {
    $where .= " AND a.status = ?";
    $params[] = $status;
}

if ($category > 0) {
    $where .= " AND a.category_id = ?";
    $params[] = $category;
}

// Count total (untuk pagination)
$totalFiltered = 0;
try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM articles a $where");
    $stmt->execute($params);
    $totalFiltered = (int)$stmt->fetchColumn();
    $totalPages = ceil($totalFiltered / $limit);
} catch (Exception $e) { $totalPages = 1; }

// Sorting
$orderBy = "ORDER BY ";
switch ($sortBy) {
    case 'oldest': $orderBy .= "a.created_at ASC"; break;
    case 'popular': $orderBy .= "a.views DESC"; break;
    case 'az': $orderBy .= "a.title ASC"; break;
    case 'za': $orderBy .= "a.title DESC"; break;
    case 'most_commented': $orderBy .= "comment_count DESC"; break;
    case 'newest':
    default: $orderBy .= "a.created_at DESC"; break;
}

// Fetch articles dengan pagination
$articles = [];
try {
    $stmt = db()->prepare("
        SELECT a.*, c.name as category_name, c.slug as category_slug,
               u.nama as author_name, u.foto as author_foto,
               (SELECT COUNT(*) FROM comments WHERE article_id = a.id AND status = 'approved') as comment_count
        FROM articles a 
        LEFT JOIN categories c ON a.category_id = c.id 
        LEFT JOIN users u ON a.author_id = u.id
        $where
        $orderBy
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $articles = $stmt->fetchAll();
} catch (Exception $e) {}

$pageTitle = 'Kelola Artikel';
include __DIR__ . '/../includes/admin-header.php';
include __DIR__ . '/../includes/sidebar.php';

// Helper untuk generate URL dengan preserve filters
function buildArticlesUrl($overrides = []) {
    $params = [
        'q' => isset($_GET['q']) ? $_GET['q'] : '',
        'status' => isset($_GET['status']) ? $_GET['status'] : '',
        'category' => isset($_GET['category']) ? $_GET['category'] : '',
        'sort' => isset($_GET['sort']) ? $_GET['sort'] : 'newest',
        'view' => isset($_GET['view']) ? $_GET['view'] : 'table',
        'page' => isset($_GET['page']) ? $_GET['page'] : 1,
    ];
    $params = array_merge($params, $overrides);
    $params = array_filter($params, function($v) { return $v !== '' && $v !== null; });
    
    $query = http_build_query($params);
    return url('admin/articles.php' . ($query ? '?' . $query : ''));
}
?>

<style>
/* ===== ARTICLES MANAGER ULTIMATE STYLES ===== */

/* ===== STATS CARDS ===== */
.articles-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.stat-card {
    background: white;
    padding: 1.2rem 1.5rem;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.3s ease;
    border-left: 4px solid;
    cursor: pointer;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
}
.stat-card.total { border-left-color: #3498db; }
.stat-card.published { border-left-color: #27ae60; }
.stat-card.draft { border-left-color: #f39c12; }
.stat-card.views { border-left-color: #9b59b6; }
.stat-card.comments { border-left-color: #e74c3c; }
.stat-card-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    color: white;
    flex-shrink: 0;
}
.stat-card.total .stat-card-icon { background: linear-gradient(135deg, #3498db, #2980b9); }
.stat-card.published .stat-card-icon { background: linear-gradient(135deg, #27ae60, #229954); }
.stat-card.draft .stat-card-icon { background: linear-gradient(135deg, #f39c12, #e67e22); }
.stat-card.views .stat-card-icon { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
.stat-card.comments .stat-card-icon { background: linear-gradient(135deg, #e74c3c, #c0392b); }
.stat-card-info { flex: 1; min-width: 0; }
.stat-card-info .value {
    font-size: 1.8rem;
    font-weight: 800;
    color: #1e3a5f;
    line-height: 1;
    margin-bottom: 0.2rem;
    display: block;
}
.stat-card-info .label {
    font-size: 0.82rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ===== TOP HEADER ===== */
.articles-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}
.articles-header h2 {
    color: #1e3a5f;
    margin: 0;
    font-size: 1.6rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.articles-header h2 i { color: #f39c12; }
.articles-header h2 .count-badge {
    background: #f39c12;
    color: white;
    padding: 0.2rem 0.7rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 700;
}

.btn-new-article {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    padding: 0.8rem 1.5rem;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
}
.btn-new-article:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.4);
    color: white;
}

/* ===== FILTERS BAR ===== */
.filters-bar {
    background: white;
    padding: 1.2rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    flex-wrap: wrap;
    gap: 0.8rem;
    align-items: center;
}
.filter-search {
    flex: 1;
    min-width: 250px;
    position: relative;
}
.filter-search input {
    width: 100%;
    padding: 0.7rem 1rem 0.7rem 2.5rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.92rem;
    outline: none;
    transition: border 0.3s ease;
}
.filter-search input:focus { border-color: #f39c12; }
.filter-search i {
    position: absolute;
    left: 0.9rem;
    top: 50%;
    transform: translateY(-50%);
    color: #aaa;
}
.filter-search .clear-search {
    position: absolute;
    right: 0.5rem;
    top: 50%;
    transform: translateY(-50%);
    background: #f0f0f0;
    border: none;
    width: 24px; height: 24px;
    border-radius: 50%;
    color: #666;
    cursor: pointer;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
}
.filter-search .clear-search.show { display: flex; }
.filter-search .clear-search:hover { background: #e74c3c; color: white; }

.filter-select {
    padding: 0.7rem 1rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.88rem;
    outline: none;
    background: white;
    cursor: pointer;
    min-width: 140px;
    transition: border 0.3s ease;
    color: #333;
    font-family: inherit;
}
.filter-select:focus { border-color: #f39c12; }

.view-toggle-group {
    display: flex;
    background: #f4f6f9;
    border-radius: 10px;
    padding: 3px;
}
.view-toggle-btn {
    width: 38px; height: 38px;
    border: none;
    background: transparent;
    color: #888;
    border-radius: 7px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}
.view-toggle-btn.active {
    background: white;
    color: #1e3a5f;
    box-shadow: 0 2px 5px rgba(0,0,0,0.08);
}
.view-toggle-btn:hover:not(.active) {
    color: #1e3a5f;
}

.btn-reset-filters {
    background: #fff3cd;
    color: #856404;
    border: none;
    padding: 0.7rem 1.2rem;
    border-radius: 10px;
    font-size: 0.88rem;
    cursor: pointer;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    transition: all 0.2s ease;
}
.btn-reset-filters:hover { background: #ffc107; color: white; }

/* ===== ACTIVE FILTERS CHIPS ===== */
.active-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
}
.filter-chip {
    background: white;
    border: 1px solid #e0e0e0;
    padding: 0.4rem 0.9rem;
    border-radius: 20px;
    font-size: 0.82rem;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: #333;
    text-decoration: none;
    transition: all 0.2s ease;
}
.filter-chip:hover {
    background: #e74c3c;
    color: white;
    border-color: #e74c3c;
}
.filter-chip strong { color: #1e3a5f; }
.filter-chip:hover strong { color: white; }

/* ===== BULK ACTION BAR ===== */
.bulk-action-bar {
    background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
    color: white;
    padding: 0.8rem 1.2rem;
    border-radius: 10px;
    margin-bottom: 1rem;
    display: none;
    align-items: center;
    gap: 1rem;
    animation: slideDown 0.3s ease;
    flex-wrap: wrap;
}
.bulk-action-bar.show { display: flex; }
.bulk-action-bar .count {
    background: rgba(255,255,255,0.2);
    padding: 0.3rem 0.8rem;
    border-radius: 15px;
    font-weight: 700;
    font-size: 0.88rem;
}
.bulk-actions-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.bulk-btn {
    background: rgba(255,255,255,0.15);
    color: white;
    border: 1px solid rgba(255,255,255,0.25);
    padding: 0.5rem 1rem;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    transition: all 0.2s ease;
    font-family: inherit;
}
.bulk-btn:hover { background: rgba(255,255,255,0.25); }
.bulk-btn.danger:hover { background: #e74c3c; border-color: #e74c3c; }
.bulk-cancel {
    margin-left: auto;
    background: transparent;
    color: white;
    border: none;
    cursor: pointer;
    padding: 0.5rem;
    font-size: 0.88rem;
    opacity: 0.8;
    transition: opacity 0.2s ease;
}
.bulk-cancel:hover { opacity: 1; }

@keyframes slideDown {
    from { transform: translateY(-10px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* ===== TABLE VIEW ===== */
.articles-table-wrap {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.articles-table {
    width: 100%;
    border-collapse: collapse;
}
.articles-table thead {
    background: #f8f9fa;
    border-bottom: 2px solid #e9ecef;
}
.articles-table th {
    padding: 0.9rem 1rem;
    text-align: left;
    font-weight: 700;
    color: #1e3a5f;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.articles-table th.sortable {
    cursor: pointer;
    transition: color 0.2s ease;
    user-select: none;
}
.articles-table th.sortable:hover { color: #f39c12; }
.articles-table th.sortable.active { color: #f39c12; }
.articles-table th.sortable i { margin-left: 0.3rem; font-size: 0.7rem; }
.articles-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s ease;
}
.articles-table tbody tr:hover { background: #f8f9fa; }
.articles-table tbody tr.selected { background: #fff9e6 !important; }
.articles-table td {
    padding: 1rem;
    vertical-align: middle;
    font-size: 0.9rem;
    color: #333;
}
.article-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #f39c12;
}
.article-thumb {
    width: 60px;
    height: 45px;
    border-radius: 6px;
    object-fit: cover;
    background: #f4f6f9;
    flex-shrink: 0;
}
.article-title-cell {
    display: flex;
    gap: 0.8rem;
    align-items: center;
    min-width: 280px;
}
.article-title-info { flex: 1; min-width: 0; }
.article-title-text {
    font-weight: 600;
    color: #1e3a5f;
    font-size: 0.95rem;
    line-height: 1.3;
    margin-bottom: 0.2rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.article-title-text a {
    color: inherit;
    text-decoration: none;
    transition: color 0.2s ease;
}
.article-title-text a:hover { color: #f39c12; }
.article-excerpt {
    font-size: 0.78rem;
    color: #888;
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.category-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    background: #f4f6f9;
    color: #1e3a5f;
    padding: 0.3rem 0.7rem;
    border-radius: 15px;
    font-size: 0.78rem;
    font-weight: 500;
    white-space: nowrap;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.3rem 0.8rem;
    border-radius: 15px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.status-pill.published { background: #d4edda; color: #155724; }
.status-pill.draft { background: #fff3cd; color: #856404; }
.status-pill::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
}
.stats-cell {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    font-size: 0.82rem;
}
.stats-cell span {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}
.stats-cell i { color: #888; width: 12px; }
.date-cell {
    white-space: nowrap;
    font-size: 0.82rem;
    color: #666;
}
.date-cell .relative {
    display: block;
    font-size: 0.72rem;
    color: #999;
    margin-top: 0.15rem;
}
.actions-cell {
    display: flex;
    gap: 0.3rem;
    justify-content: flex-end;
    white-space: nowrap;
}
.action-btn {
    width: 32px; height: 32px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    color: white;
    transition: all 0.2s ease;
    font-size: 0.82rem;
}
.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
}
.action-btn.edit { background: #3498db; }
.action-btn.view { background: #27ae60; }
.action-btn.delete { background: #e74c3c; }
.action-btn.more {
    background: #95a5a6;
    position: relative;
}

/* Action dropdown */
.action-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    background: white;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    min-width: 180px;
    padding: 0.3rem;
    margin-top: 0.3rem;
    z-index: 100;
    display: none;
}
.action-dropdown.show { display: block; }
.action-dropdown a, .action-dropdown button {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.6rem 0.8rem;
    color: #333;
    text-decoration: none;
    border-radius: 6px;
    font-size: 0.85rem;
    width: 100%;
    background: transparent;
    border: none;
    cursor: pointer;
    font-family: inherit;
    text-align: left;
    transition: background 0.15s ease;
}
.action-dropdown a:hover, .action-dropdown button:hover { background: #f4f6f9; }
.action-dropdown .danger { color: #e74c3c; }
.action-dropdown .danger:hover { background: #ffe5e5; }
.action-dropdown i { width: 16px; color: #888; }
.action-dropdown .danger i { color: #e74c3c; }

/* ===== GRID VIEW ===== */
.articles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.2rem;
}
.article-grid-card {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    border: 2px solid transparent;
}
.article-grid-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
    border-color: #f39c12;
}
.article-grid-card.selected {
    border-color: #f39c12;
    background: #fffbf0;
}
.grid-card-image {
    height: 180px;
    background: #f4f6f9;
    position: relative;
    background-size: cover;
    background-position: center;
}
.grid-card-image::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, transparent 50%, rgba(0,0,0,0.3));
}
.grid-card-checkbox {
    position: absolute;
    top: 10px; left: 10px;
    z-index: 2;
    width: 22px; height: 22px;
    accent-color: #f39c12;
}
.grid-card-status {
    position: absolute;
    top: 10px; right: 10px;
    z-index: 2;
}
.grid-card-body {
    padding: 1.2rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.grid-card-category {
    margin-bottom: 0.5rem;
}
.grid-card-title {
    font-size: 1.05rem;
    color: #1e3a5f;
    font-weight: 700;
    line-height: 1.3;
    margin-bottom: 0.5rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.grid-card-title a { color: inherit; text-decoration: none; }
.grid-card-title a:hover { color: #f39c12; }
.grid-card-excerpt {
    color: #666;
    font-size: 0.85rem;
    line-height: 1.5;
    margin-bottom: 1rem;
    flex: 1;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.grid-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 0.8rem;
    border-top: 1px solid #f0f0f0;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.grid-card-stats {
    display: flex;
    gap: 0.8rem;
    font-size: 0.78rem;
    color: #888;
}
.grid-card-stats span {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}
.grid-card-actions {
    display: flex;
    gap: 0.3rem;
}

/* ===== LIST VIEW ===== */
.articles-list {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.article-list-item {
    display: grid;
    grid-template-columns: auto 80px 1fr auto auto auto;
    gap: 1rem;
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s ease;
    align-items: center;
}
.article-list-item:last-child { border-bottom: none; }
.article-list-item:hover { background: #f8f9fa; }
.article-list-item.selected { background: #fff9e6; }
.list-item-title {
    font-weight: 600;
    color: #1e3a5f;
    font-size: 0.95rem;
    margin-bottom: 0.2rem;
}
.list-item-title a { color: inherit; text-decoration: none; }
.list-item-title a:hover { color: #f39c12; }
.list-item-meta {
    display: flex;
    gap: 0.8rem;
    font-size: 0.78rem;
    color: #888;
    flex-wrap: wrap;
}
.list-item-meta span {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

/* ===== PAGINATION ===== */
.pagination-wrap {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.2rem;
    background: white;
    border-radius: 12px;
    margin-top: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    flex-wrap: wrap;
    gap: 1rem;
}
.pagination-info {
    color: #666;
    font-size: 0.88rem;
}
.pagination-info strong { color: #1e3a5f; }
.pagination-buttons {
    display: flex;
    gap: 0.3rem;
    flex-wrap: wrap;
}
.page-btn {
    min-width: 38px;
    height: 38px;
    padding: 0 0.8rem;
    border: 1px solid #e0e0e0;
    background: white;
    color: #666;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.88rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}
.page-btn:hover:not(.disabled):not(.active) {
    background: #1e3a5f;
    color: white;
    border-color: #1e3a5f;
}
.page-btn.active {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border-color: transparent;
}
.page-btn.disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.page-ellipsis {
    padding: 0 0.5rem;
    color: #999;
}

/* ===== EMPTY STATE ===== */
.empty-state-articles {
    background: white;
    padding: 4rem 2rem;
    border-radius: 15px;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.empty-state-articles .empty-icon {
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
.empty-state-articles h3 {
    color: #1e3a5f;
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}
.empty-state-articles p {
    color: #666;
    margin-bottom: 2rem;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}
.empty-features {
    display: flex;
    gap: 2rem;
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: 2rem;
}
.empty-feature {
    text-align: center;
    max-width: 150px;
}
.empty-feature i {
    font-size: 2rem;
    color: #f39c12;
    margin-bottom: 0.5rem;
    display: block;
}
.empty-feature strong {
    display: block;
    color: #1e3a5f;
    margin-bottom: 0.2rem;
    font-size: 0.92rem;
}
.empty-feature small {
    color: #888;
    font-size: 0.78rem;
}

/* ===== DARK MODE ===== */
html[data-theme="dark"] .stat-card,
html[data-theme="dark"] .filters-bar,
html[data-theme="dark"] .articles-table-wrap,
html[data-theme="dark"] .articles-list,
html[data-theme="dark"] .article-grid-card,
html[data-theme="dark"] .pagination-wrap,
html[data-theme="dark"] .empty-state-articles {
    background: #1e2638;
}
html[data-theme="dark"] .articles-table thead {
    background: #16203a;
}
html[data-theme="dark"] .articles-table tbody tr {
    border-color: #2a3550;
}
html[data-theme="dark"] .articles-table tbody tr:hover {
    background: #25304a;
}
html[data-theme="dark"] .articles-table th,
html[data-theme="dark"] .articles-header h2,
html[data-theme="dark"] .stat-card-info .value,
html[data-theme="dark"] .article-title-text,
html[data-theme="dark"] .grid-card-title,
html[data-theme="dark"] .list-item-title,
html[data-theme="dark"] .empty-state-articles h3 {
    color: #e5e8ec;
}
html[data-theme="dark"] .filter-select,
html[data-theme="dark"] .filter-search input {
    background: #16203a;
    border-color: #2a3550;
    color: #e5e8ec;
}
html[data-theme="dark"] .article-list-item {
    border-color: #2a3550;
}
html[data-theme="dark"] .article-list-item:hover {
    background: #25304a;
}
html[data-theme="dark"] .page-btn {
    background: #16203a;
    border-color: #2a3550;
    color: #e5e8ec;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 992px) {
    .article-title-cell { min-width: 200px; }
    .articles-table-wrap { overflow-x: auto; }
}
@media (max-width: 768px) {
    .articles-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .articles-grid {
        grid-template-columns: 1fr;
    }
    .article-list-item {
        grid-template-columns: auto 1fr auto;
    }
    .article-list-item > :nth-child(2) { display: none; }
    .article-list-item > :nth-child(4) { display: none; }
    .article-list-item > :nth-child(5) { display: none; }
}
@media (max-width: 576px) {
    .articles-stats {
        grid-template-columns: 1fr;
    }
    .filters-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .filter-search { min-width: auto; }
    .filter-select { width: 100%; }
    .pagination-wrap {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
    }
    .pagination-buttons { justify-content: center; }
}
</style>

<main class="admin-main">
    
    <!-- ===== HEADER ===== -->
    <div class="articles-header">
        <h2>
            <i class="fas fa-newspaper"></i> Kelola Artikel 
            <span class="count-badge"><?php echo $stats['total']; ?></span>
        </h2>
        <a href="<?php echo url('admin/article-edit.php'); ?>" class="btn-new-article">
            <i class="fas fa-plus"></i> Tulis Artikel Baru
        </a>
    </div>

    <!-- ===== STATS CARDS ===== -->
    <div class="articles-stats">
        <a href="<?php echo buildArticlesUrl(['status' => '', 'page' => 1]); ?>" class="stat-card total" style="text-decoration:none;">
            <div class="stat-card-icon"><i class="fas fa-newspaper"></i></div>
            <div class="stat-card-info">
                <span class="value"><?php echo number_format($stats['total']); ?></span>
                <span class="label">Total Artikel</span>
            </div>
        </a>
        <a href="<?php echo buildArticlesUrl(['status' => 'published', 'page' => 1]); ?>" class="stat-card published" style="text-decoration:none;">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-info">
                <span class="value"><?php echo number_format($stats['published']); ?></span>
                <span class="label">Published</span>
            </div>
        </a>
        <a href="<?php echo buildArticlesUrl(['status' => 'draft', 'page' => 1]); ?>" class="stat-card draft" style="text-decoration:none;">
            <div class="stat-card-icon"><i class="fas fa-file-alt"></i></div>
            <div class="stat-card-info">
                <span class="value"><?php echo number_format($stats['draft']); ?></span>
                <span class="label">Draft</span>
            </div>
        </a>
        <div class="stat-card views">
            <div class="stat-card-icon"><i class="fas fa-eye"></i></div>
            <div class="stat-card-info">
                <span class="value"><?php echo number_format($stats['total_views']); ?></span>
                <span class="label">Total Views</span>
            </div>
        </div>
        <div class="stat-card comments">
            <div class="stat-card-icon"><i class="fas fa-comments"></i></div>
            <div class="stat-card-info">
                <span class="value"><?php echo number_format($stats['total_comments']); ?></span>
                <span class="label">Total Komentar</span>
            </div>
        </div>
    </div>

    <!-- ===== FILTERS BAR ===== -->
    <div class="filters-bar">
        <form method="GET" action="<?php echo url('admin/articles.php'); ?>" class="filter-search">
            <i class="fas fa-search"></i>
            <input type="text" 
                   name="q" 
                   placeholder="Cari judul, excerpt, atau konten..." 
                   value="<?php echo htmlspecialchars($search); ?>"
                   id="searchInput">
            <?php if (!empty($search)): ?>
                <button type="button" class="clear-search show" onclick="clearSearch()">
                    <i class="fas fa-times"></i>
                </button>
            <?php endif; ?>
            
            <!-- Hidden filters to preserve -->
            <?php if ($status): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>"><?php endif; ?>
            <?php if ($category): ?><input type="hidden" name="category" value="<?php echo $category; ?>"><?php endif; ?>
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewMode); ?>">
        </form>

        <select class="filter-select" onchange="applyFilter('status', this.value)">
            <option value="">Semua Status</option>
            <option value="published" <?php echo $status === 'published' ? 'selected' : ''; ?>>✅ Published</option>
            <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>📝 Draft</option>
        </select>

        <?php if (!empty($categories)): ?>
            <select class="filter-select" onchange="applyFilter('category', this.value)">
                <option value="">Semua Kategori</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?> (<?php echo $cat['article_count']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <select class="filter-select" onchange="applyFilter('sort', this.value)">
            <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>🆕 Terbaru</option>
            <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>📅 Terlama</option>
            <option value="popular" <?php echo $sortBy === 'popular' ? 'selected' : ''; ?>>🔥 Terpopuler</option>
            <option value="most_commented" <?php echo $sortBy === 'most_commented' ? 'selected' : ''; ?>>💬 Banyak Komentar</option>
            <option value="az" <?php echo $sortBy === 'az' ? 'selected' : ''; ?>>🔤 A - Z</option>
            <option value="za" <?php echo $sortBy === 'za' ? 'selected' : ''; ?>>🔤 Z - A</option>
        </select>

        <div class="view-toggle-group">
            <button type="button" class="view-toggle-btn <?php echo $viewMode === 'table' ? 'active' : ''; ?>" 
                    onclick="applyFilter('view', 'table')" title="Tampilan Tabel">
                <i class="fas fa-table"></i>
            </button>
            <button type="button" class="view-toggle-btn <?php echo $viewMode === 'grid' ? 'active' : ''; ?>" 
                    onclick="applyFilter('view', 'grid')" title="Tampilan Grid">
                <i class="fas fa-th-large"></i>
            </button>
            <button type="button" class="view-toggle-btn <?php echo $viewMode === 'list' ? 'active' : ''; ?>" 
                    onclick="applyFilter('view', 'list')" title="Tampilan List">
                <i class="fas fa-list"></i>
            </button>
        </div>

        <?php if (!empty($search) || !empty($status) || $category > 0): ?>
            <a href="<?php echo url('admin/articles.php'); ?>" class="btn-reset-filters" title="Reset semua filter">
                <i class="fas fa-redo"></i> Reset
            </a>
        <?php endif; ?>
    </div>

    <!-- ===== ACTIVE FILTERS CHIPS ===== -->
    <?php if (!empty($search) || !empty($status) || $category > 0): ?>
        <div class="active-filters">
            <?php if (!empty($search)): ?>
                <a href="<?php echo buildArticlesUrl(['q' => '', 'page' => 1]); ?>" class="filter-chip">
                    🔍 Search: <strong><?php echo htmlspecialchars($search); ?></strong>
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
            <?php if (!empty($status)): ?>
                <a href="<?php echo buildArticlesUrl(['status' => '', 'page' => 1]); ?>" class="filter-chip">
                    📌 Status: <strong><?php echo ucfirst($status); ?></strong>
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
            <?php if ($category > 0): 
                $catName = '';
                foreach ($categories as $c) { if ($c['id'] == $category) { $catName = $c['name']; break; } }
            ?>
                <a href="<?php echo buildArticlesUrl(['category' => '', 'page' => 1]); ?>" class="filter-chip">
                    🏷️ Kategori: <strong><?php echo htmlspecialchars($catName); ?></strong>
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ===== BULK ACTION BAR ===== -->
    <div class="bulk-action-bar" id="bulkActionBar">
        <span class="count"><span id="selectedCount">0</span> dipilih</span>
        <div class="bulk-actions-buttons">
            <form method="POST" class="bulk-form" data-action="bulk_publish" style="display:inline;">
                <input type="hidden" name="action" value="bulk_publish">
                <input type="hidden" name="selected_ids" class="selected-ids-input">
                <button type="submit" class="bulk-btn">
                    <i class="fas fa-check-circle"></i> Publish
                </button>
            </form>
            <form method="POST" class="bulk-form" data-action="bulk_draft" style="display:inline;">
                <input type="hidden" name="action" value="bulk_draft">
                <input type="hidden" name="selected_ids" class="selected-ids-input">
                <button type="submit" class="bulk-btn">
                    <i class="fas fa-file-alt"></i> Jadikan Draft
                </button>
            </form>
            <form method="POST" class="bulk-form" data-action="bulk_delete" style="display:inline;">
                <input type="hidden" name="action" value="bulk_delete">
                <input type="hidden" name="selected_ids" class="selected-ids-input">
                <button type="submit" class="bulk-btn danger">
                    <i class="fas fa-trash"></i> Hapus
                </button>
            </form>
        </div>
        <button type="button" class="bulk-cancel" onclick="clearSelection()">
            <i class="fas fa-times"></i> Batal
        </button>
    </div>

    <!-- ===== CONTENT AREA ===== -->
    <?php if (empty($articles)): ?>
        <div class="empty-state-articles">
            <div class="empty-icon">
                <i class="fas fa-<?php echo (!empty($search) || !empty($status) || $category > 0) ? 'search' : 'newspaper'; ?>"></i>
            </div>
            <h3>
                <?php if (!empty($search) || !empty($status) || $category > 0): ?>
                    Tidak Ada Artikel Ditemukan
                <?php else: ?>
                    Belum Ada Artikel
                <?php endif; ?>
            </h3>
            <p>
                <?php if (!empty($search) || !empty($status) || $category > 0): ?>
                    Coba ubah filter atau kata kunci pencarian Anda.
                <?php else: ?>
                    Mulai menulis artikel pertama Anda dan bagikan ilmu dengan dunia!
                <?php endif; ?>
            </p>
            
            <?php if (empty($search) && empty($status) && $category == 0): ?>
                <div class="empty-features">
                    <div class="empty-feature">
                        <i class="fas fa-pen-fancy"></i>
                        <strong>Rich Editor</strong>
                        <small>CKEditor 5 dengan formatting lengkap</small>
                    </div>
                    <div class="empty-feature">
                        <i class="fas fa-image"></i>
                        <strong>Gambar & Media</strong>
                        <small>Upload gambar dan embed media</small>
                    </div>
                    <div class="empty-feature">
                        <i class="fas fa-search"></i>
                        <strong>SEO Ready</strong>
                        <small>Optimasi otomatis untuk mesin pencari</small>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($search) || !empty($status) || $category > 0): ?>
                <a href="<?php echo url('admin/articles.php'); ?>" class="btn-new-article">
                    <i class="fas fa-redo"></i> Reset Filter
                </a>
            <?php else: ?>
                <a href="<?php echo url('admin/article-edit.php'); ?>" class="btn-new-article">
                    <i class="fas fa-plus"></i> Tulis Artikel Pertama
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <!-- Info bar -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
            <div style="color:#666;font-size:0.88rem;">
                Menampilkan <strong><?php echo count($articles); ?></strong> dari <strong><?php echo $totalFiltered; ?></strong> artikel
                <?php if ($totalPages > 1): ?>
                    (Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?>)
                <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <input type="checkbox" id="selectAllTop" class="article-checkbox" title="Pilih semua">
                <label for="selectAllTop" style="font-size:0.85rem;color:#666;cursor:pointer;">Pilih semua</label>
            </div>
        </div>

        <!-- ===== TABLE VIEW ===== -->
        <?php if ($viewMode === 'table'): ?>
            <div class="articles-table-wrap">
                <table class="articles-table">
                    <thead>
                        <tr>
                            <th style="width:40px;"></th>
                            <th style="width:80px;">Thumb</th>
                            <th>Judul Artikel</th>
                            <th>Kategori</th>
                            <th>Stats</th>
                            <th>Status</th>
                            <th>Tanggal</th>
                            <th style="width:120px;text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($articles as $a): 
                            $thumbUrl = $a['featured_image'] ?: 'https://via.placeholder.com/60x45/e9ecef/888?text=No+Img';
                        ?>
                            <tr data-id="<?php echo $a['id']; ?>">
                                <td>
                                    <input type="checkbox" class="article-checkbox article-select" 
                                           value="<?php echo $a['id']; ?>">
                                </td>
                                <td>
                                    <img src="<?php echo htmlspecialchars($thumbUrl); ?>" 
                                         alt="" 
                                         class="article-thumb"
                                         onerror="this.src='https://via.placeholder.com/60x45/e9ecef/888?text=No+Img'">
                                </td>
                                <td>
                                    <div class="article-title-cell" style="gap:0;">
                                        <div class="article-title-info">
                                            <div class="article-title-text">
                                                <a href="<?php echo url('admin/article-edit.php?id=' . $a['id']); ?>">
                                                    <?php echo htmlspecialchars($a['title']); ?>
                                                </a>
                                            </div>
                                            <div class="article-excerpt">
                                                <?php echo htmlspecialchars($a['excerpt'] ?: excerpt(strip_tags($a['content']), 80)); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($a['category_name']): ?>
                                        <span class="category-chip">
                                            <i class="fas fa-tag"></i>
                                            <?php echo htmlspecialchars($a['category_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#999;font-size:0.82rem;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="stats-cell">
                                        <span><i class="fas fa-eye"></i> <?php echo number_format($a['views']); ?></span>
                                        <span><i class="fas fa-comments"></i> <?php echo (int)$a['comment_count']; ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-pill <?php echo $a['status']; ?>">
                                        <?php echo $a['status'] === 'published' ? 'Published' : 'Draft'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="date-cell">
                                        <?php echo date('d M Y', strtotime($a['created_at'])); ?>
                                        <span class="relative"><?php echo timeAgo($a['created_at']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="actions-cell">
                                        <a href="<?php echo url('admin/article-edit.php?id=' . $a['id']); ?>" 
                                           class="action-btn edit" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($a['status'] === 'published'): ?>
                                            <a href="<?php echo url('article.php?slug=' . $a['slug']); ?>" 
                                               class="action-btn view" target="_blank" title="Lihat">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirmDelete(event, '<?php echo htmlspecialchars(addslashes($a['title'])); ?>')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="article_id" value="<?php echo $a['id']; ?>">
                                            <button type="submit" class="action-btn delete" title="Hapus">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- ===== GRID VIEW ===== -->
        <?php if ($viewMode === 'grid'): ?>
            <div class="articles-grid">
                <?php foreach ($articles as $a): 
                    $imgUrl = $a['featured_image'] ?: 'https://via.placeholder.com/400x200/e9ecef/888?text=No+Image';
                ?>
                    <div class="article-grid-card" data-id="<?php echo $a['id']; ?>">
                        <div class="grid-card-image" style="background-image: url('<?php echo htmlspecialchars($imgUrl); ?>');">
                            <input type="checkbox" class="article-checkbox article-select grid-card-checkbox" 
                                   value="<?php echo $a['id']; ?>">
                            <div class="grid-card-status">
                                <span class="status-pill <?php echo $a['status']; ?>">
                                    <?php echo $a['status'] === 'published' ? 'Published' : 'Draft'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="grid-card-body">
                            <?php if ($a['category_name']): ?>
                                <div class="grid-card-category">
                                    <span class="category-chip">
                                        <i class="fas fa-tag"></i>
                                        <?php echo htmlspecialchars($a['category_name']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <h3 class="grid-card-title">
                                <a href="<?php echo url('admin/article-edit.php?id=' . $a['id']); ?>">
                                    <?php echo htmlspecialchars($a['title']); ?>
                                </a>
                            </h3>
                            <p class="grid-card-excerpt">
                                <?php echo htmlspecialchars($a['excerpt'] ?: excerpt(strip_tags($a['content']), 100)); ?>
                            </p>
                            <div class="grid-card-footer">
                                <div class="grid-card-stats">
                                    <span><i class="fas fa-eye"></i> <?php echo number_format($a['views']); ?></span>
                                    <span><i class="fas fa-comments"></i> <?php echo (int)$a['comment_count']; ?></span>
                                    <span><i class="fas fa-calendar"></i> <?php echo date('d M', strtotime($a['created_at'])); ?></span>
                                </div>
                                <div class="grid-card-actions">
                                    <a href="<?php echo url('admin/article-edit.php?id=' . $a['id']); ?>" 
                                       class="action-btn edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($a['status'] === 'published'): ?>
                                        <a href="<?php echo url('article.php?slug=' . $a['slug']); ?>" 
                                           class="action-btn view" target="_blank" title="Lihat">
                                            <i class="fas fa-external-link-alt"></i>
                                        </a>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirmDelete(event, '<?php echo htmlspecialchars(addslashes($a['title'])); ?>')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="article_id" value="<?php echo $a['id']; ?>">
                                        <button type="submit" class="action-btn delete" title="Hapus">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ===== LIST VIEW ===== -->
        <?php if ($viewMode === 'list'): ?>
            <div class="articles-list">
                <?php foreach ($articles as $a): ?>
                    <div class="article-list-item" data-id="<?php echo $a['id']; ?>">
                        <input type="checkbox" class="article-checkbox article-select" value="<?php echo $a['id']; ?>">
                        <img src="<?php echo htmlspecialchars($a['featured_image'] ?: 'https://via.placeholder.com/80x60/e9ecef/888?text=No'); ?>" 
                             alt="" class="article-thumb"
                             onerror="this.src='https://via.placeholder.com/80x60/e9ecef/888?text=No+Img'">
                        <div>
                            <div class="list-item-title">
                                <a href="<?php echo url('admin/article-edit.php?id=' . $a['id']); ?>">
                                    <?php echo htmlspecialchars($a['title']); ?>
                                </a>
                            </div>
                            <div class="list-item-meta">
                                <?php if ($a['category_name']): ?>
                                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($a['category_name']); ?></span>
                                <?php endif; ?>
                                <span><i class="fas fa-calendar"></i> <?php echo date('d M Y', strtotime($a['created_at'])); ?></span>
                                <span><i class="fas fa-eye"></i> <?php echo number_format($a['views']); ?></span>
                                <span><i class="fas fa-comments"></i> <?php echo (int)$a['comment_count']; ?></span>
                            </div>
                        </div>
                        <span class="status-pill <?php echo $a['status']; ?>">
                            <?php echo $a['status'] === 'published' ? 'Published' : 'Draft'; ?>
                        </span>
                        <div class="date-cell" style="font-size:0.78rem;">
                            <?php echo timeAgo($a['created_at']); ?>
                        </div>
                        <div class="actions-cell">
                            <a href="<?php echo url('admin/article-edit.php?id=' . $a['id']); ?>" 
                               class="action-btn edit" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if ($a['status'] === 'published'): ?>
                                <a href="<?php echo url('article.php?slug=' . $a['slug']); ?>" 
                                   class="action-btn view" target="_blank" title="Lihat">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirmDelete(event, '<?php echo htmlspecialchars(addslashes($a['title'])); ?>')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="article_id" value="<?php echo $a['id']; ?>">
                                <button type="submit" class="action-btn delete" title="Hapus">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ===== PAGINATION ===== -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination-wrap">
                <div class="pagination-info">
                    Menampilkan <strong><?php echo (($page - 1) * $limit) + 1; ?>-<?php echo min($page * $limit, $totalFiltered); ?></strong> 
                    dari <strong><?php echo $totalFiltered; ?></strong> artikel
                </div>
                <div class="pagination-buttons">
                    <?php
                    // First & Prev
                    if ($page > 1): ?>
                        <a href="<?php echo buildArticlesUrl(['page' => 1]); ?>" class="page-btn" title="Halaman pertama">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="<?php echo buildArticlesUrl(['page' => $page - 1]); ?>" class="page-btn" title="Sebelumnya">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-btn disabled"><i class="fas fa-angle-double-left"></i></span>
                        <span class="page-btn disabled"><i class="fas fa-angle-left"></i></span>
                    <?php endif;
                    
                    // Page numbers with ellipsis
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    if ($start > 1): ?>
                        <a href="<?php echo buildArticlesUrl(['page' => 1]); ?>" class="page-btn">1</a>
                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif;
                    endif;
                    
                    for ($i = $start; $i <= $end; $i++): 
                        if ($i === $page): ?>
                            <span class="page-btn active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo buildArticlesUrl(['page' => $i]); ?>" class="page-btn"><?php echo $i; ?></a>
                        <?php endif;
                    endfor;
                    
                    if ($end < $totalPages): 
                        if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                        <a href="<?php echo buildArticlesUrl(['page' => $totalPages]); ?>" class="page-btn"><?php echo $totalPages; ?></a>
                    <?php endif;
                    
                    // Next & Last
                    if ($page < $totalPages): ?>
                        <a href="<?php echo buildArticlesUrl(['page' => $page + 1]); ?>" class="page-btn" title="Selanjutnya">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="<?php echo buildArticlesUrl(['page' => $totalPages]); ?>" class="page-btn" title="Halaman terakhir">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-btn disabled"><i class="fas fa-angle-right"></i></span>
                        <span class="page-btn disabled"><i class="fas fa-angle-double-right"></i></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</main>

<script>
(function() {
    'use strict';

    // ===== FILTER HANDLER =====
    window.applyFilter = function(key, value) {
        var url = new URL(window.location.href);
        if (value === '' || value === null) {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
    };

    window.clearSearch = function() {
        document.getElementById('searchInput').value = '';
        applyFilter('q', '');
    };

    // ===== SEARCH DEBOUNCE (auto submit setelah 500ms) =====
    var searchInput = document.getElementById('searchInput');
    var searchTimer = null;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var val = this.value;
            var clearBtn = this.parentElement.querySelector('.clear-search');
            if (clearBtn) {
                clearBtn.classList.toggle('show', val.length > 0);
            }
            
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() {
                applyFilter('q', val);
            }, 600);
        });
        
        // Submit on Enter
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                clearTimeout(searchTimer);
                this.parentElement.submit();
            }
        });
    }

    // ===== CHECKBOX SELECTION =====
    var selectCheckboxes = document.querySelectorAll('.article-select');
    var selectAllTop = document.getElementById('selectAllTop');
    var bulkBar = document.getElementById('bulkActionBar');
    var selectedCountEl = document.getElementById('selectedCount');

    function updateBulkBar() {
        var selected = document.querySelectorAll('.article-select:checked');
        var count = selected.length;
        
        if (selectedCountEl) selectedCountEl.textContent = count;
        if (bulkBar) {
            bulkBar.classList.toggle('show', count > 0);
        }
        
        // Update all selected-ids-input
        var ids = Array.from(selected).map(function(cb) { return cb.value; });
        document.querySelectorAll('.selected-ids-input').forEach(function(input) {
            // Untuk multiple IDs, pakai hidden input array
            input.value = ids.join(',');
        });
        
        // Update form untuk bulk actions (convert comma to array via hidden inputs)
        document.querySelectorAll('.bulk-form').forEach(function(form) {
            // Hapus hidden inputs lama
            form.querySelectorAll('input[name="selected_ids[]"]').forEach(function(el) { el.remove(); });
            // Tambah baru
            ids.forEach(function(id) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'selected_ids[]';
                inp.value = id;
                form.appendChild(inp);
            });
        });
        
        // Update row selection styling
        document.querySelectorAll('tr[data-id], .article-grid-card[data-id], .article-list-item[data-id]').forEach(function(row) {
            var id = row.getAttribute('data-id');
            var cb = document.querySelector('.article-select[value="' + id + '"]');
            if (cb) {
                row.classList.toggle('selected', cb.checked);
            }
        });
        
        // Update select all
        if (selectAllTop) {
            selectAllTop.checked = count === selectCheckboxes.length && count > 0;
            selectAllTop.indeterminate = count > 0 && count < selectCheckboxes.length;
        }
    }

    selectCheckboxes.forEach(function(cb) {
        cb.addEventListener('change', updateBulkBar);
    });

    if (selectAllTop) {
        selectAllTop.addEventListener('change', function() {
            var checked = this.checked;
            selectCheckboxes.forEach(function(cb) { cb.checked = checked; });
            updateBulkBar();
        });
    }

    window.clearSelection = function() {
        selectCheckboxes.forEach(function(cb) { cb.checked = false; });
        if (selectAllTop) selectAllTop.checked = false;
        updateBulkBar();
    };

    // ===== DELETE CONFIRMATION =====
    window.confirmDelete = function(e, title) {
        e.preventDefault();
        var form = e.target;
        
        if (typeof UI !== 'undefined' && UI.confirm) {
            UI.confirm(
                'Apakah Anda yakin ingin menghapus artikel "' + title + '"? Tindakan ini tidak dapat dibatalkan.',
                function() {
                    if (typeof UI !== 'undefined') UI.showLoading('Menghapus artikel...');
                    form.submit();
                },
                { danger: true, icon: '🗑️', title: 'Hapus Artikel?' }
            );
        } else {
            if (confirm('Yakin hapus artikel "' + title + '"?')) {
                form.submit();
            }
        }
        return false;
    };

    // ===== BULK FORM CONFIRMATION =====
    document.querySelectorAll('.bulk-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var action = form.getAttribute('data-action');
            var ids = form.querySelectorAll('input[name="selected_ids[]"]');
            var count = ids.length;
            
            if (count === 0) {
                e.preventDefault();
                if (typeof UI !== 'undefined') UI.warning('Pilih minimal 1 artikel!', 'Tidak Ada Selection');
                return false;
            }
            
            var messages = {
                'bulk_publish': 'Publikasikan ' + count + ' artikel?',
                'bulk_draft': 'Jadikan ' + count + ' artikel sebagai draft?',
                'bulk_delete': 'Hapus ' + count + ' artikel? Tindakan ini tidak dapat dibatalkan!'
            };
            
            e.preventDefault();
            var isDanger = action === 'bulk_delete';
            
            if (typeof UI !== 'undefined' && UI.confirm) {
                UI.confirm(
                    messages[action],
                    function() {
                        if (typeof UI !== 'undefined') UI.showLoading('Memproses...');
                        form.submit();
                    },
                    { danger: isDanger, icon: isDanger ? '🗑️' : '⚠️' }
                );
            } else {
                if (confirm(messages[action])) form.submit();
            }
            return false;
        });
    });

    // ===== BULK IDS ARRAY HANDLING =====
    // Konversi comma-separated ke array saat submit
    document.querySelectorAll('.bulk-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var singleInput = form.querySelector('input[name="selected_ids"]:not([name="selected_ids[]"])');
            if (singleInput && singleInput.value) {
                e.preventDefault();
                var ids = singleInput.value.split(',');
                singleInput.remove();
                ids.forEach(function(id) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'selected_ids[]';
                    inp.value = id;
                    form.appendChild(inp);
                });
                form.submit();
            }
        });
    });

    // ===== KEYBOARD SHORTCUTS =====
    document.addEventListener('keydown', function(e) {
        // Ctrl+F atau / : Focus search
        if (e.key === '/' || ((e.ctrlKey || e.metaKey) && e.key === 'f' && !e.shiftKey)) {
            if (!e.target.matches('input, textarea, select') || e.key === '/') {
                e.preventDefault();
                if (searchInput) searchInput.focus();
            }
        }
        // Ctrl+N : New article
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'n') {
            if (!e.target.matches('input, textarea')) {
                e.preventDefault();
                window.location.href = '<?php echo url('admin/article-edit.php'); ?>';
            }
        }
    });

    // ===== ROW CLICK TO SELECT (optional) =====
    document.querySelectorAll('tr[data-id], .article-grid-card[data-id], .article-list-item[data-id]').forEach(function(row) {
        row.addEventListener('click', function(e) {
            // Skip jika click di link, button, atau checkbox
            if (e.target.closest('a, button, .article-checkbox, form')) return;
            var id = this.getAttribute('data-id');
            var cb = document.querySelector('.article-select[value="' + id + '"]');
            if (cb) {
                cb.checked = !cb.checked;
                cb.dispatchEvent(new Event('change'));
            }
        });
    });

    console.log('%c📋 Article Manager Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
    console.log('%cShortcuts: / Search | Ctrl+N New Article', 'font-size:11px;color:#888;');
})();
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>