<?php
/**
 * ============================================================
 * INDEX.PHP - ANA SAYFA (DASHBOARD)
 * ============================================================
 */

require_once 'config.php';
require_once 'yetki.php';

$tonerStats = $db->query("
    SELECT
        COUNT(DISTINCT toner_model) as cesit,
        COALESCE(SUM(stok_miktari), 0) as toplam,
        SUM(CASE WHEN stok_miktari <= kritik_stok THEN 1 ELSE 0 END) as kritik
    FROM tonerler
")->fetch();
$tonerCesidi = $tonerStats['cesit'];
$toplamStok  = $tonerStats['toplam'];
$kritikStok  = $tonerStats['kritik'];

$toplamYazici = $db->query("SELECT COUNT(*) as toplam FROM yazicilar WHERE aktif = 1")->fetch()['toplam'];

$sonHareketler = $db->query("
    SELECT h.*, t.toner_kodu, t.renk as toner_renk,
           CONCAT(y.marka, ' ', y.model) as yazici_adi, y.lokasyon
    FROM hareketler h
    JOIN tonerler t ON h.toner_id = t.id
    LEFT JOIN yazicilar y ON h.yazici_id = y.id
    ORDER BY h.olusturma_tarihi DESC
    LIMIT 6
")->fetchAll();

$kritikTonerler = $db->query("SELECT * FROM tonerler WHERE stok_miktari <= kritik_stok ORDER BY stok_miktari ASC LIMIT 8")->fetchAll();

$sayfaBasligi = 'Genel Bakış';
require_once 'header.php';
?>

<div class="esnek yana-yasla hizala-orta ab-4 stagger-item" style="margin-top: 12px;">
    <div>
        <h2 class="metin-koyu">Hoş Geldiniz, <?= htmlspecialchars(explode(' ', $oturumKullanici['ad_soyad'] ?? 'Admin')[0]) ?>.</h2>
        <p class="metin-soluk ub-1" style="font-size: 0.95rem;">İşte sistemdeki güncel stok durumu ve son işlemler.</p>
    </div>
    <?php if (in_array($oturumKullanici['rol'] ?? 'super_admin', ['super_admin', 'admin'])): ?>
    <a href="zimmet.php" class="dugme dugme-mavi">
        <i data-lucide="plus"></i> Yeni Dağıtım
    </a>
    <?php endif; ?>
</div>

<div class="satir ab-4">
    <!-- Ultra Minimal Stat Cards -->
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-1">
        <div class="kart stat-kart aksan-mavi">
            <div class="stat-etiket">
                <i data-lucide="layers"></i> Toner Çeşidi
            </div>
            <div class="stat-deger"><?= $tonerCesidi ?></div>
            <div class="stat-alt">Kayıtlı model sayısı</div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-2">
        <div class="kart stat-kart aksan-yesil">
            <div class="stat-etiket">
                <i data-lucide="package"></i> Toplam Stok
            </div>
            <div class="stat-deger"><?= $toplamStok ?></div>
            <div class="stat-alt">Depodaki toplam adet</div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-3">
        <div class="kart stat-kart <?= $kritikStok > 0 ? 'aksan-kirmizi' : 'aksan-turuncu' ?>">
            <div class="stat-etiket" <?= $kritikStok > 0 ? 'style="color: var(--tehlike);"' : '' ?>>
                <i data-lucide="alert-circle"></i> Kritik Stok
            </div>
            <div class="stat-deger" <?= $kritikStok > 0 ? 'style="color: var(--tehlike);"' : '' ?>><?= $kritikStok ?></div>
            <div class="stat-alt"><?= $kritikStok > 0 ? 'Acil sipariş gerekiyor' : 'Stok seviyeleri normal' ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-4">
        <div class="kart stat-kart aksan-mor">
            <div class="stat-etiket">
                <i data-lucide="printer"></i> Aktif Yazıcı
            </div>
            <div class="stat-deger"><?= $toplamYazici ?></div>
            <div class="stat-alt">Sistemde kayıtlı cihaz</div>
        </div>
    </div>
</div>

<div class="satir stagger-item stagger-delay-5">
    <!-- Son Hareketler -->
    <div class="sutun-buyuk-8 ab-3">
        <div class="kart" style="height: 100%;">
            <div class="kart-baslik">
                <span>Son Hareketler</span>
                <a href="rapor.php" class="dugme dugme-kucuk dugme-ikincil">Tümünü Gör</a>
            </div>
            <div class="kart-icerik ic-0 tablo-kapsayici">
                <?php if (count($sonHareketler) > 0): ?>
                    <table class="tablo">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Toner</th>
                                <th>İşlem</th>
                                <th>Adet</th>
                                <th>Lokasyon</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sonHareketler as $h): ?>
                                <tr>
                                    <td><span class="metin-soluk"><?= function_exists('tarihFormatla') ? tarihFormatla($h['tarih']) : $h['tarih'] ?></span></td>
                                    <td>
                                        <div class="metin-koyu"><?= htmlspecialchars($h['toner_kodu']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($h['hareket_tipi'] === 'giris'): ?>
                                            <span class="rozet renk-basari"><span class="rozet-nokta"></span>Giriş</span>
                                        <?php else: ?>
                                            <span class="rozet renk-tehlike"><span class="rozet-nokta"></span>Çıkış</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><div class="metin-koyu"><?= $h['miktar'] ?></div></td>
                                    <td>
                                        <?php if ($h['yazici_adi']): ?>
                                            <span class="metin-koyu"><?= htmlspecialchars($h['lokasyon'] ?? '') ?></span>
                                            <div class="metin-soluk" style="font-size: 0.75rem;"><?= htmlspecialchars($h['yazici_adi']) ?></div>
                                        <?php else: ?>
                                            <span class="metin-soluk">Ana Depo</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="metin-orta metin-soluk ic-3" style="padding: 60px 0;">
                        <p>Henüz stok hareketi bulunmuyor.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Kritik Stoklar -->
    <div class="sutun-buyuk-4 ab-3">
        <div class="kart" style="height: 100%;">
            <div class="kart-baslik">
                <span>Stok Uyarıları</span>
                <?php if (count($kritikTonerler) > 0): ?>
                    <span class="rozet renk-tehlike"><?= count($kritikTonerler) ?></span>
                <?php endif; ?>
            </div>
            <div class="kart-icerik ic-0">
                <?php if (count($kritikTonerler) > 0): ?>
                    <div class="liste">
                        <?php foreach ($kritikTonerler as $t): ?>
                            <div class="esnek yana-yasla hizala-orta" style="padding: 16px 20px; border-bottom: 1px solid var(--kenar);">
                                <div class="esnek hizala-orta" style="gap: 12px;">
                                    <div>
                                        <div class="metin-koyu"><?= htmlspecialchars($t['toner_kodu']) ?></div>
                                        <div class="metin-soluk" style="font-size: 0.75rem; margin-top: 2px;"><?= htmlspecialchars($t['uyumlu_modeller']) ?></div>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($t['stok_miktari'] == 0): ?>
                                        <span class="rozet renk-tehlike" style="padding: 4px 8px;">Tükendi</span>
                                    <?php else: ?>
                                        <span class="rozet renk-uyari" style="padding: 4px 8px;"><?= $t['stok_miktari'] ?> Kaldı</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="metin-orta metin-soluk ic-3" style="padding: 60px 0;">
                        <p>Kritik seviyede ürün yok.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();
</script>

<?php require_once 'footer.php'; ?>
