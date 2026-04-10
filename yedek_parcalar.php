<?php
/**
 * ============================================================
 * YEDEK_PARCALAR.PHP - YEDEK PARCA YONETIMI
 * ============================================================
 *
 * Yedek parca ekleme, duzenleme, silme.
 * DRUM, DEVELOPER, TRANSFER_BELT, FUSER, DIGER turleri desteklenir.
 *
 * Yetki: super_admin, admin
 */

require_once 'config.php';
require_once 'yetki.php';
yetkiKontrol(['super_admin', 'admin']);

$izinliTipler = ['DRUM', 'DEVELOPER', 'TRANSFER_BELT', 'FUSER', 'DIGER'];

// --- POST ISLEMLERI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();
    $islem = $_POST['islem'] ?? '';

    if ($islem === 'ekle') {
        $parcaKodu      = trim($_POST['parca_kodu'] ?? '');
        $parcaTipi      = $_POST['parca_tipi'] ?? 'DIGER';
        $renk           = trim($_POST['renk'] ?? '-');
        $uyumluModeller = trim($_POST['uyumlu_modeller'] ?? '');
        $kritikStok     = max(0, (int) ($_POST['kritik_stok'] ?? 2));

        if (!in_array($parcaTipi, $izinliTipler)) $parcaTipi = 'DIGER';

        if (empty($parcaKodu)) {
            bildirim('Parca kodu zorunludur!', 'danger');
        } else {
            $stmt = $db->prepare("INSERT INTO yedek_parcalar (parca_kodu, parca_tipi, renk, uyumlu_modeller, kritik_stok) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$parcaKodu, $parcaTipi, $renk, $uyumluModeller, $kritikStok]);
            logKaydet('Yedek Parca', 'Ekleme', "Yedek parca eklendi: $parcaKodu ($parcaTipi, Renk: $renk, Uyumlu: $uyumluModeller)");
            bildirim('Yedek parca eklendi.');
        }
        yonlendir('yedek_parcalar.php');
    }

    if ($islem === 'duzenle') {
        $id             = (int) $_POST['id'];
        $parcaKodu      = trim($_POST['parca_kodu'] ?? '');
        $parcaTipi      = $_POST['parca_tipi'] ?? 'DIGER';
        $renk           = trim($_POST['renk'] ?? '-');
        $uyumluModeller = trim($_POST['uyumlu_modeller'] ?? '');
        $kritikStok     = max(0, (int) ($_POST['kritik_stok'] ?? 2));

        if (!in_array($parcaTipi, $izinliTipler)) $parcaTipi = 'DIGER';

        if (empty($parcaKodu)) {
            bildirim('Parca kodu zorunludur!', 'danger');
        } else {
            $stmt = $db->prepare("UPDATE yedek_parcalar SET parca_kodu=?, parca_tipi=?, renk=?, uyumlu_modeller=?, kritik_stok=? WHERE id=?");
            $stmt->execute([$parcaKodu, $parcaTipi, $renk, $uyumluModeller, $kritikStok, $id]);
            logKaydet('Yedek Parca', 'Duzenleme', "Yedek parca guncellendi [ID:$id]: $parcaKodu ($parcaTipi, Renk: $renk)");
            bildirim('Yedek parca guncellendi.');
        }
        yonlendir('yedek_parcalar.php');
    }

    if ($islem === 'sil') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare("SELECT COUNT(*) FROM hareketler WHERE yedek_parca_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            bildirim('Bu parcaya ait hareket kaydi var, silinemez!', 'danger');
        } else {
            $ad = $db->prepare("SELECT parca_kodu FROM yedek_parcalar WHERE id = ?");
            $ad->execute([$id]);
            $silinecekAd = $ad->fetchColumn();
            if (!$silinecekAd) {
                bildirim('Parca bulunamadi.', 'danger');
            } else {
                $stmt = $db->prepare("DELETE FROM yedek_parcalar WHERE id = ?");
                $stmt->execute([$id]);
                logKaydet('Yedek Parca', 'Silme', "Yedek parca silindi [ID:$id]: $silinecekAd");
                bildirim('Yedek parca silindi.');
            }
        }
        yonlendir('yedek_parcalar.php');
    }
}

// --- DUZENLEME MODU ---
$duzenlenecek = null;
if (isset($_GET['duzenle'])) {
    $stmt = $db->prepare("SELECT * FROM yedek_parcalar WHERE id = ?");
    $stmt->execute([(int) $_GET['duzenle']]);
    $duzenlenecek = $stmt->fetch();
}

// --- TUM PARCALARI CEK ---
$parcalar = $db->query("SELECT * FROM yedek_parcalar ORDER BY parca_tipi, parca_kodu")->fetchAll();

$toplamParca   = count($parcalar);
$kritikParcalar = array_filter($parcalar, fn($p) => $p['stok_miktari'] <= $p['kritik_stok'] && $p['stok_miktari'] > 0);
$tukenmisParca  = array_filter($parcalar, fn($p) => $p['stok_miktari'] <= 0);
$toplamParcaStok = array_sum(array_column($parcalar, 'stok_miktari'));

$sayfaBasligi = 'Yedek Parçalar';
require_once 'header.php';
?>

<div class="sayfa-ust stagger-item">
    <div>
        <h2>Yedek Parça Envanteri</h2>
        <p class="metin-soluk ub-1">Drum, Developer, Fuser ve diğer yedek parça stoklarını yönetin.</p>
    </div>
</div>

<div class="satir ab-3">
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-1">
        <div class="kart stat-kart aksan-mavi">
            <div class="stat-etiket"><i data-lucide="settings-2"></i> Parça Çeşidi</div>
            <div class="stat-deger"><?= $toplamParca ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-2">
        <div class="kart stat-kart aksan-yesil">
            <div class="stat-etiket"><i data-lucide="layers"></i> Toplam Stok</div>
            <div class="stat-deger"><?= $toplamParcaStok ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-3">
        <div class="kart stat-kart aksan-turuncu">
            <div class="stat-etiket"><i data-lucide="alert-triangle"></i> Kritik Stok</div>
            <div class="stat-deger"><?= count($kritikParcalar) ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-4">
        <div class="kart stat-kart <?= count($tukenmisParca) > 0 ? 'aksan-kirmizi' : 'aksan-mor' ?>">
            <div class="stat-etiket" <?= count($tukenmisParca) > 0 ? 'style="color:var(--tehlike);"' : '' ?>><i data-lucide="x-circle"></i> Tükenmiş</div>
            <div class="stat-deger" <?= count($tukenmisParca) > 0 ? 'style="color:var(--tehlike);"' : '' ?>><?= count($tukenmisParca) ?></div>
        </div>
    </div>
</div>

<!-- EKLEME / DUZENLEME FORMU -->
<div class="kart ab-4 stagger-item stagger-delay-1">
    <div class="kart-baslik">
        <span><?= $duzenlenecek ? 'Yedek Parça Düzenle' : 'Yeni Yedek Parça Ekle' ?></span>
    </div>
    <div class="kart-icerik">
        <form method="POST" action="yedek_parcalar.php">
            <?= csrfToken() ?>
            <input type="hidden" name="islem" value="<?= $duzenlenecek ? 'duzenle' : 'ekle' ?>">
            <?php if ($duzenlenecek): ?>
                <input type="hidden" name="id" value="<?= $duzenlenecek['id'] ?>">
            <?php endif; ?>

            <div class="satir">
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Parca Kodu *</label>
                    <input type="text" class="form-alan" name="parca_kodu" required placeholder="IUP-22K"
                           value="<?= $duzenlenecek ? temizle($duzenlenecek['parca_kodu']) : '' ?>">
                </div>
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Tip *</label>
                    <select class="form-secim" name="parca_tipi">
                        <?php
                        $tipEtiketleri = ['DRUM'=>'Drum', 'DEVELOPER'=>'Developer', 'TRANSFER_BELT'=>'Transfer Belt', 'FUSER'=>'Fuser', 'DIGER'=>'Diger'];
                        foreach ($tipEtiketleri as $tipVal => $tipAd):
                            $secili = ($duzenlenecek && $duzenlenecek['parca_tipi'] === $tipVal) ? 'selected' : '';
                        ?>
                            <option value="<?= $tipVal ?>" <?= $secili ?>><?= $tipAd ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">Renk</label>
                    <select class="form-secim" name="renk">
                        <?php
                        $renkler = ['-', 'Black', 'Cyan', 'Magenta', 'Yellow', 'Siyah', 'Renkli'];
                        foreach ($renkler as $r):
                            $secili = ($duzenlenecek && $duzenlenecek['renk'] === $r) ? 'selected' : '';
                        ?>
                            <option value="<?= $r ?>" <?= $secili ?>><?= $r ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sutun-orta-3 ab-2">
                    <label class="form-etiket">Uyumlu Modeller</label>
                    <input type="text" class="form-alan" name="uyumlu_modeller" placeholder="C258, C368, C458"
                           value="<?= $duzenlenecek ? temizle($duzenlenecek['uyumlu_modeller']) : '' ?>">
                </div>
                <div class="sutun-orta-1 ab-2">
                    <label class="form-etiket">Kritik</label>
                    <input type="number" class="form-alan" name="kritik_stok" min="0"
                           value="<?= $duzenlenecek ? $duzenlenecek['kritik_stok'] : '2' ?>">
                </div>
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">&nbsp;</label>
                    <div>
                        <button type="submit" class="dugme dugme-koyu"><?= $duzenlenecek ? 'Kaydet' : 'Ekle' ?></button>
                        <?php if ($duzenlenecek): ?>
                            <a href="yedek_parcalar.php" class="dugme dugme-ikincil dugme-kucuk ub-1">Iptal</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- YEDEK PARCA LISTESI -->
<div class="kart stagger-item stagger-delay-2">
    <div class="kart-baslik">
        <div class="esnek hizala-orta bosluk-3">
            <span>Kayıtlı Yedek Parçalar</span>
            <span class="rozet renk-ikincil"><?= count($parcalar) ?> Kayıt</span>
        </div>
    </div>
    <div class="kart-icerik ic-0">
        <?php if (count($parcalar) > 0): ?>
            <table class="tablo">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Parca Kodu</th>
                        <th>Tip</th>
                        <th>Renk</th>
                        <th>Uyumlu Modeller</th>
                        <th>Stok</th>
                        <th>Kritik</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sira = 1;
                    $oncekiTip = '';
                    foreach ($parcalar as $p):
                        $stokSinifi = stokSinifi($p['stok_miktari'], $p['kritik_stok']);
                    ?>
                        <?php if ($oncekiTip && $oncekiTip !== $p['parca_tipi']): ?>
                            <tr><td colspan="8" style="padding:2px; background:var(--kenar);"></td></tr>
                        <?php endif; ?>
                        <tr>
                            <td><?= $sira++ ?></td>
                            <td><strong><?= temizle($p['parca_kodu']) ?></strong></td>
                            <td><span class="rozet renk-ikincil"><?= temizle($p['parca_tipi']) ?></span></td>
                            <td><?= temizle($p['renk']) ?></td>
                            <td><small><?= temizle($p['uyumlu_modeller']) ?></small></td>
                            <td class="<?= $stokSinifi ?>"><?= $p['stok_miktari'] ?></td>
                            <td><?= $p['kritik_stok'] ?></td>
                            <td>
                                <a href="yedek_parcalar.php?duzenle=<?= $p['id'] ?>" class="dugme dugme-kucuk dugme-cizgi-ana">Duzenle</a>
                                <form method="POST" action="yedek_parcalar.php" class="satir-ici" onsubmit="return confirm('Silmek istediginize emin misiniz?')">
                                    <?= csrfToken() ?>
                                    <input type="hidden" name="islem" value="sil">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="dugme dugme-kucuk dugme-cizgi-tehlike">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php $oncekiTip = $p['parca_tipi']; endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="bos-durum">
                <i data-lucide="settings-2"></i>
                <p>Henüz yedek parça kaydı yok.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
