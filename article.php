<?php
require_once __DIR__ . '/config/functions.php';

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

if (!$slug) {
    header('Location: ' . url());
    exit;
}

// ============================================
// 📖 AMBIL ARTIKEL
// ============================================
$article = null;
try {
    $stmt = db()->prepare("
        SELECT a.*, 
               u.nama as author_name, u.jabatan, u.prodi, u.foto as author_foto, 
               u.email as author_email, u.bio as author_bio,
               c.name as category_name, c.slug as category_slug
        FROM articles a 
        JOIN users u ON a.author_id = u.id 
        LEFT JOIN categories c ON a.category_id = c.id 
        WHERE a.slug = ? AND a.status = 'published'
    ");
    $stmt->execute([$slug]);
    $article = $stmt->fetch();
} catch (Exception $e) {}

if (!$article) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

// ============================================
// 👁️ INCREMENT VIEWS (anti-spam dengan session)
// ============================================
$viewKey = 'viewed_' . $article['id'];
if (!isset($_SESSION[$viewKey])) {
    try {
        db()->prepare("UPDATE articles SET views = views + 1 WHERE id = ?")->execute([$article['id']]);
        $_SESSION[$viewKey] = time();
    } catch (Exception $e) {}
}

// ============================================
// 💬 HANDLE KOMENTAR (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_comment'])) {
    $nama = isset($_POST['nama']) ? trim($_POST['nama']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
    $parentId = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
    
    // Validasi
    if (strlen($nama) < 2) {
        flash('error', 'Nama minimal 2 karakter!');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Format email tidak valid!');
    } elseif (strlen($comment) < 10) {
        flash('error', 'Komentar minimal 10 karakter!');
    } else {
        try {
            $stmt = db()->prepare("
                INSERT INTO comments (article_id, nama, email, comment, parent_id, status) 
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$article['id'], $nama, $email, $comment, $parentId]);
            flash('success', '✅ Komentar Anda akan ditampilkan setelah direview admin.');
        } catch (Exception $e) {
            flash('error', 'Gagal menyimpan komentar. Silakan coba lagi.');
        }
    }
    header("Location: " . url('article.php?slug=' . $slug . '#comments'));
    exit;
}

// ============================================
// 💬 AMBIL KOMENTAR (dengan threading)
// ============================================
$comments = [];
$commentsTree = [];
try {
    $stmt = db()->prepare("
        SELECT * FROM comments 
        WHERE article_id = ? AND status = 'approved' 
        ORDER BY created_at ASC
    ");
    $stmt->execute([$article['id']]);
    $comments = $stmt->fetchAll();
    
    // Build tree untuk nested comments
    foreach ($comments as $c) {
        $comments[$c['id']] = $c;
        $comments[$c['id']]['replies'] = [];
    }
    foreach ($comments as $c) {
        if ($c['parent_id'] && isset($comments[$c['parent_id']])) {
            $comments[$c['parent_id']]['replies'][] = &$comments[$c['id']];
        } else {
            $commentsTree[] = &$comments[$c['id']];
        }
    }
} catch (Exception $e) {}

// ============================================
// 🔗 ARTIKEL TERKAIT (smart: by category + tags)
// ============================================
$related = [];
try {
    // Ambil dari kategori yang sama, exclude current
    $stmt = db()->prepare("
        SELECT a.id, a.title, a.slug, a.featured_image, a.created_at, a.views,
               u.nama as author_name, u.foto as author_foto
        FROM articles a
        JOIN users u ON a.author_id = u.id
        WHERE a.category_id = ? AND a.id != ? AND a.status = 'published'
        ORDER BY a.views DESC, a.created_at DESC
        LIMIT 3
    ");
    $stmt->execute([$article['category_id'], $article['id']]);
    $related = $stmt->fetchAll();
} catch (Exception $e) {}

// ============================================
// ⬅️➡️ PREVIOUS & NEXT ARTICLE
// ============================================
$prevArticle = null;
$nextArticle = null;
try {
    $stmt = db()->prepare("
        SELECT id, title, slug FROM articles 
        WHERE status = 'published' AND created_at < ? 
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$article['created_at']]);
    $prevArticle = $stmt->fetch();
    
    $stmt = db()->prepare("
        SELECT id, title, slug FROM articles 
        WHERE status = 'published' AND created_at > ? 
        ORDER BY created_at ASC LIMIT 1
    ");
    $stmt->execute([$article['created_at']]);
    $nextArticle = $stmt->fetch();
} catch (Exception $e) {}

// ============================================
// 📊 CALCULATIONS
// ============================================
// Reading time (200 kata per menit)
$wordCount = str_word_count(strip_tags($article['content'] ?? ''));
$readingTime = max(1, ceil($wordCount / 200));

// Format publish date
$publishDate = formatDate($article['created_at']);
$publishTimeAgo = timeAgo($article['created_at']);

// Full URL untuk share
$currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
            . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// Extract tags (dari content, cari hashtag atau manual)
$tags = [];
if (!empty($article['tags'])) {
    $tags = array_map('trim', explode(',', $article['tags']));
}

// SEO Meta
$pageTitle = $article['title'];
$pageDescription = $article['excerpt'] ?? excerpt(strip_tags($article['content']), 160);
$pageImage = $article['featured_image'] ?? url('assets/uploads/default.png');

// Author info
$authorAvatar = fotoUrl($article['author_foto'] ?? '');
$authorName = $article['author_name'] ?? 'Penulis';
$authorJabatan = $article['jabatan'] ?? 'Dosen';
$authorProdi = $article['prodi'] ?? '';
$authorBio = $article['author_bio'] ?? '';

include __DIR__ . '/includes/header.php';
?>

<style>
/* ===== ARTICLE ULTIMATE STYLES ===== */

/* Reading Progress Bar */
.reading-progress-bar {
    position: fixed;
    top: 0;
    left: 0;
    width: 0%;
    height: 4px;
    background: linear-gradient(90deg, #f39c12, #e67e22);
    z-index: 9999;
    transition: width 0.1s ease;
    box-shadow: 0 0 10px rgba(243, 156, 18, 0.5);
}

/* Breadcrumb */
.article-breadcrumb {
    background: #f8f9fa;
    padding: 1rem 0;
    border-bottom: 1px solid #e9ecef;
    font-size: 0.88rem;
}
.breadcrumb-list {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    list-style: none;
    padding: 0;
    margin: 0;
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
.breadcrumb-list a:hover { color: #f39c12; }
.breadcrumb-list .separator { color: #ccc; }
.breadcrumb-list .current { color: #f39c12; font-weight: 600; }

/* Article Layout */
.article-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 3rem;
    padding: 3rem 0;
    max-width: 1200px;
    margin: 0 auto;
}

/* Article Main */
.article-main { min-width: 0; }

/* Article Header */
.article-header-ult {
    margin-bottom: 2rem;
}
.article-category-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    padding: 0.4rem 1.2rem;
    border-radius: 25px;
    font-size: 0.82rem;
    font-weight: 700;
    text-decoration: none;
    margin-bottom: 1rem;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.article-category-badge:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(243, 156, 18, 0.4);
    color: white;
}
.article-title-ult {
    font-size: 2.5rem;
    line-height: 1.2;
    color: #1e3a5f;
    margin-bottom: 1.5rem;
    font-weight: 800;
}

/* Article Meta */
.article-meta-ult {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    flex-wrap: wrap;
    padding: 1.5rem;
    background: #f8f9fa;
    border-radius: 15px;
    margin-bottom: 2rem;
}
.author-info-ult {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    flex: 1;
    min-width: 200px;
}
.author-avatar-ult {
    width: 55px;
    height: 55px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #f39c12;
    flex-shrink: 0;
}
.author-details h4 {
    margin: 0 0 0.2rem 0;
    color: #1e3a5f;
    font-size: 1rem;
}
.author-details p {
    margin: 0;
    color: #666;
    font-size: 0.85rem;
}
.meta-stats-ult {
    display: flex;
    gap: 1.2rem;
    flex-wrap: wrap;
}
.meta-stat-item {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    color: #666;
    font-size: 0.88rem;
}
.meta-stat-item i { color: #f39c12; }
.meta-stat-item strong { color: #1e3a5f; }

/* Featured Image */
.featured-image-ult {
    width: 100%;
    height: 450px;
    object-fit: cover;
    border-radius: 20px;
    margin-bottom: 2rem;
    box-shadow: 0 10px 40px rgba(0,0,0,0.1);
}

/* Article Content Typography */
.article-content-ult {
    font-size: 1.08rem;
    line-height: 1.8;
    color: #333;
}
.article-content-ult h1,
.article-content-ult h2,
.article-content-ult h3,
.article-content-ult h4 {
    color: #1e3a5f;
    margin-top: 2rem;
    margin-bottom: 1rem;
    font-weight: 700;
    line-height: 1.3;
}
.article-content-ult h2 { 
    font-size: 1.8rem; 
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
}
.article-content-ult h3 { font-size: 1.4rem; }
.article-content-ult h4 { font-size: 1.15rem; }
.article-content-ult p { margin-bottom: 1.3rem; }
.article-content-ult a {
    color: #f39c12;
    text-decoration: underline;
    transition: color 0.2s ease;
}
.article-content-ult a:hover { color: #e67e22; }
.article-content-ult ul, 
.article-content-ult ol {
    margin-bottom: 1.3rem;
    padding-left: 1.5rem;
}
.article-content-ult li { margin-bottom: 0.5rem; }
.article-content-ult blockquote {
    border-left: 4px solid #f39c12;
    padding: 1rem 1.5rem;
    margin: 1.5rem 0;
    background: #fff9e6;
    border-radius: 0 10px 10px 0;
    font-style: italic;
    color: #555;
}
.article-content-ult img {
    max-width: 100%;
    height: auto;
    border-radius: 10px;
    margin: 1.5rem 0;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}
.article-content-ult pre {
    background: #1e2638;
    color: #e5e8ec;
    padding: 1.5rem;
    border-radius: 10px;
    overflow-x: auto;
    margin: 1.5rem 0;
    font-size: 0.9rem;
    line-height: 1.6;
}
.article-content-ult code {
    background: #f4f6f9;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.9em;
    color: #e74c3c;
}
.article-content-ult pre code {
    background: transparent;
    padding: 0;
    color: inherit;
}
.article-content-ult table {
    width: 100%;
    border-collapse: collapse;
    margin: 1.5rem 0;
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.article-content-ult th {
    background: #1e3a5f;
    color: white;
    padding: 0.8rem 1rem;
    text-align: left;
    font-weight: 600;
}
.article-content-ult td {
    padding: 0.8rem 1rem;
    border-bottom: 1px solid #f0f0f0;
}
.article-content-ult tr:last-child td { border-bottom: none; }
.article-content-ult tr:hover { background: #f8f9fa; }

/* Tags */
.article-tags-ult {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin: 2rem 0;
    padding-top: 1.5rem;
    border-top: 1px solid #f0f0f0;
}
.article-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    background: #f4f6f9;
    color: #1e3a5f;
    padding: 0.4rem 1rem;
    border-radius: 20px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 500;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}
.article-tag:hover {
    background: #1e3a5f;
    color: white;
    transform: translateY(-2px);
}
.article-tag i { font-size: 0.75rem; }

/* Share Buttons */
.share-section-ult {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    padding: 2rem;
    border-radius: 15px;
    margin: 2.5rem 0;
}
.share-section-ult h4 {
    color: #1e3a5f;
    margin-bottom: 1rem;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.share-buttons-ult {
    display: flex;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.share-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 1.2rem;
    border-radius: 10px;
    text-decoration: none;
    color: white;
    font-size: 0.88rem;
    font-weight: 600;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}
.share-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
}
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }
.share-btn.linkedin { background: #0a66c2; }
.share-btn.telegram { background: #0088cc; }
.share-btn.email { background: #ea4335; }
.share-btn.copy-link { background: #1e3a5f; }
.share-btn.print { background: #6c757d; }

/* Prev/Next Navigation */
.article-nav-ult {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin: 3rem 0;
}
.article-nav-item {
    padding: 1.2rem;
    background: #f8f9fa;
    border-radius: 12px;
    text-decoration: none;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}
.article-nav-item:hover {
    background: white;
    border-color: #f39c12;
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.08);
}
.article-nav-item.next { text-align: right; }
.article-nav-label {
    font-size: 0.78rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 0.3rem;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}
.article-nav-item.next .article-nav-label { justify-content: flex-end; }
.article-nav-title {
    color: #1e3a5f;
    font-weight: 600;
    font-size: 0.95rem;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Author Bio Box */
.author-bio-box {
    background: linear-gradient(135deg, #1e3a5f 0%, #2c5f8d 100%);
    color: white;
    padding: 2rem;
    border-radius: 20px;
    margin: 2.5rem 0;
    position: relative;
    overflow: hidden;
}
.author-bio-box::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(243, 156, 18, 0.2), transparent 70%);
    border-radius: 50%;
}
.author-bio-content {
    position: relative;
    z-index: 2;
    display: flex;
    gap: 1.5rem;
    align-items: flex-start;
}
.author-bio-avatar {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #f39c12;
    flex-shrink: 0;
}
.author-bio-info h4 {
    color: white;
    margin: 0 0 0.3rem 0;
    font-size: 1.3rem;
}
.author-bio-role {
    color: #f39c12;
    font-size: 0.9rem;
    margin-bottom: 0.8rem;
    font-weight: 600;
}
.author-bio-text {
    color: rgba(255,255,255,0.9);
    line-height: 1.6;
    font-size: 0.92rem;
    margin: 0;
}

/* Related Articles */
.related-articles-ult {
    margin: 3rem 0;
    padding: 2rem;
    background: #f8f9fa;
    border-radius: 20px;
}
.related-articles-ult h3 {
    color: #1e3a5f;
    margin-bottom: 1.5rem;
    font-size: 1.4rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.related-grid-ult {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}
.related-card-ult {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.06);
    transition: all 0.3s ease;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
}
.related-card-ult:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
}
.related-card-image {
    height: 160px;
    background-size: cover;
    background-position: center;
    position: relative;
}
.related-card-image::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, transparent 60%, rgba(0,0,0,0.3));
}
.related-card-content {
    padding: 1.2rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.related-card-title {
    color: #1e3a5f;
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
    margin-bottom: 0.8rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.related-card-meta {
    display: flex;
    gap: 0.8rem;
    font-size: 0.78rem;
    color: #888;
    margin-top: auto;
}
.related-card-meta span {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

/* Comments Section */
.comments-section-ult {
    margin: 3rem 0;
    padding: 2rem;
    background: white;
    border-radius: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}
.comments-section-ult h3 {
    color: #1e3a5f;
    margin-bottom: 1.5rem;
    font-size: 1.4rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f0f0f0;
}
.comments-count-badge {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    padding: 0.2rem 0.8rem;
    border-radius: 15px;
    font-size: 0.85rem;
    font-weight: 700;
}

/* Comment Form */
.comment-form-ult {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 15px;
    margin-bottom: 2rem;
}
.comment-form-ult h4 {
    color: #1e3a5f;
    margin-bottom: 1rem;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.form-row-ult {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}
.form-row-ult input {
    padding: 0.8rem 1rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.95rem;
    outline: none;
    transition: border 0.3s ease;
    width: 100%;
}
.form-row-ult input:focus { border-color: #f39c12; }
.comment-form-ult textarea {
    width: 100%;
    padding: 0.8rem 1rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.95rem;
    outline: none;
    transition: border 0.3s ease;
    resize: vertical;
    min-height: 120px;
    margin-bottom: 1rem;
    font-family: inherit;
}
.comment-form-ult textarea:focus { border-color: #f39c12; }
.submit-comment-btn {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border: none;
    padding: 0.9rem 2rem;
    border-radius: 25px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.submit-comment-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.4);
}
.replying-to {
    background: #fff3cd;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    font-size: 0.88rem;
    color: #856404;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.replying-to a {
    color: #856404;
    font-weight: 600;
}
.cancel-reply {
    background: #dc3545;
    color: white;
    border: none;
    padding: 0.3rem 0.8rem;
    border-radius: 15px;
    font-size: 0.78rem;
    cursor: pointer;
    text-decoration: none;
}

/* Comments List */
.comments-list-ult {
    list-style: none;
    padding: 0;
    margin: 0;
}
.comment-item-ult {
    padding: 1.5rem;
    background: #f8f9fa;
    border-radius: 12px;
    margin-bottom: 1rem;
    transition: all 0.2s ease;
    border-left: 4px solid #f39c12;
}
.comment-item-ult:hover {
    background: white;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}
.comment-header-ult {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.8rem;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.comment-author-ult {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
.comment-avatar-ult {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #f39c12;
}
.comment-author-name {
    font-weight: 700;
    color: #1e3a5f;
    font-size: 0.95rem;
}
.comment-date-ult {
    color: #888;
    font-size: 0.78rem;
}
.comment-text-ult {
    color: #555;
    line-height: 1.6;
    margin-bottom: 0.8rem;
    font-size: 0.92rem;
}
.comment-actions-ult {
    display: flex;
    gap: 1rem;
    font-size: 0.82rem;
}
.comment-action-btn {
    color: #666;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.3rem;
    transition: color 0.2s ease;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    font-size: inherit;
}
.comment-action-btn:hover { color: #f39c12; }

/* Nested Replies */
.comment-replies {
    margin-top: 1rem;
    margin-left: 2rem;
    padding-left: 1rem;
    border-left: 2px solid #e0e0e0;
}
.comment-replies .comment-item-ult {
    background: #fff;
    border-left-color: #3498db;
}

/* Sidebar (TOC + Author) */
.article-sidebar-ult {
    position: sticky;
    top: 90px;
    align-self: start;
    max-height: calc(100vh - 110px);
    overflow-y: auto;
}
.sidebar-widget-ult {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}
.sidebar-widget-ult h4 {
    color: #1e3a5f;
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.8rem;
    border-bottom: 2px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.sidebar-widget-ult h4 i { color: #f39c12; }

/* TOC */
.toc-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.toc-list li {
    margin-bottom: 0.3rem;
}
.toc-list a {
    display: block;
    padding: 0.5rem 0.8rem;
    color: #555;
    text-decoration: none;
    border-radius: 8px;
    font-size: 0.88rem;
    transition: all 0.2s ease;
    border-left: 3px solid transparent;
    line-height: 1.4;
}
.toc-list a:hover {
    background: #f8f9fa;
    color: #f39c12;
    border-left-color: #f39c12;
}
.toc-list a.active {
    background: #fff3cd;
    color: #856404;
    border-left-color: #f39c12;
    font-weight: 600;
}
.toc-list .toc-h3 {
    padding-left: 1.8rem;
    font-size: 0.82rem;
}

/* Article Stats Widget */
.article-stats-widget {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.8rem;
}
.stat-mini {
    text-align: center;
    padding: 1rem 0.5rem;
    background: #f8f9fa;
    border-radius: 10px;
    transition: all 0.2s ease;
}
.stat-mini:hover {
    background: #fff3cd;
    transform: translateY(-2px);
}
.stat-mini .icon {
    font-size: 1.5rem;
    color: #f39c12;
    margin-bottom: 0.3rem;
    display: block;
}
.stat-mini .value {
    font-size: 1.3rem;
    font-weight: 700;
    color: #1e3a5f;
    line-height: 1;
    margin-bottom: 0.2rem;
    display: block;
}
.stat-mini .label {
    font-size: 0.72rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Newsletter Widget */
.newsletter-widget {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    text-align: center;
}
.newsletter-widget h4 {
    color: white;
    border-bottom-color: rgba(255,255,255,0.2);
}
.newsletter-widget h4 i { color: white; }
.newsletter-widget p {
    font-size: 0.88rem;
    margin-bottom: 1rem;
    opacity: 0.95;
}
.newsletter-widget input {
    width: 100%;
    padding: 0.7rem;
    border: none;
    border-radius: 8px;
    margin-bottom: 0.5rem;
    font-size: 0.88rem;
    outline: none;
}
.newsletter-widget button {
    width: 100%;
    padding: 0.7rem;
    background: #1e3a5f;
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.88rem;
}
.newsletter-widget button:hover {
    background: #16324f;
}

/* Empty state */
.empty-comments {
    text-align: center;
    padding: 3rem 2rem;
    color: #888;
}
.empty-comments i {
    font-size: 3rem;
    color: #ddd;
    margin-bottom: 1rem;
    display: block;
}

/* Floating Action Button (mobile TOC) */
.mobile-toc-toggle {
    display: none;
    position: fixed;
    bottom: 25px;
    right: 25px;
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 5px 20px rgba(243, 156, 18, 0.4);
    z-index: 998;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

/* Copy Link Toast */
.copy-toast {
    position: fixed;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%) translateY(100px);
    background: #1e3a5f;
    color: white;
    padding: 0.8rem 1.5rem;
    border-radius: 25px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    opacity: 0;
    transition: all 0.3s ease;
    z-index: 9999;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.copy-toast.show {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}

/* Responsive */
@media (max-width: 992px) {
    .article-layout {
        grid-template-columns: 1fr;
    }
    .article-sidebar-ult {
        position: static;
        max-height: none;
    }
    .article-title-ult {
        font-size: 2rem;
    }
    .featured-image-ult {
        height: 300px;
    }
    .mobile-toc-toggle {
        display: flex;
    }
    .article-sidebar-ult.mobile-hidden {
        display: none;
    }
}
@media (max-width: 576px) {
    .article-title-ult {
        font-size: 1.5rem;
    }
    .article-meta-ult {
        flex-direction: column;
        align-items: flex-start;
    }
    .form-row-ult {
        grid-template-columns: 1fr;
    }
    .article-nav-ult {
        grid-template-columns: 1fr;
    }
    .share-buttons-ult {
        flex-direction: column;
    }
    .share-btn {
        width: 100%;
        justify-content: center;
    }
    .featured-image-ult {
        height: 220px;
        border-radius: 10px;
    }
    .comment-replies {
        margin-left: 0.5rem;
        padding-left: 0.5rem;
    }
}

/* Print styles */
@media print {
    .reading-progress-bar,
    .article-breadcrumb,
    .article-sidebar-ult,
    .share-section-ult,
    .article-nav-ult,
    .related-articles-ult,
    .comments-section-ult,
    .mobile-toc-toggle {
        display: none !important;
    }
    .article-layout {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Reading Progress Bar -->
<div class="reading-progress-bar" id="readingProgress"></div>

<!-- Breadcrumb -->
<div class="article-breadcrumb">
    <div class="container">
        <ul class="breadcrumb-list">
            <li><a href="<?php echo url(); ?>"><i class="fas fa-home"></i> Beranda</a></li>
            <li class="separator">/</li>
            <?php if ($article['category_slug']): ?>
                <li><a href="<?php echo url('category.php?slug=' . $article['category_slug']); ?>">
                    <?php echo htmlspecialchars($article['category_name']); ?>
                </a></li>
                <li class="separator">/</li>
            <?php endif; ?>
            <li class="current"><?php echo htmlspecialchars(excerpt($article['title'], 50)); ?></li>
        </ul>
    </div>
</div>

<!-- Article Layout -->
<div class="container">
    <div class="article-layout">
        
        <!-- ===== MAIN CONTENT ===== -->
        <article class="article-main">
            
            <!-- Article Header -->
            <header class="article-header-ult">
                <?php if ($article['category_name']): ?>
                    <a href="<?php echo url('category.php?slug=' . ($article['category_slug'] ?? '')); ?>" 
                       class="article-category-badge">
                        <i class="fas fa-tag"></i> 
                        <?php echo htmlspecialchars($article['category_name']); ?>
                    </a>
                <?php endif; ?>
                
                <h1 class="article-title-ult"><?php echo htmlspecialchars($article['title']); ?></h1>
                
                <!-- Article Meta -->
                <div class="article-meta-ult">
                    <div class="author-info-ult">
                        <img src="<?php echo $authorAvatar; ?>" 
                             onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($authorName); ?>&background=1e3a5f&color=fff&size=110'"
                             alt="<?php echo htmlspecialchars($authorName); ?>" 
                             class="author-avatar-ult">
                        <div class="author-details">
                            <h4><?php echo htmlspecialchars($authorName); ?></h4>
                            <p><?php echo htmlspecialchars($authorJabatan); ?><?php echo $authorProdi ? ' • ' . htmlspecialchars($authorProdi) : ''; ?></p>
                        </div>
                    </div>
                    <div class="meta-stats-ult">
                        <div class="meta-stat-item">
                            <i class="fas fa-calendar"></i>
                            <span><?php echo $publishDate; ?></span>
                        </div>
                        <div class="meta-stat-item">
                            <i class="fas fa-clock"></i>
                            <span><strong><?php echo $readingTime; ?></strong> menit baca</span>
                        </div>
                        <div class="meta-stat-item">
                            <i class="fas fa-eye"></i>
                            <span><strong><?php echo number_format($article['views']); ?></strong> views</span>
                        </div>
                        <div class="meta-stat-item">
                            <i class="fas fa-comments"></i>
                            <span><strong><?php echo count($comments); ?></strong> komentar</span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Featured Image -->
            <?php if (!empty($article['featured_image'])): ?>
                <img src="<?php echo htmlspecialchars($article['featured_image']); ?>" 
                     alt="<?php echo htmlspecialchars($article['title']); ?>" 
                     class="featured-image-ult"
                     loading="lazy">
            <?php endif; ?>

            <!-- Article Content -->
            <div class="article-content-ult" id="articleContent">
                <?php echo $article['content']; ?>
            </div>

            <!-- Tags -->
            <?php if (!empty($tags)): ?>
                <div class="article-tags-ult">
                    <strong style="color:#1e3a5f;margin-right:0.5rem;">
                        <i class="fas fa-tags"></i> Tags:
                    </strong>
                    <?php foreach ($tags as $tag): ?>
                        <a href="<?php echo url('search.php?q=' . urlencode($tag)); ?>" class="article-tag">
                            <i class="fas fa-hashtag"></i>
                            <?php echo htmlspecialchars($tag); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Share Section -->
            <div class="share-section-ult">
                <h4><i class="fas fa-share-alt"></i> Bagikan Artikel Ini</h4>
                <div class="share-buttons-ult">
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($currentUrl); ?>" 
                       target="_blank" class="share-btn facebook">
                        <i class="fab fa-facebook-f"></i> Facebook
                    </a>
                    <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode($currentUrl); ?>&text=<?php echo urlencode($article['title']); ?>" 
                       target="_blank" class="share-btn twitter">
                        <i class="fab fa-twitter"></i> Twitter
                    </a>
                    <a href="https://wa.me/?text=<?php echo urlencode($article['title'] . ' ' . $currentUrl); ?>" 
                       target="_blank" class="share-btn whatsapp">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </a>
                    <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo urlencode($currentUrl); ?>" 
                       target="_blank" class="share-btn linkedin">
                        <i class="fab fa-linkedin-in"></i> LinkedIn
                    </a>
                    <a href="https://t.me/share/url?url=<?php echo urlencode($currentUrl); ?>&text=<?php echo urlencode($article['title']); ?>" 
                       target="_blank" class="share-btn telegram">
                        <i class="fab fa-telegram-plane"></i> Telegram
                    </a>
                    <a href="mailto:?subject=<?php echo urlencode($article['title']); ?>&body=<?php echo urlencode('Baca artikel menarik ini: ' . $currentUrl); ?>" 
                       class="share-btn email">
                        <i class="fas fa-envelope"></i> Email
                    </a>
                    <button type="button" class="share-btn copy-link" onclick="copyLink()">
                        <i class="fas fa-link"></i> Copy Link
                    </button>
                    <button type="button" class="share-btn print" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <!-- Author Bio Box -->
            <div class="author-bio-box">
                <div class="author-bio-content">
                    <img src="<?php echo $authorAvatar; ?>" 
                         onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($authorName); ?>&background=f39c12&color=fff&size=200'"
                         alt="<?php echo htmlspecialchars($authorName); ?>" 
                         class="author-bio-avatar">
                    <div class="author-bio-info">
                        <h4><?php echo htmlspecialchars($authorName); ?></h4>
                        <div class="author-bio-role">
                            <?php echo htmlspecialchars($authorJabatan); ?>
                            <?php if ($authorProdi): ?> • <?php echo htmlspecialchars($authorProdi); ?><?php endif; ?>
                        </div>
                        <?php if ($authorBio): ?>
                            <p class="author-bio-text"><?php echo htmlspecialchars($authorBio); ?></p>
                        <?php else: ?>
                            <p class="author-bio-text">
                                Seorang dosen yang aktif berbagi ilmu dan pengalaman melalui tulisan. 
                                Mengabdi di bidang <?php echo htmlspecialchars($article['category_name'] ?? 'pendidikan'); ?> 
                                dengan semangat mencerdaskan kehidupan bangsa.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Prev/Next Navigation -->
            <?php if ($prevArticle || $nextArticle): ?>
                <div class="article-nav-ult">
                    <?php if ($prevArticle): ?>
                        <a href="<?php echo url('article.php?slug=' . $prevArticle['slug']); ?>" class="article-nav-item prev">
                            <div class="article-nav-label">
                                <i class="fas fa-arrow-left"></i> Artikel Sebelumnya
                            </div>
                            <div class="article-nav-title">
                                <?php echo htmlspecialchars($prevArticle['title']); ?>
                            </div>
                        </a>
                    <?php else: ?>
                        <div></div>
                    <?php endif; ?>
                    
                    <?php if ($nextArticle): ?>
                        <a href="<?php echo url('article.php?slug=' . $nextArticle['slug']); ?>" class="article-nav-item next">
                            <div class="article-nav-label">
                                Artikel Selanjutnya <i class="fas fa-arrow-right"></i>
                            </div>
                            <div class="article-nav-title">
                                <?php echo htmlspecialchars($nextArticle['title']); ?>
                            </div>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Related Articles -->
            <?php if (!empty($related)): ?>
                <div class="related-articles-ult">
                    <h3><i class="fas fa-newspaper"></i> Artikel Terkait</h3>
                    <div class="related-grid-ult">
                        <?php foreach ($related as $r): 
                            $rImage = $r['featured_image'] ?? 'https://images.unsplash.com/photo-1499209974431-9dddcece7f88?w=400';
                            $rAvatar = url(ltrim(!empty($r['author_foto']) ? $r['author_foto'] : 'assets/uploads/default.png', '/'));
                        ?>
                            <a href="<?php echo url('article.php?slug=' . $r['slug']); ?>" class="related-card-ult">
                                <div class="related-card-image" style="background-image: url('<?php echo htmlspecialchars($rImage); ?>');"></div>
                                <div class="related-card-content">
                                    <div class="related-card-title">
                                        <?php echo htmlspecialchars($r['title']); ?>
                                    </div>
                                    <div class="related-card-meta">
                                        <span><i class="fas fa-calendar"></i> <?php echo formatDate($r['created_at']); ?></span>
                                        <span><i class="fas fa-eye"></i> <?php echo number_format($r['views']); ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Comments Section -->
            <section class="comments-section-ult" id="comments">
                <h3>
                    <i class="fas fa-comments"></i> Diskusi 
                    <span class="comments-count-badge"><?php echo count($comments); ?></span>
                </h3>

                <!-- Comment Form -->
                <div class="comment-form-ult">
                    <h4><i class="fas fa-pen"></i> Tinggalkan Komentar</h4>
                    <div id="replyingToBox"></div>
                    <form method="POST" action="<?php echo url('article.php?slug=' . $slug . '#comments'); ?>">
                        <input type="hidden" name="parent_id" id="parentIdInput" value="0">
                        <div class="form-row-ult">
                            <input type="text" name="nama" placeholder="Nama Anda *" required minlength="2">
                            <input type="email" name="email" placeholder="Email Anda *" required>
                        </div>
                        <textarea name="comment" 
                                  placeholder="Tulis komentar Anda di sini... (minimal 10 karakter) *" 
                                  required minlength="10"></textarea>
                        <button type="submit" name="submit_comment" class="submit-comment-btn">
                            <i class="fas fa-paper-plane"></i> Kirim Komentar
                        </button>
                    </form>
                </div>

                <!-- Comments List -->
                <?php if (empty($commentsTree)): ?>
                    <div class="empty-comments">
                        <i class="fas fa-comment-slash"></i>
                        <h4>Belum ada komentar</h4>
                        <p>Jadilah yang pertama memberikan komentar pada artikel ini!</p>
                    </div>
                <?php else: ?>
                    <ul class="comments-list-ult">
                        <?php
                        // Recursive function untuk render nested comments
                        function renderComment($comment, $depth = 0) {
                            $avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($comment['nama']) . '&background=1e3a5f&color=fff&size=80';
                            ?>
                            <li class="comment-item-ult" id="comment-<?php echo $comment['id']; ?>">
                                <div class="comment-header-ult">
                                    <div class="comment-author-ult">
                                        <img src="<?php echo $avatarUrl; ?>" 
                                             alt="<?php echo htmlspecialchars($comment['nama']); ?>" 
                                             class="comment-avatar-ult">
                                        <div>
                                            <div class="comment-author-name">
                                                <?php echo htmlspecialchars($comment['nama']); ?>
                                            </div>
                                            <div class="comment-date-ult">
                                                <?php echo timeAgo($comment['created_at']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="comment-text-ult">
                                    <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                </div>
                                <div class="comment-actions-ult">
                                    <button type="button" class="comment-action-btn" 
                                            onclick="replyToComment(<?php echo $comment['id']; ?>, '<?php echo htmlspecialchars(addslashes($comment['nama'])); ?>')">
                                        <i class="fas fa-reply"></i> Balas
                                    </button>
                                </div>
                                
                                <?php if (!empty($comment['replies'])): ?>
                                    <div class="comment-replies">
                                        <?php foreach ($comment['replies'] as $reply): ?>
                                            <?php renderComment($reply, $depth + 1); ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </li>
                            <?php
                        }
                        
                        foreach ($commentsTree as $comment) {
                            renderComment($comment);
                        }
                        ?>
                    </ul>
                <?php endif; ?>
            </section>

        </article>

        <!-- ===== SIDEBAR ===== -->
        <aside class="article-sidebar-ult" id="articleSidebar">
            
            <!-- TOC Widget (auto-generated by JS) -->
            <div class="sidebar-widget-ult" id="tocWidget" style="display:none;">
                <h4><i class="fas fa-list"></i> Daftar Isi</h4>
                <ul class="toc-list" id="tocList"></ul>
            </div>

            <!-- Article Stats Widget -->
            <div class="sidebar-widget-ult">
                <h4><i class="fas fa-chart-bar"></i> Statistik Artikel</h4>
                <div class="article-stats-widget">
                    <div class="stat-mini">
                        <i class="fas fa-eye icon"></i>
                        <span class="value"><?php echo number_format($article['views']); ?></span>
                        <span class="label">Views</span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-comments icon"></i>
                        <span class="value"><?php echo count($comments); ?></span>
                        <span class="label">Komentar</span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-clock icon"></i>
                        <span class="value"><?php echo $readingTime; ?></span>
                        <span class="label">Menit</span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-file-word icon"></i>
                        <span class="value"><?php echo number_format($wordCount); ?></span>
                        <span class="label">Kata</span>
                    </div>
                </div>
            </div>

            <!-- Author Mini Widget -->
            <div class="sidebar-widget-ult">
                <h4><i class="fas fa-user-edit"></i> Tentang Penulis</h4>
                <div style="text-align:center;padding:0.5rem 0;">
                    <img src="<?php echo $authorAvatar; ?>" 
                         onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($authorName); ?>&background=1e3a5f&color=fff&size=160'"
                         alt="<?php echo htmlspecialchars($authorName); ?>" 
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #f39c12;margin-bottom:0.8rem;">
                    <h5 style="color:#1e3a5f;margin:0 0 0.3rem 0;font-size:1rem;">
                        <?php echo htmlspecialchars($authorName); ?>
                    </h5>
                    <p style="color:#888;font-size:0.82rem;margin:0;">
                        <?php echo htmlspecialchars($authorJabatan); ?>
                    </p>
                </div>
            </div>

            <!-- Newsletter Widget -->
            <div class="sidebar-widget-ult newsletter-widget">
                <h4><i class="fas fa-envelope"></i> Newsletter</h4>
                <p>Dapatkan artikel terbaru langsung di inbox Anda!</p>
                <form onsubmit="event.preventDefault(); alert('✅ Terima kasih telah berlangganan!');">
                    <input type="email" placeholder="email@anda.com" required>
                    <button type="submit">Subscribe</button>
                </form>
            </div>

        </aside>

    </div>
</div>

<!-- Mobile TOC Toggle -->
<button type="button" class="mobile-toc-toggle" id="mobileTocToggle" title="Daftar Isi">
    <i class="fas fa-list"></i>
</button>

<!-- Copy Toast -->
<div class="copy-toast" id="copyToast">
    <i class="fas fa-check-circle"></i> Link berhasil disalin!
</div>

<script>
// ===== READING PROGRESS BAR =====
window.addEventListener('scroll', function() {
    var winScroll = document.body.scrollTop || document.documentElement.scrollTop;
    var height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
    var scrolled = (winScroll / height) * 100;
    document.getElementById('readingProgress').style.width = scrolled + '%';
});

// ===== GENERATE TOC FROM HEADINGS =====
(function() {
    var content = document.getElementById('articleContent');
    var tocList = document.getElementById('tocList');
    var tocWidget = document.getElementById('tocWidget');
    
    if (!content || !tocList) return;
    
    var headings = content.querySelectorAll('h2, h3');
    if (headings.length < 2) return; // Only show TOC if 2+ headings
    
    var tocHTML = '';
    var headingCount = 0;
    
    headings.forEach(function(heading, index) {
        headingCount++;
        var id = 'heading-' + index;
        heading.id = id;
        
        var level = heading.tagName.toLowerCase();
        var text = heading.textContent.trim();
        var cls = level === 'h3' ? 'toc-h3' : '';
        
        tocHTML += '<li><a href="#' + id + '" class="' + cls + '" data-target="' + id + '">' + text + '</a></li>';
    });
    
    if (headingCount >= 2) {
        tocList.innerHTML = tocHTML;
        tocWidget.style.display = 'block';
    }
})();

// ===== TOC SCROLL SPY =====
(function() {
    var tocLinks = document.querySelectorAll('.toc-list a');
    if (tocLinks.length === 0) return;
    
    var headings = [];
    tocLinks.forEach(function(link) {
        var target = document.getElementById(link.getAttribute('data-target'));
        if (target) headings.push({ el: target, link: link });
    });
    
    function updateActive() {
        var scrollPos = window.scrollY + 150;
        var activeHeading = null;
        
        for (var i = 0; i < headings.length; i++) {
            if (headings[i].el.offsetTop <= scrollPos) {
                activeHeading = headings[i];
            }
        }
        
        tocLinks.forEach(function(link) { link.classList.remove('active'); });
        if (activeHeading) activeHeading.link.classList.add('active');
    }
    
    window.addEventListener('scroll', updateActive);
    updateActive();
    
    // Smooth scroll to heading
    tocLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var target = document.getElementById(this.getAttribute('data-target'));
            if (target) {
                var offset = target.offsetTop - 80;
                window.scrollTo({ top: offset, behavior: 'smooth' });
            }
        });
    });
})();

// ===== COPY LINK =====
function copyLink() {
    var url = window.location.href;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            showToast();
        });
    } else {
        var input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showToast();
    }
}

function showToast() {
    var toast = document.getElementById('copyToast');
    toast.classList.add('show');
    setTimeout(function() {
        toast.classList.remove('show');
    }, 2500);
}

// ===== REPLY TO COMMENT =====
function replyToComment(commentId, authorName) {
    document.getElementById('parentIdInput').value = commentId;
    
    var box = document.getElementById('replyingToBox');
    box.innerHTML = '<div class="replying-to">' +
        '<span><i class="fas fa-reply"></i> Membalas komentar <strong>' + authorName + '</strong></span>' +
        '<a href="#" class="cancel-reply" onclick="cancelReply(); return false;">✕ Batal</a>' +
        '</div>';
    
    // Scroll ke form
    document.querySelector('.comment-form-ult').scrollIntoView({ behavior: 'smooth', block: 'center' });
    document.querySelector('textarea[name="comment"]').focus();
}

function cancelReply() {
    document.getElementById('parentIdInput').value = '0';
    document.getElementById('replyingToBox').innerHTML = '';
}

// ===== MOBILE TOC TOGGLE =====
(function() {
    var toggle = document.getElementById('mobileTocToggle');
    var sidebar = document.getElementById('articleSidebar');
    
    if (toggle && sidebar) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-hidden');
            if (sidebar.classList.contains('mobile-hidden')) {
                toggle.innerHTML = '<i class="fas fa-list"></i>';
            } else {
                toggle.innerHTML = '<i class="fas fa-times"></i>';
                sidebar.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }
})();

// ===== LAZY LOAD IMAGES =====
(function() {
    if ('IntersectionObserver' in window) {
        var lazyImages = document.querySelectorAll('img[loading="lazy"]');
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                    }
                    observer.unobserve(img);
                }
            });
        });
        lazyImages.forEach(function(img) { observer.observe(img); });
    }
})();

// ===== IMAGE CLICK TO FULLSCREEN =====
document.querySelectorAll('.article-content-ult img').forEach(function(img) {
    img.style.cursor = 'zoom-in';
    img.addEventListener('click', function() {
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.9);z-index:9999;display:flex;align-items:center;justify-content:center;cursor:zoom-out;padding:2rem;';
        
        var bigImg = document.createElement('img');
        bigImg.src = this.src;
        bigImg.style.cssText = 'max-width:95%;max-height:95%;object-fit:contain;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,0.5);';
        
        overlay.appendChild(bigImg);
        overlay.addEventListener('click', function() {
            overlay.remove();
        });
        
        document.body.appendChild(overlay);
    });
});

console.log('%c📖 Article Page Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
console.log('%cReading time: <?php echo $readingTime; ?> min | Words: <?php echo $wordCount; ?>', 'color:#888;');
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>