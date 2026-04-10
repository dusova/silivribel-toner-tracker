<?php
/**
 * ============================================================
 * YAZICILAR.PHP - YAZICI YONETIMI
 * ============================================================
 *
 * Yazici ekleme, duzenleme, silme.
 * Her yazicinin lokasyonu, modeli, toner bilgisi, IP adresi vb.
 *
 * Yetki: super_admin, admin
 */

require_once 'config.php';
require_once 'yetki.php';
yetkiKontrol(['super_admin', 'admin']);

// Mevcut toner modelleri (dropdown icin)
$tonerModelleri = $db->query("SELECT DISTINCT toner_model FROM tonerler ORDER BY toner_model")->fetchAll(PDO::FETCH_COLUMN);

// --- POST ISLEMLERI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();
    $islem = $_POST['islem'] ?? '';

    if ($islem === 'ekle') {
        $marka       = trim($_POST['marka'] ?? '');
        $model       = trim($_POST['model'] ?? '');
        $baglantiTipi = $_POST['baglanti_tipi'] ?? 'USB';
        if (!in_array($baglantiTipi, ['IP', 'USB'])) $baglantiTipi = 'USB';
        $ipAdresi    = trim($_POST['ip_adresi'] ?? '');
        $lokasyon    = trim($_POST['lokasyon'] ?? '');
        $tonerModel  = trim($_POST['toner_model'] ?? '') ?: null;
        $renkli      = isset($_POST['renkli']) ? 1 : 0;

        if (empty($marka) || empty($model) || empty($lokasyon)) {
            bildirim('Marka, model ve lokasyon zorunludur!', 'danger');
        } else {
            if ($baglantiTipi !== 'IP') $ipAdresi = null;
            $stmt = $db->prepare("INSERT INTO yazicilar (marka, model, baglanti_tipi, ip_adresi, lokasyon, toner_model, renkli) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$marka, $model, $baglantiTipi, $ipAdresi, $lokasyon, $tonerModel, $renkli]);
            logKaydet('Yazıcı', 'Ekleme', "Yazıcı eklendi: $marka $model - $lokasyon (Bağlantı: $baglantiTipi, Toner: $tonerModel)");
            bildirim('Yazıcı eklendi.');
        }
        yonlendir('yazicilar.php');
    }

    if ($islem === 'duzenle') {
        $id          = (int) $_POST['id'];
        $marka       = trim($_POST['marka'] ?? '');
        $model       = trim($_POST['model'] ?? '');
        $baglantiTipi = $_POST['baglanti_tipi'] ?? 'USB';
        if (!in_array($baglantiTipi, ['IP', 'USB'])) $baglantiTipi = 'USB';
        $ipAdresi    = trim($_POST['ip_adresi'] ?? '');
        $lokasyon    = trim($_POST['lokasyon'] ?? '');
        $tonerModel  = trim($_POST['toner_model'] ?? '') ?: null;
        $renkli      = isset($_POST['renkli']) ? 1 : 0;
        $aktif       = isset($_POST['aktif']) ? 1 : 0;

        if (empty($marka) || empty($model) || empty($lokasyon)) {
            bildirim('Marka, model ve lokasyon zorunludur!', 'danger');
        } else {
            if ($baglantiTipi !== 'IP') $ipAdresi = null;
            $stmt = $db->prepare("UPDATE yazicilar SET marka=?, model=?, baglanti_tipi=?, ip_adresi=?, lokasyon=?, toner_model=?, renkli=?, aktif=? WHERE id=?");
            $stmt->execute([$marka, $model, $baglantiTipi, $ipAdresi, $lokasyon, $tonerModel, $renkli, $aktif, $id]);
            logKaydet('Yazıcı', 'Düzenleme', "Yazıcı güncellendi [ID:$id]: $marka $model - $lokasyon (Toner: $tonerModel, Aktif: $aktif)");
            bildirim('Yazıcı güncellendi.');
        }
        yonlendir('yazicilar.php');
    }

    if ($islem === 'sil') {
        $id = (int) $_POST['id'];
        $hareketVar = $db->prepare("SELECT COUNT(*) FROM hareketler WHERE yazici_id = ?");
        $hareketVar->execute([$id]);
        if ($hareketVar->fetchColumn() > 0) {
            bildirim('Bu yazıcıya ait dağıtım kaydı var, silinemez! Pasif yapabilirsiniz.', 'danger');
        } else {
            $ad = $db->prepare("SELECT CONCAT(marka,' ',model,' - ',lokasyon) FROM yazicilar WHERE id = ?");
            $ad->execute([$id]);
            $silinecekAd = $ad->fetchColumn();
            $stmt = $db->prepare("DELETE FROM yazicilar WHERE id = ?");
            $stmt->execute([$id]);
            logKaydet('Yazıcı', 'Silme', "Yazıcı silindi [ID:$id]: $silinecekAd");
            bildirim('Yazıcı silindi.');
        }
        yonlendir('yazicilar.php');
    }
}

// --- DUZENLEME MODU ---
$duzenlenecek = null;
if (isset($_GET['duzenle'])) {
    $stmt = $db->prepare("SELECT * FROM yazicilar WHERE id = ?");
    $stmt->execute([(int) $_GET['duzenle']]);
    $duzenlenecek = $stmt->fetch();
}

// --- FILTRELER ---
$filtreBaglanti = trim($_GET['baglanti'] ?? '');
$filtreLokasyon = trim($_GET['lokasyon'] ?? '');
$filtreDurum    = trim($_GET['durum'] ?? '');

$where = [];
$params = [];

if ($filtreBaglanti) {
    $where[] = "baglanti_tipi = ?";
    $params[] = $filtreBaglanti;
}
if ($filtreLokasyon) {
    $lokasyonEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $filtreLokasyon);
    $where[] = "lokasyon LIKE ?";
    $params[] = "%$lokasyonEscaped%";
}
if ($filtreDurum === 'aktif') {
    $where[] = "aktif = 1";
} elseif ($filtreDurum === 'pasif') {
    $where[] = "aktif = 0";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $db->prepare("SELECT * FROM yazicilar $whereSQL ORDER BY aktif DESC, lokasyon, marka, model");
$stmt->execute($params);
$yazicilar = $stmt->fetchAll();

$toplamAktif = $db->query("SELECT COUNT(*) FROM yazicilar WHERE aktif = 1")->fetchColumn();
$toplamPasif = $db->query("SELECT COUNT(*) FROM yazicilar WHERE aktif = 0")->fetchColumn();
$toplamIP    = $db->query("SELECT COUNT(*) FROM yazicilar WHERE baglanti_tipi = 'IP' AND aktif = 1")->fetchColumn();
$toplamUSB   = $db->query("SELECT COUNT(*) FROM yazicilar WHERE baglanti_tipi = 'USB' AND aktif = 1")->fetchColumn();

$sayfaBasligi = 'Yazıcılar';
require_once 'header.php';
?>

<div class="sayfa-ust stagger-item">
    <div>
        <h2>Yazıcı Yönetimi</h2>
        <p class="metin-soluk ub-1">Kayıtlı yazıcıları, lokasyonlarını ve bağlantı bilgilerini yönetin.</p>
    </div>
</div>

<div class="satir ab-3">
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-1">
        <div class="kart stat-kart aksan-yesil">
            <div class="stat-etiket"><i data-lucide="printer"></i> Aktif Yazıcı</div>
            <div class="stat-deger"><?= $toplamAktif ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-2">
        <div class="kart stat-kart aksan-mavi">
            <div class="stat-etiket"><i data-lucide="wifi"></i> IP Bağlantılı</div>
            <div class="stat-deger"><?= $toplamIP ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-3">
        <div class="kart stat-kart aksan-turuncu">
            <div class="stat-etiket"><i data-lucide="usb"></i> USB Bağlantılı</div>
            <div class="stat-deger"><?= $toplamUSB ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-4">
        <div class="kart stat-kart">
            <div class="stat-etiket"><i data-lucide="circle-off"></i> Pasif</div>
            <div class="stat-deger" style="color:var(--metin-soluk);"><?= $toplamPasif ?></div>
        </div>
    </div>
</div>

<!-- EKLEME / DUZENLEME FORMU -->
<div class="kart ab-4 stagger-item stagger-delay-5">
    <div class="kart-baslik">
        <span><?= $duzenlenecek ? 'Yazıcı Düzenle' : 'Yeni Yazıcı Ekle' ?></span>
    </div>
    <div class="kart-icerik">
        <form method="POST" action="yazicilar.php" id="yaziciForm">
            <?= csrfToken() ?>
            <input type="hidden" name="islem" value="<?= $duzenlenecek ? 'duzenle' : 'ekle' ?>">
            <?php if ($duzenlenecek): ?>
                <input type="hidden" name="id" value="<?= $duzenlenecek['id'] ?>">
            <?php endif; ?>

            <div class="satir">
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Marka *</label>
                    <input type="text" class="form-alan" name="marka" required placeholder="Konica Minolta" list="marka-listesi"
                           value="<?= $duzenlenecek ? temizle($duzenlenecek['marka']) : '' ?>">
                    <datalist id="marka-listesi">
                        <option value="Konica Minolta">
                        <option value="HP">
                        <option value="Canon">
                        <option value="Samsung">
                        <option value="Pantum">
                        <option value="Epson">
                        <option value="Tally">
                    </datalist>
                </div>
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Model *</label>
                    <input type="text" class="form-alan" name="model" required placeholder="C458"
                           value="<?= $duzenlenecek ? temizle($duzenlenecek['model']) : '' ?>">
                </div>
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Lokasyon *</label>
                    <input type="text" class="form-alan" name="lokasyon" required placeholder="Baskanlik"
                           value="<?= $duzenlenecek ? temizle($duzenlenecek['lokasyon']) : '' ?>">
                </div>
                <div class="sutun-orta-1 ab-2">
                    <label class="form-etiket">Bağlantı</label>
                    <select class="form-secim" name="baglanti_tipi" id="baglantiTipi" onchange="baglantiDegisti()">
                        <option value="IP" <?= ($duzenlenecek && $duzenlenecek['baglanti_tipi'] === 'IP') ? 'selected' : '' ?>>IP</option>
                        <option value="USB" <?= (!$duzenlenecek || $duzenlenecek['baglanti_tipi'] === 'USB') ? 'selected' : '' ?>>USB</option>
                    </select>
                </div>
                <div class="sutun-orta-2 ab-2" id="ipAlani" style="<?= (!$duzenlenecek || $duzenlenecek['baglanti_tipi'] !== 'IP') ? 'display:none' : '' ?>">
                    <label class="form-etiket">IP Adresi</label>
                    <input type="text" class="form-alan" name="ip_adresi" placeholder="192.168.195.xxx"
                           value="<?= $duzenlenecek ? temizle($duzenlenecek['ip_adresi'] ?? '') : '' ?>">
                </div>
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Toner Modeli</label>
                    <input type="text" class="form-alan" name="toner_model" placeholder="TN514" list="toner-model-listesi"
                           value="<?= $duzenlenecek ? temizle($duzenlenecek['toner_model'] ?? '') : '' ?>">
                    <datalist id="toner-model-listesi">
                        <?php foreach ($tonerModelleri as $tm): ?>
                            <option value="<?= temizle($tm) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="sutun-orta-1 ab-2">
                    <label class="form-etiket">Renkli</label>
                    <div style="padding-top:8px;">
                        <label><input type="checkbox" name="renkli" value="1"
                            <?= (!$duzenlenecek || $duzenlenecek['renkli']) ? 'checked' : '' ?>> Evet</label>
                    </div>
                </div>
                <?php if ($duzenlenecek): ?>
                <div class="sutun-orta-1 ab-2">
                    <label class="form-etiket">Aktif</label>
                    <div style="padding-top:8px;">
                        <label><input type="checkbox" name="aktif" value="1"
                            <?= $duzenlenecek['aktif'] ? 'checked' : '' ?>> Evet</label>
                    </div>
                </div>
                <?php endif; ?>
                <div class="sutun-orta-1 ab-2">
                    <label class="form-etiket">&nbsp;</label>
                    <div>
                        <button type="submit" class="dugme dugme-koyu"><?= $duzenlenecek ? 'Kaydet' : 'Ekle' ?></button>
                        <?php if ($duzenlenecek): ?>
                            <a href="yazicilar.php" class="dugme dugme-ikincil dugme-kucuk ub-1">İptal</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- FILTRELER -->
<div class="filtre-bar ab-3">
    <a href="yazicilar.php" class="dugme dugme-kucuk <?= !$filtreBaglanti && !$filtreDurum ? 'dugme-koyu' : 'dugme-ikincil' ?>">Tümü (<?= $toplamAktif + $toplamPasif ?>)</a>
    <a href="yazicilar.php?baglanti=IP" class="dugme dugme-kucuk <?= $filtreBaglanti === 'IP' ? 'dugme-koyu' : 'dugme-ikincil' ?>">IP (<?= $toplamIP ?>)</a>
    <a href="yazicilar.php?baglanti=USB" class="dugme dugme-kucuk <?= $filtreBaglanti === 'USB' ? 'dugme-koyu' : 'dugme-ikincil' ?>">USB (<?= $toplamUSB ?>)</a>
    <a href="yazicilar.php?durum=pasif" class="dugme dugme-kucuk <?= $filtreDurum === 'pasif' ? 'dugme-koyu' : 'dugme-ikincil' ?>">Pasif (<?= $toplamPasif ?>)</a>
    <form method="GET" action="yazicilar.php" class="satir-ici" style="gap:6px;">
        <input type="text" name="lokasyon" class="form-alan" style="width:180px;padding:5px 10px;font-size:0.82rem;" placeholder="Lokasyon ara..." value="<?= temizle($filtreLokasyon) ?>">
        <?php if ($filtreLokasyon): ?><a href="yazicilar.php" class="dugme dugme-kucuk dugme-ikincil">×</a><?php endif; ?>
        <button type="submit" class="dugme dugme-kucuk dugme-koyu">Ara</button>
    </form>
</div>

<!-- YAZICI LISTESI -->
<div class="kart">
    <div class="kart-baslik">
        <div class="esnek hizala-orta bosluk-3">
            <span>Yazıcılar</span>
            <span class="rozet renk-ikincil"><?= count($yazicilar) ?> Kayıt</span>
        </div>
    </div>
    <div class="kart-icerik ic-0">
        <?php if (count($yazicilar) > 0): ?>
            <table class="tablo">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Lokasyon</th>
                        <th>Marka / Model</th>
                        <th>Bağlantı</th>
                        <th>IP Adresi</th>
                        <th>Toner</th>
                        <th>Renk</th>
                        <th>Durum</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sira = 1; foreach ($yazicilar as $y): ?>
                        <tr style="<?= !$y['aktif'] ? 'opacity:0.5;' : '' ?>">
                            <td><?= $sira++ ?></td>
                            <td><strong><?= temizle($y['lokasyon']) ?></strong></td>
                            <td>
                                <?= temizle($y['marka']) ?>
                                <br><small class="metin-soluk"><?= temizle($y['model']) ?></small>
                            </td>
                            <td>
                                <?php if ($y['baglanti_tipi'] === 'IP'): ?>
                                    <span class="rozet renk-bilgi metin-koyu">IP</span>
                                <?php else: ?>
                                    <span class="rozet renk-ikincil">USB</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?= temizle($y['ip_adresi'] ?? '-') ?></small></td>
                            <td>
                                <?php if ($y['toner_model']): ?>
                                    <span class="rozet renk-ana"><?= temizle($y['toner_model']) ?></span>
                                <?php else: ?>
                                    <small class="metin-soluk">-</small>
                                <?php endif; ?>
                            </td>
                            <td><?= $y['renkli'] ? '<span class="rozet renk-basari">Renkli</span>' : '<span class="rozet renk-koyu">Mono</span>' ?></td>
                            <td><?= $y['aktif'] ? '<span class="rozet renk-basari">Aktif</span>' : '<span class="rozet renk-tehlike">Pasif</span>' ?></td>
                            <td>
                                <a href="yazicilar.php?duzenle=<?= $y['id'] ?>" class="dugme dugme-kucuk dugme-cizgi-ana">Düzenle</a>
                                <form method="POST" action="yazicilar.php" class="satir-ici" onsubmit="return confirm('Silmek istediğinize emin misiniz?')">
                                    <?= csrfToken() ?>
                                    <input type="hidden" name="islem" value="sil">
                                    <input type="hidden" name="id" value="<?= $y['id'] ?>">
                                    <button type="submit" class="dugme dugme-kucuk dugme-cizgi-tehlike">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="metin-orta metin-soluk ic-3">Kayıt bulunamadı</p>
        <?php endif; ?>
    </div>
</div>

<script>
function baglantiDegisti() {
    var tip = document.getElementById('baglantiTipi').value;
    document.getElementById('ipAlani').style.display = tip === 'IP' ? '' : 'none';
}
</script>

<?php require_once 'footer.php'; ?>
