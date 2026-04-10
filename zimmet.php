<?php
/**
 * ============================================================
 * ZIMMET.PHP - TONER & YEDEK PARCA DAGITIMI
 * ============================================================
 *
 * Akis:
 *   1. Yazici secilir
 *   2. O yaziciya uyumlu tonerler ve yedek parcalar listelenir
 *   3. Birden fazla secilip miktar girilir
 *   4. Toplu dagitim kaydedilir
 *
 * Yetki: super_admin, admin
 */

require_once 'config.php';
require_once 'yetki.php';
yetkiKontrol(['super_admin', 'admin']);

// --- DAGITIM FORMU GONDERILDIYSE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrfDogrula();

    $yaziciId = (int) $_POST['yazici_id'];
    $tarih    = trim($_POST['tarih'] ?? '');
    $aciklama = trim($_POST['aciklama'] ?? '');
    $dagitimData = $_POST['dagitim_data'] ?? '';

    // Tarih dogrulama
    if (!tarihDogrula($tarih)) {
        bildirim('Geçersiz tarih formatı!', 'danger');
        yonlendir('zimmet.php');
        exit;
    }

    if ($yaziciId <= 0) {
        bildirim('Yazıcı seçin!', 'danger');
        yonlendir('zimmet.php');
        exit;
    }

    $items = json_decode($dagitimData, true);
    if (!is_array($items) || empty($items)) {
        bildirim('En az bir toner veya yedek parça seçin!', 'danger');
        yonlendir('zimmet.php');
        exit;
    }

    // Yazici bilgilerini al (uyumluluk kontrolu icin)
    $yaziciStmt = $db->prepare("SELECT model, toner_model FROM yazicilar WHERE id = ? AND aktif = 1");
    $yaziciStmt->execute([$yaziciId]);
    $yazici = $yaziciStmt->fetch();
    if (!$yazici) {
        bildirim('Gecersiz veya pasif yazici!', 'danger');
        yonlendir('zimmet.php');
        exit;
    }

    $hatalar = [];
    $basariSayisi = 0;

    $db->beginTransaction();
    try {
        $stmtTonerStok   = $db->prepare("SELECT stok_miktari, toner_kodu, toner_model FROM tonerler WHERE id = ?");
        $stmtTonerCikis  = $db->prepare("INSERT INTO hareketler (toner_id, yedek_parca_id, yazici_id, miktar, hareket_tipi, tarih, aciklama) VALUES (?, NULL, ?, ?, 'cikis', ?, ?)");
        $stmtTonerUpdate = $db->prepare("UPDATE tonerler SET stok_miktari = stok_miktari - ? WHERE id = ? AND stok_miktari >= ?");
        $stmtParcaStok   = $db->prepare("SELECT stok_miktari, parca_kodu, uyumlu_modeller FROM yedek_parcalar WHERE id = ?");
        $stmtParcaCikis  = $db->prepare("INSERT INTO hareketler (toner_id, yedek_parca_id, yazici_id, miktar, hareket_tipi, tarih, aciklama) VALUES (NULL, ?, ?, ?, 'cikis', ?, ?)");
        $stmtParcaUpdate = $db->prepare("UPDATE yedek_parcalar SET stok_miktari = stok_miktari - ? WHERE id = ? AND stok_miktari >= ?");

        $izinliTipler = ['toner', 'parca'];
        foreach ($items as $item) {
            $tip = $item['tip'] ?? '';
            if (!in_array($tip, $izinliTipler)) continue;
            $id = (int) ($item['id'] ?? 0);
            $miktar = max(1, min(9999, (int) ($item['miktar'] ?? 1)));

            if ($tip === 'toner' && $id > 0) {
                $stmtTonerStok->execute([$id]);
                $row = $stmtTonerStok->fetch();
                if (!$row) {
                    $hatalar[] = "Toner #$id bulunamadi.";
                    continue;
                }
                if ($yazici['toner_model'] && $row['toner_model'] !== $yazici['toner_model']) {
                    $hatalar[] = temizle($row['toner_kodu']) . " bu yaziciyla uyumlu degil.";
                    continue;
                }
                $stmtTonerUpdate->execute([$miktar, $id, $miktar]);
                if ($stmtTonerUpdate->rowCount() === 0) {
                    $hatalar[] = temizle($row['toner_kodu']) . " icin yetersiz stok (mevcut: {$row['stok_miktari']})";
                    continue;
                }
                $stmtTonerCikis->execute([$id, $yaziciId, $miktar, $tarih, $aciklama]);
                $basariSayisi++;
            } elseif ($tip === 'parca' && $id > 0) {
                $stmtParcaStok->execute([$id]);
                $row = $stmtParcaStok->fetch();
                if (!$row) {
                    $hatalar[] = "Yedek parca #$id bulunamadi.";
                    continue;
                }
                $uyumluModeller = array_map('trim', explode(',', $row['uyumlu_modeller'] ?? ''));
                if (!in_array($yazici['model'], $uyumluModeller)) {
                    $hatalar[] = temizle($row['parca_kodu']) . " bu yaziciyla uyumlu degil.";
                    continue;
                }
                $stmtParcaUpdate->execute([$miktar, $id, $miktar]);
                if ($stmtParcaUpdate->rowCount() === 0) {
                    $hatalar[] = temizle($row['parca_kodu']) . " icin yetersiz stok (mevcut: {$row['stok_miktari']})";
                    continue;
                }
                $stmtParcaCikis->execute([$id, $yaziciId, $miktar, $tarih, $aciklama]);
                $basariSayisi++;
            }
        }

        if (!empty($hatalar)) {
            $db->rollBack();
            bildirim(implode("\n", $hatalar), 'danger');
        } else {
            $db->commit();
            $yaziciInfo = $db->prepare("SELECT CONCAT(marka,' ',model,' - ',lokasyon) FROM yazicilar WHERE id = ?");
            $yaziciInfo->execute([$yaziciId]);
            $yaziciAdi = $yaziciInfo->fetchColumn();
            $kalemDetay = [];
            foreach ($items as $item) {
                $tip = $item['tip'] ?? '';
                $itemId = (int)($item['id'] ?? 0);
                $itemMiktar = (int)($item['miktar'] ?? 1);
                if ($tip === 'toner' && $itemId > 0) {
                    $s = $db->prepare("SELECT toner_kodu FROM tonerler WHERE id = ?");
                    $s->execute([$itemId]);
                    $kalemDetay[] = $s->fetchColumn() . ' x' . $itemMiktar;
                } elseif ($tip === 'parca' && $itemId > 0) {
                    $s = $db->prepare("SELECT parca_kodu FROM yedek_parcalar WHERE id = ?");
                    $s->execute([$itemId]);
                    $kalemDetay[] = $s->fetchColumn() . ' x' . $itemMiktar;
                }
            }
            logKaydet('Dağıtım', 'Zimmet Çıkış', "Yazıcı: $yaziciAdi. Tarih: $tarih. Kalemler: " . implode(', ', $kalemDetay) . ($aciklama ? ". Açıklama: $aciklama" : ''));
            bildirim($basariSayisi . ' kalem dağıtıldı.');
        }
    } catch (Exception $e) {
        $db->rollBack();
        bildirim('Dağıtım sırasında bir hata oluştu.', 'danger');
    }
    yonlendir('zimmet.php');
}

// --- SAYFA ICIN GEREKLI VERILER (F20: Data-access fonksiyonlarini kullan) ---
$yazicilar = aktifYazicilariGetir($db);
$tonerler  = tumTonerleriGetir($db);
$parcalar  = tumParcalariGetir($db);

try {
    $sonDagitimlar = $db->query("
        SELECT h.*,
               t.toner_kodu, t.renk as toner_renk,
               p.parca_kodu, p.parca_tipi,
               CONCAT(y.marka, ' ', y.model) as yazici_adi, y.lokasyon
        FROM hareketler h
        LEFT JOIN tonerler t ON h.toner_id = t.id
        LEFT JOIN yedek_parcalar p ON h.yedek_parca_id = p.id
        LEFT JOIN yazicilar y ON h.yazici_id = y.id
        WHERE h.hareket_tipi = 'cikis'
        ORDER BY h.olusturma_tarihi DESC
        LIMIT 25
    ")->fetchAll();
} catch (Exception $e) {
    $sonDagitimlar = [];
}

// F11: Sadece JS'in ihtiyac duydugu alanlari JSON'a aktar
$tonerJson  = json_encode(array_map(fn($t) => [
    'id' => $t['id'], 'toner_kodu' => $t['toner_kodu'],
    'toner_model' => $t['toner_model'], 'renk' => $t['renk'],
    'stok_miktari' => $t['stok_miktari'], 'uyumlu_modeller' => $t['uyumlu_modeller'] ?? ''
], $tonerler), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$parcaJson  = json_encode(array_map(fn($p) => [
    'id' => $p['id'], 'parca_kodu' => $p['parca_kodu'],
    'parca_tipi' => $p['parca_tipi'], 'renk' => $p['renk'] ?? '-',
    'stok_miktari' => $p['stok_miktari'], 'uyumlu_modeller' => $p['uyumlu_modeller'] ?? ''
], $parcalar), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

$sayfaBasligi = 'Dağıtım';
require_once 'header.php';
?>

<div class="sayfa-ust stagger-item">
    <div>
        <h2>Toner & Yedek Parça Dağıtımı</h2>
        <p class="metin-soluk ub-1">Yazıcılara toner ve yedek parça çıkışı yapın, dağıtım kayıtlarını takip edin.</p>
    </div>
</div>

<div class="satir ab-3">
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-1">
        <div class="kart stat-kart aksan-mavi">
            <div class="stat-etiket"><i data-lucide="printer"></i> Aktif Yazıcı</div>
            <div class="stat-deger"><?= count($yazicilar) ?></div>
        </div>
    </div>
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-2">
        <div class="kart stat-kart aksan-yesil">
            <div class="stat-etiket"><i data-lucide="package"></i> Toner Çeşidi</div>
            <div class="stat-deger"><?= count($tonerler) ?></div>
        </div>
    </div>
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-3">
        <div class="kart stat-kart aksan-turuncu">
            <div class="stat-etiket"><i data-lucide="arrow-up-right"></i> Son Dağıtım</div>
            <div class="stat-deger"><?= count($sonDagitimlar) ?></div>
            <div class="stat-alt">Son 25 kayıt</div>
        </div>
    </div>
</div>

<div class="satir">
    <div class="sutun-buyuk-5 ab-3 stagger-item stagger-delay-1">
        <div class="kart">
            <div class="kart-baslik">
                <span>Yeni Dağıtım</span>
            </div>
            <div class="kart-icerik">
                <form method="POST" action="zimmet.php" id="dagitimForm">
                    <?= csrfToken() ?>
                    <input type="hidden" name="dagitim_data" id="dagitimData" value="">

                    <!-- 1. YAZICI SEC -->
                    <div class="ab-3">
                        <label class="form-etiket"><strong>1. Yazıcı Seçin *</strong></label>
                        <select class="form-secim" name="yazici_id" id="yaziciSec" required>
                            <option value="">-- Yazıcı Seçin --</option>
                            <?php foreach ($yazicilar as $y): ?>
                                <option value="<?= $y['id'] ?>"
                                        data-toner-model="<?= temizle($y['toner_model'] ?? '') ?>"
                                        data-model="<?= temizle($y['model']) ?>"
                                        data-lokasyon="<?= temizle($y['lokasyon']) ?>">
                                    <?= temizle($y['lokasyon']) ?> - <?= temizle($y['marka']) ?> <?= temizle($y['model']) ?>
                                    <?= !empty($y['ip_adresi']) ? '(' . temizle($y['ip_adresi']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="yaziciBilgi" class="uyari uyari-bilgi ab-3" style="display:none; font-size:13px;">
                        <div class="esnek yana-yasla">
                            <span><strong id="bilgiLokasyon"></strong></span>
                            <span id="bilgiModel"></span>
                        </div>
                    </div>

                    <!-- 2. TONERLER (coklu secim) -->
                    <div class="ab-3" id="tonerSecArea" style="display:none;">
                        <label class="form-etiket"><strong>2. Tonerler</strong> (Birden fazla seçip miktar girebilirsiniz)</label>
                        <div id="tonerButonlari" class="esnek esnek-sar bosluk-2 ab-2"></div>
                    </div>

                    <!-- 3. YEDEK PARCALAR (coklu secim) -->
                    <div class="ab-3" id="parcaSecArea" style="display:none;">
                        <label class="form-etiket"><strong>3. Yedek Parçalar</strong> (Birden fazla seçip miktar girebilirsiniz)</label>
                        <div id="parcaButonlari" class="esnek esnek-sar bosluk-2 ab-2"></div>
                    </div>

                    <!-- 4. TARIH & ACIKLAMA -->
                    <div class="ab-3">
                        <label class="form-etiket"><strong>4. Tarih</strong></label>
                        <input type="date" class="form-alan" name="tarih" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="ab-3">
                        <label class="form-etiket">Açıklama</label>
                        <textarea class="form-alan" name="aciklama" rows="2" placeholder="Talep eden, not vb."></textarea>
                    </div>

                    <button type="submit" class="dugme dugme-tehlike tam-gen" id="dagitBtn" disabled>
                        Dağıtımı Kaydet
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- SAG: Son Dagitimlar -->
    <div class="sutun-buyuk-7 ab-3 stagger-item stagger-delay-2">
        <div class="kart">
            <div class="kart-baslik"><span>Son Dağıtım Kayıtları</span></div>
            <div class="kart-icerik ic-0">
                <?php if (count($sonDagitimlar) > 0): ?>
                    <table class="tablo">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Yazıcı</th>
                                <th>Kalem</th>
                                <th>Adet</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sonDagitimlar as $d): ?>
                                <tr>
                                    <td><?= tarihFormatla($d['tarih']) ?></td>
                                    <td>
                                        <strong><?= temizle($d['lokasyon'] ?? '-') ?></strong>
                                        <br><small class="metin-soluk"><?= temizle($d['yazici_adi'] ?? '-') ?></small>
                                    </td>
                                    <td>
                                        <?php if ($d['toner_kodu']): ?>
                                            <span class="rozet <?= renkBadge($d['toner_renk']) ?>"><?= temizle($d['toner_kodu']) ?></span>
                                        <?php else: ?>
                                            <span class="rozet renk-ikincil"><?= temizle($d['parca_kodu'] ?? '-') ?></span>
                                            <small class="metin-soluk">(<?= temizle($d['parca_tipi'] ?? '') ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $d['miktar'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="metin-orta metin-soluk ic-3">Henüz dağıtım kaydı yok</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
window.zimmetTonerler = <?= $tonerJson ?>;
window.zimmetParcalar = <?= $parcaJson ?>;
</script>
<script src="js/zimmet.js"></script>

<?php require_once 'footer.php'; ?>
