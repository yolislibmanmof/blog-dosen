<?php
$settings = getSettings();

// ============================================
// DATA UNTUK FOOTER
// ============================================

// 1. Top 6 Kategori
$footerCategories = [];
try {
    $stmt = db()->query("
        SELECT c.id, c.name, c.slug, COUNT(a.id) as article_count
        FROM categories c
        LEFT JOIN articles a ON a.category_id = c.id AND a.status = 'published'
        GROUP BY c.id
        ORDER BY article_count DESC
        LIMIT 6
    ");
    $footerCategories = $stmt->fetchAll();
} catch (Exception $e) {}

// 2. Top 3 Artikel Populer
$footerPopular = [];
try {
    $stmt = db()->query("
        SELECT id, title, slug, views, created_at
        FROM articles
        WHERE status = 'published'
        ORDER BY views DESC
        LIMIT 3
    ");
    $footerPopular = $stmt->fetchAll();
} catch (Exception $e) {}

// 3. Global Stats Mini
$footerStats = [
    'articles' => 0,
    'authors' => 0,
    'views' => 0
];
try {
    $footerStats['articles'] = (int)db()->query("SELECT COUNT(*) FROM articles WHERE status='published'")->fetchColumn();
    $footerStats['authors'] = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
    $footerStats['views'] = (int)db()->query("SELECT IFNULL(SUM(views), 0) FROM articles")->fetchColumn();
} catch (Exception $e) {}

// 4. Years of service
$footerStartYear = 2020;
$footerCurrentYear = (int)date('Y');
$footerYearsActive = $footerCurrentYear - $footerStartYear + 1;
?>

<!-- ============================================ -->
<!-- 🎨 FOOTER ULTIMATE STYLES -->
<!-- ============================================ -->
<style>
/* ===== FOOTER ULTIMATE ===== */
.footer-ultimate {
    background: linear-gradient(180deg, #16324f 0%, #0f2137 100%);
    color: white;
    padding-top: 4rem;
    position: relative;
    overflow: hidden;
}
.footer-ultimate::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.5;
}
.footer-ultimate > * { position: relative; z-index: 1; }

.footer-wave {
    position: absolute;
    top: -1px;
    left: 0;
    right: 0;
    line-height: 0;
    transform: rotate(180deg);
}
.footer-wave svg {
    width: 100%;
    height: 60px;
}

/* Footer Main Grid */
.footer-main {
    padding: 3rem 0;
}
.footer-grid-ultimate {
    display: grid;
    grid-template-columns: 1.5fr 1fr 1fr 1.3fr 1fr 1.3fr;
    gap: 2.5rem;
}

/* Brand Column */
.footer-brand-logo {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    margin-bottom: 1.2rem;
}
.footer-brand-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}
.footer-brand-text h3 {
    font-size: 1.3rem;
    margin: 0;
    color: white;
    font-weight: 700;
}
.footer-brand-text small {
    font-size: 0.75rem;
    color: rgba(255,255,255,0.6);
    letter-spacing: 1px;
    text-transform: uppercase;
}
.footer-brand p {
    color: rgba(255,255,255,0.8);
    line-height: 1.7;
    margin-bottom: 1.5rem;
    font-size: 0.9rem;
}

/* Social Links */
.footer-social {
    display: flex;
    gap: 0.7rem;
}
.footer-social a {
    width: 40px;
    height: 40px;
    background: rgba(255,255,255,0.08);
    color: white;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.3s ease;
    border: 1px solid rgba(255,255,255,0.1);
}
.footer-social a:hover {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    transform: translateY(-3px);
    border-color: transparent;
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.3);
}
.footer-social a i {
    font-size: 1rem;
}

/* Footer Column Title */
.footer-col-ultimate h4 {
    color: white;
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: 1.3rem;
    position: relative;
    padding-bottom: 0.6rem;
    display: inline-block;
}
.footer-col-ultimate h4::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 35px;
    height: 3px;
    background: linear-gradient(90deg, #f39c12, #e67e22);
    border-radius: 2px;
}

/* Quick Links */
.footer-links {
    list-style: none;
    padding: 0;
    margin: 0;
}
.footer-links li {
    margin-bottom: 0.6rem;
}
.footer-links a {
    color: rgba(255,255,255,0.75);
    text-decoration: none;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.footer-links a::before {
    content: '›';
    color: #f39c12;
    font-size: 1.2rem;
    font-weight: bold;
    transition: transform 0.3s ease;
}
.footer-links a:hover {
    color: #f39c12;
    padding-left: 5px;
}
.footer-links a:hover::before {
    transform: translateX(3px);
}

/* Categories */
.footer-category-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.footer-category-list li {
    margin-bottom: 0.6rem;
}
.footer-category-list a {
    color: rgba(255,255,255,0.75);
    text-decoration: none;
    font-size: 0.88rem;
    transition: all 0.3s ease;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.4rem 0;
}
.footer-category-list a:hover {
    color: #f39c12;
    transform: translateX(3px);
}
.footer-category-count {
    background: rgba(243, 156, 18, 0.2);
    color: #f39c12;
    padding: 0.15rem 0.6rem;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 700;
}

/* Popular Articles */
.footer-popular-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.footer-popular-item {
    display: flex;
    gap: 0.8rem;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
.footer-popular-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}
.footer-popular-rank {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.85rem;
    flex-shrink: 0;
}
.footer-popular-content {
    flex: 1;
    min-width: 0;
}
.footer-popular-title {
    color: white;
    text-decoration: none;
    font-size: 0.88rem;
    font-weight: 600;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    transition: color 0.3s ease;
}
.footer-popular-title:hover {
    color: #f39c12;
}
.footer-popular-meta {
    display: flex;
    gap: 0.8rem;
    margin-top: 0.3rem;
    font-size: 0.75rem;
    color: rgba(255,255,255,0.5);
}
.footer-popular-meta span {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

/* Contact Info */
.footer-contact-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.footer-contact-item {
    display: flex;
    gap: 0.8rem;
    margin-bottom: 1rem;
    align-items: flex-start;
}
.footer-contact-icon {
    width: 36px;
    height: 36px;
    background: rgba(243, 156, 18, 0.15);
    color: #f39c12;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 0.9rem;
}
.footer-contact-info {
    flex: 1;
    font-size: 0.88rem;
    color: rgba(255,255,255,0.75);
    line-height: 1.5;
}
.footer-contact-info a {
    color: inherit;
    text-decoration: none;
    transition: color 0.3s ease;
}
.footer-contact-info a:hover {
    color: #f39c12;
}
.footer-contact-info strong {
    color: white;
    display: block;
    margin-bottom: 0.2rem;
    font-size: 0.82rem;
}

/* Newsletter */
.footer-newsletter p {
    color: rgba(255,255,255,0.75);
    font-size: 0.88rem;
    line-height: 1.6;
    margin-bottom: 1rem;
}
.footer-newsletter-form {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.footer-newsletter-form input {
    width: 100%;
    padding: 0.75rem 1rem;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 8px;
    color: white;
    font-size: 0.9rem;
    outline: none;
    transition: all 0.3s ease;
}
.footer-newsletter-form input::placeholder {
    color: rgba(255,255,255,0.5);
}
.footer-newsletter-form input:focus {
    border-color: #f39c12;
    background: rgba(255,255,255,0.12);
}
.footer-newsletter-form button {
    width: 100%;
    padding: 0.75rem;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-size: 0.9rem;
}
.footer-newsletter-form button:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.4);
}
.footer-newsletter-note {
    font-size: 0.75rem;
    color: rgba(255,255,255,0.5);
    margin-top: 0.5rem;
}

/* Footer Stats */
.footer-stats-section {
    padding: 2rem 0;
    border-top: 1px solid rgba(255,255,255,0.08);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    margin-top: 2rem;
}
.footer-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 2rem;
}
.footer-stat-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: rgba(255,255,255,0.05);
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.08);
    transition: all 0.3s ease;
}
.footer-stat-item:hover {
    background: rgba(255,255,255,0.08);
    transform: translateY(-3px);
}
.footer-stat-icon {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.footer-stat-info strong {
    display: block;
    color: white;
    font-size: 1.5rem;
    font-weight: 800;
    line-height: 1;
}
.footer-stat-info small {
    color: rgba(255,255,255,0.7);
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Footer Badges */
.footer-badges {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-top: 1.5rem;
    flex-wrap: wrap;
}
.footer-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: rgba(255,255,255,0.08);
    color: rgba(255,255,255,0.9);
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.82rem;
    font-weight: 600;
    border: 1px solid rgba(255,255,255,0.1);
    transition: all 0.3s ease;
}
.footer-badge:hover {
    background: rgba(243, 156, 18, 0.2);
    border-color: #f39c12;
}
.footer-badge i {
    color: #f39c12;
}

/* Footer Bottom */
.footer-bottom-ultimate {
    padding: 1.5rem 0;
    background: rgba(0,0,0,0.2);
    margin-top: 2rem;
}
.footer-bottom-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.footer-copyright {
    color: rgba(255,255,255,0.7);
    font-size: 0.88rem;
}
.footer-copyright i {
    color: #e74c3c;
    animation: heartbeat 1.5s infinite;
}
@keyframes heartbeat {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.2); }
}
.footer-bottom-links {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
}
.footer-bottom-links a {
    color: rgba(255,255,255,0.7);
    text-decoration: none;
    font-size: 0.85rem;
    transition: color 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}
.footer-bottom-links a:hover {
    color: #f39c12;
}

/* Back to Top */
.back-to-top-ultimate {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    box-shadow: 0 8px 25px rgba(243, 156, 18, 0.4);
    z-index: 998;
    transition: all 0.3s ease;
}
.back-to-top-ultimate.show {
    display: flex;
    animation: slideUp 0.3s ease;
}
.back-to-top-ultimate:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 30px rgba(243, 156, 18, 0.6);
}

/* Responsive */
@media (max-width: 1200px) {
    .footer-grid-ultimate {
        grid-template-columns: 1.5fr 1fr 1fr;
    }
}
@media (max-width: 768px) {
    .footer-grid-ultimate {
        grid-template-columns: 1fr 1fr;
    }
    .footer-bottom-content {
        flex-direction: column;
        text-align: center;
    }
    .footer-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 576px) {
    .footer-grid-ultimate {
        grid-template-columns: 1fr;
    }
    .footer-stats-grid {
        grid-template-columns: 1fr;
    }
    .back-to-top-ultimate {
        bottom: 20px;
        right: 20px;
        width: 45px;
        height: 45px;
    }
}
</style>

<!-- ============================================ -->
<!-- 🎯 FOOTER ULTIMATE -->
<!-- ============================================ -->
<footer class="footer-ultimate">
    <!-- Wave SVG (optional decorative) -->
    <div class="footer-wave">
        <svg viewBox="0 0 1200 120" preserveAspectRatio="none">
            <path d="M0,0 Q300,100 600,50 T1200,50 L1200,0 Z" fill="#f8f9fa"></path>
        </svg>
    </div>

    <!-- Footer Main -->
    <div class="footer-main">
        <div class="container">
            <div class="footer-grid-ultimate">
                
                <!-- Column 1: Brand -->
                <div class="footer-col-ultimate footer-brand-col">
                    <div class="footer-brand-logo">
                        <div class="footer-brand-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="footer-brand-text">
                            <h3>Blog Dosen</h3>
                            <small><?php echo htmlspecialchars($settings['nama_kampus'] ?? 'Universitas'); ?></small>
                        </div>
                    </div>
                    <p><?php echo htmlspecialchars($settings['tagline'] ?? 'Platform berbagi ilmu dan pengetahuan dari para dosen untuk masyarakat luas.'); ?></p>
                    <div class="footer-social">
                        <?php if (!empty($settings['facebook'])): ?>
                            <a href="<?php echo htmlspecialchars($settings['facebook']); ?>" target="_blank" title="Facebook" aria-label="Facebook">
                                <i class="fab fa-facebook-f"></i>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($settings['twitter'])): ?>
                            <a href="<?php echo htmlspecialchars($settings['twitter']); ?>" target="_blank" title="Twitter" aria-label="Twitter">
                                <i class="fab fa-twitter"></i>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($settings['instagram'])): ?>
                            <a href="<?php echo htmlspecialchars($settings['instagram']); ?>" target="_blank" title="Instagram" aria-label="Instagram">
                                <i class="fab fa-instagram"></i>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($settings['youtube'])): ?>
                            <a href="<?php echo htmlspecialchars($settings['youtube']); ?>" target="_blank" title="YouTube" aria-label="YouTube">
                                <i class="fab fa-youtube"></i>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($settings['linkedin'])): ?>
                            <a href="<?php echo htmlspecialchars($settings['linkedin']); ?>" target="_blank" title="LinkedIn" aria-label="LinkedIn">
                                <i class="fab fa-linkedin-in"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Column 2: Quick Links -->
                <div class="footer-col-ultimate">
                    <h4>Tautan Cepat</h4>
                    <ul class="footer-links">
                        <li><a href="<?php echo url(); ?>">Beranda</a></li>
                        <li><a href="<?php echo url('#blog'); ?>">Artikel Terbaru</a></li>
                        <li><a href="<?php echo url('category.php'); ?>">Kategori</a></li>
                        <li><a href="<?php echo url('search.php'); ?>">Pencarian</a></li>
                        <?php if (isLoggedIn()): ?>
                            <li><a href="<?php echo url('admin/'); ?>">Dashboard</a></li>
                        <?php else: ?>
                            <li><a href="<?php echo url('admin/login.php'); ?>">Login Dosen</a></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Column 3: Categories -->
                <div class="footer-col-ultimate">
                    <h4>Kategori</h4>
                    <?php if (!empty($footerCategories)): ?>
                        <ul class="footer-category-list">
                            <?php foreach ($footerCategories as $cat): ?>
                                <li>
                                    <a href="<?php echo url('category.php?slug=' . $cat['slug']); ?>">
                                        <span><?php echo htmlspecialchars($cat['name']); ?></span>
                                        <span class="footer-category-count"><?php echo $cat['article_count']; ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p style="color:rgba(255,255,255,0.5);font-size:0.85rem;">Belum ada kategori.</p>
                    <?php endif; ?>
                </div>

                <!-- Column 4: Popular Articles -->
                <div class="footer-col-ultimate">
                    <h4>Artikel Populer</h4>
                    <?php if (!empty($footerPopular)): ?>
                        <div class="footer-popular-list">
                            <?php foreach ($footerPopular as $i => $pop): ?>
                                <div class="footer-popular-item">
                                    <div class="footer-popular-rank"><?php echo $i + 1; ?></div>
                                    <div class="footer-popular-content">
                                        <a href="<?php echo url('article.php?slug=' . $pop['slug']); ?>" class="footer-popular-title">
                                            <?php echo htmlspecialchars($pop['title']); ?>
                                        </a>
                                        <div class="footer-popular-meta">
                                            <span><i class="fas fa-eye"></i> <?php echo number_format($pop['views']); ?></span>
                                            <span><i class="fas fa-calendar"></i> <?php echo formatDate($pop['created_at']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="color:rgba(255,255,255,0.5);font-size:0.85rem;">Belum ada artikel populer.</p>
                    <?php endif; ?>
                </div>

                <!-- Column 5: Contact -->
                <div class="footer-col-ultimate">
                    <h4>Hubungi Kami</h4>
                    <ul class="footer-contact-list">
                        <?php if (!empty($settings['address'])): ?>
                            <li class="footer-contact-item">
                                <div class="footer-contact-icon">
                                    <i class="fas fa-map-marker-alt"></i>
                                </div>
                                <div class="footer-contact-info">
                                    <strong>Alamat</strong>
                                    <?php echo htmlspecialchars($settings['address']); ?>
                                </div>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($settings['phone'])): ?>
                            <li class="footer-contact-item">
                                <div class="footer-contact-icon">
                                    <i class="fas fa-phone"></i>
                                </div>
                                <div class="footer-contact-info">
                                    <strong>Telepon</strong>
                                    <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $settings['phone'])); ?>">
                                        <?php echo htmlspecialchars($settings['phone']); ?>
                                    </a>
                                </div>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($settings['email_contact'])): ?>
                            <li class="footer-contact-item">
                                <div class="footer-contact-icon">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <div class="footer-contact-info">
                                    <strong>Email</strong>
                                    <a href="mailto:<?php echo htmlspecialchars($settings['email_contact']); ?>">
                                        <?php echo htmlspecialchars($settings['email_contact']); ?>
                                    </a>
                                </div>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Column 6: Newsletter -->
                <div class="footer-col-ultimate footer-newsletter">
                    <h4>Newsletter</h4>
                    <p>Dapatkan update artikel terbaru langsung di inbox Anda. Gratis, tanpa spam!</p>
                    <form class="footer-newsletter-form" onsubmit="handleNewsletter(event)">
                        <input type="email" placeholder="email@anda.com" required aria-label="Email untuk newsletter">
                        <button type="submit">
                            <i class="fas fa-paper-plane"></i> Subscribe
                        </button>
                    </form>
                    <p class="footer-newsletter-note">
                        <i class="fas fa-lock"></i> Kami menjaga privasi email Anda
                    </p>
                </div>

            </div>

            <!-- Footer Stats -->
            <div class="footer-stats-section">
                <div class="footer-stats-grid">
                    <div class="footer-stat-item">
                        <div class="footer-stat-icon">
                            <i class="fas fa-newspaper"></i>
                        </div>
                        <div class="footer-stat-info">
                            <strong><?php echo number_format($footerStats['articles']); ?>+</strong>
                            <small>Artikel</small>
                        </div>
                    </div>
                    <div class="footer-stat-item">
                        <div class="footer-stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="footer-stat-info">
                            <strong><?php echo number_format($footerStats['authors']); ?>+</strong>
                            <small>Dosen Aktif</small>
                        </div>
                    </div>
                    <div class="footer-stat-item">
                        <div class="footer-stat-icon">
                            <i class="fas fa-eye"></i>
                        </div>
                        <div class="footer-stat-info">
                            <strong><?php echo number_format($footerStats['views']); ?>+</strong>
                            <small>Total Pembaca</small>
                        </div>
                    </div>
                    <div class="footer-stat-item">
                        <div class="footer-stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="footer-stat-info">
                            <strong><?php echo $footerYearsActive; ?></strong>
                            <small>Tahun Mengabdi</small>
                        </div>
                    </div>
                </div>

                <!-- Badges -->
                <div class="footer-badges">
                    <span class="footer-badge">
                        <i class="fas fa-shield-alt"></i> SSL Secured
                    </span>
                    <span class="footer-badge">
                        <i class="fas fa-mobile-alt"></i> Mobile Friendly
                    </span>
                    <span class="footer-badge">
                        <i class="fas fa-bolt"></i> Fast Loading
                    </span>
                    <span class="footer-badge">
                        <i class="fas fa-universal-access"></i> Accessible
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer Bottom -->
    <div class="footer-bottom-ultimate">
        <div class="container">
            <div class="footer-bottom-content">
                <div class="footer-copyright">
                    &copy; <?php echo date('Y'); ?> Blog Dosen - <?php echo htmlspecialchars($settings['nama_kampus']); ?>.
                    Made with <i class="fas fa-heart"></i> in Indonesia
                </div>
                <div class="footer-bottom-links">
                    <a href="<?php echo url('privacy.php'); ?>">
                        <i class="fas fa-user-shield"></i> Privacy
                    </a>
                    <a href="<?php echo url('terms.php'); ?>">
                        <i class="fas fa-file-contract"></i> Terms
                    </a>
                    <a href="<?php echo url('sitemap.php'); ?>">
                        <i class="fas fa-sitemap"></i> Sitemap
                    </a>
                    <a href="<?php echo url('rss.php'); ?>">
                        <i class="fas fa-rss"></i> RSS Feed
                    </a>
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- Back to Top Button -->
<button class="back-to-top-ultimate" id="backToTopBtn" onclick="scrollToTopUltimate()" title="Kembali ke Atas" aria-label="Kembali ke atas">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- ============================================ -->
<!-- 📜 SCRIPTS -->
<!-- ============================================ -->
<script src="<?php echo asset('js/script.js'); ?>"></script>

<script>
// ===== NEWSLETTER HANDLER =====
function handleNewsletter(e) {
    e.preventDefault();
    var email = e.target.querySelector('input').value;
    var btn = e.target.querySelector('button');
    
    // Simulasi loading
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengirim...';
    btn.disabled = true;
    
    setTimeout(function() {
        btn.innerHTML = '<i class="fas fa-check"></i> Berhasil!';
        btn.style.background = 'linear-gradient(135deg, #27ae60, #2ecc71)';
        e.target.querySelector('input').value = '';
        
        // Alert success
        showFooterAlert('✅ Terima kasih! ' + email + ' telah terdaftar.', 'success');
        
        setTimeout(function() {
            btn.innerHTML = originalHtml;
            btn.style.background = '';
            btn.disabled = false;
        }, 3000);
    }, 1500);
}

// ===== FOOTER ALERT =====
function showFooterAlert(message, type) {
    var alert = document.createElement('div');
    alert.style.cssText = 'position:fixed;bottom:100px;right:30px;background:' + 
        (type === 'success' ? '#27ae60' : '#e74c3c') + 
        ';color:white;padding:1rem 1.5rem;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.3);z-index:9999;animation:slideUpAlert 0.3s ease;';
    alert.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;
    document.body.appendChild(alert);
    
    setTimeout(function() {
        alert.style.opacity = '0';
        alert.style.transform = 'translateY(20px)';
        setTimeout(function() { alert.remove(); }, 300);
    }, 4000);
}

// ===== BACK TO TOP =====
function scrollToTopUltimate() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

window.addEventListener('scroll', function() {
    var btn = document.getElementById('backToTopBtn');
    if (btn) {
        if (window.scrollY > 400) {
            btn.classList.add('show');
        } else {
            btn.classList.remove('show');
        }
    }
});

// ===== Add CSS for alert animation =====
var style = document.createElement('style');
style.textContent = '@keyframes slideUpAlert { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }';
document.head.appendChild(style);

console.log('%c🎓 Footer Ultimate Loaded!', 'font-size:14px;color:#f39c12;font-weight:bold;');
</script>
</body>
</html>