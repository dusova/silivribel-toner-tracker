<?php
/**
 * ============================================================
 * BIRIMLER.PHP - BIRIM YONETIMI
 * ============================================================
 *
 * Belediye birimlerini ekleme, duzenleme, silme.
 * Yetki: super_admin, admin
 */

require_once 'config.php';
require_once 'yetki.php';
yetkiKontrol(['super_admin', 'admin']);

// --- FORM ISLEMLERI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();

    $islem = $_POST['islem'] ?? '';

    // Birim ekleme
    if ($islem === 'ekle') {
        $birimAdi    = trim($_POST['birim_adi'] ?? '');
        $sorumluKisi = trim($_POST['sorumlu_kisi'] ?? '');
        $telefon     = trim($_POST['telefon'] ?? '');

        if (empty($birimAdi)) {
            bildirim('Birim adı zorunludur!', 'danger');
        } else {
            $stmt = $db->prepare("INSERT INTO birimler (birim_adi, sorumlu_kisi, telefon) VALUES (?, ?, ?)");
            $stmt->execute([$birimAdi, $sorumluKisi, $telefon]);
            logKaydet('Birim', 'Ekleme', "Birim eklendi: $birimAdi (Sorumlu: $sorumluKisi)");
            bildirim('Birim eklendi.');
        }
        yonlendir('birimler.php');
    }

    // Birim duzenleme
    if ($islem === 'duzenle') {
        $id          = (int) $_POST['id'];
        $birimAdi    = trim($_POST['birim_adi'] ?? '');
        $sorumluKisi = trim($_POST['sorumlu_kisi'] ?? '');
        $telefon     = trim($_POST['telefon'] ?? '');

        if (empty($birimAdi)) {
            bildirim('Birim adı zorunludur!', 'danger');
        } else {
            $stmt = $db->prepare("UPDATE birimler SET birim_adi = ?, sorumlu_kisi = ?, telefon = ? WHERE id = ?");
            $stmt->execute([$birimAdi, $sorumluKisi, $telefon, $id]);
            logKaydet('Birim', 'Düzenleme', "Birim güncellendi [ID:$id]: $birimAdi");
            bildirim('Birim güncellendi.');
        }
        yonlendir('birimler.php');
    }

    // Birim silme
    if ($islem === 'sil') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $ad = $db->prepare("SELECT birim_adi FROM birimler WHERE id = ?");
            $ad->execute([$id]);
            $silinecekAd = $ad->fetchColumn();
            if (!$silinecekAd) {
                bildirim('Birim bulunamadi.', 'danger');
            } else {
                $stmt = $db->prepare("DELETE FROM birimler WHERE id = ?");
                $stmt->execute([$id]);
                if ($stmt->rowCount() > 0) {
                    logKaydet('Birim', 'Silme', "Birim silindi [ID:$id]: $silinecekAd");
                    bildirim('Birim silindi.');
                } else {
                    bildirim('Birim silinemedi.', 'danger');
                }
            }
        }
        yonlendir('birimler.php');
    }
}

// Duzenleme modu: URL'de ?duzenle=3 varsa o birim yuklenir
$duzenlenecek = null;
if (isset($_GET['duzenle'])) {
    $stmt = $db->prepare("SELECT * FROM birimler WHERE id = ?");
    $stmt->execute([(int) $_GET['duzenle']]);
    $duzenlenecek = $stmt->fetch();
}

// Tum birimleri cek
$birimler = $db->query("SELECT * FROM birimler ORDER BY birim_adi")->fetchAll();
$sorumlular = array_filter($birimler, fn($b) => !empty($b['sorumlu_kisi']));

$sayfaBasligi = 'Birimler';
require_once 'header.php';
?>

<div class="sayfa-ust stagger-item">
    <div>
        <h2>Birim Yönetimi</h2>
        <p class="metin-soluk ub-1">Belediye birimlerini, sorumlu kişileri ve iletişim bilgilerini yönetin.</p>
    </div>
</div>

<div class="satir ab-3">
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-1">
        <div class="kart stat-kart aksan-mavi">
            <div class="stat-etiket"><i data-lucide="building-2"></i> Toplam Birim</div>
            <div class="stat-deger"><?= count($birimler) ?></div>
        </div>
    </div>
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-2">
        <div class="kart stat-kart aksan-yesil">
            <div class="stat-etiket"><i data-lucide="user-check"></i> Sorumlulu</div>
            <div class="stat-deger"><?= count($sorumlular) ?></div>
        </div>
    </div>
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-3">
        <div class="kart stat-kart aksan-turuncu">
            <div class="stat-etiket"><i data-lucide="user-x"></i> Sorumlulsuz</div>
            <div class="stat-deger"><?= count($birimler) - count($sorumlular) ?></div>
        </div>
    </div>
</div>

<!-- ===== ekle düzenle form -->
<div class="kart ab-4 stagger-item stagger-delay-1">
    <div class="kart-baslik">
        <span><?= $duzenlenecek ? 'Birim Düzenle' : 'Yeni Birim Ekle' ?></span>
    </div>
    <div class="kart-icerik">
        <form method="POST" action="birimler.php">
            <?= csrfToken() ?>
            <input type="hidden" name="islem" value="<?= $duzenlenecek ? 'duzenle' : 'ekle' ?>">
            <?php if ($duzenlenecek): ?>
                <input type="hidden" name="id" value="<?= $duzenlenecek['id'] ?>">
            <?php endif; ?>

            <div class="satir">
                <!-- Birim adi -->
                <div class="sutun-orta-4 ab-2">
                    <label class="form-etiket">Birim Adı *</label>
                    <input type="text" class="form-alan" name="birim_adi" required
                           placeholder="Yazı İşleri Müdürlüğü"
                           value="<?= $duzenlenecek ? temizle($duzenlenecek['birim_adi']) : '' ?>">
                </div>

                <!-- Sorumlu kisi -->
                <div class="sutun-orta-3 ab-2">
                    <label class="form-etiket">Sorumlu Kişi</label>
                    <input type="text" class="form-alan" name="sorumlu_kisi"
                           placeholder="Ad Soyad"
                           value="<?= $duzenlenecek ? temizle($duzenlenecek['sorumlu_kisi']) : '' ?>">
                </div>

                <!-- Telefon -->
                <div class="sutun-orta-3 ab-2">
                    <label class="form-etiket">Telefon</label>
                    <input type="text" class="form-alan" name="telefon"
                           placeholder="0312 555 00 00"
                           value="<?= $duzenlenecek ? temizle($duzenlenecek['telefon']) : '' ?>">
                </div>

                <!-- Butonlar -->
                <div class="sutun-orta-2 ab-2">
                    <label class="form-etiket">&nbsp;</label>
                    <div>
                        <button type="submit" class="dugme dugme-koyu">
                            <?= $duzenlenecek ? 'Güncelle' : 'Ekle' ?>
                        </button>
                        <?php if ($duzenlenecek): ?>
                            <a href="birimler.php" class="dugme dugme-ikincil">İptal</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- birim listesi -->
<div class="kart stagger-item stagger-delay-2">
    <div class="kart-baslik">
        <div class="esnek hizala-orta bosluk-3">
            <span>Kayıtlı Birimler</span>
            <span class="rozet renk-ikincil"><?= count($birimler) ?> Birim</span>
        </div>
    </div>
    <div class="kart-icerik ic-0">
        <?php if (count($birimler) > 0): ?>
            <table class="tablo">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Birim Adı</th>
                        <th>Sorumlu Kişi</th>
                        <th>Telefon</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sira = 1; foreach ($birimler as $birim): ?>
                        <tr>
                            <td><?= $sira++ ?></td>
                            <td><?= temizle($birim['birim_adi']) ?></td>
                            <td><?= temizle($birim['sorumlu_kisi']) ?: '-' ?></td>
                            <td><?= temizle($birim['telefon']) ?: '-' ?></td>
                            <td>
                                <a href="birimler.php?duzenle=<?= $birim['id'] ?>"
                                   class="dugme dugme-kucuk dugme-cizgi-ana">Düzenle</a>
                                <form method="POST" action="birimler.php" class="satir-ici" onsubmit="return confirm('Silmek istediğinize emin misiniz?')">
                                    <?= csrfToken() ?>
                                    <input type="hidden" name="islem" value="sil">
                                    <input type="hidden" name="id" value="<?= $birim['id'] ?>">
                                    <button type="submit" class="dugme dugme-kucuk dugme-cizgi-tehlike">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="bos-durum"><i data-lucide="building-2"></i><p>Henüz birim kaydı yok.</p></div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
