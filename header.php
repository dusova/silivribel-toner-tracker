<?php
/**
 * ============================================================
 * HEADER.PHP - ORTAK UST KISIM
 * ============================================================
 */

$mevcutSayfa = basename($_SERVER['SCRIPT_NAME']);

// Kullanıcı adı + avatar harfleri
$adSoyad       = $oturumKullanici['ad_soyad'] ?? 'Kullanıcı';
$_isimParcalari = explode(' ', trim($adSoyad));
if (count($_isimParcalari) >= 2) {
    $harfler = mb_strtoupper(mb_substr($_isimParcalari[0], 0, 1) . mb_substr($_isimParcalari[count($_isimParcalari) - 1], 0, 1));
} else {
    $harfler = mb_strtoupper(mb_substr($adSoyad, 0, 2));
}
unset($_isimParcalari);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($sayfaBasligi) ? htmlspecialchars($sayfaBasligi, ENT_QUOTES, 'UTF-8') . ' — ' : '' ?>Toner Takip | Silivri Belediyesi</title>
    <link rel="stylesheet" href="css/fonts.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/lucide.min.js"></script>
    <script>
    (function(){
        var t = localStorage.getItem('tema');
        if (t === 'karanlik') document.documentElement.setAttribute('data-tema', 'karanlik');
    })();
    </script>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="dashboard-kapsayici">
    <!-- ===================== SIDEBAR ===================== -->
    <aside class="sidebar" id="sidebar">
        <!-- Branding -->
        <div class="sidebar-marka-blok">
            <a href="index.php" class="sidebar-marka-link">
                <div class="sidebar-logo-kap">
                    <img src="img/logo.svg" alt="Silivri Belediyesi" class="sidebar-logo-img">
                </div>
                <div class="sidebar-marka-metin">
                    <span class="sidebar-org">T.C. Silivri Belediyesi</span>
                    <span class="sidebar-sistem">Toner Takip Sistemi</span>
                </div>
            </a>
            <button class="sidebar-kapat-dugme" id="sidebarKapat" aria-label="Menüyü kapat">
                <i data-lucide="x"></i>
            </button>
        </div>

        <div class="sidebar-icerik">
            <ul class="sidebar-menu">
                <div class="menu-baslik">Genel</div>
                <li>
                    <a href="index.php" class="<?= $mevcutSayfa === 'index.php' ? 'aktif' : '' ?>">
                        <i data-lucide="layout-dashboard" class="menu-ikon"></i>Ana Sayfa
                    </a>
                </li>

                <?php if (in_array($oturumKullanici['rol'] ?? '', ['super_admin', 'admin'])): ?>
                <li>
                    <a href="zimmet.php" class="<?= $mevcutSayfa === 'zimmet.php' ? 'aktif' : '' ?>">
                        <i data-lucide="arrow-up-right" class="menu-ikon"></i>Dağıtım
                    </a>
                </li>
                <li>
                    <a href="gecici_zimmet.php" class="<?= $mevcutSayfa === 'gecici_zimmet.php' ? 'aktif' : '' ?>">
                        <i data-lucide="arrow-left-right" class="menu-ikon"></i>Geçici Zimmet
                    </a>
                </li>

                <div class="menu-baslik">Envanter</div>
                <li>
                    <a href="tonerler.php" class="<?= $mevcutSayfa === 'tonerler.php' ? 'aktif' : '' ?>">
                        <i data-lucide="package" class="menu-ikon"></i>Tonerler
                    </a>
                </li>
                <li>
                    <a href="yedek_parcalar.php" class="<?= $mevcutSayfa === 'yedek_parcalar.php' ? 'aktif' : '' ?>">
                        <i data-lucide="settings-2" class="menu-ikon"></i>Yedek Parçalar
                    </a>
                </li>
                <li>
                    <a href="yazicilar.php" class="<?= $mevcutSayfa === 'yazicilar.php' ? 'aktif' : '' ?>">
                        <i data-lucide="printer" class="menu-ikon"></i>Yazıcılar
                    </a>
                </li>
                <li>
                    <a href="stok_giris.php" class="<?= $mevcutSayfa === 'stok_giris.php' ? 'aktif' : '' ?>">
                        <i data-lucide="plus-circle" class="menu-ikon"></i>Stok Giriş
                    </a>
                </li>

                <div class="menu-baslik">Tanımlamalar</div>
                <li>
                    <a href="birimler.php" class="<?= $mevcutSayfa === 'birimler.php' ? 'aktif' : '' ?>">
                        <i data-lucide="building-2" class="menu-ikon"></i>Birimler
                    </a>
                </li>
                <li>
                    <a href="depo.php" class="<?= $mevcutSayfa === 'depo.php' ? 'aktif' : '' ?>">
                        <i data-lucide="warehouse" class="menu-ikon"></i>Depo Takip
                    </a>
                </li>
                <?php endif; ?>

                <div class="menu-baslik">Sistem</div>
                <li>
                    <a href="rapor.php" class="<?= $mevcutSayfa === 'rapor.php' ? 'aktif' : '' ?>">
                        <i data-lucide="bar-chart-2" class="menu-ikon"></i>Raporlar
                    </a>
                </li>

                <?php if (($oturumKullanici['rol'] ?? '') === 'super_admin'): ?>
                <li>
                    <a href="kullanicilar.php" class="<?= $mevcutSayfa === 'kullanicilar.php' ? 'aktif' : '' ?>">
                        <i data-lucide="users" class="menu-ikon"></i>Kullanıcılar
                    </a>
                </li>
                <li>
                    <a href="loglar.php" class="<?= $mevcutSayfa === 'loglar.php' ? 'aktif' : '' ?>">
                        <i data-lucide="activity" class="menu-ikon"></i>Log Kayıtları
                    </a>
                </li>
                <?php else: ?>
                <li>
                    <a href="kullanicilar.php" class="<?= $mevcutSayfa === 'kullanicilar.php' ? 'aktif' : '' ?>">
                        <i data-lucide="key-round" class="menu-ikon"></i>Şifremi Değiştir
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </aside>

    <!-- ===================== MAIN CONTENT ===================== -->
    <div class="ana-icerik">
        <header class="ust-bilgi">
            <div class="ust-bilgi-sol">
                <button class="hamburger-dugme" id="menuToggle" aria-label="Menüyü aç">
                    <i data-lucide="menu"></i>
                </button>
                <div class="breadcrumb">
                    <span class="metin-soluk">Uygulama</span>
                    <i data-lucide="chevron-right" class="breadcrumb-icon"></i>
                    <span class="metin-koyu"><?= isset($sayfaBasligi) ? htmlspecialchars($sayfaBasligi, ENT_QUOTES, 'UTF-8') : 'Ana Sayfa' ?></span>
                </div>
            </div>

            <div class="ust-bilgi-sag">
                <button class="tema-dugme" id="temaDegistir" title="Temayı değiştir">
                    <i data-lucide="moon"></i>
                </button>

                <div class="topbar-ayrac"></div>

                <div class="kullanici-bilgi">
                    <div class="kullanici-avatar">
                        <i data-lucide="user-round"></i>
                    </div>
                    <span class="kullanici-isim"><?= htmlspecialchars($adSoyad, ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <form method="POST" action="cikis.php" style="display:inline-flex;">
                    <?= function_exists('csrfToken') ? csrfToken() : '' ?>
                    <button type="submit" class="tema-dugme" title="Çıkış Yap">
                        <i data-lucide="log-out"></i>
                    </button>
                </form>
            </div>
        </header>

        <main class="kapsayici ub-4">
            <?= function_exists('bildirimiGoster') ? bildirimiGoster() : '' ?>
