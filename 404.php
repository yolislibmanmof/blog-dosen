<?php
require_once __DIR__ . '/config/functions.php';
$pageTitle = '404 - Tidak Ditemukan';
include __DIR__ . '/includes/header.php';
?>

<section style="padding:5rem 0;text-align:center;min-height:60vh;display:flex;align-items:center;justify-content:center;">
    <div>
        <h1 style="font-size:8rem;color:#1e3a5f;margin:0;">404</h1>
        <h2 style="color:#f39c12;margin-bottom:1rem;">Halaman Tidak Ditemukan</h2>
        <p style="color:#666;margin-bottom:2rem;">
            Maaf, halaman yang Anda cari tidak ada atau telah dipindahkan.
        </p>
        <a href="<?= url() ?>" class="btn-primary">
            <i class="fas fa-home"></i> Kembali ke Beranda
        </a>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>