<?php
require_once __DIR__ . '/../config/functions.php';
checkAuth();

if (!isAdmin()) {
    flash('error', 'Akses ditolak! Hanya admin yang bisa mengelola kategori.');
    redirect('admin/');
}

// ============================================
// 🔒 HANDLE POST ACTIONS (AMAN!)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        flash('error', '❌ Sesi keamanan tidak valid. Silakan refresh halaman dan coba lagi.');
        redirect('admin/categories.php');
    }

    try {
        $action = $_POST['action'];
        
        // ===== ADD CATEGORY =====
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $customSlug = trim($_POST['slug'] ?? '');
            $icon = trim($_POST['icon'] ?? 'fa-tag');
            $color = trim($_POST['color'] ?? '#3498db');
            
            if (empty($name) || strlen($name) < 2) {
                throw new Exception('Nama kategori minimal 2 karakter!');
            }
            if (strlen($name) > 50) {
                throw new Exception('Nama kategori maksimal 50 karakter!');
            }
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $color = '#3498db';
            }
            if (!preg_match('/^fa-[a-z0-9-]+$/', $icon)) {
                $icon = 'fa-tag';
            }
            
            // Generate unique slug
            $baseSlug = !empty($customSlug) ? slugify($customSlug) : slugify($name);
            $slug = $baseSlug;
            $counter = 1;
            while (true) {
                $check = db()->prepare("SELECT id FROM categories WHERE slug = ?");
                $check->execute([$slug]);
                if (!$check->fetch()) break;
                $counter++;
                $slug = $baseSlug . '-' . $counter;
            }
            
            // Cek duplikasi nama
            $check = db()->prepare("SELECT id FROM categories WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                throw new Exception('Kategori dengan nama "' . $name . '" sudah ada!');
            }
            
            // Cek kolom icon & color (fallback kalau belum ada)
            try {
                $stmt = db()->prepare("
                    INSERT INTO categories (name, slug, description, icon, color, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$name, $slug, $desc, $icon, $color]);
            } catch (Exception $e) {
                // Kolom icon/color mungkin belum ada, fallback ke insert dasar
                $stmt = db()->prepare("
                    INSERT INTO categories (name, slug, description) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$name, $slug, $desc]);
            }
            
            flash('success', '✅ Kategori "' . $name . '" berhasil ditambahkan!');
        }
        
        // ===== EDIT CATEGORY =====
        elseif ($action === 'edit' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $customSlug = trim($_POST['slug'] ?? '');
            $icon = trim($_POST['icon'] ?? 'fa-tag');
            $color = trim($_POST['color'] ?? '#3498db');
            
            if (empty($name) || strlen($name) < 2) {
                throw new Exception('Nama kategori minimal 2 karakter!');
            }
            
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $color = '#3498db';
            }
            
            // ✅ Ambil slug lama agar URL publik tidak berubah saat edit
            $oldStmt = db()->prepare("SELECT slug FROM categories WHERE id = ?");
            $oldStmt->execute([$id]);
            $oldCat = $oldStmt->fetch();
            if (!$oldCat) {
                throw new Exception('Kategori tidak ditemukan!');
            }
            
            // Cek duplikasi nama (kecuali diri sendiri)
            $check = db()->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
            $check->execute([$name, $id]);
            if ($check->fetch()) {
                throw new Exception('Kategori dengan nama "' . $name . '" sudah ada!');
            }
            
            // ✅ Slug tetap, kecuali admin benar-benar mengetik slug baru
            if (empty($customSlug) || $customSlug === $oldCat['slug']) {
                $slug = $oldCat['slug'];
            } else {
                $baseSlug = slugify($customSlug);
                $slug = $baseSlug;
                $counter = 1;
                while (true) {
                    $check = db()->prepare("SELECT id FROM categories WHERE slug = ? AND id != ?");
                    $check->execute([$slug, $id]);
                    if (!$check->fetch()) break;
                    $counter++;
                    $slug = $baseSlug . '-' . $counter;
                }
            }
            
            // Update dengan fallback
            try {
                $stmt = db()->prepare("
                    UPDATE categories SET name=?, slug=?, description=?, icon=?, color=? 
                    WHERE id=?
                ");
                $stmt->execute([$name, $slug, $desc, $icon, $color, $id]);
            } catch (Exception $e) {
                $stmt = db()->prepare("
                    UPDATE categories SET name=?, slug=?, description=? WHERE id=?
                ");
                $stmt->execute([$name, $slug, $desc, $id]);
            }
            
            flash('success', '✅ Kategori "' . $name . '" berhasil diupdate!');
        }
        
        // ===== DELETE CATEGORY =====
        elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            
            // Cek apakah ada artikel terkait
            $check = db()->prepare("SELECT COUNT(*) FROM articles WHERE category_id = ?");
            $check->execute([$id]);
            $articleCount = (int)$check->fetchColumn();
            
            if ($articleCount > 0) {
                $mode = $_POST['delete_mode'] ?? 'keep';
                if ($mode === 'delete_articles') {
                    // Hapus artikel juga
                    db()->prepare("DELETE FROM articles WHERE category_id = ?")->execute([$id]);
                    db()->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
                    flash('success', "🗑️ Kategori dan $articleCount artikel terkait berhasil dihapus!");
                } else {
                    // Set artikel ke uncategorized (category_id = NULL)
                    db()->prepare("UPDATE articles SET category_id = NULL WHERE category_id = ?")->execute([$id]);
                    db()->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
                    flash('success', "🗑️ Kategori dihapus. $articleCount artikel dipindahkan ke 'Tanpa Kategori'.");
                }
            } else {
                db()->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
                flash('success', '🗑️ Kategori berhasil dihapus!');
            }
        }
        
        // ===== BULK DELETE =====
        elseif ($action === 'bulk_delete' && !empty($_POST['selected_ids'])) {
            $rawIds = is_array($_POST['selected_ids']) ? $_POST['selected_ids'] : explode(',', $_POST['selected_ids']);
            $ids = array_filter(array_map('intval', $rawIds));
            if (empty($ids)) {
                throw new Exception('Tidak ada kategori yang dipilih.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // Pindahkan artikel terkait ke NULL dulu
            db()->prepare("UPDATE articles SET category_id = NULL WHERE category_id IN ($placeholders)")
              ->execute($ids);
            $deleted = db()->prepare("DELETE FROM categories WHERE id IN ($placeholders)");
            $deleted->execute($ids);
            
            flash('success', '🗑️ ' . $deleted->rowCount() . ' kategori berhasil dihapus!');
        }
        
    } catch (Exception $e) {
        flash('error', '❌ ' . $e->getMessage());
    }
    
    redirect('admin/categories.php');
}

// ============================================
// 📊 STATISTIK
// ============================================
$stats = [
    'total' => 0,
    'with_articles' => 0,
    'empty' => 0,
    'total_articles' => 0,
];
try {
    $stmt = db()->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN article_count > 0 THEN 1 ELSE 0 END) as with_articles,
            SUM(CASE WHEN article_count = 0 THEN 1 ELSE 0 END) as empty,
            IFNULL(SUM(article_count), 0) as total_articles
        FROM (
            SELECT c.id, COUNT(a.id) as article_count
            FROM categories c
            LEFT JOIN articles a ON a.category_id = c.id AND a.status = 'published'
            GROUP BY c.id
        ) as stats
    ");
    $s = $stmt->fetch();
    if ($s) {
        $stats['total'] = (int)$s['total'];
        $stats['with_articles'] = (int)$s['with_articles'];
        $stats['empty'] = (int)$s['empty'];
        $stats['total_articles'] = (int)$s['total_articles'];
    }
} catch (Exception $e) {}

// ============================================
// 📚 LOAD CATEGORIES
// ============================================
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'name_asc';
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'grid';

$allowedSort = ['name_asc', 'name_desc', 'articles_desc', 'articles_asc', 'newest'];
if (!in_array($sortBy, $allowedSort)) $sortBy = 'name_asc';

$allowedView = ['grid', 'table'];
if (!in_array($viewMode, $allowedView)) $viewMode = 'grid';

$where = "WHERE 1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (c.name LIKE ? OR c.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$orderBy = "ORDER BY ";
switch ($sortBy) {
    case 'name_desc': $orderBy .= "c.name DESC"; break;
    case 'articles_desc': $orderBy .= "article_count DESC, c.name ASC"; break;
    case 'articles_asc': $orderBy .= "article_count ASC, c.name ASC"; break;
    case 'newest': $orderBy .= "c.id DESC"; break;
    case 'name_asc':
    default: $orderBy .= "c.name ASC"; break;
}

$categories = [];
try {
    // Cek apakah kolom icon & color ada
    $hasExtras = false;
    try {
        $check = db()->query("SELECT icon, color FROM categories LIMIT 1");
        $hasExtras = true;
    } catch (Exception $e) {}
    
    $iconSelect = $hasExtras ? "c.icon, c.color," : "'fa-tag' as icon, '#3498db' as color,";
    
    $stmt = db()->prepare("
        SELECT c.*, $iconSelect
               COUNT(a.id) as article_count,
               IFNULL(SUM(a.views), 0) as total_views
        FROM categories c
        LEFT JOIN articles a ON a.category_id = c.id AND a.status = 'published'
        $where
        GROUP BY c.id
        $orderBy
    ");
    $stmt->execute($params);
    $categories = $stmt->fetchAll();
} catch (Exception $e) {}

$pageTitle = 'Manajemen Kategori';
include __DIR__ . '/../includes/admin-header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ===== CATEGORY MANAGER ULTIMATE STYLES ===== */

.categories-layout {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 1.5rem;
    align-items: start;
}

/* ===== FORM SIDEBAR ===== */
.category-form-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    position: sticky;
    top: 90px;
    border: 2px solid transparent;
}
.category-form-card.editing {
    border-color: #f39c12;
}
.category-form-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.2rem;
    padding-bottom: 0.8rem;
    border-bottom: 2px solid #f0f0f0;
}
.category-form-header h3 {
    color: #1e3a5f;
    font-size: 1.1rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.category-form-header h3 i { color: #f39c12; }
.cancel-edit-btn {
    background: #f4f6f9;
    border: none;
    color: #666;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.78rem;
    display: none;
    align-items: center;
    gap: 0.3rem;
    transition: all 0.2s ease;
}
.category-form-card.editing .cancel-edit-btn { display: inline-flex; }
.cancel-edit-btn:hover { background: #e74c3c; color: white; }

.form-field {
    margin-bottom: 1rem;
}
.form-field label {
    display: block;
    color: #1e3a5f;
    font-weight: 600;
    font-size: 0.85rem;
    margin-bottom: 0.4rem;
}
.form-field label .required { color: #e74c3c; }
.form-field input[type="text"],
.form-field textarea {
    width: 100%;
    padding: 0.7rem 0.9rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.9rem;
    outline: none;
    transition: border 0.3s ease;
    font-family: inherit;
    background: white;
}
.form-field input:focus,
.form-field textarea:focus { border-color: #f39c12; }
.form-field textarea { resize: vertical; min-height: 70px; }
.form-field .field-help {
    font-size: 0.75rem;
    color: #888;
    margin-top: 0.3rem;
    display: block;
}

/* Slug Preview */
.slug-preview {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    background: #f4f6f9;
    padding: 0.6rem 0.8rem;
    border-radius: 8px;
    font-size: 0.82rem;
    font-family: 'Courier New', monospace;
    margin-top: 0.4rem;
}
.slug-preview-prefix { color: #888; }
.slug-preview-value {
    color: #1e3a5f;
    font-weight: 600;
    word-break: break-all;
    flex: 1;
}
.slug-preview.empty .slug-preview-value { color: #ccc; }

/* Icon Picker */
.icon-picker {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 0.4rem;
    max-height: 140px;
    overflow-y: auto;
    padding: 0.5rem;
    background: #f8f9fa;
    border-radius: 10px;
    border: 2px solid #e0e0e0;
}
.icon-picker::-webkit-scrollbar { width: 6px; }
.icon-picker::-webkit-scrollbar-thumb { background: #ccc; border-radius: 3px; }
.icon-option {
    aspect-ratio: 1;
    background: white;
    border: 2px solid transparent;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    color: #666;
    transition: all 0.2s ease;
    padding: 0;
}
.icon-option:hover {
    background: #fff3cd;
    color: #f39c12;
    transform: scale(1.1);
}
.icon-option.selected {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border-color: #e67e22;
    box-shadow: 0 4px 10px rgba(243, 156, 18, 0.3);
}

/* Color Picker */
.color-picker {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
    gap: 0.4rem;
}
.color-option {
    aspect-ratio: 1;
    border-radius: 50%;
    cursor: pointer;
    border: 3px solid transparent;
    transition: all 0.2s ease;
    position: relative;
    padding: 0;
}
.color-option:hover { transform: scale(1.15); }
.color-option.selected {
    border-color: #1e3a5f;
    transform: scale(1.15);
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
}
.color-option.selected::after {
    content: '✓';
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 0.9rem;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}

.btn-submit-category {
    width: 100%;
    padding: 0.85rem;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
    font-family: inherit;
}
.btn-submit-category:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.4);
}

/* ===== STATS CARDS ===== */
.cat-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.cat-stat-card {
    background: white;
    padding: 1.2rem 1.3rem;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 0.9rem;
    border-left: 4px solid;
    transition: all 0.3s ease;
}
.cat-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
}
.cat-stat-card.total { border-left-color: #3498db; }
.cat-stat-card.active { border-left-color: #27ae60; }
.cat-stat-card.empty { border-left-color: #f39c12; }
.cat-stat-card.articles { border-left-color: #9b59b6; }
.cat-stat-icon {
    width: 45px; height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.cat-stat-card.total .cat-stat-icon { background: linear-gradient(135deg, #3498db, #2980b9); }
.cat-stat-card.active .cat-stat-icon { background: linear-gradient(135deg, #27ae60, #229954); }
.cat-stat-card.empty .cat-stat-icon { background: linear-gradient(135deg, #f39c12, #e67e22); }
.cat-stat-card.articles .cat-stat-icon { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
.cat-stat-info { flex: 1; min-width: 0; }
.cat-stat-info .value {
    font-size: 1.6rem;
    font-weight: 800;
    color: #1e3a5f;
    line-height: 1;
    margin-bottom: 0.15rem;
    display: block;
}
.cat-stat-info .label {
    font-size: 0.75rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ===== HEADER & CONTROLS ===== */
.cat-main-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}
.cat-main-header h2 {
    color: #1e3a5f;
    margin: 0;
    font-size: 1.6rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.cat-main-header h2 i { color: #f39c12; }

.cat-controls {
    background: white;
    padding: 1rem 1.2rem;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    gap: 0.8rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    align-items: center;
}
.cat-search {
    flex: 1;
    min-width: 200px;
    position: relative;
}
.cat-search input {
    width: 100%;
    padding: 0.65rem 1rem 0.65rem 2.3rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.88rem;
    outline: none;
    transition: border 0.3s ease;
    background: white;
}
.cat-search input:focus { border-color: #f39c12; }
.cat-search i {
    position: absolute;
    left: 0.8rem;
    top: 50%;
    transform: translateY(-50%);
    color: #aaa;
}
.cat-select {
    padding: 0.65rem 1rem;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 0.88rem;
    outline: none;
    background: white;
    cursor: pointer;
    transition: border 0.3s ease;
    min-width: 150px;
    font-family: inherit;
}
.cat-select:focus { border-color: #f39c12; }

/* ===== GRID VIEW (CARDS) ===== */
.categories-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.2rem;
}
.cat-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border: 2px solid transparent;
}
.cat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
}
.cat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 4px;
    background: var(--cat-color, #3498db);
}
.cat-card.selected {
    border-color: #f39c12;
    background: #fffbf0;
}
.cat-card-checkbox {
    position: absolute;
    top: 15px; right: 15px;
    width: 20px; height: 20px;
    cursor: pointer;
    accent-color: #f39c12;
    z-index: 2;
}
.cat-card-icon {
    width: 60px; height: 60px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: white;
    margin-bottom: 1rem;
    background: var(--cat-color, #3498db);
    box-shadow: 0 5px 15px rgba(0,0,0,0.15);
    transition: transform 0.3s ease;
}
.cat-card:hover .cat-card-icon {
    transform: rotate(-8deg) scale(1.05);
}
.cat-card-name {
    font-size: 1.2rem;
    color: #1e3a5f;
    font-weight: 700;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-right: 30px;
}
.cat-card-slug {
    font-size: 0.78rem;
    color: #888;
    font-family: 'Courier New', monospace;
    margin-bottom: 0.8rem;
    word-break: break-all;
}
.cat-card-desc {
    color: #666;
    font-size: 0.88rem;
    line-height: 1.5;
    margin-bottom: 1rem;
    min-height: 2.6rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.cat-card-stats {
    display: flex;
    gap: 1rem;
    padding: 0.8rem;
    background: #f8f9fa;
    border-radius: 10px;
    margin-bottom: 1rem;
}
.cat-card-stat {
    flex: 1;
    text-align: center;
}
.cat-card-stat .value {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1e3a5f;
    display: block;
    line-height: 1;
    margin-bottom: 0.2rem;
}
.cat-card-stat .label {
    font-size: 0.7rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.cat-card-actions {
    display: flex;
    gap: 0.5rem;
}
.cat-action-btn {
    flex: 1;
    padding: 0.6rem;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.82rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.3rem;
    transition: all 0.2s ease;
    text-decoration: none;
    font-family: inherit;
}
.cat-action-btn.edit {
    background: #e3f2fd;
    color: #1976d2;
}
.cat-action-btn.edit:hover {
    background: #1976d2;
    color: white;
}
.cat-action-btn.view {
    background: #e8f5e9;
    color: #388e3c;
}
.cat-action-btn.view:hover {
    background: #388e3c;
    color: white;
}
.cat-action-btn.delete {
    background: #ffebee;
    color: #c62828;
}
.cat-action-btn.delete:hover {
    background: #c62828;
    color: white;
}

/* ===== TABLE VIEW ===== */
.cat-table-wrap {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.cat-table {
    width: 100%;
    border-collapse: collapse;
}
.cat-table thead {
    background: #f8f9fa;
    border-bottom: 2px solid #e9ecef;
}
.cat-table th {
    padding: 0.9rem 1rem;
    text-align: left;
    font-weight: 700;
    color: #1e3a5f;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.cat-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s ease;
}
.cat-table tbody tr:hover { background: #f8f9fa; }
.cat-table tbody tr.selected { background: #fffbf0; }
.cat-table td {
    padding: 1rem;
    vertical-align: middle;
    font-size: 0.9rem;
    color: #333;
}
.cat-table-row {
    display: flex;
    align-items: center;
    gap: 0.8rem;
}
.cat-table-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1rem;
    flex-shrink: 0;
}
.cat-table-info { flex: 1; min-width: 0; }
.cat-table-name {
    font-weight: 600;
    color: #1e3a5f;
    margin-bottom: 0.15rem;
}
.cat-table-slug {
    font-size: 0.75rem;
    color: #888;
    font-family: 'Courier New', monospace;
}

/* ===== BULK BAR ===== */
.bulk-bar {
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
.bulk-bar.show { display: flex; }
.bulk-bar .count {
    background: rgba(255,255,255,0.2);
    padding: 0.3rem 0.8rem;
    border-radius: 15px;
    font-weight: 700;
    font-size: 0.88rem;
}
.bulk-btn {
    background: rgba(231, 76, 60, 0.2);
    color: white;
    border: 1px solid rgba(255,255,255,0.3);
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
.bulk-btn:hover { background: #e74c3c; border-color: #e74c3c; }
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

/* ===== EMPTY STATE ===== */
.empty-state-cat {
    background: white;
    padding: 4rem 2rem;
    border-radius: 15px;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.empty-state-cat .empty-icon {
    width: 120px; height: 120px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3.5rem;
    margin: 0 auto 1.5rem;
}
.empty-state-cat h3 {
    color: #1e3a5f;
    font-size: 1.4rem;
    margin-bottom: 0.5rem;
}
.empty-state-cat p {
    color: #666;
    max-width: 400px;
    margin: 0 auto;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1024px) {
    .categories-layout {
        grid-template-columns: 1fr;
    }
    .category-form-card {
        position: static;
    }
}
@media (max-width: 768px) {
    .cat-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .categories-grid {
        grid-template-columns: 1fr;
    }
    .cat-controls {
        flex-direction: column;
        align-items: stretch;
    }
    .cat-search { min-width: auto; }
}

/* ===== DARK MODE ===== */
html[data-theme="dark"] .category-form-card,
html[data-theme="dark"] .cat-stat-card,
html[data-theme="dark"] .cat-controls,
html[data-theme="dark"] .cat-card,
html[data-theme="dark"] .cat-table-wrap,
html[data-theme="dark"] .empty-state-cat {
    background: #1e2638;
}
html[data-theme="dark"] .cat-table thead { background: #16203a; }
html[data-theme="dark"] .cat-table tbody tr { border-color: #2a3550; }
html[data-theme="dark"] .cat-table tbody tr:hover { background: #25304a; }
html[data-theme="dark"] .cat-table-name,
html[data-theme="dark"] .cat-card-name,
html[data-theme="dark"] .cat-main-header h2,
html[data-theme="dark"] .cat-stat-info .value,
html[data-theme="dark"] .empty-state-cat h3,
html[data-theme="dark"] .category-form-header h3,
html[data-theme="dark"] .form-field label {
    color: #e5e8ec;
}
html[data-theme="dark"] .form-field input,
html[data-theme="dark"] .form-field textarea,
html[data-theme="dark"] .cat-search input,
html[data-theme="dark"] .cat-select {
    background: #16203a;
    border-color: #2a3550;
    color: #e5e8ec;
}
html[data-theme="dark"] .icon-picker {
    background: #16203a;
    border-color: #2a3550;
}
html[data-theme="dark"] .icon-option {
    background: #25304a;
    color: #a0a8b5;
}
html[data-theme="dark"] .cat-card-stats { background: #16203a; }
html[data-theme="dark"] .cat-card-stat .value { color: #e5e8ec; }

@keyframes slideDown {
    from { transform: translateY(-10px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
</style>

<main class="admin-main">
    
    <!-- ===== HEADER ===== -->
    <div class="cat-main-header">
        <h2><i class="fas fa-tags"></i> Manajemen Kategori</h2>
    </div>

    <!-- ===== STATS CARDS ===== -->
    <div class="cat-stats">
        <div class="cat-stat-card total">
            <div class="cat-stat-icon"><i class="fas fa-tags"></i></div>
            <div class="cat-stat-info">
                <span class="value"><?php echo $stats['total']; ?></span>
                <span class="label">Total Kategori</span>
            </div>
        </div>
        <div class="cat-stat-card active">
            <div class="cat-stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="cat-stat-info">
                <span class="value"><?php echo $stats['with_articles']; ?></span>
                <span class="label">Aktif</span>
            </div>
        </div>
        <div class="cat-stat-card empty">
            <div class="cat-stat-icon"><i class="fas fa-folder-open"></i></div>
            <div class="cat-stat-info">
                <span class="value"><?php echo $stats['empty']; ?></span>
                <span class="label">Tanpa Artikel</span>
            </div>
        </div>
        <div class="cat-stat-card articles">
            <div class="cat-stat-icon"><i class="fas fa-newspaper"></i></div>
            <div class="cat-stat-info">
                <span class="value"><?php echo number_format($stats['total_articles']); ?></span>
                <span class="label">Total Artikel</span>
            </div>
        </div>
    </div>

    <div class="categories-layout">
        
        <!-- ===== FORM SIDEBAR ===== -->
        <div class="category-form-card" id="categoryFormCard">
            <div class="category-form-header">
                <h3>
                    <i class="fas fa-plus-circle" id="formIcon"></i>
                    <span id="formTitle">Tambah Kategori</span>
                </h3>
                <button type="button" class="cancel-edit-btn" onclick="cancelEdit()">
                    <i class="fas fa-times"></i> Batal
                </button>
            </div>
            
            <form method="POST" id="categoryForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="add" id="formAction">
                <input type="hidden" name="id" value="" id="formId">
                
                <div class="form-field">
                    <label>Nama Kategori <span class="required">*</span></label>
                    <input type="text" name="name" id="catName" required 
                           placeholder="Contoh: Teknologi" 
                           maxlength="50">
                    <small class="field-help">Maksimal 50 karakter</small>
                </div>

                <div class="form-field">
                    <label>Slug URL</label>
                    <input type="text" name="slug" id="catSlug" 
                           placeholder="Kosongkan untuk auto-generate">
                    <div class="slug-preview empty" id="slugPreview">
                        <span class="slug-preview-prefix">/category/</span>
                        <span class="slug-preview-value" id="slugPreviewValue">auto-generate</span>
                    </div>
                </div>

                <div class="form-field">
                    <label>Deskripsi</label>
                    <textarea name="description" id="catDesc" rows="3" 
                              placeholder="Deskripsi singkat kategori..."></textarea>
                </div>

                <div class="form-field">
                    <label>Icon</label>
                    <input type="hidden" name="icon" id="catIcon" value="fa-tag">
                    <div class="icon-picker" id="iconPicker">
                        <!-- Icons akan di-generate via JS -->
                    </div>
                </div>

                <div class="form-field">
                    <label>Warna</label>
                    <input type="hidden" name="color" id="catColor" value="#3498db">
                    <div class="color-picker" id="colorPicker">
                        <!-- Colors akan di-generate via JS -->
                    </div>
                </div>

                <button type="submit" class="btn-submit-category" id="submitBtn">
                    <i class="fas fa-plus"></i>
                    <span id="submitBtnText">Tambah Kategori</span>
                </button>
            </form>
        </div>

        <!-- ===== MAIN CONTENT ===== -->
        <div class="categories-main">
            
            <!-- Controls -->
            <div class="cat-controls">
                <form method="GET" class="cat-search">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Cari kategori..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <?php if ($sortBy !== 'name_asc'): ?>
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
                    <?php endif; ?>
                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewMode); ?>">
                </form>
                
                <select class="cat-select" onchange="applyCatFilter('sort', this.value)">
                    <option value="name_asc" <?php echo $sortBy === 'name_asc' ? 'selected' : ''; ?>>🔤 A - Z</option>
                    <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>🔤 Z - A</option>
                    <option value="articles_desc" <?php echo $sortBy === 'articles_desc' ? 'selected' : ''; ?>>📊 Artikel Terbanyak</option>
                    <option value="articles_asc" <?php echo $sortBy === 'articles_asc' ? 'selected' : ''; ?>>📊 Artikel Tersedikit</option>
                    <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>🆕 Terbaru</option>
                </select>

                <div style="display:flex;gap:0.3rem;background:#f4f6f9;border-radius:10px;padding:3px;">
                    <button type="button" class="cat-action-btn <?php echo $viewMode === 'grid' ? 'edit' : ''; ?>" 
                            onclick="applyCatFilter('view', 'grid')" 
                            style="padding:0.5rem 0.8rem;flex:none;"
                            title="Tampilan Grid">
                        <i class="fas fa-th-large"></i>
                    </button>
                    <button type="button" class="cat-action-btn <?php echo $viewMode === 'table' ? 'edit' : ''; ?>" 
                            onclick="applyCatFilter('view', 'table')" 
                            style="padding:0.5rem 0.8rem;flex:none;"
                            title="Tampilan Tabel">
                        <i class="fas fa-table"></i>
                    </button>
                </div>

                <?php if (!empty($search)): ?>
                    <a href="<?php echo url('admin/categories.php'); ?>" 
                       class="cat-action-btn delete" 
                       style="padding:0.6rem 1rem;text-decoration:none;">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                <?php endif; ?>
            </div>

            <!-- Bulk Action Bar -->
            <div class="bulk-bar" id="bulkBar">
                <span class="count"><span id="bulkCount">0</span> dipilih</span>
                <form method="POST" style="display:inline;" id="bulkForm" 
                      onsubmit="return confirmBulkDelete(event)">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="bulk_delete">
                    <button type="submit" class="bulk-btn">
                        <i class="fas fa-trash"></i> Hapus Terpilih
                    </button>
                </form>
                <button type="button" class="bulk-cancel" onclick="clearCatSelection()">
                    <i class="fas fa-times"></i> Batal
                </button>
            </div>

            <!-- Info -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
                <div style="color:#666;font-size:0.88rem;">
                    <strong><?php echo count($categories); ?></strong> kategori 
                    <?php if (!empty($search)): ?>
                        untuk "<strong><?php echo htmlspecialchars($search); ?></strong>"
                    <?php endif; ?>
                </div>
                <?php if (count($categories) > 0): ?>
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <input type="checkbox" id="selectAllCat" class="cat-checkbox" style="width:18px;height:18px;accent-color:#f39c12;">
                        <label for="selectAllCat" style="font-size:0.85rem;color:#666;cursor:pointer;">Pilih semua</label>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ===== LIST CATEGORIES ===== -->
            <?php if (empty($categories)): ?>
                <div class="empty-state-cat">
                    <div class="empty-icon">
                        <i class="fas fa-<?php echo !empty($search) ? 'search' : 'folder-open'; ?>"></i>
                    </div>
                    <h3>
                        <?php echo !empty($search) ? 'Kategori Tidak Ditemukan' : 'Belum Ada Kategori'; ?>
                    </h3>
                    <p>
                        <?php if (!empty($search)): ?>
                            Tidak ada kategori yang cocok dengan pencarian Anda.
                        <?php else: ?>
                            Mulai buat kategori pertama untuk mengorganisir artikel Anda!
                        <?php endif; ?>
                    </p>
                </div>

            <?php elseif ($viewMode === 'grid'): ?>
                <!-- GRID VIEW -->
                <div class="categories-grid">
                    <?php foreach ($categories as $cat): 
                        $icon = !empty($cat['icon']) ? $cat['icon'] : 'fa-tag';
                        $color = !empty($cat['color']) ? $cat['color'] : '#3498db';
                    ?>
                        <div class="cat-card" data-id="<?php echo $cat['id']; ?>" 
                             style="--cat-color: <?php echo htmlspecialchars($color); ?>;">
                            <input type="checkbox" class="cat-checkbox cat-select" 
                                   value="<?php echo $cat['id']; ?>">
                            
                            <div class="cat-card-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                                <i class="fas <?php echo htmlspecialchars($icon); ?>"></i>
                            </div>
                            
                            <h3 class="cat-card-name">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </h3>
                            <div class="cat-card-slug">
                                /<?php echo htmlspecialchars($cat['slug']); ?>
                            </div>
                            
                            <p class="cat-card-desc">
                                <?php echo htmlspecialchars($cat['description'] ?: 'Tidak ada deskripsi'); ?>
                            </p>
                            
                            <div class="cat-card-stats">
                                <div class="cat-card-stat">
                                    <span class="value"><?php echo (int)$cat['article_count']; ?></span>
                                    <span class="label">Artikel</span>
                                </div>
                                <div class="cat-card-stat">
                                    <span class="value"><?php echo number_format($cat['total_views']); ?></span>
                                    <span class="label">Views</span>
                                </div>
                            </div>
                            
                            <div class="cat-card-actions">
                                <button type="button" class="cat-action-btn edit" 
                                        onclick="editCategory(<?php echo htmlspecialchars(json_encode($cat)); ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <a href="<?php echo url('category.php?slug=' . $cat['slug']); ?>" 
                                   class="cat-action-btn view" target="_blank">
                                    <i class="fas fa-external-link-alt"></i> Lihat
                                </a>
                                <form method="POST" style="flex:1;" 
                                      onsubmit="return confirmDeleteCat(event, '<?php echo htmlspecialchars(addslashes($cat['name'])); ?>', <?php echo (int)$cat['article_count']; ?>)">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                    <button type="submit" class="cat-action-btn delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <!-- TABLE VIEW -->
                <div class="cat-table-wrap">
                    <table class="cat-table">
                        <thead>
                            <tr>
                                <th style="width:40px;"></th>
                                <th>Kategori</th>
                                <th>Deskripsi</th>
                                <th>Artikel</th>
                                <th>Views</th>
                                <th style="text-align:right;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): 
                                $icon = !empty($cat['icon']) ? $cat['icon'] : 'fa-tag';
                                $color = !empty($cat['color']) ? $cat['color'] : '#3498db';
                            ?>
                                <tr data-id="<?php echo $cat['id']; ?>">
                                    <td>
                                        <input type="checkbox" class="cat-checkbox cat-select" 
                                               value="<?php echo $cat['id']; ?>">
                                    </td>
                                    <td>
                                        <div class="cat-table-row">
                                            <div class="cat-table-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                                                <i class="fas <?php echo htmlspecialchars($icon); ?>"></i>
                                            </div>
                                            <div class="cat-table-info">
                                                <div class="cat-table-name">
                                                    <?php echo htmlspecialchars($cat['name']); ?>
                                                </div>
                                                <div class="cat-table-slug">
                                                    /<?php echo htmlspecialchars($cat['slug']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($cat['description'] ?: '-'); ?>
                                    </td>
                                    <td>
                                        <span style="background:#f4f6f9;padding:0.3rem 0.7rem;border-radius:15px;font-weight:600;font-size:0.85rem;">
                                            <?php echo (int)$cat['article_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color:#888;font-size:0.88rem;">
                                            <i class="fas fa-eye"></i> <?php echo number_format($cat['total_views']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:0.3rem;justify-content:flex-end;">
                                            <button type="button" class="cat-action-btn edit" 
                                                    onclick="editCategory(<?php echo htmlspecialchars(json_encode($cat)); ?>)"
                                                    style="padding:0.5rem 0.8rem;">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="<?php echo url('category.php?slug=' . $cat['slug']); ?>" 
                                               class="cat-action-btn view" target="_blank"
                                               style="padding:0.5rem 0.8rem;text-decoration:none;">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                            <form method="POST" style="display:inline;"
                                                  onsubmit="return confirmDeleteCat(event, '<?php echo htmlspecialchars(addslashes($cat['name'])); ?>', <?php echo (int)$cat['article_count']; ?>)">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                                <button type="submit" class="cat-action-btn delete" 
                                                        style="padding:0.5rem 0.8rem;">
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

        </div>
    </div>
</main>

<script>
(function() {
    'use strict';

    // ===== ICON & COLOR OPTIONS =====
    var ICONS = [
        'fa-tag', 'fa-tags', 'fa-folder', 'fa-book', 'fa-graduation-cap',
        'fa-brain', 'fa-microchip', 'fa-flask', 'fa-leaf', 'fa-heartbeat',
        'fa-users', 'fa-chart-line', 'fa-gavel', 'fa-palette', 'fa-landmark',
        'fa-running', 'fa-code', 'fa-music', 'fa-camera', 'fa-globe',
        'fa-lightbulb', 'fa-rocket', 'fa-star', 'fa-trophy', 'fa-medal',
        'fa-fire', 'fa-bolt', 'fa-crown', 'fa-gem', 'fa-dice',
        'fa-plane', 'fa-utensils', 'fa-home', 'fa-car', 'fa-briefcase',
        'fa-newspaper'
    ];
    
    var COLORS = [
        '#3498db', '#2ecc71', '#e74c3c', '#f39c12', '#9b59b6',
        '#1abc9c', '#e67e22', '#34495e', '#16a085', '#c0392b',
        '#8e44ad', '#d35400', '#27ae60', '#2980b9', '#f1c40f',
        '#95a5a6'
    ];

    // ===== INIT ICON PICKER =====
    var iconPicker = document.getElementById('iconPicker');
    var catIconInput = document.getElementById('catIcon');
    ICONS.forEach(function(icon) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'icon-option' + (icon === catIconInput.value ? ' selected' : '');
        btn.innerHTML = '<i class="fas ' + icon + '"></i>';
        btn.title = icon.replace('fa-', '');
        btn.addEventListener('click', function() {
            iconPicker.querySelectorAll('.icon-option').forEach(function(o) {
                o.classList.remove('selected');
            });
            btn.classList.add('selected');
            catIconInput.value = icon;
        });
        iconPicker.appendChild(btn);
    });

    // ===== INIT COLOR PICKER =====
    var colorPicker = document.getElementById('colorPicker');
    var catColorInput = document.getElementById('catColor');
    COLORS.forEach(function(color) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'color-option' + (color === catColorInput.value ? ' selected' : '');
        btn.style.background = color;
        btn.title = color;
        btn.addEventListener('click', function() {
            colorPicker.querySelectorAll('.color-option').forEach(function(o) {
                o.classList.remove('selected');
            });
            btn.classList.add('selected');
            catColorInput.value = color;
        });
        colorPicker.appendChild(btn);
    });

    // ===== SLUG PREVIEW =====
    var nameInput = document.getElementById('catName');
    var slugInput = document.getElementById('catSlug');
    var slugPreviewValue = document.getElementById('slugPreviewValue');
    var slugPreview = document.getElementById('slugPreview');
    
    function updateSlugPreview() {
        var val = slugInput.value.trim() || slugifyClient(nameInput.value);
        if (val) {
            slugPreviewValue.textContent = val;
            slugPreview.classList.remove('empty');
        } else {
            slugPreviewValue.textContent = 'auto-generate';
            slugPreview.classList.add('empty');
        }
    }
    
    nameInput.addEventListener('input', updateSlugPreview);
    slugInput.addEventListener('input', updateSlugPreview);

    function slugifyClient(text) {
        return text.toString().toLowerCase()
            .replace(/\s+/g, '-')
            .replace(/[^\w\-]+/g, '')
            .replace(/\-\-+/g, '-')
            .replace(/^-+/, '')
            .replace(/-+$/, '');
    }

    // ===== EDIT CATEGORY (load ke form) =====
    window.editCategory = function(cat) {
        var formCard = document.getElementById('categoryFormCard');
        formCard.classList.add('editing');
        
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formId').value = cat.id;
        document.getElementById('catName').value = cat.name;
        document.getElementById('catSlug').value = cat.slug;
        document.getElementById('catDesc').value = cat.description || '';
        
        // Icon
        var iconVal = cat.icon || 'fa-tag';
        catIconInput.value = iconVal;
        iconPicker.querySelectorAll('.icon-option').forEach(function(btn) {
            var i = btn.querySelector('i');
            btn.classList.toggle('selected', i && i.className === 'fas ' + iconVal);
        });
        
        // Color
        var colorVal = cat.color || '#3498db';
        catColorInput.value = colorVal;
        colorPicker.querySelectorAll('.color-option').forEach(function(btn) {
            btn.classList.toggle('selected', btn.style.background === colorVal || 
                                              rgbToHex(btn.style.background) === colorVal.toLowerCase());
        });
        
        // Update labels
        document.getElementById('formTitle').textContent = 'Edit Kategori';
        document.getElementById('formIcon').className = 'fas fa-edit';
        document.getElementById('submitBtnText').textContent = 'Update Kategori';
        document.getElementById('submitBtn').querySelector('i').className = 'fas fa-save';
        
        updateSlugPreview();
        
        // Scroll to form
        formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(function() { nameInput.focus(); }, 300);
    };

    function rgbToHex(rgb) {
        if (!rgb || rgb.charAt(0) === '#') return rgb;
        var match = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
        if (!match) return rgb;
        return '#' + [match[1], match[2], match[3]].map(function(x) {
            var hex = parseInt(x).toString(16);
            return hex.length === 1 ? '0' + hex : hex;
        }).join('');
    }

    window.cancelEdit = function() {
        var formCard = document.getElementById('categoryFormCard');
        formCard.classList.remove('editing');
        document.getElementById('categoryForm').reset();
        document.getElementById('formAction').value = 'add';
        document.getElementById('formId').value = '';
        catIconInput.value = 'fa-tag';
        catColorInput.value = '#3498db';
        
        iconPicker.querySelectorAll('.icon-option').forEach(function(btn, idx) {
            btn.classList.toggle('selected', idx === 0);
        });
        colorPicker.querySelectorAll('.color-option').forEach(function(btn, idx) {
            btn.classList.toggle('selected', idx === 0);
        });
        
        document.getElementById('formTitle').textContent = 'Tambah Kategori';
        document.getElementById('formIcon').className = 'fas fa-plus-circle';
        document.getElementById('submitBtnText').textContent = 'Tambah Kategori';
        document.getElementById('submitBtn').querySelector('i').className = 'fas fa-plus';
        updateSlugPreview();
    };

    // ===== FILTER HANDLER =====
    window.applyCatFilter = function(key, value) {
        var url = new URL(window.location.href);
        if (!value) url.searchParams.delete(key);
        else url.searchParams.set(key, value);
        window.location.href = url.toString();
    };

    // ===== DELETE CONFIRMATION =====
    window.confirmDeleteCat = function(e, name, articleCount) {
        e.preventDefault();
        var form = e.target;
        
        var message = 'Hapus kategori "' + name + '"?';
        var mode = 'keep';
        
        if (articleCount > 0) {
            message += '\n\nKategori ini memiliki ' + articleCount + ' artikel. Pilih tindakan:';
            var choice = confirm(
                message + '\n\n' +
                'OK = Pindahkan artikel ke "Tanpa Kategori"\n' +
                'Cancel = Batalkan penghapusan\n\n' +
                '(Untuk menghapus artikel juga, refresh dan gunakan mode advanced)'
            );
            if (!choice) return false;
            
            // Tambah hidden input untuk mode
            var modeInput = document.createElement('input');
            modeInput.type = 'hidden';
            modeInput.name = 'delete_mode';
            modeInput.value = mode;
            form.appendChild(modeInput);
        } else {
            if (typeof UI !== 'undefined' && UI.confirm) {
                UI.confirm(
                    message + ' Tindakan ini tidak dapat dibatalkan.',
                    function() {
                        if (typeof UI !== 'undefined') UI.showLoading('Menghapus...');
                        form.submit();
                    },
                    { danger: true, icon: '🗑️', title: 'Hapus Kategori?' }
                );
                return false;
            }
            if (!confirm(message)) return false;
        }
        
        if (typeof UI !== 'undefined') UI.showLoading('Menghapus...');
        form.submit();
        return false;
    };

    // ===== BULK SELECTION =====
    var selectCheckboxes = document.querySelectorAll('.cat-select');
    var selectAll = document.getElementById('selectAllCat');
    var bulkBar = document.getElementById('bulkBar');
    var bulkCount = document.getElementById('bulkCount');
    var bulkForm = document.getElementById('bulkForm');

    function updateBulk() {
        var selected = document.querySelectorAll('.cat-select:checked');
        var count = selected.length;
        if (bulkCount) bulkCount.textContent = count;
        if (bulkBar) bulkBar.classList.toggle('show', count > 0);
        
        // Update row highlighting
        document.querySelectorAll('[data-id]').forEach(function(row) {
            var id = row.getAttribute('data-id');
            var cb = document.querySelector('.cat-select[value="' + id + '"]');
            if (cb) row.classList.toggle('selected', cb.checked);
        });
        
        // Update select all state
        if (selectAll) {
            selectAll.checked = count === selectCheckboxes.length && count > 0;
            selectAll.indeterminate = count > 0 && count < selectCheckboxes.length;
        }
        
        // Update bulk form with IDs
        if (bulkForm) {
            bulkForm.querySelectorAll('input[name="selected_ids[]"]').forEach(function(el) { el.remove(); });
            selected.forEach(function(cb) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'selected_ids[]';
                inp.value = cb.value;
                bulkForm.appendChild(inp);
            });
        }
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

    window.clearCatSelection = function() {
        selectCheckboxes.forEach(function(cb) { cb.checked = false; });
        if (selectAll) selectAll.checked = false;
        updateBulk();
    };

    window.confirmBulkDelete = function(e) {
        var ids = bulkForm.querySelectorAll('input[name="selected_ids[]"]');
        var count = ids.length;
        if (count === 0) {
            if (typeof UI !== 'undefined') UI.warning('Pilih minimal 1 kategori!', 'Tidak Ada Selection');
            e.preventDefault();
            return false;
        }
        
        e.preventDefault();
        if (typeof UI !== 'undefined' && UI.confirm) {
            UI.confirm(
                'Hapus ' + count + ' kategori? Artikel terkait akan dipindahkan ke "Tanpa Kategori".',
                function() {
                    if (typeof UI !== 'undefined') UI.showLoading('Menghapus...');
                    bulkForm.submit();
                },
                { danger: true, icon: '🗑️', title: 'Hapus ' + count + ' Kategori?' }
            );
        } else {
            if (confirm('Hapus ' + count + ' kategori?')) bulkForm.submit();
        }
        return false;
    };

    // ===== ROW CLICK TO SELECT =====
    document.querySelectorAll('[data-id]').forEach(function(row) {
        row.addEventListener('click', function(e) {
            if (e.target.closest('a, button, .cat-checkbox, form, .cat-action-btn')) return;
            var id = this.getAttribute('data-id');
            var cb = document.querySelector('.cat-select[value="' + id + '"]');
            if (cb) {
                cb.checked = !cb.checked;
                cb.dispatchEvent(new Event('change'));
            }
        });
    });

    // ===== SEARCH AUTO-SUBMIT =====
    var searchInput = document.querySelector('.cat-search input');
    var searchTimer;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            var val = this.value;
            searchTimer = setTimeout(function() {
                if (val.length >= 2 || val === '') {
                    searchInput.parentElement.submit();
                }
            }, 500);
        });
    }

    console.log('%c🏷️ Category Manager Ultimate Loaded!', 'font-size:14px;color:#1e3a5f;font-weight:bold;');
})();
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>