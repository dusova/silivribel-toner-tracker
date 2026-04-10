<?php
/**
 * ============================================================
 * DEPO.PHP - DEPO TAKIP (GENEL ENVANTER)
 * ============================================================
 *
 * Bilgisayar, monitor, klavye vb. BT envanterini yonetir.
 * Urun ekleme/duzenleme/silme + stok giris/cikis islemleri.
 *
 * Yetki: super_admin, admin
 */

require_once 'config.php';
require_once 'yetki.php';
yetkiKontrol(['super_admin', 'admin']);

// --- TABLO YOKSA OLUSTUR (oturum basina bir kez) ---
if (empty($_SESSION['_depo_tabloHazir'])) {
    $db->exec("CREATE TABLE IF NOT EXISTS `depo_urunler` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `urun_adi`         VARCHAR(200) NOT NULL,
        `kategori`         VARCHAR(100) NOT NULL,
        `marka`            VARCHAR(100),
        `model`            VARCHAR(100),
        `aciklama`         TEXT,
        `stok_miktari`     INT DEFAULT 0,
        `kritik_stok`      INT DEFAULT 1,
        `olusturma_tarihi` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS `depo_hareketler` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `urun_id`          INT NOT NULL,
        `miktar`           INT NOT NULL,
        `hareket_tipi`     ENUM('giris','cikis') NOT NULL,
        `tarih`            DATE NOT NULL,
        `teslim_alan`      VARCHAR(200),
        `aciklama`         TEXT,
        `olusturma_tarihi` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`urun_id`) REFERENCES `depo_urunler`(`id`) ON DELETE RESTRICT,
        INDEX `idx_depo_tarih` (`tarih`),
        INDEX `idx_depo_tip` (`hareket_tipi`, `tarih`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");
    $_SESSION['_depo_tabloHazir'] = true;
}

// --- POST ISLEMLERI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();
    $islem = $_POST['islem'] ?? '';

    // Urun ekleme
    if ($islem === 'ekle') {
        $urunAdi   = trim($_POST['urun_adi'] ?? '');
        $kategori  = trim($_POST['kategori'] ?? '');
        $marka     = trim($_POST['marka'] ?? '');
        $model     = trim($_POST['model'] ?? '');
        $aciklama  = trim($_POST['aciklama'] ?? '');
        $kritikStok = max(0, (int) ($_POST['kritik_stok'] ?? 1));

        if (empty($urunAdi) || empty($kategori)) {
            bildirim('Ürün adı ve kategori zorunludur!', 'danger');
        } else {
            $stmt = $db->prepare("INSERT INTO depo_urunler (urun_adi, kategori, marka, model, aciklama, kritik_stok) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$urunAdi, $kategori, $marka, $model, $aciklama, $kritikStok]);
            logKaydet('Depo', 'Ürün Ekleme', "Ürün eklendi: $urunAdi (Kategori: $kategori, Marka: $marka, Model: $model)");
            bildirim('Ürün eklendi.');
        }
        yonlendir('depo.php');
    }

    // Urun duzenleme
    if ($islem === 'duzenle') {
        $id        = (int) $_POST['id'];
        $urunAdi   = trim($_POST['urun_adi'] ?? '');
        $kategori  = trim($_POST['kategori'] ?? '');
        $marka     = trim($_POST['marka'] ?? '');
        $model     = trim($_POST['model'] ?? '');
        $aciklama  = trim($_POST['aciklama'] ?? '');
        $kritikStok = max(0, (int) ($_POST['kritik_stok'] ?? 1));

        if (empty($urunAdi) || empty($kategori)) {
            bildirim('Ürün adı ve kategori zorunludur!', 'danger');
        } else {
            $stmt = $db->prepare("UPDATE depo_urunler SET urun_adi=?, kategori=?, marka=?, model=?, aciklama=?, kritik_stok=? WHERE id=?");
            $stmt->execute([$urunAdi, $kategori, $marka, $model, $aciklama, $kritikStok, $id]);
            logKaydet('Depo', 'Ürün Düzenleme', "Ürün güncellendi [ID:$id]: $urunAdi (Kategori: $kategori)");
            bildirim('Ürün güncellendi.');
        }
        yonlendir('depo.php');
    }

    // Urun silme
    if ($islem === 'sil') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare("SELECT COUNT(*) FROM depo_hareketler WHERE urun_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            bildirim('Bu ürüne ait hareket kaydı var, silinemez!', 'danger');
        } else {
            $ad = $db->prepare("SELECT urun_adi FROM depo_urunler WHERE id = ?");
            $ad->execute([$id]);
            $silinecekAd = $ad->fetchColumn();
            $stmt = $db->prepare("DELETE FROM depo_urunler WHERE id = ?");
            $stmt->execute([$id]);
            logKaydet('Depo', 'Ürün Silme', "Ürün silindi [ID:$id]: $silinecekAd");
            bildirim('Ürün silindi.');
        }
        yonlendir('depo.php');
    }

    // Stok giris
    if ($islem === 'stok_giris') {
        $urunId   = (int) $_POST['urun_id'];
        $miktar   = (int) $_POST['miktar'];
        $tarih    = trim($_POST['tarih'] ?? '');
        $aciklama = trim($_POST['aciklama'] ?? '');

        if ($urunId <= 0 || $miktar <= 0 || $miktar > MAKS_MIKTAR) {
            bildirim('Ürün seçin ve geçerli miktar girin!', 'danger');
        } elseif (!tarihDogrula($tarih)) {
            bildirim('Geçersiz tarih!', 'danger');
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO depo_hareketler (urun_id, miktar, hareket_tipi, tarih, aciklama) VALUES (?, ?, 'giris', ?, ?)")
                    ->execute([$urunId, $miktar, $tarih, $aciklama]);
                $db->prepare("UPDATE depo_urunler SET stok_miktari = stok_miktari + ? WHERE id = ?")->execute([$miktar, $urunId]);
                $db->commit();
                $uAdi = $db->prepare("SELECT urun_adi FROM depo_urunler WHERE id = ?");
                $uAdi->execute([$urunId]);
                $urunAdiLog = $uAdi->fetchColumn();
                logKaydet('Depo', 'Stok Giriş', "Depo stok girişi: $urunAdiLog x$miktar adet. Tarih: $tarih" . ($aciklama ? ". Açıklama: $aciklama" : ''));
                bildirim("Stok girişi kaydedildi ($miktar adet).");
            } catch (Exception $e) {
                $db->rollBack();
                bildirim('Stok girişi sırasında hata oluştu.', 'danger');
            }
        }
        yonlendir('depo.php');
    }

    // Stok cikis
    if ($islem === 'stok_cikis') {
        $urunId     = (int) $_POST['urun_id'];
        $miktar     = (int) $_POST['miktar'];
        $tarih      = trim($_POST['tarih'] ?? '');
        $teslimAlan = trim($_POST['teslim_alan'] ?? '');
        $aciklama   = trim($_POST['aciklama'] ?? '');

        if ($urunId <= 0 || $miktar <= 0 || $miktar > MAKS_MIKTAR) {
            bildirim('Ürün seçin ve geçerli miktar girin!', 'danger');
        } elseif (!tarihDogrula($tarih)) {
            bildirim('Geçersiz tarih!', 'danger');
        } else {
            $db->beginTransaction();
            try {
                $stokGuncelle = $db->prepare("UPDATE depo_urunler SET stok_miktari = stok_miktari - ? WHERE id = ? AND stok_miktari >= ?");
                $stokGuncelle->execute([$miktar, $urunId, $miktar]);

                if ($stokGuncelle->rowCount() === 0) {
                    $db->rollBack();
                    $mevcutStok = $db->prepare("SELECT stok_miktari FROM depo_urunler WHERE id = ?");
                    $mevcutStok->execute([$urunId]);
                    $stok = (int) $mevcutStok->fetchColumn();
                    bildirim("Yetersiz stok! Mevcut: $stok adet.", 'danger');
                } else {
                    $db->prepare("INSERT INTO depo_hareketler (urun_id, miktar, hareket_tipi, tarih, teslim_alan, aciklama) VALUES (?, ?, 'cikis', ?, ?, ?)")
                        ->execute([$urunId, $miktar, $tarih, $teslimAlan, $aciklama]);
                    $db->commit();
                    $uAdi = $db->prepare("SELECT urun_adi FROM depo_urunler WHERE id = ?");
                    $uAdi->execute([$urunId]);
                    $urunAdiLog = $uAdi->fetchColumn();
                    logKaydet('Depo', 'Stok Çıkış', "Depo stok çıkışı: $urunAdiLog x$miktar adet. Teslim alan: $teslimAlan. Tarih: $tarih" . ($aciklama ? ". Açıklama: $aciklama" : ''));
                    bildirim("Stok çıkışı kaydedildi ($miktar adet).");
                }
            } catch (Exception $e) {
                $db->rollBack();
                bildirim('Stok çıkışı sırasında hata oluştu.', 'danger');
            }
        }
        yonlendir('depo.php');
    }
}

// --- DUZENLEME MODU ---
$duzenlenecek = null;
if (isset($_GET['duzenle'])) {
    $stmt = $db->prepare("SELECT * FROM depo_urunler WHERE id = ?");
    $stmt->execute([(int) $_GET['duzenle']]);
    $duzenlenecek = $stmt->fetch();
}

// --- FILTRE ---
$filtreKategori = trim($_GET['kategori'] ?? '');

// --- VERILERI CEK ---
$kategoriler = $db->query("SELECT DISTINCT kategori FROM depo_urunler ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);

$urunSorgu = "SELECT * FROM depo_urunler";
$urunParams = [];
if ($filtreKategori) {
    $urunSorgu .= " WHERE kategori = ?";
    $urunParams[] = $filtreKategori;
}
$urunSorgu .= " ORDER BY kategori, urun_adi";
$stmt = $db->prepare($urunSorgu);
$stmt->execute($urunParams);
$urunler = $stmt->fetchAll();

$depoStats = $db->query("SELECT COUNT(*) as cesit, COALESCE(SUM(stok_miktari),0) as toplam, SUM(CASE WHEN stok_miktari <= kritik_stok THEN 1 ELSE 0 END) as kritik FROM depo_urunler")->fetch();

$sonHareketler = $db->query("
    SELECT h.*, u.urun_adi, u.kategori
    FROM depo_hareketler h
    JOIN depo_urunler u ON h.urun_id = u.id
    ORDER BY h.olusturma_tarihi DESC
    LIMIT 20
")->fetchAll();

$sayfaBasligi = 'Depo Takip';
require_once 'header.php';
?>

<div class="sayfa-ust stagger-item">
    <div>
        <h2>Depo Takip</h2>
        <p class="metin-soluk ub-1">BT envanteri — bilgisayar, monitör, klavye ve diğer donanım stoklarını yönetin.</p>
    </div>
</div>

<div class="satir ab-3">
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-1">
        <div class="kart stat-kart aksan-mavi">
            <div class="stat-etiket"><i data-lucide="laptop"></i> Ürün Çeşidi</div>
            <div class="stat-deger"><?= $depoStats['cesit'] ?></div>
        </div>
    </div>
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-2">
        <div class="kart stat-kart aksan-yesil">
            <div class="stat-etiket"><i data-lucide="package"></i> Toplam Stok</div>
            <div class="stat-deger"><?= $depoStats['toplam'] ?></div>
        </div>
    </div>
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-3">
        <div class="kart stat-kart <?= ($depoStats['kritik'] ?? 0) > 0 ? 'aksan-kirmizi' : 'aksan-turuncu' ?>">
            <div class="stat-etiket" <?= ($depoStats['kritik'] ?? 0) > 0 ? 'style="color:var(--tehlike);"' : '' ?>><i data-lucide="alert-triangle"></i> Kritik Stok</div>
            <div class="stat-deger" <?= ($depoStats['kritik'] ?? 0) > 0 ? 'style="color:var(--tehlike);"' : '' ?>><?= $depoStats['kritik'] ?? 0 ?></div>
        </div>
    </div>
</div>

<!-- SEKMELER -->
<div class="sekmeler stagger-item stagger-delay-4">
    <button class="sekme-dugme aktif" onclick="sekmeGoster('urunler',this)">Ürünler</button>
    <button class="sekme-dugme" onclick="sekmeGoster('stok',this)">Stok Giriş / Çıkış</button>
    <button class="sekme-dugme" onclick="sekmeGoster('hareketler',this)">Son Hareketler</button>
</div>

<!-- ==================== SEKME 1: URUNLER ==================== -->
<div class="sekme-icerik aktif" id="sekme-urunler">

    <!-- URUN EKLEME / DUZENLEME FORMU -->
    <div class="kart ab-4">
        <div class="kart-baslik">
            <span><?= $duzenlenecek ? 'Ürün Düzenle' : 'Yeni Ürün Ekle' ?></span>
        </div>
        <div class="kart-icerik">
            <form method="POST" action="depo.php">
                <?= csrfToken() ?>
                <input type="hidden" name="islem" value="<?= $duzenlenecek ? 'duzenle' : 'ekle' ?>">
                <?php if ($duzenlenecek): ?>
                    <input type="hidden" name="id" value="<?= $duzenlenecek['id'] ?>">
                <?php endif; ?>

                <div class="satir">
                    <div class="sutun-orta-2 ab-2">
                        <label class="form-etiket">Ürün Adı *</label>
                        <input type="text" class="form-alan" name="urun_adi" required placeholder="Lenovo ThinkPad L14"
                               value="<?= $duzenlenecek ? temizle($duzenlenecek['urun_adi']) : '' ?>">
                    </div>
                    <div class="sutun-orta-2 ab-2">
                        <label class="form-etiket">Kategori *</label>
                        <input type="text" class="form-alan" name="kategori" required placeholder="Bilgisayar" list="kategori-listesi"
                               value="<?= $duzenlenecek ? temizle($duzenlenecek['kategori']) : '' ?>">
                        <datalist id="kategori-listesi">
                            <option value="Bilgisayar">
                            <option value="Monitör">
                            <option value="Klavye">
                            <option value="Mouse">
                            <option value="Kablo">
                            <option value="Adaptör">
                            <option value="Switch">
                            <option value="Router">
                            <option value="Telefon">
                            <option value="Diğer">
                            <?php foreach ($kategoriler as $k): ?>
                                <option value="<?= temizle($k) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="sutun-orta-2 ab-2">
                        <label class="form-etiket">Marka</label>
                        <input type="text" class="form-alan" name="marka" placeholder="Lenovo"
                               value="<?= $duzenlenecek ? temizle($duzenlenecek['marka']) : '' ?>">
                    </div>
                    <div class="sutun-orta-2 ab-2">
                        <label class="form-etiket">Model</label>
                        <input type="text" class="form-alan" name="model" placeholder="ThinkPad L14 Gen3"
                               value="<?= $duzenlenecek ? temizle($duzenlenecek['model']) : '' ?>">
                    </div>
                    <div class="sutun-orta-2 ab-2">
                        <label class="form-etiket">Açıklama</label>
                        <input type="text" class="form-alan" name="aciklama" placeholder="i5, 16GB RAM, 512GB SSD"
                               value="<?= $duzenlenecek ? temizle($duzenlenecek['aciklama']) : '' ?>">
                    </div>
                    <div class="sutun-orta-1 ab-2">
                        <label class="form-etiket">Kritik</label>
                        <input type="number" class="form-alan" name="kritik_stok" min="0"
                               value="<?= $duzenlenecek ? $duzenlenecek['kritik_stok'] : '1' ?>">
                    </div>
                    <div class="sutun-orta-1 ab-2">
                        <label class="form-etiket">&nbsp;</label>
                        <div>
                            <button type="submit" class="dugme dugme-koyu"><?= $duzenlenecek ? 'Kaydet' : 'Ekle' ?></button>
                            <?php if ($duzenlenecek): ?>
                                <a href="depo.php" class="dugme dugme-ikincil dugme-kucuk ub-1">İptal</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- KATEGORI FILTRE -->
    <?php if (count($kategoriler) > 0): ?>
    <div class="ab-3">
        <a href="depo.php" class="dugme dugme-kucuk <?= !$filtreKategori ? 'dugme-koyu' : 'dugme-ikincil' ?>">Tümü</a>
        <?php foreach ($kategoriler as $k): ?>
            <a href="depo.php?kategori=<?= urlencode($k) ?>" class="dugme dugme-kucuk <?= $filtreKategori === $k ? 'dugme-koyu' : 'dugme-ikincil' ?>"><?= temizle($k) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- URUN LISTESI -->
    <div class="kart">
        <div class="kart-baslik">
            <div class="esnek hizala-orta bosluk-3">
                <span>Envanter</span>
                <span class="rozet renk-ikincil"><?= count($urunler) ?> Ürün</span>
            </div>
        </div>
        <div class="kart-icerik ic-0">
            <?php if (count($urunler) > 0): ?>
                <table class="tablo">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Ürün Adı</th>
                            <th>Kategori</th>
                            <th>Marka</th>
                            <th>Model</th>
                            <th>Açıklama</th>
                            <th>Stok</th>
                            <th>Kritik</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sira = 1;
                        $oncekiKat = '';
                        foreach ($urunler as $u):
                            $stokSinifi = stokSinifi($u['stok_miktari'], $u['kritik_stok']);
                        ?>
                            <?php if ($oncekiKat && $oncekiKat !== $u['kategori']): ?>
                                <tr><td colspan="9" style="padding:2px; background:var(--kenar);"></td></tr>
                            <?php endif; ?>
                            <tr>
                                <td><?= $sira++ ?></td>
                                <td><strong><?= temizle($u['urun_adi']) ?></strong></td>
                                <td><span class="rozet renk-bilgi metin-koyu"><?= temizle($u['kategori']) ?></span></td>
                                <td><small><?= temizle($u['marka']) ?></small></td>
                                <td><small><?= temizle($u['model']) ?></small></td>
                                <td><small><?= temizle($u['aciklama']) ?></small></td>
                                <td class="<?= $stokSinifi ?>"><?= $u['stok_miktari'] ?></td>
                                <td><?= $u['kritik_stok'] ?></td>
                                <td>
                                    <a href="depo.php?duzenle=<?= $u['id'] ?>" class="dugme dugme-kucuk dugme-cizgi-ana">Düzenle</a>
                                    <form method="POST" action="depo.php" class="satir-ici" onsubmit="return confirm('Silmek istediğinize emin misiniz?')">
                                        <?= csrfToken() ?>
                                        <input type="hidden" name="islem" value="sil">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="dugme dugme-kucuk dugme-cizgi-tehlike">Sil</button>
                                    </form>
                                </td>
                            </tr>
                        <?php $oncekiKat = $u['kategori']; endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="metin-orta metin-soluk ic-3">Henüz ürün kaydı yok</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==================== SEKME 2: STOK GIRIS/CIKIS ==================== -->
<div class="sekme-icerik" id="sekme-stok">
    <div class="satir">
        <!-- STOK GIRIS -->
        <div class="sutun-buyuk-6 ab-3">
            <div class="kart">
                <div class="kart-baslik" style="border-left:4px solid var(--basari);"><span>Stok Giriş</span></div>
                <div class="kart-icerik">
                    <form method="POST">
                        <?= csrfToken() ?>
                        <input type="hidden" name="islem" value="stok_giris">
                        <div class="ab-3">
                            <label class="form-etiket">Ürün *</label>
                            <select class="form-secim" name="urun_id" required>
                                <option value="">-- Ürün Seçin --</option>
                                <?php
                                $tum = $db->query("SELECT * FROM depo_urunler ORDER BY kategori, urun_adi")->fetchAll();
                                $onceki = '';
                                foreach ($tum as $u):
                                    if ($onceki && $onceki !== $u['kategori']): ?>
                                        <option disabled>── <?= temizle($u['kategori']) ?> ──</option>
                                    <?php endif; $onceki = $u['kategori']; ?>
                                    <option value="<?= $u['id'] ?>"><?= temizle($u['urun_adi']) ?> (<?= temizle($u['kategori']) ?>) - Stok: <?= $u['stok_miktari'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Miktar *</label>
                            <input type="number" class="form-alan" name="miktar" min="1" max="<?= MAKS_MIKTAR ?>" required placeholder="Kaç adet?">
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Tarih *</label>
                            <input type="date" class="form-alan" name="tarih" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Açıklama</label>
                            <textarea class="form-alan" name="aciklama" rows="2" placeholder="Fatura no, tedarikçi bilgisi vb."></textarea>
                        </div>
                        <button type="submit" class="dugme dugme-basari tam-gen">Stok Girişi Kaydet</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- STOK CIKIS -->
        <div class="sutun-buyuk-6 ab-3">
            <div class="kart">
                <div class="kart-baslik" style="border-left:4px solid var(--tehlike);"><span>Stok Çıkış</span></div>
                <div class="kart-icerik">
                    <form method="POST">
                        <?= csrfToken() ?>
                        <input type="hidden" name="islem" value="stok_cikis">
                        <div class="ab-3">
                            <label class="form-etiket">Ürün *</label>
                            <select class="form-secim" name="urun_id" required>
                                <option value="">-- Ürün Seçin --</option>
                                <?php
                                $onceki = '';
                                foreach ($tum as $u):
                                    if ($u['stok_miktari'] <= 0) continue;
                                    if ($onceki && $onceki !== $u['kategori']): ?>
                                        <option disabled>── <?= temizle($u['kategori']) ?> ──</option>
                                    <?php endif; $onceki = $u['kategori']; ?>
                                    <option value="<?= $u['id'] ?>"><?= temizle($u['urun_adi']) ?> (<?= temizle($u['kategori']) ?>) - Stok: <?= $u['stok_miktari'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Miktar *</label>
                            <input type="number" class="form-alan" name="miktar" min="1" max="<?= MAKS_MIKTAR ?>" required placeholder="Kaç adet?">
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Tarih *</label>
                            <input type="date" class="form-alan" name="tarih" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Teslim Alan</label>
                            <input type="text" class="form-alan" name="teslim_alan" placeholder="Kime verildi?">
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Açıklama</label>
                            <textarea class="form-alan" name="aciklama" rows="2" placeholder="Neden, nereye vb."></textarea>
                        </div>
                        <button type="submit" class="dugme dugme-tehlike tam-gen">Stok Çıkışı Kaydet</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== SEKME 3: SON HAREKETLER ==================== -->
<div class="sekme-icerik" id="sekme-hareketler">
    <div class="kart">
        <div class="kart-baslik"><span>Son Depo Hareketleri</span></div>
        <div class="kart-icerik ic-0">
            <?php if (count($sonHareketler) > 0): ?>
                <table class="tablo">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Ürün</th>
                            <th>Kategori</th>
                            <th>İşlem</th>
                            <th>Adet</th>
                            <th>Teslim Alan</th>
                            <th>Açıklama</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sonHareketler as $h): ?>
                            <tr>
                                <td><?= tarihFormatla($h['tarih']) ?></td>
                                <td><strong><?= temizle($h['urun_adi']) ?></strong></td>
                                <td><span class="rozet renk-bilgi metin-koyu"><?= temizle($h['kategori']) ?></span></td>
                                <td>
                                    <?php if ($h['hareket_tipi'] === 'giris'): ?>
                                        <span class="rozet renk-basari">Giriş</span>
                                    <?php else: ?>
                                        <span class="rozet renk-tehlike">Çıkış</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $h['miktar'] ?></td>
                                <td><small><?= temizle($h['teslim_alan']) ?: '-' ?></small></td>
                                <td><small><?= temizle($h['aciklama']) ?: '-' ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="metin-orta metin-soluk ic-3">Henüz hareket kaydı yok</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function sekmeGoster(id, btn) {
    document.querySelectorAll('.sekme-icerik').forEach(function(el){ el.classList.remove('aktif'); });
    document.querySelectorAll('.sekme-dugme').forEach(function(el){ el.classList.remove('aktif'); });
    document.getElementById('sekme-' + id).classList.add('aktif');
    btn.classList.add('aktif');
    if(window.lucide) lucide.createIcons();
}
</script>

<?php require_once 'footer.php'; ?>
