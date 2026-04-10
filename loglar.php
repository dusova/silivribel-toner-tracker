<?php
/**
 * ============================================================
 * LOGLAR.PHP - SISTEM LOG KAYITLARI
 * ============================================================
 *
 * Tum sistem islemlerinin detayli kayitlarini gosterir.
 * Filtreleme: modul, kullanici, tarih araligi, arama
 *
 * Yetki: super_admin
 */

require_once 'config.php';
require_once 'yetki.php';
yetkiKontrol(['super_admin']);

// --- TABLO YOKSA OLUSTUR (oturum basina bir kez) ---
if (empty($_SESSION['_log_tabloHazir'])) {
    $db->exec("CREATE TABLE IF NOT EXISTS `sistem_loglari` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `kullanici_id` INT NULL, `kullanici_adi` VARCHAR(50),
        `ad_soyad` VARCHAR(200), `ip_adresi` VARCHAR(45), `modul` VARCHAR(50) NOT NULL DEFAULT '',
        `islem` VARCHAR(50) NOT NULL DEFAULT '', `detay` TEXT, `olusturma_tarihi` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_log_tarih` (`olusturma_tarihi`), INDEX `idx_log_modul` (`modul`), INDEX `idx_log_kullanici` (`kullanici_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $_SESSION['_log_tabloHazir'] = true;
}
// IIS OPCache'i temizle (PHP cache sorunu)
if (function_exists('opcache_reset')) { opcache_reset(); }

// --- LOG TEMIZLEME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'temizle') {
    csrfDogrula();
    $gun = (int) ($_POST['gun'] ?? 90);
    if ($gun < 7) $gun = 7;
    $stmt = $db->prepare("DELETE FROM sistem_loglari WHERE olusturma_tarihi < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$gun]);
    $silinenSayisi = $stmt->rowCount();
    logKaydet('Sistem', 'Log Temizleme', "$gun günden eski $silinenSayisi log kaydı silindi.");
    bildirim("$silinenSayisi eski log kaydı silindi.");
    yonlendir('loglar.php');
}

// --- FILTRELER ---
$filtreModul     = trim($_GET['modul'] ?? '');
$filtreKullanici = trim($_GET['kullanici'] ?? '');
$filtreTarihBas  = trim($_GET['tarih_bas'] ?? '');
$filtreTarihBit  = trim($_GET['tarih_bit'] ?? '');
$filtreArama     = trim($_GET['arama'] ?? '');
$sayfa           = max(1, (int) ($_GET['sayfa'] ?? 1));
$sayfaBasi       = 50;

// Mevcut moduller
$moduller = $db->query("SELECT DISTINCT modul FROM sistem_loglari ORDER BY modul")->fetchAll(PDO::FETCH_COLUMN);
$kullanicilar = $db->query("SELECT DISTINCT kullanici_adi FROM sistem_loglari WHERE kullanici_adi IS NOT NULL ORDER BY kullanici_adi")->fetchAll(PDO::FETCH_COLUMN);

// --- SORGU OLUSTUR ---
$where = [];
$params = [];

if ($filtreModul) {
    $where[] = "modul = ?";
    $params[] = $filtreModul;
}
if ($filtreKullanici) {
    $where[] = "kullanici_adi = ?";
    $params[] = $filtreKullanici;
}
if ($filtreTarihBas) {
    $where[] = "olusturma_tarihi >= ?";
    $params[] = $filtreTarihBas . ' 00:00:00';
}
if ($filtreTarihBit) {
    $where[] = "olusturma_tarihi <= ?";
    $params[] = $filtreTarihBit . ' 23:59:59';
}
if ($filtreArama) {
    $aramaEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $filtreArama);
    $where[] = "(detay LIKE ? OR islem LIKE ?)";
    $params[] = "%$aramaEscaped%";
    $params[] = "%$aramaEscaped%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Toplam kayit
$toplamStmt = $db->prepare("SELECT COUNT(*) FROM sistem_loglari $whereSQL");
$toplamStmt->execute($params);
$toplamKayit = (int) $toplamStmt->fetchColumn();
$toplamSayfa = max(1, ceil($toplamKayit / $sayfaBasi));
$offset = ($sayfa - 1) * $sayfaBasi;

// Loglari cek
$stmt = $db->prepare("SELECT * FROM sistem_loglari $whereSQL ORDER BY olusturma_tarihi DESC LIMIT $sayfaBasi OFFSET $offset");
$stmt->execute($params);
$loglar = $stmt->fetchAll();

// Istatistikler
$bugunSayisi = $db->query("SELECT COUNT(*) FROM sistem_loglari WHERE DATE(olusturma_tarihi) = CURDATE()")->fetchColumn();
$toplamLogSayisi = $db->query("SELECT COUNT(*) FROM sistem_loglari")->fetchColumn();
$sonGiris = $db->query("SELECT olusturma_tarihi FROM sistem_loglari WHERE modul = 'Oturum' AND islem = 'Giriş' ORDER BY olusturma_tarihi DESC LIMIT 1")->fetchColumn();
$basarisizGiris = $db->query("SELECT COUNT(*) FROM sistem_loglari WHERE islem = 'Başarısız Giriş' AND DATE(olusturma_tarihi) = CURDATE()")->fetchColumn();

// Excel export URL (mevcut filtreler korunur)
$excelParams = array_filter([
    'modul'     => $filtreModul,
    'kullanici' => $filtreKullanici,
    'tarih_bas' => $filtreTarihBas,
    'tarih_bit' => $filtreTarihBit,
    'arama'     => $filtreArama,
]);
$excelUrl = 'loglar_excel.php' . ($excelParams ? '?' . http_build_query($excelParams) : '');

$sayfaBasligi = 'Log Kayıtları';
require_once 'header.php';

// Modul ve islem icin renk/ikon
function logRenk($modul) {
    $modul = (string)($modul ?? '');
    $map = [
        'Oturum'     => 'renk-bilgi metin-koyu',
        'Toner'      => 'renk-ana',
        'Stok Giriş' => 'renk-basari',
        'Dağıtım'    => 'renk-tehlike',
        'Depo'       => 'renk-uyari metin-koyu',
        'Kullanıcı'  => 'renk-magenta',
        'Birim'      => 'renk-ikincil',
        'Sistem'     => 'renk-koyu',
    ];
    return $map[$modul] ?? 'renk-ikincil';
}
function islemRenk($islem) {
    $islem = (string)($islem ?? '');
    if (strpos($islem, 'Silme') !== false || strpos($islem, 'Başarısız') !== false) return 'renk-tehlike';
    if (strpos($islem, 'Ekleme') !== false || strpos($islem, 'Giriş') !== false)    return 'renk-basari';
    if (strpos($islem, 'Düzenleme') !== false)  return 'renk-bilgi metin-koyu';
    if (strpos($islem, 'Çıkış') !== false)      return 'renk-uyari metin-koyu';
    return 'renk-ikincil';
}
?>

<div class="sayfa-ust stagger-item">
    <div>
        <h2>Log Kayıtları</h2>
        <p class="metin-soluk ub-1">Sistem üzerindeki tüm kullanıcı işlemlerini ve güvenlik olaylarını inceleyin.</p>
    </div>
</div>

<div class="satir ab-3">
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-1">
        <div class="kart stat-kart aksan-mavi">
            <div class="stat-etiket"><i data-lucide="database"></i> Toplam Log</div>
            <div class="stat-deger"><?= number_format($toplamLogSayisi) ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-2">
        <div class="kart stat-kart aksan-yesil">
            <div class="stat-etiket"><i data-lucide="calendar"></i> Bugün</div>
            <div class="stat-deger"><?= $bugunSayisi ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-3">
        <div class="kart stat-kart <?= $basarisizGiris > 0 ? 'aksan-kirmizi' : 'aksan-turuncu' ?>">
            <div class="stat-etiket" <?= $basarisizGiris > 0 ? 'style="color:var(--tehlike);"' : '' ?>><i data-lucide="shield-alert"></i> Başarısız Giriş</div>
            <div class="stat-deger" <?= $basarisizGiris > 0 ? 'style="color:var(--tehlike);"' : '' ?>><?= $basarisizGiris ?></div>
            <div class="stat-alt">Bugün</div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-4">
        <div class="kart stat-kart aksan-mor">
            <div class="stat-etiket"><i data-lucide="log-in"></i> Son Giriş</div>
            <div class="stat-deger" style="font-size:1.1rem;"><?= $sonGiris ? date('d.m H:i', strtotime($sonGiris)) : '—' ?></div>
            <?php if ($sonGiris): ?><div class="stat-alt"><?= date('Y', strtotime($sonGiris)) ?></div><?php endif; ?>
        </div>
    </div>
</div>

<!-- FILTRELER -->
<div class="kart ab-3 stagger-item stagger-delay-5">
    <div class="kart-icerik">
        <form method="GET" action="loglar.php">
            <div class="satir">
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Modül</label>
                    <select class="form-secim" name="modul">
                        <option value="">Tümü</option>
                        <?php foreach ($moduller as $m): ?>
                            <option value="<?= temizle($m) ?>" <?= $filtreModul === $m ? 'selected' : '' ?>><?= temizle($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Kullanıcı</label>
                    <select class="form-secim" name="kullanici">
                        <option value="">Tümü</option>
                        <?php foreach ($kullanicilar as $k): ?>
                            <option value="<?= temizle($k) ?>" <?= $filtreKullanici === $k ? 'selected' : '' ?>><?= temizle($k) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Başlangıç</label>
                    <input type="date" class="form-alan" name="tarih_bas" value="<?= temizle($filtreTarihBas) ?>">
                </div>
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Bitiş</label>
                    <input type="date" class="form-alan" name="tarih_bit" value="<?= temizle($filtreTarihBit) ?>">
                </div>
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Arama</label>
                    <input type="text" class="form-alan" name="arama" placeholder="Detay içinde ara..." value="<?= temizle($filtreArama) ?>">
                </div>
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">&nbsp;</label>
                    <div>
                        <button type="submit" class="dugme dugme-koyu">Filtrele</button>
                        <a href="loglar.php" class="dugme dugme-ikincil dugme-kucuk">Temizle</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- LOG TABLOSU -->
<div class="kart">
    <div class="kart-baslik esnek yana-yasla">
        <div class="esnek hizala-orta bosluk-3">
            <span>Log Kayıtları</span>
            <span class="rozet renk-ikincil"><?= $toplamKayit ?> Kayıt<?= $toplamSayfa > 1 ? " · Sayfa $sayfa/$toplamSayfa" : '' ?></span>
        </div>
        <div class="esnek bosluk-2 hizala-orta">
            <a href="<?= htmlspecialchars($excelUrl, ENT_QUOTES, 'UTF-8') ?>" class="dugme dugme-kucuk dugme-basari" style="display:inline-flex; align-items:center; gap:6px;" title="Mevcut filtreyle tüm kayıtları Excel'e aktar">
                <i data-lucide="file-spreadsheet" style="width: 16px; height: 16px;"></i> Excel'e Aktar
            </a>
            <form method="POST" class="satir-ici" onsubmit="return confirm('Eski log kayıtlarını silmek istediğinize emin misiniz?')">
            <?= csrfToken() ?>
            <input type="hidden" name="islem" value="temizle">
            <select name="gun" class="form-secim" style="width:auto;display:inline-block;padding:4px 8px;font-size:0.8rem;">
                <option value="30">30 günden eski</option>
                <option value="60">60 günden eski</option>
                <option value="90" selected>90 günden eski</option>
                <option value="180">180 günden eski</option>
            </select>
            <button type="submit" class="dugme dugme-kucuk dugme-cizgi-tehlike">Eski Logları Sil</button>
        </form>
        </div><!-- /esnek bosluk-2 -->
    </div>
    <div class="kart-icerik ic-0">
        <?php if (count($loglar) > 0): ?>
            <table class="tablo">
                <thead>
                    <tr>
                        <th>Tarih / Saat</th>
                        <th>Kullanıcı</th>
                        <th>IP</th>
                        <th>Modül</th>
                        <th>İşlem</th>
                        <th>Detay</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($loglar as $log):
                        // Tüm değerleri ÖNCE hesapla — hata olursa bu satırı tamamen atla
                        try {
                            $ts = !empty($log['olusturma_tarihi']) ? @strtotime((string)$log['olusturma_tarihi']) : false;
                            $tarihGoster = ($ts && $ts > 0) ? date('d.m.Y', $ts) : htmlspecialchars((string)($log['olusturma_tarihi'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $saatGoster  = ($ts && $ts > 0) ? date('H:i:s', $ts) : '';
                            $kullaniciAdi = htmlspecialchars((string)($log['kullanici_adi'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $adSoyad      = htmlspecialchars((string)($log['ad_soyad']      ?? ''), ENT_QUOTES, 'UTF-8');
                            $ipAdresi     = htmlspecialchars((string)($log['ip_adresi']     ?? ''), ENT_QUOTES, 'UTF-8');
                            $modulVal     = (string)($log['modul'] ?? '');
                            $islemVal     = (string)($log['islem'] ?? '');
                            $detayVal     = htmlspecialchars((string)($log['detay'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $modulRenk    = logRenk($modulVal);
                            $islemRenk    = islemRenk($islemVal);
                            $modulHtml    = htmlspecialchars($modulVal, ENT_QUOTES, 'UTF-8');
                            $islemHtml    = htmlspecialchars($islemVal, ENT_QUOTES, 'UTF-8');
                        } catch (Throwable $e) {
                            continue; // Hatalı satırı tamamen atla, yarım <tr> çıkmaz
                        }
                    ?>
                        <tr>
                            <td style="white-space:nowrap;">
                                <small><?= $tarihGoster ?></small><br>
                                <small class="metin-soluk"><?= $saatGoster ?></small>
                            </td>
                            <td>
                                <strong><?= $kullaniciAdi ?></strong>
                                <?php if ($adSoyad): ?>
                                    <br><small class="metin-soluk"><?= $adSoyad ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small class="metin-soluk"><?= $ipAdresi ?></small></td>
                            <td><span class="rozet <?= $modulRenk ?>"><?= $modulHtml ?></span></td>
                            <td><span class="rozet <?= $islemRenk ?>"><?= $islemHtml ?></span></td>
                            <td><small><?= $detayVal ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($toplamSayfa > 1): ?>
                <div class="sayfalama">
                    <?php
                    $baseUrl = htmlspecialchars('loglar.php?' . http_build_query(array_filter([
                        'modul' => $filtreModul, 'kullanici' => $filtreKullanici,
                        'tarih_bas' => $filtreTarihBas, 'tarih_bit' => $filtreTarihBit, 'arama' => $filtreArama
                    ])), ENT_QUOTES, 'UTF-8');
                    ?>
                    <?php if ($sayfa > 1): ?>
                        <a href="<?= $baseUrl ?>&amp;sayfa=1" class="dugme dugme-kucuk dugme-ikincil">&laquo;</a>
                        <a href="<?= $baseUrl ?>&amp;sayfa=<?= $sayfa - 1 ?>" class="dugme dugme-kucuk dugme-ikincil">&lsaquo;</a>
                    <?php endif; ?>

                    <?php
                    $baslangic = max(1, $sayfa - 2);
                    $bitis = min($toplamSayfa, $sayfa + 2);
                    for ($s = $baslangic; $s <= $bitis; $s++): ?>
                        <a href="<?= $baseUrl ?>&amp;sayfa=<?= $s ?>" class="dugme dugme-kucuk <?= $s === $sayfa ? 'dugme-koyu' : 'dugme-ikincil' ?>"><?= $s ?></a>
                    <?php endfor; ?>

                    <?php if ($sayfa < $toplamSayfa): ?>
                        <a href="<?= $baseUrl ?>&amp;sayfa=<?= $sayfa + 1 ?>" class="dugme dugme-kucuk dugme-ikincil">&rsaquo;</a>
                        <a href="<?= $baseUrl ?>&amp;sayfa=<?= $toplamSayfa ?>" class="dugme dugme-kucuk dugme-ikincil">&raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="bos-durum"><i data-lucide="file-x"></i><p>Seçilen filtrelerle eşleşen log kaydı bulunamadı.</p></div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
