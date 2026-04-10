<?php
/**
 * ============================================================
 * GECICI_ZIMMET.PHP - GECICI ZIMMET / EMANET TAKIP
 * ============================================================
 */

require_once 'config.php';
require_once 'yetki.php';
yetkiKontrol(['super_admin', 'admin']);

// Otomatik tablo oluşturma (İlk çalıştırıldığında)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `gecici_zimmet` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `birim_adi`        VARCHAR(100) NOT NULL,
        `teslim_alan`      VARCHAR(100) NOT NULL,
        `teslim_eden`      VARCHAR(100) NOT NULL,
        `parca_turu`       VARCHAR(50) NOT NULL,
        `parca_detay`      VARCHAR(255) NOT NULL,
        `verilis_tarihi`   DATE NOT NULL,
        `iade_tarihi`      DATE NULL,
        `durum`            ENUM('Zimmette', 'İade Edildi') DEFAULT 'Zimmette',
        `aciklama`         TEXT,
        `olusturma_tarihi` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");
} catch (Exception $e) {
    // Hata durumunda ses çıkarma (yetki vs.)
}

// --- FORM GÖNDERİLDİYSE İŞLE (Ekle / İade Al) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();

    if (isset($_POST['islem_tipi']) && $_POST['islem_tipi'] === 'ekle') {
        $birim_adi    = trim($_POST['birim_adi'] ?? '');
        $teslim_alan  = trim($_POST['teslim_alan'] ?? '');
        $teslim_eden  = trim($_POST['teslim_eden'] ?? '');
        $parca_turu   = trim($_POST['parca_turu'] ?? '');
        $parca_detay  = trim($_POST['parca_detay'] ?? '');
        $verilis_tarihi = trim($_POST['verilis_tarihi'] ?? '');
        $aciklama     = trim($_POST['aciklama'] ?? '');

        if (!$birim_adi || !$teslim_alan || !$teslim_eden || !$parca_turu || !$verilis_tarihi) {
            bildirim('Lütfen zorunlu alanları doldurun.', 'danger');
        } elseif (!tarihDogrula($verilis_tarihi)) {
            bildirim('Geçersiz veriliş tarihi.', 'danger');
        } else {
            $stmt = $db->prepare("INSERT INTO gecici_zimmet (birim_adi, teslim_alan, teslim_eden, parca_turu, parca_detay, verilis_tarihi, aciklama) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$birim_adi, $teslim_alan, $teslim_eden, $parca_turu, $parca_detay, $verilis_tarihi, $aciklama]);
            
            logKaydet('Geçici Zimmet', 'Zimmet Verildi', "$birim_adi birimine $parca_turu ($parca_detay) zimmetlendi. Alan: $teslim_alan");
            bildirim('Emanet/Geçici zimmet başarıyla oluşturuldu.');
        }
        yonlendir('gecici_zimmet.php');
        exit;
    }

    if (isset($_POST['islem_tipi']) && $_POST['islem_tipi'] === 'iade_al') {
        $id = (int)$_POST['zimmet_id'];
        $iade_tarihi = date('Y-m-d');
        
        $s = $db->prepare("SELECT * FROM gecici_zimmet WHERE id = ?");
        $s->execute([$id]);
        $kayit = $s->fetch();
        
        if ($kayit) {
            $db->prepare("UPDATE gecici_zimmet SET durum = 'İade Edildi', iade_tarihi = ? WHERE id = ?")
               ->execute([$iade_tarihi, $id]);
               
            logKaydet('Geçici Zimmet', 'İade Alındı', "Zimmet ID: $id ($kayit[parca_turu] - $kayit[birim_adi]) geri alındı.");
            bildirim('Donanım başarıyla iade alındı olarak işaretlendi.');
        } else {
            bildirim('Kayıt bulunamadı.', 'danger');
        }
        yonlendir('gecici_zimmet.php');
        exit;
    }
}

// --- VERİ ÇEKİMİ ---
$zimmetler = $db->query("
    SELECT * FROM gecici_zimmet 
    ORDER BY CASE WHEN durum = 'Zimmette' THEN 0 ELSE 1 END, verilis_tarihi DESC, id DESC
")->fetchAll();

$aktifZimmet = array_filter($zimmetler, fn($z) => $z['durum'] === 'Zimmette');
$iadeEdilen  = array_filter($zimmetler, fn($z) => $z['durum'] === 'İade Edildi');

$sayfaBasligi = 'Geçici Zimmet/Emanet';
require_once 'header.php';
?>

<div class="sayfa-ust stagger-item">
    <div>
        <h2>Geçici Zimmet & Emanet Takibi</h2>
        <p class="metin-soluk ub-1">BT donanımlarının geçici zimmetini ve iade durumunu takip edin.</p>
    </div>
</div>

<div class="satir ab-3">
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-1">
        <div class="kart stat-kart aksan-turuncu">
            <div class="stat-etiket"><i data-lucide="arrow-left-right"></i> Aktif Zimmet</div>
            <div class="stat-deger"><?= count($aktifZimmet) ?></div>
            <div class="stat-alt">Şu an dışarıda</div>
        </div>
    </div>
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-2">
        <div class="kart stat-kart aksan-yesil">
            <div class="stat-etiket"><i data-lucide="check-circle"></i> İade Edildi</div>
            <div class="stat-deger"><?= count($iadeEdilen) ?></div>
        </div>
    </div>
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-3">
        <div class="kart stat-kart aksan-mavi">
            <div class="stat-etiket"><i data-lucide="list"></i> Toplam Kayıt</div>
            <div class="stat-deger"><?= count($zimmetler) ?></div>
        </div>
    </div>
</div>

<div class="satir">
    <!-- SOL: Ekleme Formu -->
    <div class="sutun-buyuk-4 ab-3 stagger-item stagger-delay-1">
        <div class="kart">
            <div class="kart-baslik">
                <span>Yeni Emanet Ver</span>
            </div>
            <div class="kart-icerik">
                <form method="POST" action="gecici_zimmet.php">
                    <?= csrfToken() ?>
                    <input type="hidden" name="islem_tipi" value="ekle">

                    <div class="ab-3">
                        <label class="form-etiket"><strong>Birim / Lokasyon *</strong></label>
                        <input type="text" class="form-alan" name="birim_adi" required placeholder="Örn: İnsan Kaynakları, Kat-1">
                    </div>

                    <div class="ab-3">
                        <label class="form-etiket"><strong>Teslim Alan Kişi *</strong></label>
                        <input type="text" class="form-alan" name="teslim_alan" required placeholder="Cihazı alan personel">
                    </div>

                    <div class="ab-3">
                        <label class="form-etiket"><strong>Teslim Eden Kişi *</strong></label>
                        <input type="text" class="form-alan" name="teslim_eden" required value="<?= temizle($oturumKullanici['ad_soyad'] ?? '') ?>">
                    </div>

                    <div class="ab-3">
                        <label class="form-etiket"><strong>Donanım Türü *</strong></label>
                        <select class="form-secim" name="parca_turu" required>
                            <option value="">-- Cihaz Çeşidi Seçin --</option>
                            <option value="Kasa/Bilgisayar">Masaüstü Bilgisayar (Kasa)</option>
                            <option value="Laptop/Dizüstü">Laptop / Dizüstü</option>
                            <option value="Monitör">Monitör</option>
                            <option value="Yazıcı/Tarayıcı">Yazıcı / Tarayıcı</option>
                            <option value="Klavye & Mouse">Klavye & Mouse Seti</option>
                            <option value="Network / Switch">Ağ Cihazı / Switch</option>
                            <option value="Diğer (Kablo, Aks.)">Diğer / Aksesuar / Yedek Parça</option>
                        </select>
                    </div>

                    <div class="ab-3">
                        <label class="form-etiket">Cihaz Detayı / Seri No</label>
                        <input type="text" class="form-alan" name="parca_detay" placeholder="Örn: HP 24f - SeriNo: 1234, Demirbaş: 44">
                    </div>

                    <div class="ab-3">
                        <label class="form-etiket"><strong>Veriliş Tarihi *</strong></label>
                        <input type="date" class="form-alan" name="verilis_tarihi" required value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="ab-3">
                        <label class="form-etiket">Açıklama / Not</label>
                        <textarea class="form-alan" name="aciklama" rows="2" placeholder="Gidiş sebebi: Bilgisayar onarımda vb."></textarea>
                    </div>

                    <button type="submit" class="dugme dugme-ana tam-gen">Emanet Kaydını Oluştur</button>
                </form>
            </div>
        </div>
    </div>

    <!-- SAĞ: Liste -->
    <div class="sutun-buyuk-8 ab-3 stagger-item stagger-delay-2">
        <div class="kart">
            <div class="kart-baslik">
                <span>Geçici Zimmet / Emanet Listesi</span>
            </div>
            <div class="kart-icerik ic-0" style="overflow-x:auto;">
                <table class="tablo">
                    <thead>
                        <tr>
                            <th>Durum</th>
                            <th>Birim & Personeller</th>
                            <th>Cihaz / Donanım</th>
                            <th>Tarihler</th>
                            <th class="metin-sag">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($zimmetler) > 0): ?>
                            <?php foreach ($zimmetler as $z): ?>
                            <tr>
                                <td>
                                    <?php if ($z['durum'] === 'Zimmette'): ?>
                                        <span class="rozet renk-uyari">Emanette</span>
                                    <?php else: ?>
                                        <span class="rozet renk-bilgi">İade Edildi</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= temizle($z['birim_adi']) ?></strong><br>
                                    <small class="metin-soluk">Alan: <?= temizle($z['teslim_alan']) ?></small><br>
                                    <small class="metin-soluk">Veren: <?= temizle($z['teslim_eden']) ?></small>
                                </td>
                                <td>
                                    <strong><?= temizle($z['parca_turu']) ?></strong><br>
                                    <span class="metin-soluk" style="font-size:13px;"><?= temizle($z['parca_detay']) ?></span>
                                    <?php if ($z['aciklama']): ?>
                                        <div style="font-size:12px; margin-top:4px;" class="metin-soluk"><em>Not: <?= temizle($z['aciklama']) ?></em></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size: 13px;">
                                        <strong>V:</strong> <?= tarihFormatla($z['verilis_tarihi']) ?><br>
                                        <?php if ($z['iade_tarihi']): ?>
                                            <strong>İ:</strong> <?= tarihFormatla($z['iade_tarihi']) ?>
                                        <?php else: ?>
                                            <span class="metin-soluk">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="metin-sag">
                                    <?php if ($z['durum'] === 'Zimmette'): ?>
                                    <form method="POST" action="gecici_zimmet.php" style="display:inline-block;" onsubmit="return confirm('Bu cihazın iade alındığını onaylıyor musunuz?');">
                                        <?= csrfToken() ?>
                                        <input type="hidden" name="islem_tipi" value="iade_al">
                                        <input type="hidden" name="zimmet_id" value="<?= $z['id'] ?>">
                                        <button type="submit" class="dugme dugme-kucuk dugme-ana" style="display:inline-flex; align-items:center; gap:4px;" title="İade Al">
                                            <i data-lucide="undo-2" style="width: 16px; height: 16px;"></i> İade Al
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="metin-orta metin-soluk ic-3">Henüz geçici zimmet veya emanet kaydı bulunmamaktadır.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
