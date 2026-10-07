<?php
require_once __DIR__ . '/../config/functions.php';
checkAuth();

$userId = $_SESSION['user_id'];

// ============================================
// 🛡️ HANDLE POST ACTIONS (AMAN! No GET delete)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        
        // ===== SINGLE APPROVE =====
        if ($action === 'approve' && isset($_POST['comment_id'])) {
            $id = (int)$_POST['comment_id'];
            $stmt = db()->prepare("
                UPDATE comments c 
                JOIN articles a ON c.article_id = a.id 
                SET c.status = 'approved' 
                WHERE c.id = ? AND a.author_id = ?
            ");
            $stmt->execute([$id, $userId]);
            if ($stmt->rowCount() > 0) {
                flash('success', '✅ Komentar berhasil disetujui!');
            } else {
                flash('error', 'Komentar tidak ditemukan atau tidak boleh diubah.');
            }
        }
        
        // ===== SINGLE REJECT =====
        elseif ($action === 'reject' && isset($_POST['comment_id'])) {
            $id = (int)$_POST['comment_id'];
            $stmt = db()->prepare("
                UPDATE comments c 
                JOIN articles a ON c.article_id = a.id 
                SET c.status = 'rejected' 
                WHERE c.id = ? AND a.author_id = ?
            ");
            $stmt->execute([$id, $userId]);
            if ($stmt->rowCount() > 0) {
                flash('success', '❌ Komentar berhasil ditolak.');
            }
        }
        
        // ===== SINGLE DELETE =====
        elseif ($action === 'delete' && isset($_POST['comment_id'])) {
            $id = (int)$_POST['comment_id'];
            $stmt = db()->prepare("
                DELETE c FROM comments c 
                JOIN articles a ON c.article_id = a.id 
                WHERE c.id = ? AND a.author_id = ?
            ");
            $stmt->execute([$id, $userId]);
            if ($stmt->rowCount() > 0) {
                flash('success', '🗑️ Komentar berhasil dihapus!');
            }
        }
        
        // ===== BULK APPROVE =====
        elseif ($action === 'bulk_approve' && !empty($_POST['selected_ids'])) {
            $ids = array_map('intval', $_POST['selected_ids']);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("
                UPDATE comments c 
                JOIN articles a ON c.article_id = a.id 
                SET c.status = 'approved' 
                WHERE c.id IN ($placeholders) AND a.author_id = ?
            ");
            $stmt->execute(array_merge($ids, [$userId]));
            flash('success', '✅ ' . $stmt->rowCount() . ' komentar berhasil disetujui!');
        }
        
        // ===== BULK REJECT =====
        elseif ($action === 'bulk_reject' && !empty($_POST['selected_ids'])) {
            $ids = array_map('intval', $_POST['selected_ids']);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("
                UPDATE comments c 
                JOIN articles a ON c.article_id = a.id 
                SET c.status = 'rejected' 
                WHERE c.id IN ($placeholders) AND a.author_id = ?
            ");
            $stmt->execute(array_merge($ids, [$userId]));
            flash('success', '❌ ' . $stmt->rowCount() . ' komentar berhasil ditolak.');
        }
        
        // ===== BULK DELETE =====
        elseif ($action === 'bulk_delete' && !empty($_POST['selected_ids'])) {
            $ids = array_map('intval', $_POST['selected_ids']);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("
                DELETE c FROM comments c 
                JOIN articles a ON c.article_id = a.id 
                WHERE c.id IN ($placeholders) AND a.author_id = ?
            ");
            $stmt->execute(array_merge($ids, [$userId]));
            flash('success', '🗑️ ' . $stmt->rowCount() . ' komentar berhasil dihapus!');
        }
        
        // ===== REPLY TO COMMENT =====
        elseif ($action === 'reply' && isset($_POST['comment_id']) && isset($_POST['reply_text'])) {
            $parentId = (int)$_POST['comment_id'];
            $replyText = trim($_POST['reply_text']);
            
            if (empty($replyText) || strlen($replyText) < 5) {
                throw new Exception('Balasan minimal 5 karakter!');
            }
            
            // Cek ownership komentar parent
            $stmt = db()->prepare("
                SELECT c.article_id, c.nama as parent_name, a.title as article_title
                FROM comments c
                JOIN articles a ON c.article_id = a.id
                WHERE c.id = ? AND a.author_id = ?
            ");
            $stmt->execute([$parentId, $userId]);
            $parent = $stmt->fetch();
            
            if (!$parent) {
                throw new Exception('Komentar tidak ditemukan.');
            }
            
            // Ambil nama & email user saat ini
            $stmt = db()->prepare("SELECT nama, email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            // Insert reply (auto-approved karena dari author)
            $stmt = db()->prepare("
                INSERT INTO comments (article_id, parent_id, nama, email, comment, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'approved', NOW())
            ");
            $stmt->execute([
                $parent['article_id'],
                $parentId,
                $user['nama'] . ' (Author)',
                $user['email'],
                $replyText
            ]);
            
            flash('success', '💬 Balasan berhasil dikirim ke ' . htmlspecialchars($parent['parent_name']) . '!');
        }
        
    } catch (Exception $e) {
        flash('error', '❌ ' . $e->getMessage());
    }
    
    // Preserve filters saat redirect
    $params = [];
    if (!empty($_GET['filter'])) $params[] = 'filter=' . urlencode($_GET['filter']);
    if (!empty($_GET['q'])) $params[] = 'q=' . urlencode($_GET['q']);
    if (!empty($_GET['sort'])) $params[] = 'sort=' . urlencode($_GET['sort']);
    if (!empty($_GET['article'])) $params[] = 'article=' . urlencode($_GET['article']);
    if (!empty($_GET['page'])) $params[] = 'page=' . urlencode($_GET['page']);
    $query = $params ? '?' . implode('&', $params) : '';
    
    redirect('admin/comments.php' . $query);
}

// ============================================
// 📊 STATISTIK
// ============================================
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'today' => 0];
try {
    $stmt = db()->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN DATE(c.created_at) = CURDATE() THEN 1 ELSE 0 END) as today
        FROM comments c
        JOIN articles a ON c.article_id = a.id
        WHERE a.author_id = ?
    ");
    $stmt->execute([$userId]);
    $s = $stmt->fetch();
    if ($s) {
        $stats['total'] = (int)$s['total'];
        $stats['pending'] = (int)$s['pending'];
        $stats['approved'] = (int)$s['approved'];
        $stats['rejected'] = (int)$s['rejected'];
        $stats['today'] = (int)$s['today'];
    }
} catch (Exception $e) {}

// ============================================
// 🎛️ FILTERS
// ============================================
$filter   = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search   = isset($_GET['q']) ? trim($_GET['q']) : '';
$sortBy   = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$articleFilter = isset($_GET['article']) ? (int)$_GET['article'] : 0;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit    = 15;
$offset   = ($page - 1) * $limit;

$allowedFilter = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($filter, $allowedFilter)) $filter = 'all';

$allowedSort = ['newest', 'oldest', 'name_az'];
if (!in_array($sortBy, $allowedSort)) $sortBy = 'newest';

// Build WHERE
$where = "WHERE a.author_id = ?";
$params = [$userId];

if ($filter !== 'all') {
    $where .= " AND c.status = ?";
    $params[] = $filter;
}

if (!empty($search)) {
    $where .= " AND (c.nama LIKE ? OR c.email LIKE ? OR c.comment LIKE ? OR a.title LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
}

if ($articleFilter > 0) {
    $where .= " AND c.article_id = ?";
    $params[] = $articleFilter;
}

// Sorting
$orderBy = "ORDER BY ";
switch ($sortBy) {
    case 'oldest': $orderBy .= "c.created_at ASC"; break;
    case 'name_az': $orderBy .= "c.nama ASC"; break;
    case 'newest':
    default: $orderBy .= "c.created_at DESC"; break;
}

// Count total
$totalFiltered = 0;
try {
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM comments c 
        JOIN articles a ON c.article_id = a.id 
        $where
    ");
    $stmt->execute($params);
    $totalFiltered = (int)$stmt->fetchColumn();
    $totalPages = ceil($totalFiltered / $limit);
} catch (Exception $e) { $totalPages = 1; }

// Fetch comments
$comments = [];
try {
    $stmt = db()->prepare("
        SELECT c.*, a.title as article_title, a.slug as article_slug, a.id as article_id,
               (SELECT COUNT(*) FROM comments WHERE parent_id = c.id) as reply_count
        FROM comments c 
        JOIN articles a ON c.article_id = a.id 
        $where
        $orderBy
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $comments = $stmt->fetchAll();
} catch (Exception $e) {}

// Load articles untuk filter dropdown
$articles = [];
try {
    $articles = db()->prepare("
        SELECT a.id, a.title, COUNT(c.id) as comment_count
        FROM articles a
        LEFT JOIN comments c ON c.article_id = a.id
        WHERE a.author_id = ?
        GROUP BY a.id
        HAVING comment_count > 0
        ORDER BY a.title ASC
    ");
    $articles->execute([$userId]);
    $articles = $articles->fetchAll();
} catch (Exception $e) {}

// Helper untuk build URL dengan preserve filters
function buildCommentsUrl($overrides = []) {
    $params = [
        'filter' => isset($_GET['filter']) ? $_GET['filter'] : 'all',
        'q' => isset($_GET['q']) ? $_GET['q'] : '',
        'sort' => isset($_GET['sort']) ? $_GET['sort'] : 'newest',
        'article' => isset($_GET['article']) ? $_GET['article'] : '',
        'page' => isset($_GET['page']) ? $_GET['page'] : 1,
    ];
    $params = array_merge($params, $overrides);
    $params = array_filter($params, function($v) { 
        return $v !== '' && $v !== null && $v !== 'all' && $v !== 'newest'; 
    });
    $query = http_build_query($params);
    return url('admin/comments.php' . ($query ? '?' . $query : ''));
}

$pageTitle = 'Manajemen Komentar';
include __DIR__ . '/../includes/admin-header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ===== COMMENT MANAGER ULTIMATE STYLES ===== */

.comments-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}
.comments-header h1 {
    color: #1e3a5f;
    margin: 0;
    font-size: 1.8rem;
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
.comments-header h1 i { color: #f39c12; }
.comments-header-sub {
    color: #666;
    font-size: 0.9rem;
    margin-top: 0.3rem;
}

/* ===== STATS CARDS ===== */
.comment-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.cstat-card {
    background: white;
    padding: 1.2rem 1.3rem;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 0.9rem;
    border-left: 4px solid;
    transition: all 0.3s ease;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
}
.cstat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    color: inherit;
}
.cstat-card.total { border-left-color: #3498db; }
.cstat-card.pending { border-left-color: #f39c12; }
.cstat-card.approved { border-left-color: #27ae60; }
.cstat-card.rejected { border-left-color: #e74c3c; }
.cstat-card.today { border-left-color: #9b59b6; }
.cstat-icon {
    width: 45px; height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.cstat-card.total .cstat-icon { background: linear-gradient(135deg, #3498db, #2980b9); }
.cstat-card.pending .cstat-icon { background: linear-gradient(135deg, #f39c12, #e67e22); }
.cstat-card.approved .cstat-icon { background: linear-gradient(135deg, #27ae60, #229954); }
.cstat-card.rejected .cstat-icon { background: linear-gradient(135deg, #e74c3c, #c0392b); }
.cstat-card.today .cstat-icon { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
.cstat-info { flex: 1; min-width: 0; }
.cstat-info .value {
    font-size: 1.6rem;
    font-weight: 800;
    color: #1e3a5f;
    line-height: 1;
    margin-bottom: 0.15rem;
    display: block;
}
.cstat-info .label {
    font-size: 0.75rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ===== FILTERS BAR ===== */
.comment-filters {
    background: white;
    padding: 1rem 1.2rem;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 1.5rem;
    display: flex;
    gap: 0.8rem;
    flex-wrap: wrap;
    align-items: center;
}
.cfilter-search {
    flex: 1;
    min-width: 220px;
    position: relative;
}
.cfilter-search input {
    width: 100%;
    padding: 0.65rem 1rem 0.65rem 2.3rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.88rem;
    outline: none;
    transition: border 0.3s ease;
    background: white;
    font-family: inherit;
}
.cfilter-search input:focus { border-color: #f39c12; }
.cfilter-search i {
    position: absolute;
    left: 0.8rem;
    top: 50%;
    transform: translateY(-50%);
    color: #aaa;
}
.cfilter-select {
    padding: 0.65rem 1rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.88rem;
    outline: none;
    background: white;
    cursor: pointer;
    transition: border 0.3s ease;
    min-width: 160px;
    font-family: inherit;
    color: #333;
}
.cfilter-select:focus { border-color: #f39c12; }

.btn-reset-cfilter {
    background: #fff3cd;
    color: #856404;
    border: none;
    padding: 0.65rem 1rem;
    border-radius: 10px;
    font-size: 0.85rem;
    cursor: pointer;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    transition: all 0.2s ease;
    text-decoration: none;
    font-family: inherit;
}
.btn-reset-cfilter:hover { background: #ffc107; color: white; }

/* ===== ACTIVE FILTERS CHIPS ===== */
.active-cfilters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
}
.cfilter-chip {
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
.cfilter-chip:hover {
    background: #e74c3c;
    color: white;
    border-color: #e74c3c;
}
.cfilter-chip strong { color: #1e3a5f; }
.cfilter-chip:hover strong { color: white; }

/* ===== BULK BAR ===== */
.bulk-bar-comments {
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
.bulk-bar-comments.show { display: flex; }
.bulk-bar-comments .count {
    background: rgba(255,255,255,0.2);
    padding: 0.3rem 0.8rem;
    border-radius: 15px;
    font-weight: 700;
    font-size: 0.88rem;
}
.bulk-cbtn {
    background: rgba(255,255,255,0.15);
    color: white;
    border: 1px solid rgba(255,255,255,0.25);
    padding: 0.5rem 1rem;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    transition: all 0.2s ease;
    font-family: inherit;
}
.bulk-cbtn:hover { background: rgba(255,255,255,0.25); }
.bulk-cbtn.approve:hover { background: #27ae60; border-color: #27ae60; }
.bulk-cbtn.reject:hover { background: #f39c12; border-color: #f39c12; }
.bulk-cbtn.delete:hover { background: #e74c3c; border-color: #e74c3c; }
.bulk-cancel {
    margin-left: auto;
    background: transparent;
    color: white;
    border: none;
    cursor: pointer;
    padding: 0.5rem;
    opacity: 0.8;
    font-family: inherit;
}
.bulk-cancel:hover { opacity: 1; }

@keyframes slideDown {
    from { transform: translateY(-10px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* ===== COMMENT CARDS ===== */
.comment-cards-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.comment-card-ult {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    border: 2px solid transparent;
    position: relative;
}
.comment-card-ult:hover {
    box-shadow: 0 8px 25px rgba(0,0,0,0.08);
    transform: translateY(-2px);
}
.comment-card-ult.selected {
    border-color: #f39c12;
    background: #fffbf0;
}
.comment-card-ult.pending { border-left: 4px solid #f39c12; }
.comment-card-ult.approved { border-left: 4px solid #27ae60; }
.comment-card-ult.rejected { border-left: 4px solid #e74c3c; opacity: 0.7; }

.comment-card-checkbox {
    position: absolute;
    top: 1.2rem;
    right: 1.2rem;
    width: 20px; height: 20px;
    cursor: pointer;
    accent-color: #f39c12;
    z-index: 2;
}

.comment-card-header {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
    align-items: flex-start;
}
.commenter-avatar {
    width: 50px; height: 50px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #f39c12;
    flex-shrink: 0;
}
.commenter-info { flex: 1; min-width: 0; padding-right: 2rem; }
.commenter-name-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-bottom: 0.2rem;
}
.commenter-name {
    font-weight: 700;
    color: #1e3a5f;
    font-size: 1rem;
}
.commenter-name.is-author {
    color: #f39c12;
}
.commenter-name.is-author::after {
    content: '✍️';
    margin-left: 0.3rem;
    font-size: 0.85rem;
}
.comment-email {
    color: #3498db;
    font-size: 0.82rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    transition: color 0.2s ease;
}
.comment-email:hover { color: #2980b9; text-decoration: underline; }
.comment-time {
    font-size: 0.78rem;
    color: #888;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin-top: 0.2rem;
}
.comment-time .relative {
    color: #f39c12;
    font-weight: 600;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.25rem 0.7rem;
    border-radius: 15px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.status-pill.pending { background: #fff3cd; color: #856404; }
.status-pill.approved { background: #d4edda; color: #155724; }
.status-pill.rejected { background: #f8d7da; color: #721c24; }
.status-pill::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
}

/* Comment Content */
.comment-content-box {
    background: #f8f9fa;
    padding: 1rem 1.2rem;
    border-radius: 10px;
    margin-bottom: 1rem;
    color: #333;
    line-height: 1.6;
    font-size: 0.92rem;
    word-wrap: break-word;
    position: relative;
}
.comment-content-box::before {
    content: '';
    position: absolute;
    top: 0; left: 1.2rem;
    width: 0; height: 0;
    border-left: 8px solid transparent;
    border-right: 8px solid transparent;
    border-bottom: 8px solid #f8f9fa;
    transform: translateY(-100%);
}

/* Article Link */
.comment-article-link {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.8rem 1rem;
    background: #f4f6f9;
    border-radius: 10px;
    margin-bottom: 1rem;
    text-decoration: none;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}
.comment-article-link:hover {
    background: white;
    border-color: #f39c12;
    box-shadow: 0 4px 12px rgba(243, 156, 18, 0.15);
}
.article-link-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
    color: white;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    flex-shrink: 0;
}
.article-link-info { flex: 1; min-width: 0; }
.article-link-label {
    font-size: 0.7rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 0.1rem;
}
.article-link-title {
    color: #1e3a5f;
    font-weight: 600;
    font-size: 0.88rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.article-link-arrow {
    color: #ccc;
    transition: all 0.2s ease;
}
.comment-article-link:hover .article-link-arrow {
    color: #f39c12;
    transform: translateX(3px);
}

/* Reply Indicator */
.reply-indicator {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.8rem;
    background: #e3f2fd;
    color: #1976d2;
    border-radius: 8px;
    margin-bottom: 0.8rem;
    font-size: 0.82rem;
}
.reply-indicator i { font-size: 0.75rem; }

/* Reply Count Badge */
.reply-count-badge {
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

/* Action Buttons */
.comment-actions-row {
    display: flex;
    gap: 0.4rem;
    flex-wrap: wrap;
    padding-top: 0.8rem;
    border-top: 1px solid #f0f0f0;
}
.comment-action-btn {
    padding: 0.5rem 0.9rem;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.82rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    transition: all 0.2s ease;
    text-decoration: none;
    font-family: inherit;
}
.comment-action-btn.approve { background: #e8f5e9; color: #2e7d32; }
.comment-action-btn.approve:hover { background: #27ae60; color: white; }
.comment-action-btn.reject { background: #fff3e0; color: #e65100; }
.comment-action-btn.reject:hover { background: #f39c12; color: white; }
.comment-action-btn.reply { background: #e3f2fd; color: #1565c0; }
.comment-action-btn.reply:hover { background: #1976d2; color: white; }
.comment-action-btn.delete { background: #ffebee; color: #c62828; }
.comment-action-btn.delete:hover { background: #e74c3c; color: white; }
.comment-action-btn.view-replies { background: #f3e5f5; color: #6a1b9a; }
.comment-action-btn.view-replies:hover { background: #9b59b6; color: white; }

/* ===== REPLY MODAL ===== */
.reply-modal {
    position: fixed;
    inset: 0;
    z-index: 10001;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.reply-modal.show {
    display: flex;
    animation: modalFadeIn 0.2s ease;
}
.reply-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
}
.reply-modal-content {
    position: relative;
    background: white;
    border-radius: 20px;
    padding: 2rem;
    max-width: 500px;
    width: 100%;
    box-shadow: 0 25px 60px rgba(0,0,0,0.3);
    animation: modalSlideDown 0.3s ease;
}
.reply-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 0.8rem;
    border-bottom: 2px solid #f0f0f0;
}
.reply-modal-header h3 {
    color: #1e3a5f;
    margin: 0;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.reply-modal-header h3 i { color: #f39c12; }
.reply-close-btn {
    background: #f4f6f9;
    border: none;
    width: 32px; height: 32px;
    border-radius: 50%;
    cursor: pointer;
    color: #666;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}
.reply-close-btn:hover { background: #e74c3c; color: white; }
.reply-to-info {
    background: #f8f9fa;
    padding: 0.8rem 1rem;
    border-radius: 10px;
    margin-bottom: 1rem;
    font-size: 0.85rem;
    color: #666;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.reply-to-info i { color: #f39c12; }
.reply-to-info strong { color: #1e3a5f; }
.reply-original-comment {
    background: #fff9e6;
    padding: 0.8rem 1rem;
    border-left: 3px solid #f39c12;
    border-radius: 8px;
    margin-bottom: 1rem;
    font-size: 0.85rem;
    color: #555;
    font-style: italic;
    max-height: 100px;
    overflow-y: auto;
}
.reply-textarea {
    width: 100%;
    padding: 0.8rem 1rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.92rem;
    outline: none;
    resize: vertical;
    min-height: 120px;
    font-family: inherit;
    transition: border 0.3s ease;
    margin-bottom: 0.5rem;
}
.reply-textarea:focus { border-color: #f39c12; }
.reply-counter {
    text-align: right;
    font-size: 0.75rem;
    color: #888;
    margin-bottom: 1rem;
}
.reply-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
}

/* ===== PAGINATION ===== */
.pagination-wrap-comments {
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
.pagination-info { color: #666; font-size: 0.88rem; }
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
.page-ellipsis { padding: 0 0.5rem; color: #999; }

/* ===== EMPTY STATE ===== */
.empty-state-comments {
    background: white;
    padding: 4rem 2rem;
    border-radius: 15px;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.empty-state-comments .empty-icon {
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
.empty-state-comments h3 {
    color: #1e3a5f;
    font-size: 1.4rem;
    margin-bottom: 0.5rem;
}
.empty-state-comments p {
    color: #666;
    max-width: 400px;
    margin: 0 auto;
}

/* ===== DARK MODE ===== */
html[data-theme="dark"] .cstat-card,
html[data-theme="dark"] .comment-filters,
html[data-theme="dark"] .comment-card-ult,
html[data-theme="dark"] .pagination-wrap-comments,
html[data-theme="dark"] .empty-state-comments,
html[data-theme="dark"] .reply-modal-content {
    background: #1e2638;
}
html[data-theme="dark"] .comments-header h1,
html[data-theme="dark"] .cstat-info .value,
html[data-theme="dark"] .commenter-name,
html[data-theme="dark"] .article-link-title,
html[data-theme="dark"] .empty-state-comments h3,
html[data-theme="dark"] .reply-modal-header h3 {
    color: #e5e8ec;
}
html[data-theme="dark"] .cfilter-search input,
html[data-theme="dark"] .cfilter-select,
html[data-theme="dark"] .reply-textarea {
    background: #16203a;
    border-color: #2a3550;
    color: #e5e8ec;
}
html[data-theme="dark"] .comment-content-box,
html[data-theme="dark"] .comment-article-link,
html[data-theme="dark"] .reply-to-info {
    background: #16203a;
    color: #d5dae2;
}
html[data-theme="dark"] .comment-content-box::before {
    border-bottom-color: #16203a;
}
html[data-theme="dark"] .comment-actions-row {
    border-color: #2a3550;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .comment-stats { grid-template-columns: repeat(2, 1fr); }
    .comment-filters { flex-direction: column; align-items: stretch; }
    .cfilter-search { min-width: auto; }
    .cfilter-select { width: 100%; }
    .comment-card-checkbox { top: 0.8rem; right: 0.8rem; }
    .commenter-info { padding-right: 2.5rem; }
}
@media (max-width: 576px) {
    .comment-stats { grid-template-columns: 1fr; }
    .comment-actions-row { flex-direction: column; }
    .comment-action-btn { justify-content: center; }
    .pagination-wrap-comments { flex-direction: column; text-align: center; }
}

@keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes modalSlideDown {
    from { transform: translateY(-30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
</style>

<main class="admin-main">
    
    <!-- ===== HEADER ===== -->
    <div class="comments-header">
        <div>
            <h1><i class="fas fa-comments"></i> Manajemen Komentar</h1>
            <div class="comments-header-sub">
                Kelola komentar dari pembaca artikel Anda
            </div>
        </div>
        <?php if ($stats['pending'] > 0): ?>
            <div style="background:linear-gradient(135deg,#fff3cd,#ffeaa7);padding:0.6rem 1.2rem;border-radius:10px;display:flex;align-items:center;gap:0.5rem;font-weight:600;color:#856404;">
                <i class="fas fa-bell"></i>
                <?php echo $stats['pending']; ?> komentar menunggu moderasi
            </div>
        <?php endif; ?>
    </div>

    <!-- ===== STATS CARDS ===== -->
    <div class="comment-stats">
        <a href="<?php echo buildCommentsUrl(['filter' => 'all', 'page' => 1]); ?>" class="cstat-card total">
            <div class="cstat-icon"><i class="fas fa-comments"></i></div>
            <div class="cstat-info">
                <span class="value"><?php echo number_format($stats['total']); ?></span>
                <span class="label">Total Komentar</span>
            </div>
        </a>
        <a href="<?php echo buildCommentsUrl(['filter' => 'pending', 'page' => 1]); ?>" class="cstat-card pending">
            <div class="cstat-icon"><i class="fas fa-clock"></i></div>
            <div class="cstat-info">
                <span class="value"><?php echo number_format($stats['pending']); ?></span>
                <span class="label">Pending</span>
            </div>
        </a>
        <a href="<?php echo buildCommentsUrl(['filter' => 'approved', 'page' => 1]); ?>" class="cstat-card approved">
            <div class="cstat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="cstat-info">
                <span class="value"><?php echo number_format($stats['approved']); ?></span>
                <span class="label">Disetujui</span>
            </div>
        </a>
        <a href="<?php echo buildCommentsUrl(['filter' => 'rejected', 'page' => 1]); ?>" class="cstat-card rejected">
            <div class="cstat-icon"><i class="fas fa-times-circle"></i></div>
            <div class="cstat-info">
                <span class="value"><?php echo number_format($stats['rejected']); ?></span>
                <span class="label">Ditolak</span>
            </div>
        </a>
        <div class="cstat-card today">
            <div class="cstat-icon"><i class="fas fa-calendar-day"></i></div>
            <div class="cstat-info">
                <span class="value"><?php echo number_format($stats['today']); ?></span>
                <span class="label">Hari Ini</span>
            </div>
        </div>
    </div>

    <!-- ===== FILTERS ===== -->
    <div class="comment-filters">
        <form method="GET" action="<?php echo url('admin/comments.php'); ?>" class="cfilter-search">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="Cari nama, email, komentar..." 
                   value="<?php echo htmlspecialchars($search); ?>"
                   id="commentSearch">
            <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>"><?php endif; ?>
            <?php if ($sortBy !== 'newest'): ?><input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>"><?php endif; ?>
            <?php if ($articleFilter > 0): ?><input type="hidden" name="article" value="<?php echo $articleFilter; ?>"><?php endif; ?>
        </form>

        <select class="cfilter-select" onchange="applyCFilter('filter', this.value)">
            <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>📋 Semua Status</option>
            <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>⏳ Pending</option>
            <option value="approved" <?php echo $filter === 'approved' ? 'selected' : ''; ?>>✅ Disetujui</option>
            <option value="rejected" <?php echo $filter === 'rejected' ? 'selected' : ''; ?>>❌ Ditolak</option>
        </select>

        <select class="cfilter-select" onchange="applyCFilter('sort', this.value)">
            <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>🆕 Terbaru</option>
            <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>📅 Terlama</option>
            <option value="name_az" <?php echo $sortBy === 'name_az' ? 'selected' : ''; ?>>🔤 Nama A-Z</option>
        </select>

        <?php if (!empty($articles)): ?>
            <select class="cfilter-select" onchange="applyCFilter('article', this.value)">
                <option value="">📄 Semua Artikel</option>
                <?php foreach ($articles as $art): ?>
                    <option value="<?php echo $art['id']; ?>" <?php echo $articleFilter == $art['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(excerpt($art['title'], 30)); ?> (<?php echo $art['comment_count']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if (!empty($search) || $filter !== 'all' || $sortBy !== 'newest' || $articleFilter > 0): ?>
            <a href="<?php echo url('admin/comments.php'); ?>" class="btn-reset-cfilter">
                <i class="fas fa-redo"></i> Reset
            </a>
        <?php endif; ?>
    </div>

    <!-- ===== ACTIVE FILTERS ===== -->
    <?php if (!empty($search) || $filter !== 'all' || $articleFilter > 0): ?>
        <div class="active-cfilters">
            <?php if (!empty($search)): ?>
                <a href="<?php echo buildCommentsUrl(['q' => '', 'page' => 1]); ?>" class="cfilter-chip">
                    🔍 Search: <strong><?php echo htmlspecialchars($search); ?></strong>
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
            <?php if ($filter !== 'all'): ?>
                <a href="<?php echo buildCommentsUrl(['filter' => 'all', 'page' => 1]); ?>" class="cfilter-chip">
                    📌 Status: <strong><?php echo ucfirst($filter); ?></strong>
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
            <?php if ($articleFilter > 0): 
                $artTitle = '';
                foreach ($articles as $a) { if ($a['id'] == $articleFilter) { $artTitle = $a['title']; break; } }
            ?>
                <a href="<?php echo buildCommentsUrl(['article' => '', 'page' => 1]); ?>" class="cfilter-chip">
                    📄 Artikel: <strong><?php echo htmlspecialchars(excerpt($artTitle, 30)); ?></strong>
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ===== BULK BAR ===== -->
    <div class="bulk-bar-comments" id="bulkBar">
        <span class="count"><span id="bulkCount">0</span> dipilih</span>
        <form method="POST" class="bulk-form" data-action="bulk_approve" style="display:inline;">
            <input type="hidden" name="action" value="bulk_approve">
            <button type="submit" class="bulk-cbtn approve">
                <i class="fas fa-check-circle"></i> Setujui
            </button>
        </form>
        <form method="POST" class="bulk-form" data-action="bulk_reject" style="display:inline;">
            <input type="hidden" name="action" value="bulk_reject">
            <button type="submit" class="bulk-cbtn reject">
                <i class="fas fa-times-circle"></i> Tolak
            </button>
        </form>
        <form method="POST" class="bulk-form" data-action="bulk_delete" style="display:inline;">
            <input type="hidden" name="action" value="bulk_delete">
            <button type="submit" class="bulk-cbtn delete">
                <i class="fas fa-trash"></i> Hapus
            </button>
        </form>
        <button type="button" class="bulk-cancel" onclick="clearSelection()">
            <i class="fas fa-times"></i> Batal
        </button>
    </div>

    <!-- ===== INFO BAR ===== -->
    <?php if (!empty($comments)): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
            <div style="color:#666;font-size:0.88rem;">
                Menampilkan <strong><?php echo count($comments); ?></strong> dari <strong><?php echo $totalFiltered; ?></strong> komentar
            </div>
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <input type="checkbox" id="selectAllComments" style="width:18px;height:18px;accent-color:#f39c12;">
                <label for="selectAllComments" style="font-size:0.85rem;color:#666;cursor:pointer;">Pilih semua</label>
            </div>
        </div>
    <?php endif; ?>

    <!-- ===== COMMENTS LIST ===== -->
    <?php if (empty($comments)): ?>
        <div class="empty-state-comments">
            <div class="empty-icon">
                <i class="fas fa-<?php echo (!empty($search) || $filter !== 'all') ? 'search' : 'comment-slash'; ?>"></i>
            </div>
            <h3>
                <?php if (!empty($search) || $filter !== 'all' || $articleFilter > 0): ?>
                    Tidak Ada Komentar Ditemukan
                <?php else: ?>
                    Belum Ada Komentar
                <?php endif; ?>
            </h3>
            <p>
                <?php if (!empty($search) || $filter !== 'all' || $articleFilter > 0): ?>
                    Coba ubah filter atau kata kunci pencarian Anda.
                <?php else: ?>
                    Komentar dari pembaca akan muncul di sini setelah mereka mengomentari artikel Anda.
                <?php endif; ?>
            </p>
            <?php if (!empty($search) || $filter !== 'all' || $articleFilter > 0): ?>
                <a href="<?php echo url('admin/comments.php'); ?>" 
                   style="display:inline-flex;align-items:center;gap:0.5rem;margin-top:1rem;background:linear-gradient(135deg,#f39c12,#e67e22);color:white;padding:0.8rem 1.5rem;border-radius:10px;text-decoration:none;font-weight:600;">
                    <i class="fas fa-redo"></i> Reset Filter
                </a>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <div class="comment-cards-list">
            <?php foreach ($comments as $c): 
                $isAuthorComment = strpos($c['nama'], '(Author)') !== false;
                $avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($c['nama']) . '&background=' . 
                             ($c['status'] === 'pending' ? 'f39c12' : ($c['status'] === 'approved' ? '27ae60' : 'e74c3c')) . 
                             '&color=fff&size=100';
            ?>
                <div class="comment-card-ult <?php echo $c['status']; ?>" data-id="<?php echo $c['id']; ?>">
                    <input type="checkbox" class="comment-card-checkbox comment-select" 
                           value="<?php echo $c['id']; ?>">
                    
                    <!-- Header: Avatar + Commenter Info -->
                    <div class="comment-card-header">
                        <img src="<?php echo $avatarUrl; ?>" 
                             alt="<?php echo htmlspecialchars($c['nama']); ?>" 
                             class="commenter-avatar">
                        <div class="commenter-info">
                            <div class="commenter-name-row">
                                <span class="commenter-name <?php echo $isAuthorComment ? 'is-author' : ''; ?>">
                                    <?php echo htmlspecialchars($c['nama']); ?>
                                </span>
                                <span class="status-pill <?php echo $c['status']; ?>">
                                    <?php echo ucfirst($c['status']); ?>
                                </span>
                                <?php if ($c['reply_count'] > 0): ?>
                                    <span class="reply-count-badge">
                                        <i class="fas fa-reply"></i> <?php echo $c['reply_count']; ?> balasan
                                    </span>
                                <?php endif; ?>
                            </div>
                            <a href="mailto:<?php echo htmlspecialchars($c['email']); ?>" class="comment-email">
                                <i class="fas fa-envelope"></i> 
                                <?php echo htmlspecialchars($c['email']); ?>
                            </a>
                            <div class="comment-time">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('d M Y, H:i', strtotime($c['created_at'])); ?>
                                <span class="relative">• <?php echo timeAgo($c['created_at']); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Comment Content -->
                    <div class="comment-content-box">
                        <?php echo nl2br(htmlspecialchars($c['comment'])); ?>
                    </div>

                    <!-- Article Link -->
                    <a href="<?php echo url('article.php?slug=' . $c['article_slug']); ?>" 
                       target="_blank"
                       class="comment-article-link">
                        <div class="article-link-icon">
                            <i class="fas fa-newspaper"></i>
                        </div>
                        <div class="article-link-info">
                            <span class="article-link-label">Komentar pada artikel:</span>
                            <div class="article-link-title">
                                <?php echo htmlspecialchars($c['article_title']); ?>
                            </div>
                        </div>
                        <i class="fas fa-external-link-alt article-link-arrow"></i>
                    </a>

                    <!-- Action Buttons -->
                    <div class="comment-actions-row">
                        <?php if ($c['status'] !== 'approved'): ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirmAction(event, 'Setujui komentar ini?', 'approve')">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="comment_id" value="<?php echo $c['id']; ?>">
                                <button type="submit" class="comment-action-btn approve">
                                    <i class="fas fa-check"></i> Setujui
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <?php if ($c['status'] !== 'rejected'): ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirmAction(event, 'Tolak komentar ini?', 'reject')">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="comment_id" value="<?php echo $c['id']; ?>">
                                <button type="submit" class="comment-action-btn reject">
                                    <i class="fas fa-times"></i> Tolak
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <button type="button" class="comment-action-btn reply"
                                onclick="openReplyModal(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['nama'])); ?>', '<?php echo htmlspecialchars(addslashes(excerpt($c['comment'], 100))); ?>')">
                            <i class="fas fa-reply"></i> Balas
                        </button>

                        <?php if ($c['reply_count'] > 0): ?>
                            <a href="<?php echo url('admin/comments.php?parent=' . $c['id']); ?>" 
                               class="comment-action-btn view-replies">
                                <i class="fas fa-comments"></i> Lihat Balasan (<?php echo $c['reply_count']; ?>)
                            </a>
                        <?php endif; ?>
                        
                        <form method="POST" style="display:inline;margin-left:auto;"
                              onsubmit="return confirmAction(event, 'Hapus komentar ini secara permanen?', 'delete', true)">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="comment_id" value="<?php echo $c['id']; ?>">
                            <button type="submit" class="comment-action-btn delete">
                                <i class="fas fa-trash"></i> Hapus
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ===== PAGINATION ===== -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination-wrap-comments">
                <div class="pagination-info">
                    Menampilkan <strong><?php echo (($page - 1) * $limit) + 1; ?>-<?php echo min($page * $limit, $totalFiltered); ?></strong> 
                    dari <strong><?php echo $totalFiltered; ?></strong> komentar
                </div>
                <div class="pagination-buttons">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo buildCommentsUrl(['page' => 1]); ?>" class="page-btn" title="Halaman pertama">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="<?php echo buildCommentsUrl(['page' => $page - 1]); ?>" class="page-btn" title="Sebelumnya">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-btn disabled"><i class="fas fa-angle-double-left"></i></span>
                        <span class="page-btn disabled"><i class="fas fa-angle-left"></i></span>
                    <?php endif;
                    
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    if ($start > 1): ?>
                        <a href="<?php echo buildCommentsUrl(['page' => 1]); ?>" class="page-btn">1</a>
                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif;
                    endif;
                    
                    for ($i = $start; $i <= $end; $i++): 
                        if ($i === $page): ?>
                            <span class="page-btn active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo buildCommentsUrl(['page' => $i]); ?>" class="page-btn"><?php echo $i; ?></a>
                        <?php endif;
                    endfor;
                    
                    if ($end < $totalPages): 
                        if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                        <a href="<?php echo buildCommentsUrl(['page' => $totalPages]); ?>" class="page-btn"><?php echo $totalPages; ?></a>
                    <?php endif;
                    
                    if ($page < $totalPages): ?>
                        <a href="<?php echo buildCommentsUrl(['page' => $page + 1]); ?>" class="page-btn" title="Selanjutnya">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="<?php echo buildCommentsUrl(['page' => $totalPages]); ?>" class="page-btn" title="Halaman terakhir">
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

<!-- ===== REPLY MODAL ===== -->
<div class="reply-modal" id="replyModal">
    <div class="reply-modal-backdrop" onclick="closeReplyModal()"></div>
    <div class="reply-modal-content">
        <div class="reply-modal-header">
            <h3><i class="fas fa-reply"></i> Balas Komentar</h3>
            <button type="button" class="reply-close-btn" onclick="closeReplyModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="reply-to-info">
            <i class="fas fa-user"></i>
            Membalas komentar dari <strong id="replyToName"></strong>
        </div>
        
        <div class="reply-original-comment" id="replyOriginalText"></div>
        
        <form method="POST" id="replyForm" onsubmit="return submitReply(event)">
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="comment_id" id="replyCommentId">
            
            <textarea name="reply_text" 
                      class="reply-textarea" 
                      id="replyText"
                      placeholder="Tulis balasan Anda di sini..." 
                      required minlength="5" maxlength="1000"
                      oninput="updateReplyCounter()"></textarea>
            <div class="reply-counter">
                <span id="replyCharCount">0</span> / 1000 karakter
            </div>
            
            <div class="reply-actions">
                <button type="button" class="comment-action-btn reject" onclick="closeReplyModal()">
                    <i class="fas fa-times"></i> Batal
                </button>
                <button type="submit" class="comment-action-btn approve">
                    <i class="fas fa-paper-plane"></i> Kirim Balasan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    'use strict';

    // ===== FILTER HANDLER =====
    window.applyCFilter = function(key, value) {
        var url = new URL(window.location.href);
        if (!value || value === 'all' || value === 'newest') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
    };

    // ===== SEARCH AUTO-SUBMIT =====
    var searchInput = document.getElementById('commentSearch');
    var searchTimer = null;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            var val = this.value;
            searchTimer = setTimeout(function() {
                if (val.length >= 2 || val === '') {
                    searchInput.parentElement.submit();
                }
            }, 600);
        });
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                clearTimeout(searchTimer);
                this.parentElement.submit();
            }
        });
    }

    // ===== CHECKBOX SELECTION =====
    var selectCheckboxes = document.querySelectorAll('.comment-select');
    var selectAll = document.getElementById('selectAllComments');
    var bulkBar = document.getElementById('bulkBar');
    var bulkCountEl = document.getElementById('bulkCount');

    function updateBulk() {
        var selected = document.querySelectorAll('.comment-select:checked');
        var count = selected.length;
        if (bulkCountEl) bulkCountEl.textContent = count;
        if (bulkBar) bulkBar.classList.toggle('show', count > 0);
        
        document.querySelectorAll('.comment-card-ult').forEach(function(card) {
            var id = card.getAttribute('data-id');
            var cb = document.querySelector('.comment-select[value="' + id + '"]');
            if (cb) card.classList.toggle('selected', cb.checked);
        });
        
        if (selectAll) {
            selectAll.checked = count === selectCheckboxes.length && count > 0;
            selectAll.indeterminate = count > 0 && count < selectCheckboxes.length;
        }
        
        // Update bulk forms
        document.querySelectorAll('.bulk-form').forEach(function(form) {
            form.querySelectorAll('input[name="selected_ids[]"]').forEach(function(el) { el.remove(); });
            selected.forEach(function(cb) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'selected_ids[]';
                inp.value = cb.value;
                form.appendChild(inp);
            });
        });
    }

    selectCheckboxes.forEach(function(cb) {
        cb.addEventListener('change', updateBulk);
    });

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checked = this.checked;
            selectCheckboxes.forEach(function(cb) { cb.checked = checked; });
            updateBulk();
        });
    }

    window.clearSelection = function() {
        selectCheckboxes.forEach(function(cb) { cb.checked = false; });
        if (selectAll) selectAll.checked = false;
        updateBulk();
    };

    // ===== CONFIRM ACTIONS =====
    window.confirmAction = function(e, message, action, isDanger) {
        e.preventDefault();
        var form = e.target;
        
        if (typeof UI !== 'undefined' && UI.confirm) {
            UI.confirm(
                message,
                function() {
                    if (typeof UI !== 'undefined') UI.showLoading('Memproses...');
                    form.submit();
                },
                { danger: !!isDanger, icon: isDanger ? '🗑️' : '⚠️', title: 'Konfirmasi' }
            );
        } else {
            if (confirm(message)) form.submit();
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
                if (typeof UI !== 'undefined') UI.warning('Pilih minimal 1 komentar!', 'Tidak Ada Selection');
                return false;
            }
            
            var messages = {
                'bulk_approve': 'Setujui ' + count + ' komentar?',
                'bulk_reject': 'Tolak ' + count + ' komentar?',
                'bulk_delete': 'Hapus ' + count + ' komentar secara permanen?'
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

    // ===== REPLY MODAL =====
    window.openReplyModal = function(commentId, commenterName, commentText) {
        document.getElementById('replyCommentId').value = commentId;
        document.getElementById('replyToName').textContent = commenterName;
        document.getElementById('replyOriginalText').textContent = '"' + commentText + '"';
        document.getElementById('replyText').value = '';
        document.getElementById('replyCharCount').textContent = '0';
        document.getElementById('replyModal').classList.add('show');
        setTimeout(function() {
            document.getElementById('replyText').focus();
        }, 100);
    };

    window.closeReplyModal = function() {
        document.getElementById('replyModal').classList.remove('show');
    };

    window.updateReplyCounter = function() {
        var textarea = document.getElementById('replyText');
        var counter = document.getElementById('replyCharCount');
        if (textarea && counter) {
            counter.textContent = textarea.value.length;
        }
    };

    window.submitReply = function(e) {
        e.preventDefault();
        var form = e.target;
        var text = document.getElementById('replyText').value.trim();
        
        if (text.length < 5) {
            if (typeof UI !== 'undefined') UI.warning('Balasan minimal 5 karakter!', 'Terlalu Pendek');
            else alert('Balasan minimal 5 karakter!');
            return false;
        }
        
        if (typeof UI !== 'undefined') UI.showLoading('Mengirim balasan...');
        form.submit();
        return false;
    };

    // ESC to close reply modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeReplyModal();
        }
    });

    // ===== KEYBOARD SHORTCUTS =====
    document.addEventListener('keydown', function(e) {
        // Ctrl+F atau / : Focus search
        if (e.key === '/' || ((e.ctrlKey || e.metaKey) && e.key === 'f')) {
            if (!e.target.matches('input, textarea, select') || e.key === '/') {
                e.preventDefault();
                if (searchInput) searchInput.focus();
            }
        }
    });

    // ===== CARD CLICK TO SELECT =====
    document.querySelectorAll('.comment-card-ult').forEach(function(card) {
        card.addEventListener('click', function(e) {
            if (e.target.closest('a, button, .comment-card-checkbox, form, .comment-action-btn, .comment-article-link, .comment-email')) return;
            var id = this.getAttribute('data-id');
            var cb = document.querySelector('.comment-select[value="' + id + '"]');
            if (cb) {
                cb.checked = !cb.checked;
                cb.dispatchEvent(new Event('change'));
            }
        });
    });

    console.log('%c💬 Comment Manager Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
    console.log('%cShortcuts: / Search | ESC Close modal', 'font-size:11px;color:#888;');
})();
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>