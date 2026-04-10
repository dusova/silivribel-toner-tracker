<?php
/**
 * ============================================================
 * TONERLER.PHP - TONER YONETIMI
 * ============================================================
 */

require_once 'config.php';
require_once 'yetki.php';
yetkiKontrol(['super_admin', 'admin']);

// --- POST ILE GELEN FORM ISLEMLERI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(function_exists('csrfDogrula')) csrfDogrula();
    $islem = $_POST['islem'] ?? '';

    if ($islem === 'ekle' || $islem === 'duzenle') {
        $id             = (int) ($_POST['id'] ?? 0);
        $tonerKodu      = trim($_POST['toner_kodu'] ?? '');
        $tonerModel     = trim($_POST['toner_model'] ?? '');
        $marka          = trim($_POST['marka'] ?? '');
        $renk           = trim($_POST['renk'] ?? '');
        $uyumluModeller = trim($_POST['uyumlu_modeller'] ?? '');
        $kritikStok     = max(0, (int) $_POST['kritik_stok']);

        if (empty($tonerKodu) || empty($tonerModel)) {
            bildirim('Toner kodu ve model zorunludur!', 'danger');
        } else {
            if ($islem === 'ekle') {
                $stmt = $db->prepare("INSERT INTO tonerler (toner_kodu, toner_model, marka, renk, uyumlu_modeller, kritik_stok) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$tonerKodu, $tonerModel, $marka, $renk, $uyumluModeller, $kritikStok]);
                if(function_exists('logKaydet')) logKaydet('Toner', 'Ekleme', "Toner eklendi: $tonerKodu");
                bildirim('Toner başarıyla eklendi.');
            } else {
                $stmt = $db->prepare("UPDATE tonerler SET toner_kodu=?, toner_model=?, marka=?, renk=?, uyumlu_modeller=?, kritik_stok=? WHERE id=?");
                $stmt->execute([$tonerKodu, $tonerModel, $marka, $renk, $uyumluModeller, $kritikStok, $id]);
                if(function_exists('logKaydet')) logKaydet('Toner', 'Düzenleme', "Toner güncellendi: $tonerKodu");
                bildirim('Toner güncellendi.');
            }
        }
        yonlendir('tonerler.php');
    }

    if ($islem === 'sil') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare("SELECT COUNT(*) FROM hareketler WHERE toner_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            bildirim('Bu tonere ait hareket kaydı var, silinemez!', 'danger');
        } else {
            $stmt = $db->prepare("DELETE FROM tonerler WHERE id = ?");
            $stmt->execute([$id]);
            bildirim('Toner silindi.');
        }
        yonlendir('tonerler.php');
    }
}

$duzenlenecek = null;
if (isset($_GET['duzenle'])) {
    $stmt = $db->prepare("SELECT * FROM tonerler WHERE id = ?");
    $stmt->execute([(int) $_GET['duzenle']]);
    $duzenlenecek = $stmt->fetch();
}

$tonerler = function_exists('tumTonerleriGetir') ? tumTonerleriGetir($db) : $db->query("SELECT * FROM tonerler ORDER BY marka, toner_model")->fetchAll();

$toplamToner   = count($tonerler);
$kritikTonerler = array_filter($tonerler, fn($t) => $t['stok_miktari'] <= $t['kritik_stok'] && $t['stok_miktari'] > 0);
$tukenmisToner  = array_filter($tonerler, fn($t) => $t['stok_miktari'] <= 0);
$toplamStok     = array_sum(array_column($tonerler, 'stok_miktari'));

$sayfaBasligi = 'Toner Envanteri';
require_once 'header.php';
?>

<div class="sayfa-ust stagger-item">
    <div>
        <h2>Toner Envanteri</h2>
        <p class="metin-soluk ub-1">Toner kayıtlarını yönetin ve stok seviyelerini takip edin.</p>
    </div>
</div>

<div class="satir ab-3">
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-1">
        <div class="kart stat-kart aksan-mavi">
            <div class="stat-etiket"><i data-lucide="package"></i> Toner Çeşidi</div>
            <div class="stat-deger"><?= $toplamToner ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-2">
        <div class="kart stat-kart aksan-yesil">
            <div class="stat-etiket"><i data-lucide="layers"></i> Toplam Stok</div>
            <div class="stat-deger"><?= $toplamStok ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-3">
        <div class="kart stat-kart aksan-turuncu">
            <div class="stat-etiket"><i data-lucide="alert-triangle"></i> Kritik Stok</div>
            <div class="stat-deger"><?= count($kritikTonerler) ?></div>
        </div>
    </div>
    <div class="sutun-orta-3 sutun-6 ab-2 stagger-item stagger-delay-4">
        <div class="kart stat-kart <?= count($tukenmisToner) > 0 ? 'aksan-kirmizi' : 'aksan-mor' ?>">
            <div class="stat-etiket" <?= count($tukenmisToner) > 0 ? 'style="color:var(--tehlike);"' : '' ?>><i data-lucide="x-circle"></i> Tükenmiş</div>
            <div class="stat-deger" <?= count($tukenmisToner) > 0 ? 'style="color:var(--tehlike);"' : '' ?>><?= count($tukenmisToner) ?></div>
        </div>
    </div>
</div>

<!-- FORM KARTI -->
<div class="kart ab-4 stagger-item stagger-delay-1">
    <div class="kart-baslik">
        <span><?= $duzenlenecek ? 'Toner Düzenle' : 'Yeni Toner Ekle' ?></span>
    </div>
    <div class="kart-icerik">
        <form method="POST" action="tonerler.php">
            <?= function_exists('csrfToken') ? csrfToken() : '' ?>
            <input type="hidden" name="islem" value="<?= $duzenlenecek ? 'duzenle' : 'ekle' ?>">
            <?php if ($duzenlenecek): ?>
                <input type="hidden" name="id" value="<?= $duzenlenecek['id'] ?>">
            <?php endif; ?>

            <div class="satir">
                <div class="sutun-orta-3">
                    <div class="form-grup">
                        <label class="form-etiket">Toner Kodu</label>
                        <input type="text" class="form-alan" name="toner_kodu" required placeholder="TN-324 vb."
                               value="<?= $duzenlenecek ? htmlspecialchars($duzenlenecek['toner_kodu']) : '' ?>">
                    </div>
                </div>
                <div class="sutun-orta-3">
                    <div class="form-grup">
                        <label class="form-etiket">Model</label>
                        <input type="text" class="form-alan" name="toner_model" required placeholder="Bizhub Serisi"
                               value="<?= $duzenlenecek ? htmlspecialchars($duzenlenecek['toner_model']) : '' ?>">
                    </div>
                </div>
                <div class="sutun-orta-3">
                    <div class="form-grup">
                        <label class="form-etiket">Marka</label>
                        <input type="text" class="form-alan" name="marka" required placeholder="Konica Minolta"
                               value="<?= $duzenlenecek ? htmlspecialchars($duzenlenecek['marka']) : 'Konica Minolta' ?>">
                    </div>
                </div>
                <div class="sutun-orta-3">
                    <div class="form-grup">
                        <label class="form-etiket">Renk</label>
                        <select class="form-secim" name="renk">
                            <?php
                            $renkler = ['Siyah', 'Cyan', 'Magenta', 'Yellow'];
                            foreach ($renkler as $r):
                                $secili = ($duzenlenecek && $duzenlenecek['renk'] === $r) ? 'selected' : '';
                            ?>
                                <option value="<?= $r ?>" <?= $secili ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="sutun-orta-6">
                    <div class="form-grup" style="margin-bottom: 0;">
                        <label class="form-etiket">Uyumlu Cihazlar</label>
                        <input type="text" class="form-alan" name="uyumlu_modeller" placeholder="C258, C308, vb."
                               value="<?= $duzenlenecek ? htmlspecialchars($duzenlenecek['uyumlu_modeller']) : '' ?>">
                    </div>
                </div>
                <div class="sutun-orta-3">
                    <div class="form-grup" style="margin-bottom: 0;">
                        <label class="form-etiket">Kritik Stok Uyarı Sınırı</label>
                        <input type="number" class="form-alan" name="kritik_stok" min="0"
                               value="<?= $duzenlenecek ? $duzenlenecek['kritik_stok'] : '3' ?>">
                    </div>
                </div>
                <div class="sutun-orta-3 esnek hizala-orta" style="justify-content: flex-end; padding-top: 24px;">
                    <?php if ($duzenlenecek): ?>
                        <a href="tonerler.php" class="dugme dugme-ikincil" style="margin-right: 8px;">İptal</a>
                    <?php endif; ?>
                    <button type="submit" class="dugme dugme-ana">
                        <?= $duzenlenecek ? 'Kaydet' : 'Toner Ekle' ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- TABLO KARTI -->
<div class="kart stagger-item stagger-delay-2">
    <div class="kart-baslik">
        <div class="esnek hizala-orta bosluk-3">
            <span>Tüm Tonerler</span>
            <span class="rozet renk-ikincil"><?= count($tonerler) ?> Kayıt</span>
        </div>
        <div class="tablo-arama">
            <i data-lucide="search"></i>
            <input type="text" id="tabloArama" placeholder="Filtrele...">
        </div>
    </div>
    <div class="kart-icerik ic-0 tablo-kapsayici">
        <?php if (count($tonerler) > 0): ?>
            <table class="tablo" id="tonerTablosu">
                <thead>
                    <tr>
                        <th>Toner Bilgisi</th>
                        <th>Renk</th>
                        <th>Uyumlu Cihazlar</th>
                        <th>Stok / Kritik</th>
                        <th style="text-align: right;">Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tonerler as $t): ?>
                        <tr>
                            <td>
                                <div class="metin-koyu"><?= htmlspecialchars($t['toner_kodu']) ?></div>
                                <div class="metin-soluk" style="font-size: 0.75rem; margin-top: 2px;">
                                    <?= htmlspecialchars($t['marka']) ?> &middot; <?= htmlspecialchars($t['toner_model']) ?>
                                </div>
                            </td>
                            <td>
                                <?php 
                                $r = strtolower($t['renk']);
                                $rb = 'renk-ikincil';
                                if($r == 'siyah' || $r == 'black') $rb = 'renk-ikincil';
                                elseif($r == 'cyan') $rb = 'renk-bilgi';
                                elseif($r == 'magenta') $rb = 'renk-tehlike';
                                elseif($r == 'yellow') $rb = 'renk-uyari';
                                ?>
                                <span class="rozet <?= $rb ?>">
                                    <?php if($rb != 'renk-ikincil'): ?><span class="rozet-nokta"></span><?php endif; ?>
                                    <?= htmlspecialchars($t['renk']) ?>
                                </span>
                            </td>
                            <td><span class="metin-2"><?= htmlspecialchars($t['uyumlu_modeller']) ?></span></td>
                            <td>
                                <div class="esnek hizala-orta" style="gap: 8px;">
                                    <?php if ($t['stok_miktari'] <= 0): ?>
                                        <span class="rozet renk-tehlike" style="padding: 2px 6px;">Tükendi</span>
                                    <?php elseif ($t['stok_miktari'] <= $t['kritik_stok']): ?>
                                        <span class="rozet renk-uyari" style="padding: 2px 6px;"><?= $t['stok_miktari'] ?></span>
                                    <?php else: ?>
                                        <span class="rozet renk-basari" style="padding: 2px 6px;"><?= $t['stok_miktari'] ?></span>
                                    <?php endif; ?>
                                    <span class="metin-soluk" style="font-size: 0.75rem;">/ <?= $t['kritik_stok'] ?> min</span>
                                </div>
                            </td>
                            <td style="text-align: right;">
                                <div class="esnek hizala-orta" style="justify-content: flex-end; gap: 8px;">
                                    <a href="tonerler.php?duzenle=<?= $t['id'] ?>" class="dugme dugme-kucuk dugme-ikincil" title="Düzenle">
                                        Düzenle
                                    </a>
                                    <form method="POST" action="tonerler.php" class="satir-ici" onsubmit="return confirm('Silmek istediğinize emin misiniz?')">
                                        <?= function_exists('csrfToken') ? csrfToken() : '' ?>
                                        <input type="hidden" name="islem" value="sil">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="dugme dugme-kucuk dugme-cizgi-tehlike" title="Sil">
                                            Sil
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="metin-orta metin-soluk" style="padding: 60px 0;">
                <p>Kayıtlı toner bulunamadı.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
lucide.createIcons();

document.getElementById('tabloArama')?.addEventListener('keyup', function(e) {
    let deger = e.target.value.toLowerCase();
    let satirlar = document.querySelectorAll('#tonerTablosu tbody tr');
    satirlar.forEach(satir => {
        satir.style.display = satir.textContent.toLowerCase().includes(deger) ? '' : 'none';
    });
});
</script>

<?php require_once 'footer.php'; ?>
