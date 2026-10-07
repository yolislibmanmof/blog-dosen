<?php
$settings = getSettings();
$isLoggedIn = isLoggedIn();
$currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
              . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$pageTitleFinal = isset($pageTitle) ? $pageTitle : 'Beranda';
$pageDescription = isset($pageDescription) ? $pageDescription : 'Blog Dosen - Platform berbagi ilmu dan pengetahuan dari para dosen ' . (isset($settings['nama_kampus']) ? $settings['nama_kampus'] : '');

// ============================================
// DATA UNTUK HEADER
// ============================================

// 1. Kategori dengan jumlah artikel
$headerCategories = [];
try {
    $stmt = db()->query("
        SELECT c.id, c.name, c.slug, c.description,
               COUNT(a.id) as article_count
        FROM categories c
        LEFT JOIN articles a ON a.category_id = c.id AND a.status = 'published'
        GROUP BY c.id
        ORDER BY article_count DESC, c.name ASC
        LIMIT 8
    ");
    $headerCategories = $stmt->fetchAll();
} catch (Exception $e) {}

// 2. Artikel trending
$trendingArticles = [];
try {
    $stmt = db()->query("
        SELECT a.id, a.title, a.slug, a.views, a.featured_image,
               u.nama as author_name
        FROM articles a
        JOIN users u ON a.author_id = u.id
        WHERE a.status = 'published'
        ORDER BY a.views DESC
        LIMIT 5
    ");
    $trendingArticles = $stmt->fetchAll();
} catch (Exception $e) {}

// 3. Artikel terbaru
$latestArticles = [];
try {
    $stmt = db()->query("
        SELECT a.title, a.slug, a.created_at, u.nama as author_name
        FROM articles a
        JOIN users u ON a.author_id = u.id
        WHERE a.status = 'published'
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $latestArticles = $stmt->fetchAll();
} catch (Exception $e) {}

// 4. Total statistik
$totalStats = ['articles' => 0, 'authors' => 0, 'views' => 0];
try {
    $totalStats['articles'] = db()->query("SELECT COUNT(*) FROM articles WHERE status='published'")->fetchColumn();
    $totalStats['authors'] = db()->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
    $totalStats['views'] = db()->query("SELECT IFNULL(SUM(views), 0) FROM articles")->fetchColumn();
} catch (Exception $e) {}

// 5. Notifikasi untuk user
$notifications = [];
$unreadCount = 0;
if ($isLoggedIn) {
    try {
        $userId = $_SESSION['user_id'];
        
        $stmt = db()->prepare("
            SELECT COUNT(*) FROM comments c
            JOIN articles a ON c.article_id = a.id
            WHERE a.author_id = ? AND c.status = 'pending'
        ");
        $stmt->execute([$userId]);
        $pendingComments = $stmt->fetchColumn();
        
        if ($pendingComments > 0) {
            $notifications[] = [
                'icon' => '💬',
                'title' => $pendingComments . ' Komentar Pending',
                'desc' => 'Menunggu moderasi Anda',
                'url' => url('admin/comments.php'),
                'time' => 'Baru saja',
                'type' => 'warning'
            ];
            $unreadCount += $pendingComments;
        }
        
        $stmt = db()->prepare("SELECT COUNT(*) FROM articles WHERE author_id = ? AND status = 'draft'");
        $stmt->execute([$userId]);
        $drafts = $stmt->fetchColumn();
        
        if ($drafts > 0) {
            $notifications[] = [
                'icon' => '📝',
                'title' => $drafts . ' Draft Artikel',
                'desc' => 'Selesaikan dan publikasikan',
                'url' => url('admin/articles.php'),
                'time' => 'Hari ini',
                'type' => 'info'
            ];
            $unreadCount += $drafts;
        }
    } catch (Exception $e) {}
}

// 6. User info
$userInfo = null;
if ($isLoggedIn) {
    try {
        $stmt = db()->prepare("SELECT nama, email, foto, jabatan, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userInfo = $stmt->fetch();
    } catch (Exception $e) {
        $userInfo = [
            'nama' => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'User',
            'email' => '',
            'foto' => null,
            'jabatan' => 'Dosen',
            'role' => isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'dosen'
        ];
    }
}

// 7. Greeting
$hour = (int)date('H');
if ($hour < 11) {
    $greetingEmoji = '🌅';
} elseif ($hour < 15) {
    $greetingEmoji = '☀️';
} elseif ($hour < 18) {
    $greetingEmoji = '🌇';
} else {
    $greetingEmoji = '🌙';
}

// 8. Kategori icons
$categoryIcons = [
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

function getCategoryIcon($slug) {
    global $categoryIcons;
    if (isset($categoryIcons[$slug])) {
        return $categoryIcons[$slug];
    }
    return 'fa-tag';
}

// 9. Detect current page
$currentPage = basename($_SERVER['PHP_SELF']);
$isHome = ($currentPage === 'index.php' && !isset($_GET['slug']));
$isCategory = ($currentPage === 'category.php');
$isSearch = ($currentPage === 'search.php');
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    
    <title><?php echo htmlspecialchars($pageTitleFinal); ?> - <?php echo htmlspecialchars(isset($settings['nama_kampus']) ? $settings['nama_kampus'] : 'Blog Dosen'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars(excerpt($pageDescription, 160)); ?>">
    <meta name="keywords" content="blog dosen, <?php echo htmlspecialchars(isset($settings['nama_kampus']) ? $settings['nama_kampus'] : ''); ?>, artikel ilmiah, pendidikan, penelitian">
    <meta name="author" content="<?php echo htmlspecialchars(isset($settings['nama_kampus']) ? $settings['nama_kampus'] : 'Blog Dosen'); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo htmlspecialchars($currentUrl); ?>">
    
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitleFinal); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars(excerpt($pageDescription, 200)); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($currentUrl); ?>">
    <meta property="og:site_name" content="<?php echo htmlspecialchars(isset($settings['nama_kampus']) ? $settings['nama_kampus'] : 'Blog Dosen'); ?>">
    <meta property="og:locale" content="id_ID">
    
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($pageTitleFinal); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars(excerpt($pageDescription, 200)); ?>">
    
    <meta name="theme-color" content="#1e3a5f">
    <meta name="msapplication-TileColor" content="#1e3a5f">
    
    <link rel="stylesheet" href="<?php echo asset('css/style.css'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@300;400;700&family=Open+Sans:wght@300;400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">
    
    <style>
        :root {
            --header-primary: #1e3a5f;
            --header-secondary: #2c5f8d;
            --header-accent: #f39c12;
            --header-text: #ffffff;
            --header-bg: #ffffff;
            --topbar-bg: #1e3a5f;
            --ticker-bg: #f39c12;
        }
        
        [data-theme="dark"] {
            --header-bg: #1a1a2e;
            --header-text: #ffffff;
        }
        
        .preloader {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }
        
        .preloader.hide {
            opacity: 0;
            visibility: hidden;
        }
        
        .preloader-icon {
            font-size: 4rem;
            color: white;
            animation: bounce 1s infinite;
        }
        
        .preloader-text {
            color: white;
            margin-top: 1rem;
            font-family: 'Poppins', sans-serif;
            letter-spacing: 2px;
        }
        
        .preloader-bar {
            width: 200px;
            height: 4px;
            background: rgba(255,255,255,0.2);
            border-radius: 2px;
            margin-top: 1rem;
            overflow: hidden;
        }
        
        .preloader-bar::after {
            content: '';
            display: block;
            width: 50%;
            height: 100%;
            background: #f39c12;
            animation: loading 1.5s infinite;
        }
        
        @keyframes loading {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(300%); }
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        
        .reading-progress {
            position: fixed;
            top: 0; left: 0;
            width: 0%;
            height: 3px;
            background: linear-gradient(90deg, #f39c12, #e74c3c);
            z-index: 10000;
            transition: width 0.1s ease;
        }
        
        .top-bar {
            background: var(--topbar-bg);
            color: white;
            font-size: 0.85rem;
            padding: 0.4rem 0;
            position: relative;
            z-index: 999;
        }
        
        .top-bar-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .top-bar-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        
        .top-bar-item {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            color: rgba(255,255,255,0.9);
        }
        
        .top-bar-item i {
            color: var(--header-accent);
        }
        
        .top-bar-item a {
            color: inherit;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .top-bar-item a:hover {
            color: var(--header-accent);
        }
        
        .top-bar-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .top-bar-clock {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
        }
        
        .top-bar-social {
            display: flex;
            gap: 0.5rem;
        }
        
        .top-bar-social a {
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .top-bar-social a:hover {
            background: var(--header-accent);
            transform: translateY(-2px);
        }
        
        .theme-toggle {
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .theme-toggle:hover {
            background: var(--header-accent);
            transform: rotate(180deg);
        }
        
        .font-adjuster {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            background: rgba(255,255,255,0.1);
            padding: 0.2rem 0.5rem;
            border-radius: 15px;
        }
        
        .font-adjuster button {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-weight: bold;
            padding: 0 0.3rem;
            transition: color 0.3s ease;
        }
        
        .font-adjuster button:hover {
            color: var(--header-accent);
        }
        
        .main-header {
            background: var(--header-bg);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            position: sticky;
            top: 0;
            z-index: 998;
            transition: all 0.3s ease;
        }
        
        .main-header.scrolled {
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 2rem;
            padding-top: 1rem;
            padding-bottom: 1rem;
        }
        
        .logo-ultimate {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            text-decoration: none;
            color: var(--header-primary);
        }
        
        .logo-icon-wrapper {
            position: relative;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            transition: transform 0.3s ease;
        }
        
        .logo-ultimate:hover .logo-icon-wrapper {
            transform: rotate(-10deg) scale(1.1);
        }
        
        .logo-text {
            display: flex;
            flex-direction: column;
        }
        
        .logo-main {
            font-family: 'Poppins', sans-serif;
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--header-primary);
            line-height: 1.1;
        }
        
        .logo-tagline {
            font-size: 0.75rem;
            color: #666;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .search-bar-ultimate {
            flex: 1;
            max-width: 450px;
            position: relative;
        }
        
        .search-form-ultimate {
            display: flex;
            background: #f5f5f5;
            border-radius: 30px;
            overflow: hidden;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .search-form-ultimate:focus-within {
            border-color: var(--header-accent);
            background: white;
            box-shadow: 0 4px 15px rgba(243, 156, 18, 0.2);
        }
        
        .search-form-ultimate input {
            flex: 1;
            border: none;
            background: transparent;
            padding: 0.7rem 1.2rem;
            font-size: 0.95rem;
            outline: none;
        }
        
        .search-form-ultimate button {
            background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
            border: none;
            color: white;
            padding: 0 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .search-form-ultimate button:hover {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }
        
        .search-suggestions {
            position: absolute;
            top: calc(100% + 10px);
            left: 0;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            padding: 1rem;
            display: none;
            z-index: 1000;
        }
        
        .search-suggestions.show {
            display: block;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .suggestion-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #999;
            letter-spacing: 1px;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .suggestion-item {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.5rem;
            border-radius: 8px;
            text-decoration: none;
            color: #333;
            transition: background 0.2s ease;
        }
        
        .suggestion-item:hover {
            background: #f5f5f5;
        }
        
        .suggestion-item i {
            color: var(--header-accent);
        }
        
        .nav-ultimate {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .nav-menu-ultimate {
            display: flex;
            list-style: none;
            gap: 0.3rem;
            margin: 0;
            padding: 0;
        }
        
        .nav-item {
            position: relative;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.6rem 1rem;
            color: #333;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover,
        .nav-link.active {
            color: var(--header-primary);
            background: rgba(30, 58, 95, 0.08);
        }
        
        .nav-link i {
            font-size: 0.85rem;
        }
        
        .mega-menu {
            position: absolute;
            top: calc(100% + 10px);
            left: 50%;
            transform: translateX(-50%) translateY(10px);
            background: white;
            min-width: 600px;
            border-radius: 15px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.15);
            padding: 1.5rem;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 999;
        }
        
        .nav-item:hover .mega-menu {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0);
        }
        
        .mega-menu-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .mega-menu-item {
            display: flex;
            align-items: flex-start;
            gap: 0.8rem;
            padding: 0.8rem;
            border-radius: 10px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
        }
        
        .mega-menu-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        
        .mega-menu-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
            color: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .mega-menu-text h4 {
            font-size: 0.95rem;
            margin-bottom: 0.2rem;
            color: var(--header-primary);
        }
        
        .mega-menu-text p {
            font-size: 0.8rem;
            color: #666;
            margin: 0;
        }
        
        .mega-menu-count {
            margin-left: auto;
            background: #f39c12;
            color: white;
            padding: 0.1rem 0.5rem;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .notification-wrapper {
            position: relative;
        }
        
        .notification-btn {
            position: relative;
            width: 42px;
            height: 42px;
            background: #f5f5f5;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        
        .notification-btn:hover {
            background: var(--header-primary);
            color: white;
            transform: scale(1.1);
        }
        
        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #e74c3c;
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
            border: 2px solid white;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .notification-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 340px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.15);
            display: none;
            z-index: 1000;
            overflow: hidden;
        }
        
        .notification-dropdown.show {
            display: block;
            animation: slideDown 0.3s ease;
        }
        
        .notification-header {
            background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
            color: white;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .notification-item {
            display: flex;
            gap: 0.8rem;
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            text-decoration: none;
            color: #333;
            transition: background 0.2s ease;
        }
        
        .notification-item:hover {
            background: #f8f9fa;
        }
        
        .notification-icon {
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        
        .notification-content h4 {
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }
        
        .notification-content p {
            font-size: 0.8rem;
            color: #666;
            margin: 0;
        }
        
        .notification-time {
            font-size: 0.7rem;
            color: #999;
            margin-top: 0.3rem;
        }
        
        .user-menu-wrapper {
            position: relative;
        }
        
        .user-menu-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #f5f5f5;
            border: none;
            padding: 0.3rem 0.8rem 0.3rem 0.3rem;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .user-menu-btn:hover {
            background: #e9ecef;
        }
        
        .user-avatar-small {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--header-accent);
        }
        
        .user-menu-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: #333;
        }
        
        .user-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 240px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.15);
            display: none;
            overflow: hidden;
            z-index: 1000;
        }
        
        .user-dropdown.show {
            display: block;
            animation: slideDown 0.3s ease;
        }
        
        .user-dropdown-header {
            background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
            color: white;
            padding: 1.5rem 1rem;
            text-align: center;
        }
        
        .user-dropdown-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            margin-bottom: 0.5rem;
            object-fit: cover;
        }
        
        .user-dropdown-menu {
            padding: 0.5rem 0;
        }
        
        .user-dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.7rem 1rem;
            color: #333;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        
        .user-dropdown-item:hover {
            background: #f8f9fa;
            padding-left: 1.3rem;
        }
        
        .user-dropdown-item.logout {
            color: #e74c3c;
            border-top: 1px solid #f0f0f0;
        }
        
        .user-dropdown-item i {
            width: 20px;
            color: var(--header-primary);
        }
        
        .user-dropdown-item.logout i {
            color: #e74c3c;
        }
        
        .btn-cta {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white !important;
            padding: 0.6rem 1.3rem;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
        }
        
        .btn-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(243, 156, 18, 0.4);
        }
        
        .news-ticker {
            background: linear-gradient(90deg, #f39c12, #e67e22);
            color: white;
            padding: 0.5rem 0;
            overflow: hidden;
            position: relative;
        }
        
        .ticker-label {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            background: #1e3a5f;
            color: white;
            padding: 0.5rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
            z-index: 2;
            font-size: 0.85rem;
        }
        
        .ticker-label::after {
            content: '';
            position: absolute;
            right: -15px;
            top: 0;
            bottom: 0;
            width: 15px;
            background: linear-gradient(90deg, #1e3a5f, transparent);
        }
        
        .ticker-content {
            display: flex;
            animation: ticker 30s linear infinite;
            padding-left: 150px;
        }
        
        .ticker-content:hover {
            animation-play-state: paused;
        }
        
        @keyframes ticker {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        
        .ticker-item {
            color: white;
            text-decoration: none;
            padding: 0 2rem;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            transition: opacity 0.3s ease;
        }
        
        .ticker-item:hover {
            opacity: 0.8;
            text-decoration: underline;
        }
        
        .ticker-item::before {
            content: '🔥';
        }
        
        .hamburger-ultimate {
            display: none;
            flex-direction: column;
            gap: 5px;
            cursor: pointer;
            padding: 5px;
        }
        
        .hamburger-ultimate span {
            width: 25px;
            height: 3px;
            background: var(--header-primary);
            border-radius: 3px;
            transition: all 0.3s ease;
        }
        
        .hamburger-ultimate.active span:nth-child(1) {
            transform: rotate(45deg) translate(5px, 5px);
        }
        
        .hamburger-ultimate.active span:nth-child(2) {
            opacity: 0;
        }
        
        .hamburger-ultimate.active span:nth-child(3) {
            transform: rotate(-45deg) translate(7px, -6px);
        }
        
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            z-index: 999;
            transition: all 0.3s ease;
        }
        
        .back-to-top.show {
            display: flex;
            animation: slideUp 0.3s ease;
        }
        
        .back-to-top:hover {
            transform: translateY(-5px);
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .stats-mini-bar {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }
        
        .stat-mini {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.85rem;
            color: rgba(255,255,255,0.9);
        }
        
        .stat-mini i {
            color: var(--header-accent);
        }
        
        .stat-mini strong {
            color: white;
        }
        
        @media (max-width: 992px) {
            .search-bar-ultimate {
                display: none;
            }
            
            .nav-menu-ultimate {
                position: fixed;
                top: 0;
                right: -100%;
                width: 300px;
                height: 100vh;
                background: white;
                flex-direction: column;
                padding: 5rem 1.5rem 1.5rem;
                box-shadow: -5px 0 20px rgba(0,0,0,0.1);
                transition: right 0.3s ease;
                z-index: 997;
            }
            
            .nav-menu-ultimate.active {
                right: 0;
            }
            
            .mega-menu {
                position: static;
                transform: none;
                min-width: 100%;
                box-shadow: none;
                padding: 0;
                display: none;
            }
            
            .nav-item:hover .mega-menu {
                display: block;
                opacity: 1;
                visibility: visible;
            }
            
            .mega-menu-grid {
                grid-template-columns: 1fr;
            }
            
            .hamburger-ultimate {
                display: flex;
            }
            
            .user-menu-name {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .top-bar {
                display: none;
            }
            
            .stats-mini-bar {
                display: none;
            }
            
            .logo-tagline {
                display: none;
            }
            
            .news-ticker {
                display: none;
            }
        }
    </style>
</head>
<body>

<div class="preloader" id="preloader">
    <div class="preloader-icon">🎓</div>
    <div class="preloader-text">BLOG DOSEN</div>
    <div class="preloader-bar"></div>
</div>

<div class="reading-progress" id="readingProgress"></div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<div class="top-bar">
    <div class="top-bar-content">
        <div class="top-bar-left">
            <?php if (!empty($settings['email_contact'])): ?>
                <span class="top-bar-item">
                    <i class="fas fa-envelope"></i>
                    <a href="mailto:<?php echo htmlspecialchars($settings['email_contact']); ?>">
                        <?php echo htmlspecialchars($settings['email_contact']); ?>
                    </a>
                </span>
            <?php endif; ?>
            
            <?php if (!empty($settings['phone'])): ?>
                <span class="top-bar-item">
                    <i class="fas fa-phone"></i>
                    <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $settings['phone'])); ?>">
                        <?php echo htmlspecialchars($settings['phone']); ?>
                    </a>
                </span>
            <?php endif; ?>
            
            <div class="stats-mini-bar">
                <span class="stat-mini">
                    <i class="fas fa-newspaper"></i>
                    <strong><?php echo number_format($totalStats['articles']); ?></strong> Artikel
                </span>
                <span class="stat-mini">
                    <i class="fas fa-users"></i>
                    <strong><?php echo number_format($totalStats['authors']); ?></strong> Dosen
                </span>
                <span class="stat-mini">
                    <i class="fas fa-eye"></i>
                    <strong><?php echo number_format($totalStats['views']); ?></strong> Views
                </span>
            </div>
        </div>
        
        <div class="top-bar-right">
            <div class="top-bar-clock">
                <i class="fas fa-clock"></i>
                <span id="liveClock">--:--:--</span>
            </div>
            
            <div class="font-adjuster" title="Ukuran Font">
                <button onclick="adjustFont(-1)" title="Perkecil">A-</button>
                <span>|</span>
                <button onclick="adjustFont(1)" title="Perbesar">A+</button>
            </div>
            
            <button class="theme-toggle" onclick="toggleTheme()" title="Mode Gelap/Terang">
                <i class="fas fa-moon" id="themeIcon"></i>
            </button>
            
            <div class="top-bar-social">
                <?php if (!empty($settings['facebook'])): ?>
                    <a href="<?php echo htmlspecialchars($settings['facebook']); ?>" target="_blank" title="Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                <?php endif; ?>
                <?php if (!empty($settings['twitter'])): ?>
                    <a href="<?php echo htmlspecialchars($settings['twitter']); ?>" target="_blank" title="Twitter">
                        <i class="fab fa-twitter"></i>
                    </a>
                <?php endif; ?>
                <?php if (!empty($settings['instagram'])): ?>
                    <a href="<?php echo htmlspecialchars($settings['instagram']); ?>" target="_blank" title="Instagram">
                        <i class="fab fa-instagram"></i>
                    </a>
                <?php endif; ?>
                <?php if (!empty($settings['youtube'])): ?>
                    <a href="<?php echo htmlspecialchars($settings['youtube']); ?>" target="_blank" title="YouTube">
                        <i class="fab fa-youtube"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<header class="main-header" id="mainHeader">
    <div class="header-container">
        
        <a href="<?php echo url(); ?>" class="logo-ultimate">
            <div class="logo-icon-wrapper">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="logo-text">
                <span class="logo-main">Blog Dosen</span>
                <span class="logo-tagline"><?php echo htmlspecialchars(excerpt(isset($settings['nama_kampus']) ? $settings['nama_kampus'] : '', 30)); ?></span>
            </div>
        </a>
        
        <div class="search-bar-ultimate">
            <form action="<?php echo url('search.php'); ?>" method="GET" class="search-form-ultimate">
                <input type="text" 
                       name="q" 
                       placeholder="Cari artikel, topik, atau dosen... (Ctrl+/)" 
                       id="searchInput"
                       autocomplete="off">
                <button type="submit" title="Cari">
                    <i class="fas fa-search"></i>
                </button>
            </form>
            
            <?php if (!empty($trendingArticles)): ?>
                <div class="search-suggestions" id="searchSuggestions">
                    <div class="suggestion-title">
                        <i class="fas fa-fire"></i> Artikel Trending
                    </div>
                    <?php foreach ($trendingArticles as $trending): ?>
                        <a href="<?php echo url('article.php?slug=' . $trending['slug']); ?>" class="suggestion-item">
                            <i class="fas fa-arrow-right"></i>
                            <div>
                                <div style="font-weight:600;font-size:0.9rem;">
                                    <?php echo htmlspecialchars(excerpt($trending['title'], 50)); ?>
                                </div>
                                <small style="color:#999;">
                                    <i class="fas fa-eye"></i> <?php echo number_format($trending['views']); ?> views
                                </small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <nav class="nav-ultimate">
            <ul class="nav-menu-ultimate" id="navMenu">
                <li class="nav-item">
                    <a href="<?php echo url(); ?>" class="nav-link <?php echo $isHome ? 'active' : ''; ?>">
                        <i class="fas fa-home"></i> Beranda
                    </a>
                </li>
                
                <?php if (!empty($headerCategories)): ?>
                    <li class="nav-item">
                        <a href="<?php echo url('category.php'); ?>" class="nav-link <?php echo $isCategory ? 'active' : ''; ?>">
                            <i class="fas fa-th-large"></i> Kategori
                            <i class="fas fa-chevron-down" style="font-size:0.7rem;"></i>
                        </a>
                        <div class="mega-menu">
                            <div class="mega-menu-grid">
                                <?php foreach ($headerCategories as $cat): ?>
                                    <a href="<?php echo url('category.php?slug=' . $cat['slug']); ?>" class="mega-menu-item">
                                        <div class="mega-menu-icon">
                                            <i class="fas <?php echo getCategoryIcon($cat['slug']); ?>"></i>
                                        </div>
                                        <div class="mega-menu-text">
                                            <h4><?php echo htmlspecialchars($cat['name']); ?></h4>
                                            <p><?php echo htmlspecialchars(excerpt(isset($cat['description']) ? $cat['description'] : 'Lihat artikel', 40)); ?></p>
                                        </div>
                                        <span class="mega-menu-count"><?php echo $cat['article_count']; ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </li>
                <?php endif; ?>
                
                <li class="nav-item">
                    <a href="<?php echo url('search.php'); ?>" class="nav-link <?php echo $isSearch ? 'active' : ''; ?>">
                        <i class="fas fa-search"></i> Cari
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="<?php echo url('#layanan'); ?>" class="nav-link">
                        <i class="fas fa-concierge-bell"></i> Layanan
                    </a>
                </li>
            </ul>
            
            <?php if ($isLoggedIn): ?>
                <div class="notification-wrapper">
                    <button class="notification-btn" onclick="toggleNotifications()" title="Notifikasi">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notification-badge"><?php echo $unreadCount; ?></span>
                        <?php endif; ?>
                    </button>
                    
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="notification-header">
                            <strong>🔔 Notifikasi</strong>
                            <small><?php echo $unreadCount; ?> belum dibaca</small>
                        </div>
                        <div class="notification-list">
                            <?php if (empty($notifications)): ?>
                                <div style="padding:2rem;text-align:center;color:#999;">
                                    <i class="fas fa-bell-slash" style="font-size:2rem;margin-bottom:0.5rem;"></i>
                                    <p>Tidak ada notifikasi baru</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($notifications as $notif): ?>
                                    <a href="<?php echo $notif['url']; ?>" class="notification-item">
                                        <div class="notification-icon"><?php echo $notif['icon']; ?></div>
                                        <div class="notification-content">
                                            <h4><?php echo htmlspecialchars($notif['title']); ?></h4>
                                            <p><?php echo htmlspecialchars($notif['desc']); ?></p>
                                            <div class="notification-time">
                                                <i class="fas fa-clock"></i> <?php echo $notif['time']; ?>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($isLoggedIn && $userInfo): ?>
                <div class="user-menu-wrapper">
                    <button class="user-menu-btn" onclick="toggleUserMenu()">
                        <img src="<?php echo url(ltrim($userInfo['foto'] ? $userInfo['foto'] : 'assets/uploads/default.png', '/')); ?>" 
                             alt="Avatar"
                             class="user-avatar-small"
                             onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($userInfo['nama']); ?>&background=1e3a5f&color=fff&size=80'">
                        <span class="user-menu-name"><?php echo htmlspecialchars(explode(' ', $userInfo['nama'])[0]); ?></span>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem;color:#666;"></i>
                    </button>
                    
                    <div class="user-dropdown" id="userDropdown">
                        <div class="user-dropdown-header">
                            <img src="<?php echo url(ltrim($userInfo['foto'] ? $userInfo['foto'] : 'assets/uploads/default.png', '/')); ?>" 
                                 alt="Avatar"
                                 class="user-dropdown-avatar"
                                 onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($userInfo['nama']); ?>&background=fff&color=1e3a5f&size=140'">
                            <h4 style="margin:0;"><?php echo htmlspecialchars($userInfo['nama']); ?></h4>
                            <small style="opacity:0.8;"><?php echo htmlspecialchars(isset($userInfo['jabatan']) ? $userInfo['jabatan'] : 'Dosen'); ?></small>
                        </div>
                        <div class="user-dropdown-menu">
                            <a href="<?php echo url('admin/'); ?>" class="user-dropdown-item">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                            <a href="<?php echo url('admin/article-edit.php'); ?>" class="user-dropdown-item">
                                <i class="fas fa-pen"></i> Tulis Artikel
                            </a>
                            <a href="<?php echo url('admin/articles.php'); ?>" class="user-dropdown-item">
                                <i class="fas fa-newspaper"></i> Artikel Saya
                            </a>
                            <a href="<?php echo url('admin/profile.php'); ?>" class="user-dropdown-item">
                                <i class="fas fa-user"></i> Profil Saya
                            </a>
                            <?php if (isset($userInfo['role']) && $userInfo['role'] === 'admin'): ?>
                                <a href="<?php echo url('admin/settings.php'); ?>" class="user-dropdown-item">
                                    <i class="fas fa-cog"></i> Pengaturan
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo url('admin/logout.php'); ?>" class="user-dropdown-item logout">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?php echo url('admin/login.php'); ?>" class="btn-cta">
                    <i class="fas fa-sign-in-alt"></i> Login Dosen
                </a>
            <?php endif; ?>
            
            <div class="hamburger-ultimate" id="hamburger" onclick="toggleMobileMenu()">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </nav>
    </div>
</header>

<?php if (!empty($latestArticles)): ?>
    <div class="news-ticker">
        <div class="ticker-label">
            <i class="fas fa-bolt"></i> TERBARU
        </div>
        <div class="ticker-content">
            <?php 
            $allTickerItems = array_merge($latestArticles, $latestArticles);
            foreach ($allTickerItems as $latest): 
            ?>
                <a href="<?php echo url('article.php?slug=' . $latest['slug']); ?>" class="ticker-item">
                    <?php echo htmlspecialchars($latest['title']); ?>
                    <small style="opacity:0.8;">— <?php echo htmlspecialchars($latest['author_name']); ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<button class="back-to-top" id="backToTop" onclick="scrollToTop()" title="Kembali ke Atas">
    <i class="fas fa-arrow-up"></i>
</button>

<script>
window.addEventListener('load', function() {
    setTimeout(function() {
        document.getElementById('preloader').classList.add('hide');
    }, 500);
});

function updateClock() {
    var now = new Date();
    var timeStr = now.toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    var clockEl = document.getElementById('liveClock');
    if (clockEl) clockEl.textContent = timeStr;
}
updateClock();
setInterval(updateClock, 1000);

window.addEventListener('scroll', function() {
    var scrollTop = window.scrollY;
    var docHeight = document.documentElement.scrollHeight - window.innerHeight;
    var progress = (scrollTop / docHeight) * 100;
    var progressBar = document.getElementById('readingProgress');
    if (progressBar) progressBar.style.width = progress + '%';
    
    var header = document.getElementById('mainHeader');
    if (header) {
        if (scrollTop > 50) header.classList.add('scrolled');
        else header.classList.remove('scrolled');
    }
    
    var backToTop = document.getElementById('backToTop');
    if (backToTop) {
        if (scrollTop > 300) backToTop.classList.add('show');
        else backToTop.classList.remove('show');
    }
});

function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function toggleTheme() {
    var html = document.documentElement;
    var icon = document.getElementById('themeIcon');
    var current = html.getAttribute('data-theme');
    
    if (current === 'dark') {
        html.setAttribute('data-theme', 'light');
        icon.className = 'fas fa-moon';
        localStorage.setItem('theme', 'light');
    } else {
        html.setAttribute('data-theme', 'dark');
        icon.className = 'fas fa-sun';
        localStorage.setItem('theme', 'dark');
    }
}

if (localStorage.getItem('theme') === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
    var icon = document.getElementById('themeIcon');
    if (icon) icon.className = 'fas fa-sun';
}

var currentFontSize = parseFloat(localStorage.getItem('fontSize')) || 100;

function adjustFont(change) {
    currentFontSize = Math.max(80, Math.min(130, currentFontSize + (change * 10)));
    document.body.style.fontSize = currentFontSize + '%';
    localStorage.setItem('fontSize', currentFontSize);
}

if (localStorage.getItem('fontSize')) {
    document.body.style.fontSize = currentFontSize + '%';
}

var searchInput = document.getElementById('searchInput');
var searchSuggestions = document.getElementById('searchSuggestions');

if (searchInput && searchSuggestions) {
    searchInput.addEventListener('focus', function() {
        searchSuggestions.classList.add('show');
    });
    
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-bar-ultimate')) {
            searchSuggestions.classList.remove('show');
        }
    });
}

function toggleNotifications() {
    var dropdown = document.getElementById('notificationDropdown');
    var userDropdown = document.getElementById('userDropdown');
    if (userDropdown) userDropdown.classList.remove('show');
    if (dropdown) dropdown.classList.toggle('show');
}

function toggleUserMenu() {
    var dropdown = document.getElementById('userDropdown');
    var notifDropdown = document.getElementById('notificationDropdown');
    if (notifDropdown) notifDropdown.classList.remove('show');
    if (dropdown) dropdown.classList.toggle('show');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.notification-wrapper')) {
        var notif = document.getElementById('notificationDropdown');
        if (notif) notif.classList.remove('show');
    }
    if (!e.target.closest('.user-menu-wrapper')) {
        var user = document.getElementById('userDropdown');
        if (user) user.classList.remove('show');
    }
});

function toggleMobileMenu() {
    var menu = document.getElementById('navMenu');
    var hamburger = document.getElementById('hamburger');
    if (menu) menu.classList.toggle('active');
    if (hamburger) hamburger.classList.toggle('active');
}

document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === '/') {
        e.preventDefault();
        var search = document.getElementById('searchInput');
        if (search) search.focus();
    }
    
    if (e.key === 'Escape') {
        document.querySelectorAll('.notification-dropdown, .user-dropdown, .search-suggestions')
            .forEach(function(el) { el.classList.remove('show'); });
        var menu = document.getElementById('navMenu');
        var hamburger = document.getElementById('hamburger');
        if (menu) menu.classList.remove('active');
        if (hamburger) hamburger.classList.remove('active');
    }
});

setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.transition = 'all 0.3s ease';
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 300);
    });
}, 5000);

document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
    anchor.addEventListener('click', function(e) {
        var target = document.querySelector(this.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

console.log('%c🎓 Blog Dosen ULTIMATE EDITION', 'font-size:20px;color:#1e3a5f;font-weight:bold;');
console.log('%cDibuat dengan ❤️ untuk pendidikan Indonesia', 'font-size:12px;color:#f39c12;');
</script>