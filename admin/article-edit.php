<?php
require_once __DIR__ . '/../config/functions.php';
checkAuth();

$userId = $_SESSION['user_id'];
$articleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$article = null;
$isEdit = false;

// ============================================
// 📖 LOAD ARTICLE JIKA EDIT MODE
// ============================================
if ($articleId > 0) {
    try {
        $stmt = db()->prepare("SELECT * FROM articles WHERE id = ? AND author_id = ?");
        $stmt->execute([$articleId, $userId]);
        $article = $stmt->fetch();
        if ($article) {
            $isEdit = true;
        } else {
            flash('error', 'Artikel tidak ditemukan atau Anda tidak punya akses.');
            header('Location: ' . url('admin/articles.php'));
            exit;
        }
    } catch (Exception $e) {
        flash('error', 'Gagal memuat artikel: ' . $e->getMessage());
        header('Location: ' . url('admin/articles.php'));
        exit;
    }
}

// ============================================
// 💾 HANDLE POST (CREATE / UPDATE)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Sanitize input
        $title = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        $excerpt = trim($_POST['excerpt'] ?? '');
        $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
        $status = $_POST['status'] ?? 'draft';
        $customSlug = trim($_POST['slug'] ?? '');
        $tags = trim($_POST['tags'] ?? '');
        $metaTitle = trim($_POST['meta_title'] ?? '');
        $metaDescription = trim($_POST['meta_description'] ?? '');
        
        // Validasi
        if (empty($title)) {
            throw new Exception('Judul artikel wajib diisi!');
        }
        if (strlen($title) < 5) {
            throw new Exception('Judul minimal 5 karakter!');
        }
        if (empty($content) || strlen(strip_tags($content)) < 50) {
            throw new Exception('Konten minimal 50 karakter!');
        }
        if ($categoryId <= 0) {
            throw new Exception('Kategori wajib dipilih!');
        }
        if (!in_array($status, ['draft', 'published'])) {
            $status = 'draft';
        }
        
        // Featured image upload
        $featuredImage = $isEdit ? ($article['featured_image'] ?? null) : null;
        if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['featured_image'];
            
            // Validasi file
            $maxSize = 5 * 1024 * 1024; // 5MB
            if ($file['size'] > $maxSize) {
                throw new Exception('Ukuran gambar maksimal 5MB!');
            }
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes)) {
                throw new Exception('Format gambar tidak valid! Gunakan JPG, PNG, WEBP, atau GIF.');
            }
            
            if (function_exists('uploadImage')) {
                $uploaded = uploadImage($file, 'articles');
                if ($uploaded) {
                    $featuredImage = $uploaded;
                }
            }
        }
        
        // Handle remove image
        if (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
            $featuredImage = null;
        }
        
        // Generate slug
        if (!empty($customSlug)) {
            $slug = slugify($customSlug);
        } else {
            $slug = slugify($title);
        }
        
        // Cek slug unik
        $baseSlug = $slug;
        $counter = 1;
        while (true) {
            $checkQuery = "SELECT id FROM articles WHERE slug = ?";
            $checkParams = [$slug];
            if ($isEdit) {
                $checkQuery .= " AND id != ?";
                $checkParams[] = $articleId;
            }
            $stmt = db()->prepare($checkQuery);
            $stmt->execute($checkParams);
            if (!$stmt->fetch()) break;
            $counter++;
            $slug = $baseSlug . '-' . $counter;
        }
        
        // Auto-generate excerpt jika kosong
        if (empty($excerpt)) {
            $excerpt = excerpt(strip_tags($content), 160);
        }
        
        // Auto-generate meta description jika kosong
        if (empty($metaDescription)) {
            $metaDescription = excerpt(strip_tags($content), 155);
        }
        
        if ($isEdit) {
            // UPDATE
            $stmt = db()->prepare("
                UPDATE articles SET 
                    title = ?, slug = ?, content = ?, excerpt = ?, 
                    category_id = ?, featured_image = ?, status = ?, 
                    tags = ?, meta_title = ?, meta_description = ?,
                    updated_at = NOW()
                WHERE id = ? AND author_id = ?
            ");
            $stmt->execute([
                $title, $slug, $content, $excerpt, 
                $categoryId, $featuredImage, $status,
                $tags, $metaTitle, $metaDescription,
                $articleId, $userId
            ]);
            
            flash('success', '✅ Artikel berhasil diupdate!');
        } else {
            // CREATE
            $stmt = db()->prepare("
                INSERT INTO articles 
                    (title, slug, content, excerpt, category_id, featured_image, 
                     author_id, status, tags, meta_title, meta_description, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $title, $slug, $content, $excerpt, $categoryId, $featuredImage,
                $userId, $status, $tags, $metaTitle, $metaDescription
            ]);
            
            $newId = db()->lastInsertId();
            flash('success', '🎉 Artikel berhasil ' . ($status === 'published' ? 'dipublikasikan' : 'disimpan sebagai draft') . '!');
            $articleId = $newId;
            $isEdit = true;
        }
        
        // Redirect ke editor (biar user bisa lanjut edit)
        header('Location: ' . url('admin/article-edit.php?id=' . $articleId));
        exit;
        
    } catch (Exception $e) {
        flash('error', '❌ ' . $e->getMessage());
        // Redirect back with POST data preserved in session if needed
        header('Location: ' . url('admin/article-edit.php' . ($articleId ? '?id=' . $articleId : '')));
        exit;
    }
}

// ============================================
// 📚 LOAD CATEGORIES
// ============================================
$categories = [];
try {
    $categories = db()->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {}

// ============================================
// 🏷️ LOAD EXISTING TAGS (untuk autocomplete)
// ============================================
$allTags = [];
try {
    $stmt = db()->query("SELECT DISTINCT tags FROM articles WHERE tags IS NOT NULL AND tags != ''");
    $tagRows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tagRows as $tagString) {
        $tags = array_map('trim', explode(',', $tagString));
        foreach ($tags as $tag) {
            if (!empty($tag) && !in_array($tag, $allTags)) {
                $allTags[] = $tag;
            }
        }
    }
    sort($allTags);
} catch (Exception $e) {}

$pageTitle = $isEdit ? 'Edit: ' . ($article['title'] ?? '') : 'Tulis Artikel Baru';
include __DIR__ . '/../includes/admin-header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ===== EDITOR ULTIMATE STYLES ===== */

.editor-layout {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.editor-main {
    min-width: 0;
}

.editor-sidebar {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

/* ===== EDITOR HEADER BAR ===== */
.editor-topbar {
    background: white;
    border-radius: 15px;
    padding: 1rem 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}
.editor-topbar-left {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex: 1;
    min-width: 0;
}
.editor-back-btn {
    width: 40px; height: 40px;
    background: #f4f6f9;
    color: #1e3a5f;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.editor-back-btn:hover {
    background: #1e3a5f;
    color: white;
    transform: translateX(-3px);
}
.editor-title-info {
    flex: 1;
    min-width: 0;
}
.editor-title-info h1 {
    font-size: 1.3rem;
    color: #1e3a5f;
    margin: 0 0 0.2rem 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.editor-save-status {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.78rem;
    color: #888;
}
.editor-save-status i { color: #27ae60; }
.editor-save-status.saving i { color: #f39c12; animation: spin 1s linear infinite; }
.editor-save-status.error i { color: #e74c3c; }

.editor-topbar-right {
    display: flex;
    gap: 0.5rem;
    flex-shrink: 0;
}

.btn-draft {
    background: #f4f6f9;
    color: #1e3a5f;
    border: none;
    padding: 0.7rem 1.3rem;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.88rem;
}
.btn-draft:hover {
    background: #e9ecef;
    transform: translateY(-1px);
}

.btn-publish {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border: none;
    padding: 0.7rem 1.5rem;
    border-radius: 10px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.88rem;
    box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
}
.btn-publish:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.4);
}
.btn-publish:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* ===== EDITOR CARDS ===== */
.editor-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.editor-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 0.8rem;
    border-bottom: 2px solid #f0f0f0;
}
.editor-card-header h3 {
    color: #1e3a5f;
    font-size: 1rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.editor-card-header h3 i {
    color: #f39c12;
}

/* ===== TITLE INPUT (Enhanced) ===== */
.title-input-wrap {
    position: relative;
}
.title-input {
    width: 100%;
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a5f;
    border: none;
    border-bottom: 2px solid #f0f0f0;
    padding: 1rem 0 0.8rem 0;
    outline: none;
    transition: border 0.3s ease;
    background: transparent;
    font-family: inherit;
}
.title-input:focus {
    border-bottom-color: #f39c12;
}
.title-input::placeholder {
    color: #ccc;
    font-weight: 400;
}
.title-counter {
    position: absolute;
    right: 0;
    bottom: -22px;
    font-size: 0.75rem;
    color: #888;
}
.title-counter.warning { color: #f39c12; }
.title-counter.danger { color: #e74c3c; }

/* ===== CKEditor Wrapper ===== */
.ck-editor-wrap {
    min-height: 500px;
}
.ck-editor-wrap .ck-editor__editable {
    min-height: 500px;
    border-radius: 0 0 10px 10px !important;
}
.ck.ck-toolbar {
    border-radius: 10px 10px 0 0 !important;
    border-color: #e0e0e0 !important;
}
.ck.ck-editor__main > .ck-editor__editable:not(.ck-focused) {
    border-color: #e0e0e0 !important;
}
.ck.ck-editor__main > .ck-editor__editable.ck-focused {
    border-color: #f39c12 !important;
    box-shadow: 0 0 0 2px rgba(243, 156, 18, 0.1) !important;
}

/* ===== SLUG INPUT ===== */
.slug-wrap {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: #f4f6f9;
    padding: 0.6rem 0.8rem;
    border-radius: 8px;
    font-size: 0.88rem;
}
.slug-prefix {
    color: #888;
    flex-shrink: 0;
    font-family: 'Courier New', monospace;
    font-size: 0.82rem;
}
.slug-input {
    flex: 1;
    background: transparent;
    border: none;
    outline: none;
    font-family: 'Courier New', monospace;
    color: #1e3a5f;
    font-weight: 600;
    font-size: 0.88rem;
    min-width: 0;
}
.slug-edit-btn {
    background: white;
    border: 1px solid #e0e0e0;
    color: #666;
    padding: 0.3rem 0.6rem;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.3rem;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.slug-edit-btn:hover {
    background: #f39c12;
    color: white;
    border-color: #f39c12;
}

/* ===== FORM FIELD ENHANCED ===== */
.field-label {
    display: block;
    color: #1e3a5f;
    font-weight: 600;
    font-size: 0.88rem;
    margin-bottom: 0.5rem;
}
.field-label .required {
    color: #e74c3c;
    margin-left: 0.2rem;
}
.field-help {
    font-size: 0.78rem;
    color: #888;
    margin-top: 0.3rem;
    display: block;
}

.form-select-ult {
    width: 100%;
    padding: 0.7rem 0.9rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.92rem;
    outline: none;
    transition: border 0.3s ease;
    background: white;
    cursor: pointer;
    color: #333;
    font-family: inherit;
}
.form-select-ult:focus { border-color: #f39c12; }

.form-textarea-ult {
    width: 100%;
    padding: 0.8rem 1rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.92rem;
    outline: none;
    transition: border 0.3s ease;
    resize: vertical;
    min-height: 80px;
    font-family: inherit;
    line-height: 1.5;
}
.form-textarea-ult:focus { border-color: #f39c12; }

/* ===== IMAGE UPLOAD ZONE ===== */
.image-upload-zone {
    border: 2px dashed #d0d0d0;
    border-radius: 12px;
    padding: 2rem 1rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: #f8f9fa;
    position: relative;
}
.image-upload-zone:hover,
.image-upload-zone.drag-over {
    border-color: #f39c12;
    background: #fffbf0;
}
.image-upload-zone.has-image {
    padding: 0.5rem;
    border-style: solid;
    background: white;
}
.image-upload-icon {
    font-size: 3rem;
    color: #bbb;
    margin-bottom: 0.8rem;
    display: block;
}
.image-upload-zone:hover .image-upload-icon {
    color: #f39c12;
}
.image-upload-text {
    color: #666;
    font-size: 0.92rem;
    margin-bottom: 0.3rem;
}
.image-upload-hint {
    font-size: 0.78rem;
    color: #999;
}
.image-upload-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}
.image-preview-wrap {
    position: relative;
}
.image-preview {
    width: 100%;
    border-radius: 8px;
    display: block;
    max-height: 300px;
    object-fit: cover;
}
.image-remove-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    background: rgba(231, 76, 60, 0.95);
    color: white;
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}
.image-remove-btn:hover {
    background: #c0392b;
    transform: scale(1.1);
}

/* ===== TAGS INPUT ===== */
.tags-input-wrap {
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    padding: 0.5rem;
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
    min-height: 44px;
    transition: border 0.3s ease;
    background: white;
    align-items: center;
}
.tags-input-wrap.focused { border-color: #f39c12; }
.tag-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
    color: white;
    padding: 0.3rem 0.7rem;
    border-radius: 20px;
    font-size: 0.82rem;
    font-weight: 500;
    animation: chipIn 0.2s ease;
}
@keyframes chipIn {
    from { transform: scale(0); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}
.tag-chip-remove {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 18px; height: 18px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    transition: background 0.2s ease;
    padding: 0;
}
.tag-chip-remove:hover { background: rgba(231, 76, 60, 0.8); }
.tags-text-input {
    flex: 1;
    min-width: 100px;
    border: none;
    outline: none;
    padding: 0.3rem 0.5rem;
    font-size: 0.88rem;
    background: transparent;
    font-family: inherit;
}
.tag-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border-radius: 8px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.15);
    margin-top: 0.3rem;
    max-height: 200px;
    overflow-y: auto;
    z-index: 100;
    display: none;
}
.tag-suggestions.show { display: block; }
.tag-suggestion-item {
    padding: 0.5rem 0.8rem;
    cursor: pointer;
    transition: background 0.15s ease;
    font-size: 0.88rem;
}
.tag-suggestion-item:hover,
.tag-suggestion-item.active {
    background: #f4f6f9;
    color: #f39c12;
}

/* ===== SEO PREVIEW ===== */
.seo-preview {
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 1rem;
    margin-top: 0.8rem;
    font-family: Arial, sans-serif;
}
.seo-preview-url {
    color: #202124;
    font-size: 0.82rem;
    margin-bottom: 0.3rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.seo-preview-url img {
    width: 16px;
    height: 16px;
    border-radius: 50%;
}
.seo-preview-title {
    color: #1a0dab;
    font-size: 1.15rem;
    line-height: 1.3;
    margin-bottom: 0.3rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    cursor: pointer;
}
.seo-preview-title:hover { text-decoration: underline; }
.seo-preview-desc {
    color: #4d5156;
    font-size: 0.88rem;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* ===== WORD COUNT WIDGET ===== */
.stats-grid-mini {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.5rem;
}
.stat-mini-card {
    background: #f4f6f9;
    padding: 0.8rem;
    border-radius: 10px;
    text-align: center;
    transition: all 0.2s ease;
}
.stat-mini-card:hover {
    background: #fffbf0;
    transform: translateY(-2px);
}
.stat-mini-card .icon {
    font-size: 1.2rem;
    color: #f39c12;
    margin-bottom: 0.3rem;
    display: block;
}
.stat-mini-card .value {
    font-size: 1.3rem;
    font-weight: 700;
    color: #1e3a5f;
    line-height: 1;
    margin-bottom: 0.2rem;
}
.stat-mini-card .label {
    font-size: 0.72rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ===== EXCERPT TEXTAREA (Enhanced) ===== */
.excerpt-counter {
    text-align: right;
    font-size: 0.75rem;
    color: #888;
    margin-top: 0.3rem;
}
.excerpt-counter.warning { color: #f39c12; }
.excerpt-counter.danger { color: #e74c3c; }

/* ===== KEYBOARD SHORTCUTS HINT ===== */
.shortcut-hint {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: #888;
    margin-top: 0.5rem;
}
.shortcut-hint kbd {
    background: #f4f6f9;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    font-size: 0.7rem;
    border: 1px solid #e0e0e0;
    color: #555;
}

/* ===== DARK MODE ===== */
html[data-theme="dark"] .editor-topbar,
html[data-theme="dark"] .editor-card {
    background: #1e2638;
}
html[data-theme="dark"] .editor-title-info h1,
html[data-theme="dark"] .editor-card-header h3,
html[data-theme="dark"] .title-input,
html[data-theme="dark"] .field-label,
html[data-theme="dark"] .slug-input,
html[data-theme="dark"] .stat-mini-card .value {
    color: #e5e8ec;
}
html[data-theme="dark"] .title-input {
    border-bottom-color: #2a3550;
}
html[data-theme="dark"] .form-select-ult,
html[data-theme="dark"] .form-textarea-ult,
html[data-theme="dark"] .tags-input-wrap {
    background: #16203a;
    border-color: #2a3550;
    color: #e5e8ec;
}
html[data-theme="dark"] .image-upload-zone {
    background: #16203a;
    border-color: #2a3550;
}
html[data-theme="dark"] .stat-mini-card {
    background: #16203a;
}
html[data-theme="dark"] .slug-wrap {
    background: #16203a;
}
html[data-theme="dark"] .btn-draft {
    background: #2a3550;
    color: #e5e8ec;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1024px) {
    .editor-layout {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 576px) {
    .editor-topbar {
        flex-direction: column;
        align-items: stretch;
    }
    .editor-topbar-right {
        justify-content: stretch;
    }
    .btn-draft, .btn-publish {
        flex: 1;
        justify-content: center;
    }
    .title-input {
        font-size: 1.5rem;
    }
}

/* ===== ANIMATIONS ===== */
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Hide native file input */
input[type="file"].hidden-file {
    display: none;
}
</style>

<main class="admin-main">
    <form method="POST" enctype="multipart/form-data" id="articleForm" autocomplete="off">
        
        <!-- ===== EDITOR TOPBAR ===== -->
        <div class="editor-topbar">
            <div class="editor-topbar-left">
                <a href="<?php echo url('admin/articles.php'); ?>" class="editor-back-btn" title="Kembali">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div class="editor-title-info">
                    <h1>
                        <?php echo $isEdit ? '✏️ Edit Artikel' : '📝 Tulis Artikel Baru'; ?>
                    </h1>
                    <div class="editor-save-status" id="saveStatus">
                        <i class="fas fa-circle"></i>
                        <span id="saveStatusText">
                            <?php echo $isEdit ? 'Terakhir diupdate ' . timeAgo($article['updated_at'] ?? $article['created_at']) : 'Siap menulis'; ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="editor-topbar-right">
                <button type="button" class="btn-draft" id="saveDraftBtn" title="Simpan Draft (Ctrl+S)">
                    <i class="fas fa-save"></i> Simpan Draft
                </button>
                <button type="submit" class="btn-publish" id="publishBtn" name="status" value="published">
                    <i class="fas fa-rocket"></i> 
                    <?php echo $isEdit ? 'Update' : 'Publikasikan'; ?>
                </button>
            </div>
        </div>

        <div class="editor-layout">
            
            <!-- ===== MAIN EDITOR ===== -->
            <div class="editor-main">
                
                <!-- Title -->
                <div class="editor-card">
                    <div class="title-input-wrap">
                        <input type="text" 
                               name="title" 
                               id="articleTitle" 
                               class="title-input" 
                               placeholder="Tulis judul artikel yang menarik..." 
                               value="<?php echo htmlspecialchars($article['title'] ?? ''); ?>"
                               required
                               maxlength="200"
                               autocomplete="off">
                        <div class="title-counter" id="titleCounter">0 / 200 karakter</div>
                    </div>
                    <div class="shortcut-hint" style="margin-top: 1.5rem;">
                        <i class="fas fa-lightbulb" style="color:#f39c12;"></i>
                        Tips: Judul ideal 50-60 karakter untuk SEO optimal
                    </div>
                </div>

                <!-- Content Editor -->
                <div class="editor-card">
                    <div class="editor-card-header">
                        <h3><i class="fas fa-pen-fancy"></i> Konten Artikel</h3>
                        <span style="font-size: 0.78rem; color: #888;">
                            <i class="fas fa-info-circle"></i> Gunakan toolbar untuk format
                        </span>
                    </div>
                    <div class="ck-editor-wrap">
                        <textarea name="content" id="articleContent"><?php echo htmlspecialchars($article['content'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Excerpt -->
                <div class="editor-card">
                    <div class="editor-card-header">
                        <h3><i class="fas fa-align-left"></i> Ringkasan (Excerpt)</h3>
                        <button type="button" class="btn-draft" onclick="autoGenerateExcerpt()" style="padding: 0.4rem 0.8rem; font-size: 0.78rem;">
                            <i class="fas fa-magic"></i> Auto Generate
                        </button>
                    </div>
                    <textarea name="excerpt" 
                              id="articleExcerpt"
                              class="form-textarea-ult" 
                              rows="3" 
                              placeholder="Ringkasan singkat artikel (opsional, akan di-generate otomatis jika kosong)..."
                              maxlength="300"><?php echo htmlspecialchars($article['excerpt'] ?? ''); ?></textarea>
                    <div class="excerpt-counter" id="excerptCounter">0 / 300 karakter</div>
                    <small class="field-help">
                        💡 Excerpt ditampilkan di halaman kategori dan hasil pencarian
                    </small>
                </div>

            </div>

            <!-- ===== SIDEBAR ===== -->
            <div class="editor-sidebar">
                
                <!-- Publish Settings -->
                <div class="editor-card">
                    <div class="editor-card-header">
                        <h3><i class="fas fa-cog"></i> Pengaturan</h3>
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <label class="field-label">
                            Status <span class="required">*</span>
                        </label>
                        <select name="status" class="form-select-ult" id="articleStatus">
                            <option value="draft" <?php echo (($article['status'] ?? 'draft') === 'draft') ? 'selected' : ''; ?>>
                                📝 Draft (belum dipublish)
                            </option>
                            <option value="published" <?php echo (($article['status'] ?? '') === 'published') ? 'selected' : ''; ?>>
                                🚀 Published (live)
                            </option>
                        </select>
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label class="field-label">
                            Kategori <span class="required">*</span>
                        </label>
                        <select name="category_id" class="form-select-ult" required>
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" 
                                        <?php echo (($article['category_id'] ?? 0) == $cat['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="field-label">
                            Slug URL
                        </label>
                        <div class="slug-wrap">
                            <span class="slug-prefix">/article/</span>
                            <input type="text" 
                                   name="slug" 
                                   id="articleSlug" 
                                   class="slug-input" 
                                   value="<?php echo htmlspecialchars($article['slug'] ?? ''); ?>"
                                   readonly
                                   placeholder="auto-generated">
                            <button type="button" class="slug-edit-btn" id="slugEditBtn" title="Edit manual">
                                <i class="fas fa-pen"></i>
                            </button>
                        </div>
                        <small class="field-help">
                            Auto-generate dari judul. Klik ✏️ untuk edit manual.
                        </small>
                    </div>
                </div>

                <!-- Featured Image -->
                <div class="editor-card">
                    <div class="editor-card-header">
                        <h3><i class="fas fa-image"></i> Gambar Utama</h3>
                    </div>
                    
                    <div class="image-upload-zone <?php echo !empty($article['featured_image']) ? 'has-image' : ''; ?>" id="imageUploadZone">
                        <?php if (!empty($article['featured_image'])): ?>
                            <div class="image-preview-wrap">
                                <img src="<?php echo htmlspecialchars($article['featured_image']); ?>" 
                                     alt="Preview" 
                                     class="image-preview"
                                     id="imagePreview">
                                <button type="button" class="image-remove-btn" id="removeImageBtn" title="Hapus gambar">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <input type="hidden" name="remove_image" id="removeImageInput" value="0">
                        <?php else: ?>
                            <i class="fas fa-cloud-upload-alt image-upload-icon"></i>
                            <div class="image-upload-text">
                                <strong>Klik untuk upload</strong> atau drag & drop
                            </div>
                            <div class="image-upload-hint">
                                JPG, PNG, WEBP, GIF (Maks 5MB)
                            </div>
                        <?php endif; ?>
                        <input type="file" 
                               name="featured_image" 
                               class="image-upload-input" 
                               id="imageFileInput"
                               accept="image/jpeg,image/png,image/webp,image/gif">
                    </div>
                </div>

                <!-- Tags -->
                <div class="editor-card">
                    <div class="editor-card-header">
                        <h3><i class="fas fa-tags"></i> Tags</h3>
                    </div>
                    <input type="hidden" name="tags" id="tagsHidden" value="<?php echo htmlspecialchars($article['tags'] ?? ''); ?>">
                    <div class="tags-input-wrap" id="tagsWrap" style="position: relative;">
                        <input type="text" 
                               class="tags-text-input" 
                               id="tagsInput" 
                               placeholder="Ketik tag, tekan Enter..."
                               autocomplete="off">
                        <div class="tag-suggestions" id="tagSuggestions"></div>
                    </div>
                    <small class="field-help">
                        Tekan Enter atau koma untuk menambah tag
                    </small>
                </div>

                <!-- SEO -->
                <div class="editor-card">
                    <div class="editor-card-header">
                        <h3><i class="fas fa-search"></i> SEO</h3>
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <label class="field-label">Meta Title</label>
                        <input type="text" 
                               name="meta_title" 
                               id="metaTitle"
                               class="form-textarea-ult" 
                               style="min-height: auto; padding: 0.6rem 0.9rem;"
                               placeholder="Kosongkan untuk pakai judul artikel"
                               value="<?php echo htmlspecialchars($article['meta_title'] ?? ''); ?>"
                               maxlength="70">
                        <small class="field-help">Ideal: 50-60 karakter</small>
                    </div>

                    <div>
                        <label class="field-label">Meta Description</label>
                        <textarea name="meta_description" 
                                  id="metaDescription"
                                  class="form-textarea-ult" 
                                  rows="2"
                                  placeholder="Kosongkan untuk auto-generate dari excerpt"
                                  maxlength="160"><?php echo htmlspecialchars($article['meta_description'] ?? ''); ?></textarea>
                        <small class="field-help">Ideal: 150-160 karakter</small>
                    </div>

                    <div class="seo-preview">
                        <div class="seo-preview-url">
                            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ccircle cx='8' cy='8' r='8' fill='%231e3a5f'/%3E%3Ctext x='8' y='11' font-size='8' text-anchor='middle' fill='white' font-family='Arial'%3E🎓%3C/text%3E%3C/svg%3E" alt="">
                            <span>blog-dosen.com › article › <span id="seoSlug">slug</span></span>
                        </div>
                        <div class="seo-preview-title" id="seoTitle">Judul Artikel Anda</div>
                        <div class="seo-preview-desc" id="seoDesc">Deskripsi artikel akan muncul di sini...</div>
                    </div>
                </div>

                <!-- Stats Widget -->
                <div class="editor-card">
                    <div class="editor-card-header">
                        <h3><i class="fas fa-chart-bar"></i> Statistik</h3>
                    </div>
                    <div class="stats-grid-mini">
                        <div class="stat-mini-card">
                            <i class="fas fa-file-word icon"></i>
                            <div class="value" id="wordCount">0</div>
                            <div class="label">Kata</div>
                        </div>
                        <div class="stat-mini-card">
                            <i class="fas fa-clock icon"></i>
                            <div class="value" id="readTime">0</div>
                            <div class="label">Menit Baca</div>
                        </div>
                        <div class="stat-mini-card">
                            <i class="fas fa-text-height icon"></i>
                            <div class="value" id="charCount">0</div>
                            <div class="label">Karakter</div>
                        </div>
                        <div class="stat-mini-card">
                            <i class="fas fa-paragraph icon"></i>
                            <div class="value" id="paraCount">0</div>
                            <div class="label">Paragraf</div>
                        </div>
                    </div>
                    <div class="shortcut-hint" style="margin-top: 1rem;">
                        <i class="fas fa-keyboard" style="color:#f39c12;"></i>
                        <kbd>Ctrl+S</kbd> Save | <kbd>Ctrl+Enter</kbd> Publish
                    </div>
                </div>

            </div>
        </div>
    </form>
</main>

<!-- CKEditor 5 CDN -->
<script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/super-build/ckeditor.js"></script>

<script>
(function() {
    'use strict';

    // ===== STATE =====
    var editor = null;
    var autoSaveTimer = null;
    var isDirty = false;
    var lastSavedContent = '';
    var allTags = <?php echo json_encode($allTags); ?>;
    var currentTags = [];

    // ===== INIT CKEDITOR =====
    CKEDITOR.ClassicEditor.create(document.getElementById('articleContent'), {
        licenseKey: '',
        toolbar: {
            items: [
                'undo', 'redo', '|',
                'heading', '|',
                'bold', 'italic', 'underline', 'strikethrough', '|',
                'link', 'blockquote', 'codeBlock', '|',
                'bulletedList', 'numberedList', 'outdent', 'indent', '|',
                'insertImage', 'insertTable', 'mediaEmbed', '|',
                'sourceEditing', '-',
                'findAndReplace', 'specialCharacters', 'horizontalLine'
            ],
            shouldNotGroupWhenFull: true
        },
        heading: {
            options: [
                { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
                { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' },
                { model: 'heading4', view: 'h4', title: 'Heading 4', class: 'ck-heading_heading4' }
            ]
        },
        image: {
            toolbar: ['imageTextAlternative', 'imageStyle:inline', 'imageStyle:block', 'imageStyle:side']
        },
        table: {
            contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells']
        },
        placeholder: 'Mulai menulis artikel Anda di sini...',
        removePlugins: ['CKBox', 'EasyImage']
    }).then(function(ed) {
        editor = ed;
        
        // Sync content change
        editor.model.document.on('change:data', function() {
            isDirty = true;
            if (typeof UI !== 'undefined') UI.markUnsaved();
            updateStats();
            scheduleAutoSave();
        });
        
        updateStats();
        lastSavedContent = editor.getData();
    }).catch(function(err) {
        console.error('CKEditor init error:', err);
        if (typeof UI !== 'undefined') {
            UI.error('Gagal memuat editor. Silakan reload halaman.', 'Error Editor');
        }
    });

    // ===== TITLE COUNTER & SLUG AUTO-GENERATE =====
    var titleInput = document.getElementById('articleTitle');
    var titleCounter = document.getElementById('titleCounter');
    var slugInput = document.getElementById('articleSlug');
    var slugEditBtn = document.getElementById('slugEditBtn');
    var slugManuallyEdited = <?php echo $isEdit ? 'true' : 'false'; ?>;

    function updateTitleCounter() {
        var len = titleInput.value.length;
        titleCounter.textContent = len + ' / 200 karakter';
        titleCounter.className = 'title-counter';
        if (len > 180) titleCounter.classList.add('danger');
        else if (len > 150) titleCounter.classList.add('warning');
        
        // Update SEO preview
        updateSeoPreview();
        
        // Auto-generate slug kalau belum manual edit
        if (!slugManuallyEdited) {
            slugInput.value = generateSlug(titleInput.value);
        }
    }

    titleInput.addEventListener('input', updateTitleCounter);
    updateTitleCounter();

    slugEditBtn.addEventListener('click', function() {
        if (slugInput.readOnly) {
            slugInput.readOnly = false;
            slugInput.focus();
            slugInput.select();
            slugEditBtn.innerHTML = '<i class="fas fa-check"></i>';
            slugManuallyEdited = true;
        } else {
            slugInput.readOnly = true;
            slugEditBtn.innerHTML = '<i class="fas fa-pen"></i>';
        }
    });

    // ===== EXCERPT COUNTER =====
    var excerptInput = document.getElementById('articleExcerpt');
    var excerptCounter = document.getElementById('excerptCounter');

    function updateExcerptCounter() {
        var len = excerptInput.value.length;
        excerptCounter.textContent = len + ' / 300 karakter';
        excerptCounter.className = 'excerpt-counter';
        if (len > 280) excerptCounter.classList.add('danger');
        else if (len > 250) excerptCounter.classList.add('warning');
        updateSeoPreview();
    }
    excerptInput.addEventListener('input', updateExcerptCounter);
    updateExcerptCounter();

    // ===== AUTO-GENERATE EXCERPT =====
    window.autoGenerateExcerpt = function() {
        if (!editor) return;
        var text = editor.getData().replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
        if (text.length > 300) text = text.substring(0, 297) + '...';
        excerptInput.value = text;
        updateExcerptCounter();
        if (typeof UI !== 'undefined') UI.success('Excerpt berhasil di-generate!', '✨ Auto Generate');
    };

    // ===== IMAGE UPLOAD =====
    var imageZone = document.getElementById('imageUploadZone');
    var imageFileInput = document.getElementById('imageFileInput');
    var removeImageBtn = document.getElementById('removeImageBtn');
    var removeImageInput = document.getElementById('removeImageInput');

    imageFileInput.addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;
        
        if (file.size > 5 * 1024 * 1024) {
            if (typeof UI !== 'undefined') UI.error('Ukuran maksimal 5MB!', 'File terlalu besar');
            return;
        }
        
        var reader = new FileReader();
        reader.onload = function(e) {
            imageZone.classList.add('has-image');
            imageZone.innerHTML = 
                '<div class="image-preview-wrap">' +
                    '<img src="' + e.target.result + '" alt="Preview" class="image-preview">' +
                    '<button type="button" class="image-remove-btn" id="removeImageBtn2" title="Hapus">' +
                        '<i class="fas fa-times"></i>' +
                    '</button>' +
                '</div>' +
                '<input type="file" name="featured_image" class="image-upload-input" accept="image/jpeg,image/png,image/webp,image/gif">';
            
            if (removeImageInput) removeImageInput.value = '0';
            
            // Re-bind events
            var newInput = imageZone.querySelector('input[type="file"]');
            newInput.addEventListener('change', arguments.callee);
            
            var newRemove = document.getElementById('removeImageBtn2');
            newRemove.addEventListener('click', removeImage);
        };
        reader.readAsDataURL(file);
    });

    function removeImage(e) {
        e.stopPropagation();
        imageZone.classList.remove('has-image');
        imageZone.innerHTML = 
            '<i class="fas fa-cloud-upload-alt image-upload-icon"></i>' +
            '<div class="image-upload-text"><strong>Klik untuk upload</strong> atau drag & drop</div>' +
            '<div class="image-upload-hint">JPG, PNG, WEBP, GIF (Maks 5MB)</div>' +
            '<input type="file" name="featured_image" class="image-upload-input" accept="image/jpeg,image/png,image/webp,image/gif">';
        
        if (removeImageInput) removeImageInput.value = '1';
        
        // Re-bind file input
        var newInput = imageZone.querySelector('input[type="file"]');
        newInput.addEventListener('change', function(ev) {
            var file = ev.target.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function(e) {
                // ... (sama seperti di atas)
                imageZone.classList.add('has-image');
            };
            reader.readAsDataURL(file);
        });
    }

    if (removeImageBtn) {
        removeImageBtn.addEventListener('click', removeImage);
    }

    // Drag & drop
    ['dragenter', 'dragover'].forEach(function(evt) {
        imageZone.addEventListener(evt, function(e) {
            e.preventDefault();
            imageZone.classList.add('drag-over');
        });
    });
    ['dragleave', 'drop'].forEach(function(evt) {
        imageZone.addEventListener(evt, function(e) {
            e.preventDefault();
            imageZone.classList.remove('drag-over');
        });
    });
    imageZone.addEventListener('drop', function(e) {
        var file = e.dataTransfer.files[0];
        if (file && file.type.startsWith('image/')) {
            imageFileInput.files = e.dataTransfer.files;
            imageFileInput.dispatchEvent(new Event('change'));
        }
    });

    // ===== TAGS SYSTEM =====
    var tagsWrap = document.getElementById('tagsWrap');
    var tagsInput = document.getElementById('tagsInput');
    var tagsHidden = document.getElementById('tagsHidden');
    var tagSuggestions = document.getElementById('tagSuggestions');

    // Init current tags
    var initTags = tagsHidden.value;
    if (initTags) {
        currentTags = initTags.split(',').map(function(t) { return t.trim(); }).filter(function(t) { return t; });
        renderTags();
    }

    function renderTags() {
        // Hapus semua chips
        var chips = tagsWrap.querySelectorAll('.tag-chip');
        chips.forEach(function(c) { c.remove(); });
        
        // Tambah chips sebelum input
        currentTags.forEach(function(tag) {
            var chip = document.createElement('span');
            chip.className = 'tag-chip';
            chip.innerHTML = tag + ' <button type="button" class="tag-chip-remove" data-tag="' + tag + '"><i class="fas fa-times"></i></button>';
            tagsWrap.insertBefore(chip, tagsInput);
        });
        
        tagsHidden.value = currentTags.join(', ');
        
        // Bind remove
        tagsWrap.querySelectorAll('.tag-chip-remove').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var tag = this.getAttribute('data-tag');
                currentTags = currentTags.filter(function(t) { return t !== tag; });
                renderTags();
                isDirty = true;
            });
        });
    }

    function addTag(tag) {
        tag = tag.trim().toLowerCase();
        if (!tag) return;
        if (currentTags.indexOf(tag) !== -1) return;
        if (currentTags.length >= 10) {
            if (typeof UI !== 'undefined') UI.warning('Maksimal 10 tag!', 'Batas Tercapai');
            return;
        }
        currentTags.push(tag);
        renderTags();
        tagsInput.value = '';
        hideSuggestions();
        isDirty = true;
    }

    tagsInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addTag(this.value);
        } else if (e.key === 'Backspace' && !this.value && currentTags.length > 0) {
            currentTags.pop();
            renderTags();
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            navigateSuggestions(e.key === 'ArrowDown' ? 1 : -1);
        }
    });

    tagsInput.addEventListener('input', function() {
        var val = this.value.trim().toLowerCase();
        if (val.length < 2) {
            hideSuggestions();
            return;
        }
        var matches = allTags.filter(function(t) {
            return t.toLowerCase().indexOf(val) !== -1 && currentTags.indexOf(t) === -1;
        }).slice(0, 5);
        if (matches.length > 0) {
            showSuggestions(matches);
        } else {
            hideSuggestions();
        }
    });

    tagsInput.addEventListener('focus', function() {
        tagsWrap.classList.add('focused');
    });
    tagsInput.addEventListener('blur', function() {
        tagsWrap.classList.remove('focused');
        setTimeout(hideSuggestions, 200);
    });

    function showSuggestions(tags) {
        tagSuggestions.innerHTML = tags.map(function(t) {
            return '<div class="tag-suggestion-item" data-tag="' + t + '">' + t + '</div>';
        }).join('');
        tagSuggestions.classList.add('show');
        
        tagSuggestions.querySelectorAll('.tag-suggestion-item').forEach(function(item) {
            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                addTag(this.getAttribute('data-tag'));
            });
        });
    }

    function hideSuggestions() {
        tagSuggestions.classList.remove('show');
    }

    function navigateSuggestions(dir) {
        var items = tagSuggestions.querySelectorAll('.tag-suggestion-item');
        if (items.length === 0) return;
        var active = tagSuggestions.querySelector('.active');
        var idx = -1;
        items.forEach(function(item, i) { if (item === active) idx = i; });
        if (active) active.classList.remove('active');
        idx = (idx + dir + items.length) % items.length;
        items[idx].classList.add('active');
    }

    // ===== SEO PREVIEW =====
    var metaTitle = document.getElementById('metaTitle');
    var metaDescription = document.getElementById('metaDescription');
    var seoTitle = document.getElementById('seoTitle');
    var seoDesc = document.getElementById('seoDesc');
    var seoSlug = document.getElementById('seoSlug');

    function updateSeoPreview() {
        var t = metaTitle.value.trim() || titleInput.value || 'Judul Artikel Anda';
        var d = metaDescription.value.trim() || excerptInput.value || 'Deskripsi artikel akan muncul di sini...';
        var s = slugInput.value || 'slug-artikel';
        
        seoTitle.textContent = t;
        seoDesc.textContent = d;
        seoSlug.textContent = s;
    }

    metaTitle.addEventListener('input', updateSeoPreview);
    metaDescription.addEventListener('input', updateSeoPreview);
    slugInput.addEventListener('input', updateSeoPreview);
    updateSeoPreview();

    // ===== STATS UPDATE =====
    function updateStats() {
        if (!editor) return;
        var html = editor.getData();
        var text = html.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
        var words = text ? text.split(/\s+/).length : 0;
        var chars = text.length;
        var paras = html.split(/<\/p>|<\/h[1-6]>|<\/li>/).length - 1;
        var readTime = Math.max(1, Math.ceil(words / 200));
        
        document.getElementById('wordCount').textContent = words.toLocaleString('id-ID');
        document.getElementById('charCount').textContent = chars.toLocaleString('id-ID');
        document.getElementById('paraCount').textContent = paras;
        document.getElementById('readTime').textContent = readTime;
    }

    // ===== AUTO-SAVE (localStorage setiap 30 detik) =====
    function scheduleAutoSave() {
        if (autoSaveTimer) clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(saveToLocalStorage, 30000);
    }

    function saveToLocalStorage() {
        if (!editor) return;
        try {
            var key = 'article_draft_<?php echo $articleId ?: "new"; ?>';
            var data = {
                title: titleInput.value,
                content: editor.getData(),
                excerpt: excerptInput.value,
                slug: slugInput.value,
                tags: currentTags.join(','),
                savedAt: Date.now()
            };
            localStorage.setItem(key, JSON.stringify(data));
            
            var statusEl = document.getElementById('saveStatus');
            var statusText = document.getElementById('saveStatusText');
            statusEl.className = 'editor-save-status';
            statusText.textContent = 'Auto-saved ' + new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            console.warn('Auto-save failed:', e);
        }
    }

    // Load draft dari localStorage
    (function() {
        try {
            var key = 'article_draft_<?php echo $articleId ?: "new"; ?>';
            var saved = localStorage.getItem(key);
            if (saved && !<?php echo $isEdit ? 'true' : 'false'; ?>) {
                var data = JSON.parse(saved);
                var age = (Date.now() - data.savedAt) / 1000 / 60;
                if (age < 60) { // < 1 jam
                    if (confirm('Ada draft tersimpan ' + Math.round(age) + ' menit lalu. Muat draft tersebut?')) {
                        titleInput.value = data.title || '';
                        if (editor) editor.setData(data.content || '');
                        excerptInput.value = data.excerpt || '';
                        slugInput.value = data.slug || '';
                        if (data.tags) {
                            currentTags = data.tags.split(',').filter(function(t) { return t; });
                            renderTags();
                        }
                        updateTitleCounter();
                        updateExcerptCounter();
                        updateStats();
                        if (typeof UI !== 'undefined') UI.info('Draft berhasil dimuat!', '♻️ Draft Loaded');
                    } else {
                        localStorage.removeItem(key);
                    }
                }
            }
        } catch (e) {}
    })();

    // ===== SAVE DRAFT BUTTON =====
    document.getElementById('saveDraftBtn').addEventListener('click', function() {
        document.getElementById('articleStatus').value = 'draft';
        document.getElementById('articleForm').submit();
    });

    // ===== KEYBOARD SHORTCUTS =====
    document.addEventListener('keydown', function(e) {
        // Ctrl+S : Save draft
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            document.getElementById('saveDraftBtn').click();
        }
        // Ctrl+Enter : Publish
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('publishBtn').click();
        }
    });

    // ===== FORM SUBMIT HANDLING =====
    document.getElementById('articleForm').addEventListener('submit', function() {
        // Sync CKEditor content ke textarea
        if (editor) {
            document.getElementById('articleContent').value = editor.getData();
        }
        
        // Hapus draft dari localStorage
        try {
            localStorage.removeItem('article_draft_<?php echo $articleId ?: "new"; ?>');
        } catch (e) {}
        
        // Show loading
        if (typeof UI !== 'undefined') UI.showLoading('Menyimpan artikel...');
        
        // Mark as saved
        if (typeof UI !== 'undefined') UI.markSaved();
        isDirty = false;
    });

    // ===== PREVENT ACCIDENTAL LEAVE =====
    window.addEventListener('beforeunload', function(e) {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });

    console.log('%c📝 Article Editor Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
    console.log('%cShortcuts: Ctrl+S Save | Ctrl+Enter Publish', 'font-size:11px;color:#888;');

})();
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>