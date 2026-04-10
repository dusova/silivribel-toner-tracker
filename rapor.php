<?php
/**
 * ============================================================
 * RAPOR.PHP - STOK HAREKET RAPORLARI
 * ============================================================
 *
 * Filtreler: Tarih, toner, yedek parca, yazici, hareket tipi (giris/cikis)
 * Tum roller erisebilir.
 */

require_once 'config.php';
require_once 'yetki.php';

// --- URL'den filtre parametreleri ---
$filtreKaynak = $_GET['kaynak'] ?? 'toner_parca';
if (!in_array($filtreKaynak, ['toner_parca', 'depo'])) $filtreKaynak = 'toner_parca';

$tarihBas    = $_GET['tarih_bas']    ?? date('Y-m-01');
$tarihSon    = $_GET['tarih_son']    ?? date('Y-m-d');
$filtreTip   = $_GET['hareket_tipi'] ?? '';
$sayfa       = max(1, (int) ($_GET['sayfa'] ?? 1));
$sayfaBasina = SAYFA_BASINA_KAYIT;

if (!empty($tarihBas) && !tarihDogrula($tarihBas)) $tarihBas = date('Y-m-01');
if (!empty($tarihSon) && !tarihDogrula($tarihSon)) $tarihSon = date('Y-m-d');
if (!empty($filtreTip) && !in_array($filtreTip, ['giris', 'cikis'])) $filtreTip = '';

if ($filtreKaynak === 'depo') {
    // --- DEPO ENVANTER HAREKETLERI ---
    $filtreDepoUrun = $_GET['depo_urun_id'] ?? '';

    $whereSQL = " WHERE 1=1";
    $params = [];

    if (!empty($tarihBas))      { $whereSQL .= " AND dh.tarih >= ?";       $params[] = $tarihBas; }
    if (!empty($tarihSon))      { $whereSQL .= " AND dh.tarih <= ?";       $params[] = $tarihSon; }
    if (!empty($filtreTip))     { $whereSQL .= " AND dh.hareket_tipi = ?"; $params[] = $filtreTip; }
    if (!empty($filtreDepoUrun)){ $whereSQL .= " AND dh.urun_id = ?";      $params[] = (int) $filtreDepoUrun; }

    $ozetSQL = "SELECT COUNT(*) as toplam_hareket,
        COALESCE(SUM(CASE WHEN dh.hareket_tipi = 'giris' THEN dh.miktar ELSE 0 END), 0) as toplam_giris,
        COALESCE(SUM(CASE WHEN dh.hareket_tipi = 'cikis' THEN dh.miktar ELSE 0 END), 0) as toplam_cikis
        FROM depo_hareketler dh" . $whereSQL;
    $ozetStmt = $db->prepare($ozetSQL);
    $ozetStmt->execute($params);
    $ozet = $ozetStmt->fetch();
    $toplamHareket = $ozet['toplam_hareket'];
    $toplamGiris   = $ozet['toplam_giris'];
    $toplamCikis   = $ozet['toplam_cikis'];

    $toplamSayfa = max(1, ceil($toplamHareket / $sayfaBasina));
    if ($sayfa > $toplamSayfa) $sayfa = $toplamSayfa;
    $offset = ($sayfa - 1) * $sayfaBasina;

    $sql = "SELECT dh.*, du.urun_adi, du.kategori
            FROM depo_hareketler dh
            JOIN depo_urunler du ON dh.urun_id = du.id"
            . $whereSQL
            . " ORDER BY dh.tarih DESC, dh.olusturma_tarihi DESC"
            . " LIMIT $sayfaBasina OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $hareketler = $stmt->fetchAll();

    $depoUrunler = $db->query("SELECT id, urun_adi, kategori FROM depo_urunler ORDER BY kategori, urun_adi")->fetchAll();

} else {
    // --- TONER & YEDEK PARCA HAREKETLERI ---
    $filtreToner  = $_GET['toner_id']     ?? '';
    $filtreParca  = $_GET['parca_id']     ?? '';
    $filtreYazici = $_GET['yazici_id']    ?? '';

    $whereSQL = " WHERE (h.toner_id IS NOT NULL OR h.yedek_parca_id IS NOT NULL)";
    $params = [];

    if (!empty($tarihBas))     { $whereSQL .= " AND h.tarih >= ?";        $params[] = $tarihBas; }
    if (!empty($tarihSon))     { $whereSQL .= " AND h.tarih <= ?";        $params[] = $tarihSon; }
    if (!empty($filtreToner))  { $whereSQL .= " AND h.toner_id = ?";      $params[] = (int) $filtreToner; }
    if (!empty($filtreParca))  { $whereSQL .= " AND h.yedek_parca_id = ?";$params[] = (int) $filtreParca; }
    if (!empty($filtreTip))    { $whereSQL .= " AND h.hareket_tipi = ?";  $params[] = $filtreTip; }
    if (!empty($filtreYazici)) { $whereSQL .= " AND h.yazici_id = ?";     $params[] = (int) $filtreYazici; }

    $toplamHareket = 0; $toplamGiris = 0; $toplamCikis = 0;
    $toplamSayfa = 1; $hareketler = [];
    $tonerler = []; $parcalar = []; $yazicilar = [];

    try {
        $ozetSQL = "SELECT COUNT(*) as toplam_hareket,
            COALESCE(SUM(CASE WHEN h.hareket_tipi = 'giris' THEN h.miktar ELSE 0 END), 0) as toplam_giris,
            COALESCE(SUM(CASE WHEN h.hareket_tipi = 'cikis' THEN h.miktar ELSE 0 END), 0) as toplam_cikis
            FROM hareketler h" . $whereSQL;
        $ozetStmt = $db->prepare($ozetSQL);
        $ozetStmt->execute($params);
        $ozet = $ozetStmt->fetch();
        $toplamHareket = (int)($ozet['toplam_hareket'] ?? 0);
        $toplamGiris   = (int)($ozet['toplam_giris']   ?? 0);
        $toplamCikis   = (int)($ozet['toplam_cikis']   ?? 0);

        $toplamSayfa = max(1, ceil($toplamHareket / $sayfaBasina));
        if ($sayfa > $toplamSayfa) $sayfa = $toplamSayfa;
        $offset = ($sayfa - 1) * $sayfaBasina;

        $sql = "SELECT h.*, t.toner_kodu, t.renk as toner_renk, t.toner_model,
                       p.parca_kodu, p.parca_tipi,
                       CONCAT(y.marka, ' ', y.model) as yazici_adi, y.lokasyon
                FROM hareketler h
                LEFT JOIN tonerler t ON h.toner_id = t.id
                LEFT JOIN yedek_parcalar p ON h.yedek_parca_id = p.id
                LEFT JOIN yazicilar y ON h.yazici_id = y.id"
                . $whereSQL
                . " ORDER BY h.tarih DESC, h.olusturma_tarihi DESC"
                . " LIMIT $sayfaBasina OFFSET $offset";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $hareketler = $stmt->fetchAll();

        $tonerler  = function_exists('tumTonerleriGetir')  ? tumTonerleriGetir($db)  : [];
        $parcalar  = function_exists('tumParcalariGetir')  ? tumParcalariGetir($db)  : [];
        $yazicilar = function_exists('aktifYazicilariGetir') ? aktifYazicilariGetir($db) : [];
    } catch (Exception $e) {
        // tablo henüz oluşmamış olabilir — boş veri ile devam et
    }
}

$sayfaBasligi = 'Raporlar';
require_once 'header.php';
?>

<div class="sayfa-ust stagger-item">
    <div>
        <h2>Raporlar</h2>
        <p class="metin-soluk ub-1">Toner, yedek parça ve depo stok hareketlerini filtreleyin ve inceleyin.</p>
    </div>
</div>

<!-- FILTRE FORMU -->
<div class="kart ab-3 stagger-item stagger-delay-1">
    <div class="kart-baslik"><span>Filtrele</span></div>
    <div class="kart-icerik">
        <form method="GET" action="rapor.php" id="raporFiltre">
            <div class="rapor-filtre-grid">

                <div class="rapor-filtre-alan">
                    <label class="form-etiket">Kaynak</label>
                    <select class="form-secim" name="kaynak" onchange="document.getElementById('raporFiltre').submit()">
                        <option value="toner_parca" <?= $filtreKaynak === 'toner_parca' ? 'selected' : '' ?>>Toner & Yedek Parça</option>
                        <option value="depo" <?= $filtreKaynak === 'depo' ? 'selected' : '' ?>>Depo Envanteri</option>
                    </select>
                </div>

                <div class="rapor-filtre-alan">
                    <label class="form-etiket">Başlangıç</label>
                    <input type="date" class="form-alan" name="tarih_bas" value="<?= htmlspecialchars($tarihBas, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="rapor-filtre-alan">
                    <label class="form-etiket">Bitiş</label>
                    <input type="date" class="form-alan" name="tarih_son" value="<?= htmlspecialchars($tarihSon, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <?php if ($filtreKaynak === 'depo'): ?>
                <div class="rapor-filtre-alan">
                    <label class="form-etiket">Ürün</label>
                    <select class="form-secim" name="depo_urun_id">
                        <option value="">Tümü</option>
                        <?php foreach ($depoUrunler as $du): ?>
                            <option value="<?= $du['id'] ?>" <?= ($filtreDepoUrun ?? '') == $du['id'] ? 'selected' : '' ?>>
                                <?= temizle($du['urun_adi']) ?> (<?= temizle($du['kategori']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <div class="rapor-filtre-alan">
                    <label class="form-etiket">Toner</label>
                    <select class="form-secim" name="toner_id">
                        <option value="">Tümü</option>
                        <?php foreach ($tonerler as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= ($filtreToner ?? '') == $t['id'] ? 'selected' : '' ?>>
                                <?= temizle($t['toner_kodu']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rapor-filtre-alan">
                    <label class="form-etiket">Yedek Parça</label>
                    <select class="form-secim" name="parca_id">
                        <option value="">Tümü</option>
                        <?php foreach ($parcalar as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($filtreParca ?? '') == $p['id'] ? 'selected' : '' ?>>
                                <?= temizle($p['parca_kodu']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rapor-filtre-alan">
                    <label class="form-etiket">Yazıcı / Lokasyon</label>
                    <select class="form-secim" name="yazici_id">
                        <option value="">Tümü</option>
                        <?php foreach ($yazicilar as $y): ?>
                            <option value="<?= $y['id'] ?>" <?= ($filtreYazici ?? '') == $y['id'] ? 'selected' : '' ?>>
                                <?= temizle($y['lokasyon']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="rapor-filtre-alan">
                    <label class="form-etiket">İşlem Tipi</label>
                    <select class="form-secim" name="hareket_tipi">
                        <option value="">Tümü</option>
                        <option value="giris" <?= $filtreTip === 'giris' ? 'selected' : '' ?>>Giriş</option>
                        <option value="cikis" <?= $filtreTip === 'cikis' ? 'selected' : '' ?>>Çıkış</option>
                    </select>
                </div>

                <div class="rapor-filtre-dugme">
                    <label class="form-etiket">&nbsp;</label>
                    <button type="submit" class="dugme dugme-koyu tam-gen">
                        <i data-lucide="search"></i> Filtrele
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>

<!-- OZET -->
<div class="satir ab-3 stagger-item stagger-delay-2">
    <div class="sutun-orta-4 ab-2">
        <div class="kart stat-kart aksan-mavi">
            <div class="stat-etiket"><i data-lucide="activity"></i> Toplam Hareket</div>
            <div class="stat-deger"><?= $toplamHareket ?></div>
        </div>
    </div>
    <div class="sutun-orta-4 ab-2">
        <div class="kart stat-kart aksan-yesil">
            <div class="stat-etiket"><i data-lucide="arrow-down-circle"></i> Toplam Giriş</div>
            <div class="stat-deger"><?= $toplamGiris ?></div>
        </div>
    </div>
    <div class="sutun-orta-4 ab-2">
        <div class="kart stat-kart aksan-kirmizi">
            <div class="stat-etiket" style="color:var(--tehlike);"><i data-lucide="arrow-up-circle"></i> Toplam Çıkış</div>
            <div class="stat-deger" style="color:var(--tehlike);"><?= $toplamCikis ?></div>
        </div>
    </div>
</div>

<!-- HAREKET TABLOSU -->
<div class="kart stagger-item stagger-delay-3">
    <div class="kart-baslik esnek yana-yasla">
        <div class="esnek hizala-orta bosluk-3">
            <span><?= $filtreKaynak === 'depo' ? 'Depo Hareketleri' : 'Stok Hareketleri' ?></span>
            <span class="rozet renk-ikincil"><?= $toplamHareket ?> Kayıt<?= $toplamSayfa > 1 ? ' · Sayfa ' . $sayfa . '/' . $toplamSayfa : '' ?></span>
        </div>
        <button class="dugme dugme-kucuk dugme-ikincil" onclick="window.print()"><i data-lucide="printer"></i> Yazdır</button>
    </div>
    <div class="kart-icerik ic-0">
        <?php if (count($hareketler) > 0): ?>
            <table class="tablo">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Tarih</th>
                        <?php if ($filtreKaynak === 'depo'): ?>
                            <th>Ürün</th>
                            <th>Kategori</th>
                        <?php else: ?>
                            <th>Toner / Parça</th>
                        <?php endif; ?>
                        <th>İşlem</th>
                        <th>Adet</th>
                        <?php if ($filtreKaynak === 'depo'): ?>
                            <th>Teslim Alan</th>
                        <?php else: ?>
                            <th>Yazıcı / Lokasyon</th>
                        <?php endif; ?>
                        <th>Açıklama</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sira = ($sayfa - 1) * $sayfaBasina + 1; foreach ($hareketler as $h): ?>
                        <tr>
                            <td><?= $sira++ ?></td>
                            <td><?= tarihFormatla($h['tarih']) ?></td>
                            <?php if ($filtreKaynak === 'depo'): ?>
                                <td><strong><?= temizle($h['urun_adi']) ?></strong></td>
                                <td><span class="rozet renk-bilgi metin-koyu"><?= temizle($h['kategori']) ?></span></td>
                            <?php else: ?>
                                <td>
                                    <?php if (!empty($h['toner_kodu'])): ?>
                                        <strong><?= temizle($h['toner_kodu']) ?></strong>
                                        <small class="metin-soluk">(<?= temizle($h['toner_renk']) ?>)</small>
                                    <?php else: ?>
                                        <strong><?= temizle($h['parca_kodu'] ?? '-') ?></strong>
                                        <small class="metin-soluk">(<?= temizle($h['parca_tipi'] ?? '') ?>)</small>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td>
                                <?php if ($h['hareket_tipi'] === 'giris'): ?>
                                    <span class="rozet renk-basari">Giriş</span>
                                <?php else: ?>
                                    <span class="rozet renk-tehlike">Çıkış</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $h['miktar'] ?></td>
                            <?php if ($filtreKaynak === 'depo'): ?>
                                <td><small><?= temizle($h['teslim_alan'] ?? '') ?: '-' ?></small></td>
                            <?php else: ?>
                                <td>
                                    <?php if (!empty($h['yazici_adi'])): ?>
                                        <?= temizle($h['lokasyon']) ?>
                                        <br><small class="metin-soluk"><?= temizle($h['yazici_adi']) ?></small>
                                    <?php else: ?>
                                        Depo
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><small><?= temizle($h['aciklama']) ?: '-' ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="bos-durum"><i data-lucide="search"></i><p>Seçilen filtrelerle eşleşen kayıt bulunamadı.</p></div>
        <?php endif; ?>
    </div>
</div>
<script>if(window.lucide) lucide.createIcons();</script>

<?php if ($toplamSayfa > 1): ?>
<div class="sayfalama">
    <?php
    // Mevcut filtre parametrelerini koruyarak sayfa linklerini olustur
    $filtreParams = $_GET;
    unset($filtreParams['sayfa']);
    $filtreQuery = htmlspecialchars(http_build_query($filtreParams), ENT_QUOTES, 'UTF-8');
    ?>
    <?php if ($sayfa > 1): ?>
        <a href="rapor.php?<?= $filtreQuery ?>&amp;sayfa=1" class="dugme dugme-kucuk dugme-cizgi-ana">&laquo; İlk</a>
        <a href="rapor.php?<?= $filtreQuery ?>&amp;sayfa=<?= $sayfa - 1 ?>" class="dugme dugme-kucuk dugme-cizgi-ana">&lsaquo; Önceki</a>
    <?php endif; ?>

    <?php
    $baslangic = max(1, $sayfa - 2);
    $bitis = min($toplamSayfa, $sayfa + 2);
    for ($s = $baslangic; $s <= $bitis; $s++):
    ?>
        <?php if ($s == $sayfa): ?>
            <span class="dugme dugme-kucuk dugme-ana"><?= $s ?></span>
        <?php else: ?>
            <a href="rapor.php?<?= $filtreQuery ?>&amp;sayfa=<?= $s ?>" class="dugme dugme-kucuk dugme-cizgi-ana"><?= $s ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($sayfa < $toplamSayfa): ?>
        <a href="rapor.php?<?= $filtreQuery ?>&amp;sayfa=<?= $sayfa + 1 ?>" class="dugme dugme-kucuk dugme-cizgi-ana">Sonraki &rsaquo;</a>
        <a href="rapor.php?<?= $filtreQuery ?>&amp;sayfa=<?= $toplamSayfa ?>" class="dugme dugme-kucuk dugme-cizgi-ana">Son &raquo;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
