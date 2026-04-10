<?php
/**
 * ============================================================
 * KULLANICILAR.PHP - KULLANICI YONETIMI
 * ============================================================
 *
 * Super Admin / Admin: Tum kullanici listesi, ekleme, duzenleme, silme.
 * Normal Kullanici   : Sadece kendi sifresi degistirilebilir.
 */

require_once 'config.php';
require_once 'yetki.php';
yetkiKontrol(['super_admin', 'admin', 'kullanici']);

// Oturumdaki kullanicinin rolu
$oturumRol = $oturumKullanici['rol'] ?? 'kullanici';
$isYonetici = ($oturumRol === 'super_admin'); // Sadece super_admin kullanici yonetir

// Guvenlik: Sadece bu roller kabul edilir
$izinliRoller = ['super_admin', 'admin', 'kullanici'];

// --- POST ILE GELEN FORM ISLEMLERI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();
    $islem = $_POST['islem'] ?? '';

    // ---- KENDI SIFRESINI DEGISTIR (Tum roller) ----
    if ($islem === 'sifre_degistir') {
        $mevcutSifre = $_POST['mevcut_sifre'] ?? '';
        $yeniSifre   = $_POST['yeni_sifre']   ?? '';
        $tekrarSifre = $_POST['tekrar_sifre'] ?? '';

        // Mevcut sifreyi dogrula
        $stmt = $db->prepare("SELECT sifre_hash FROM kullanicilar WHERE id = ?");
        $stmt->execute([$oturumKullanici['id']]);
        $kayit = $stmt->fetch();

        if (!$kayit || !password_verify($mevcutSifre, $kayit['sifre_hash'])) {
            bildirim('Mevcut sifreniz hatalı.', 'danger');
        } elseif ($yeniSifre !== $tekrarSifre) {
            bildirim('Yeni sifre ve tekrarı eşleşmiyor.', 'danger');
        } elseif (!sifreKuralKontrol($yeniSifre)) {
            bildirim(SIFRE_KURAL_MESAJI, 'danger');
        } elseif ($mevcutSifre === $yeniSifre) {
            bildirim('Yeni sifre eskisiyle aynı olamaz.', 'danger');
        } else {
            $stmt = $db->prepare("UPDATE kullanicilar SET sifre_hash = ? WHERE id = ?");
            $stmt->execute([password_hash($yeniSifre, PASSWORD_DEFAULT), $oturumKullanici['id']]);
            logKaydet('Kullanıcı', 'Şifre Değişikliği', 'Kendi şifresini değiştirdi: ' . ($oturumKullanici['kullanici_adi'] ?? ''));
            bildirim('Şifreniz başarıyla güncellendi.');
        }
        yonlendir('kullanicilar.php');
        exit;
    }

    // Kullanici ekleme (sadece yonetici)
    if ($isYonetici && $islem === 'ekle') {
        $kullaniciAdi = trim($_POST['kullanici_adi'] ?? '');
        $adSoyad      = trim($_POST['ad_soyad'] ?? '');
        $sifre        = $_POST['sifre'] ?? '';
        $rol          = $_POST['rol'] ?? 'kullanici';

        // Rol whitelist kontrolu
        if (!in_array($rol, $izinliRoller)) {
            $rol = 'kullanici';
        }

        if (empty($kullaniciAdi) || empty($adSoyad) || empty($sifre)) {
            bildirim('Tum alanlari doldurun.', 'danger');
        } elseif (!sifreKuralKontrol($sifre)) {
            bildirim(SIFRE_KURAL_MESAJI, 'danger');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO kullanicilar (kullanici_adi, sifre_hash, ad_soyad, rol) VALUES (?, ?, ?, ?)");
                $stmt->execute([$kullaniciAdi, password_hash($sifre, PASSWORD_DEFAULT), $adSoyad, $rol]);
                logKaydet('Kullanıcı', 'Ekleme', "Kullanıcı eklendi: $kullaniciAdi ($adSoyad, Rol: $rol)");
                bildirim('Kullanici basariyla eklendi.');
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    bildirim('Bu kullanici adi zaten mevcut.', 'danger');
                } else {
                    bildirim('Kullanici eklenirken bir hata olustu.', 'danger');
                }
            }
        }
        yonlendir('kullanicilar.php');
    }

    // Kullanici duzenleme (sadece yonetici)
    if ($isYonetici && $islem === 'duzenle') {
        $id           = (int)$_POST['id'];
        $kullaniciAdi = trim($_POST['kullanici_adi'] ?? '');
        $adSoyad      = trim($_POST['ad_soyad'] ?? '');
        $rol          = $_POST['rol'] ?? 'kullanici';
        $aktif        = isset($_POST['aktif']) ? 1 : 0;
        $yeniSifre    = $_POST['yeni_sifre'] ?? '';

        // Rol whitelist kontrolu
        if (!in_array($rol, $izinliRoller)) {
            $rol = 'kullanici';
        }

        if (empty($kullaniciAdi) || empty($adSoyad)) {
            bildirim('Kullanici adi ve ad soyad zorunludur.', 'danger');
        } else {
            try {
                $sifreDegisti = false;
                if (!empty($yeniSifre)) {
                    if (!sifreKuralKontrol($yeniSifre)) {
                        bildirim(SIFRE_KURAL_MESAJI, 'danger');
                        yonlendir('kullanicilar.php');
                        exit;
                    }
                    $stmt = $db->prepare("UPDATE kullanicilar SET kullanici_adi = ?, ad_soyad = ?, rol = ?, aktif = ?, sifre_hash = ? WHERE id = ?");
                    $stmt->execute([$kullaniciAdi, $adSoyad, $rol, $aktif, password_hash($yeniSifre, PASSWORD_DEFAULT), $id]);
                    $sifreDegisti = true;
                } else {
                    $stmt = $db->prepare("UPDATE kullanicilar SET kullanici_adi = ?, ad_soyad = ?, rol = ?, aktif = ? WHERE id = ?");
                    $stmt->execute([$kullaniciAdi, $adSoyad, $rol, $aktif, $id]);
                }
                $detay = "Kullanıcı güncellendi [ID:$id]: $kullaniciAdi ($adSoyad, Rol: $rol, Aktif: $aktif)";
                if ($sifreDegisti) $detay .= ' [Şifre değiştirildi]';
                logKaydet('Kullanıcı', 'Düzenleme', $detay);
                bildirim('Kullanici guncellendi.');
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    bildirim('Bu kullanici adi baskasi tarafindan kullaniliyor.', 'danger');
                } else {
                    bildirim('Kullanici guncellenirken bir hata olustu.', 'danger');
                }
            }
        }
        yonlendir('kullanicilar.php');
    }

    // Kullanici silme (sadece yonetici)
    if ($isYonetici && $islem === 'sil') {
        $silId = (int)$_POST['id'];
        if ($silId === $oturumKullanici['id']) {
            bildirim('Kendi hesabinizi silemezsiniz.', 'danger');
        } else {
            $ad = $db->prepare("SELECT kullanici_adi, ad_soyad FROM kullanicilar WHERE id = ?");
            $ad->execute([$silId]);
            $silinecek = $ad->fetch();
            $stmt = $db->prepare("DELETE FROM kullanicilar WHERE id = ?");
            $stmt->execute([$silId]);
            logKaydet('Kullanıcı', 'Silme', "Kullanıcı silindi [ID:$silId]: " . ($silinecek['kullanici_adi'] ?? '') . " (" . ($silinecek['ad_soyad'] ?? '') . ")");
            bildirim('Kullanici silindi.');
        }
        yonlendir('kullanicilar.php');
    }
}

// Duzenleme modu: URL'de ?duzenle=2 varsa o kullanici yuklenir (sadece yonetici)
$duzenle = null;
if ($isYonetici && isset($_GET['duzenle'])) {
    $stmt = $db->prepare("SELECT * FROM kullanicilar WHERE id = ?");
    $stmt->execute([(int)$_GET['duzenle']]);
    $duzenle = $stmt->fetch();
}

// Tum kullanicilari cek (sadece yonetici icin)
$kullanicilar = [];
if ($isYonetici) {
    $kullanicilar = $db->query("SELECT id, kullanici_adi, ad_soyad, rol, aktif, olusturma_tarihi FROM kullanicilar ORDER BY olusturma_tarihi DESC")->fetchAll();
}

$sayfaBasligi = $isYonetici ? 'Kullanıcı Yönetimi' : 'Şifre Değiştir';
require_once 'header.php';
?>

<?php if ($isYonetici): ?>
<div class="sayfa-ust stagger-item">
    <div>
        <h2>Kullanıcı Yönetimi</h2>
        <p class="metin-soluk ub-1">Sistem kullanıcılarını, rollerini ve erişim yetkilerini yönetin.</p>
    </div>
    <button class="dugme dugme-mavi" onclick="document.getElementById('ekleModal').classList.add('aktif')">
        <i data-lucide="user-plus"></i> Yeni Kullanıcı
    </button>
</div>

<!-- Kullanici Listesi -->
<div class="kart stagger-item stagger-delay-1">
    <div class="kart-baslik">
        <div class="esnek hizala-orta bosluk-3">
            <span>Kayıtlı Kullanıcılar</span>
            <span class="rozet renk-ikincil"><?= count($kullanicilar) ?> Kişi</span>
        </div>
    </div>
    <div class="kart-icerik ic-0">
        <table class="tablo">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Ad Soyad</th>
                    <th>Kullanıcı Adı</th>
                    <th>Rol</th>
                    <th>Durum</th>
                    <th>Kayıt Tarihi</th>
                    <th>İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($kullanicilar as $k): ?>
                    <tr>
                        <td><?= $k['id'] ?></td>
                        <td><strong><?= temizle($k['ad_soyad']) ?></strong></td>
                        <td><?= temizle($k['kullanici_adi']) ?></td>
                        <td>
                            <?php if ($k['rol'] === 'super_admin'): ?>
                                <span class="rozet renk-tehlike">Super Admin</span>
                            <?php elseif ($k['rol'] === 'admin'): ?>
                                <span class="rozet renk-ana">Admin</span>
                            <?php else: ?>
                                <span class="rozet renk-ikincil">Kullanici</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($k['aktif']): ?>
                                <span class="rozet renk-basari">Aktif</span>
                            <?php else: ?>
                                <span class="rozet renk-uyari metin-koyu">Pasif</span>
                            <?php endif; ?>
                        </td>
                        <td><?= tarihFormatla($k['olusturma_tarihi']) ?></td>
                        <td>
                            <a href="?duzenle=<?= $k['id'] ?>" class="dugme dugme-cizgi-ana dugme-kucuk"><i data-lucide="pencil"></i> Düzenle</a>
                            <?php if ($k['id'] !== $oturumKullanici['id']): ?>
                                <form method="POST" class="satir-ici" onsubmit="return confirm('Bu kullanıcıyı silmek istediğinize emin misiniz?')">
                                    <?= csrfToken() ?>
                                    <input type="hidden" name="islem" value="sil">
                                    <input type="hidden" name="id" value="<?= $k['id'] ?>">
                                    <button type="submit" class="dugme dugme-cizgi-tehlike dugme-kucuk"><i data-lucide="trash-2"></i> Sil</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Yonetici icin kendi sifresini de degistirme kutusu -->
<div class="kart ub-4 stagger-item stagger-delay-2">
    <div class="kart-baslik">
        <span>Kendi Şifremi Değiştir</span>
    </div>
    <div class="kart-icerik">
        <form method="POST" style="max-width:480px;">
            <?= csrfToken() ?>
            <input type="hidden" name="islem" value="sifre_degistir">
            <div class="ab-3">
                <label class="form-etiket">Mevcut Şifre</label>
                <input type="password" class="form-alan" name="mevcut_sifre" required autocomplete="current-password" placeholder="Şu anki şifrenizi girin">
            </div>
            <div class="ab-3">
                <label class="form-etiket">Yeni Şifre <small class="metin-soluk">(en az 8 karakter, büyük/küçük harf + rakam)</small></label>
                <input type="password" class="form-alan" name="yeni_sifre" minlength="8" required autocomplete="new-password" placeholder="Yeni şifrenizi girin">
            </div>
            <div class="ab-3">
                <label class="form-etiket">Yeni Şifre (Tekrar)</label>
                <input type="password" class="form-alan" name="tekrar_sifre" minlength="8" required autocomplete="new-password" placeholder="Yeni şifreyi tekrar girin">
            </div>
            <button type="submit" class="dugme dugme-ana">Şifremi Güncelle</button>
        </form>
    </div>
</div>

<!-- Duzenleme Formu -->
<?php if ($duzenle): ?>
<div class="kart ub-4 stagger-item stagger-delay-3">
    <div class="kart-baslik">
        <span>Kullanıcı Düzenle: <?= temizle($duzenle['ad_soyad']) ?></span>
    </div>
    <div class="kart-icerik">
        <form method="POST">
            <?= csrfToken() ?>
            <input type="hidden" name="islem" value="duzenle">
            <input type="hidden" name="id" value="<?= $duzenle['id'] ?>">
            <div class="satir">
                <div class="sutun-orta-6 ab-3">
                    <label class="form-etiket">Ad Soyad</label>
                    <input type="text" class="form-alan" name="ad_soyad" value="<?= temizle($duzenle['ad_soyad']) ?>" required>
                </div>
                <div class="sutun-orta-6 ab-3">
                    <label class="form-etiket">Kullanıcı Adı</label>
                    <input type="text" class="form-alan" name="kullanici_adi" value="<?= temizle($duzenle['kullanici_adi']) ?>" required>
                </div>
                <div class="sutun-orta-4 ab-3">
                    <label class="form-etiket">Rol</label>
                    <select class="form-secim" name="rol">
                        <option value="super_admin" <?= $duzenle['rol'] === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                        <option value="admin" <?= $duzenle['rol'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="kullanici" <?= $duzenle['rol'] === 'kullanici' ? 'selected' : '' ?>>Kullanıcı</option>
                    </select>
                </div>
                <div class="sutun-orta-4 ab-3">
                    <label class="form-etiket">Yeni Şifre <small class="metin-soluk">(boş bırakırsan değişmez)</small></label>
                    <input type="password" class="form-alan" name="yeni_sifre" minlength="8" placeholder="Değiştirmek için yaz">
                </div>
                <div class="sutun-orta-4 ab-3 esnek hizala-son">
                    <div class="form-onay">
                        <input class="form-onay-kutu" type="checkbox" name="aktif" id="aktif" <?= $duzenle['aktif'] ? 'checked' : '' ?>>
                        <label class="form-onay-yazi" for="aktif">Aktif</label>
                    </div>
                </div>
            </div>
            <button type="submit" class="dugme dugme-ana">Kaydet</button>
            <a href="kullanicilar.php" class="dugme dugme-cizgi-ikincil">İptal</a>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Ekleme Modal -->
<div class="modal-kaplama" id="ekleModal">
    <div class="modal-kutu">
        <form method="POST">
            <?= csrfToken() ?>
            <input type="hidden" name="islem" value="ekle">
            <div class="modal-baslik">
                Yeni Kullanıcı Ekle
                <button type="button" class="modal-kapat" onclick="document.getElementById('ekleModal').classList.remove('aktif')">&times;</button>
            </div>
            <div class="modal-govde">
                <div class="ab-3">
                    <label class="form-etiket">Ad Soyad</label>
                    <input type="text" class="form-alan" name="ad_soyad" required>
                </div>
                <div class="ab-3">
                    <label class="form-etiket">Kullanıcı Adı</label>
                    <input type="text" class="form-alan" name="kullanici_adi" required>
                </div>
                <div class="ab-3">
                    <label class="form-etiket">Şifre (en az 8 karakter, büyük/küçük harf + rakam)</label>
                    <input type="password" class="form-alan" name="sifre" minlength="8" required>
                </div>
                <div class="ab-3">
                    <label class="form-etiket">Rol</label>
                    <select class="form-secim" name="rol">
                        <option value="kullanici">Kullanıcı</option>
                        <option value="admin">Admin</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                </div>
            </div>
            <div class="modal-alt">
                <button type="button" class="dugme dugme-cizgi-ikincil" onclick="document.getElementById('ekleModal').classList.remove('aktif')">Kapat</button>
                <button type="submit" class="dugme dugme-ana">Ekle</button>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<div class="sayfa-ust stagger-item">
    <div>
        <h2>Şifremi Değiştir</h2>
        <p class="metin-soluk ub-1">Hesap güvenliğiniz için şifrenizi düzenli olarak güncelleyin.</p>
    </div>
</div>

<div class="satir ab-4">
    <!-- Profil Bilgisi -->
    <div class="sutun-orta-4 sutun-12 ab-3 stagger-item stagger-delay-1">
        <div class="kart" style="height:100%;">
            <div class="kart-baslik">
                <span>Hesap Bilgileri</span>
            </div>
            <div class="kart-icerik">
                <div style="display:flex; flex-direction:column; gap:16px;">
                    <div style="display:flex; align-items:center; gap:14px;">
                        <div class="kullanici-avatar" style="width:52px;height:52px;flex-shrink:0;">
                            <i data-lucide="user-round" style="width:26px;height:26px;"></i>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:0.95rem;color:var(--metin);"><?= temizle($oturumKullanici['ad_soyad'] ?? '') ?></div>
                            <div style="font-size:0.8rem;color:var(--metin-soluk);margin-top:2px;">@<?= temizle($oturumKullanici['kullanici_adi'] ?? '') ?></div>
                        </div>
                    </div>
                    <div style="padding-top:12px;border-top:1px solid var(--kenar);">
                        <div style="font-size:0.75rem;font-weight:600;color:var(--metin-soluk);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px;">Rol</div>
                        <?php if ($oturumRol === 'admin'): ?>
                            <span class="rozet renk-ana">Admin</span>
                        <?php else: ?>
                            <span class="rozet renk-ikincil">Kullanıcı</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Şifre Değiştir Formu -->
    <div class="sutun-orta-8 sutun-12 ab-3 stagger-item stagger-delay-2">
        <div class="kart">
            <div class="kart-baslik">
                <div class="esnek hizala-orta bosluk-2">
                    <i data-lucide="lock" style="width:16px;height:16px;color:var(--metin-soluk);"></i>
                    <span>Şifre Güncelle</span>
                </div>
            </div>
            <div class="kart-icerik">
                <form method="POST" style="max-width:440px;">
                    <?= csrfToken() ?>
                    <input type="hidden" name="islem" value="sifre_degistir">
                    <div class="ab-3">
                        <label class="form-etiket">Mevcut Şifre</label>
                        <input type="password" class="form-alan" name="mevcut_sifre" required
                               autocomplete="current-password" placeholder="Şu anki şifreniz">
                    </div>
                    <div class="ab-3">
                        <label class="form-etiket">Yeni Şifre <small class="metin-soluk">(en az 8 karakter, büyük/küçük harf + rakam)</small></label>
                        <input type="password" class="form-alan" name="yeni_sifre" minlength="8" required
                               autocomplete="new-password" placeholder="Yeni şifreniz">
                    </div>
                    <div class="ab-4">
                        <label class="form-etiket">Yeni Şifre (Tekrar)</label>
                        <input type="password" class="form-alan" name="tekrar_sifre" minlength="8" required
                               autocomplete="new-password" placeholder="Yeni şifreyi tekrar girin">
                    </div>
                    <button type="submit" class="dugme dugme-mavi">
                        <i data-lucide="check"></i> Şifremi Güncelle
                    </button>
                </form>
                <p class="metin-soluk" style="font-size:0.78rem;margin-top:16px;">
                    Diğer hesap ayarları için sistem yöneticinizle iletişime geçin.
                </p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
