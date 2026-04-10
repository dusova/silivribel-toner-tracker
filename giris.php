<?php
/**
 * ============================================================
 * GIRIS.PHP - GIRIS SAYFASI
 * ============================================================
 *
 * Kullanici adi + sifre ile sisteme giris.
 * Basarili olursa: Session olusturulur, ana sayfaya gidilir.
 * Guvenlik: Brute force korumasi (MAKS_GIRIS_DENEMESI sonrasi KILIT_SURESI_SANIYE kilitleme)
 */

require_once __DIR__ . '/config_base.php';

// Zaten giris yapmissa ana sayfaya gonder
if (isset($_SESSION['kullanici'])) {
    header("Location: index.php");
    exit;
}

$hata = '';

// --- BRUTE FORCE KORUMASI ---
// Cok fazla yanlis sifre denemesinde kilitlenme (IP bazli)
$maksimumDenemeSayisi = MAKS_GIRIS_DENEMESI;
$kilitlenmeSuresiSaniye = KILIT_SURESI_SANIYE;

$bruteForceKlasoru = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'toner_takip_bf';
if (!is_dir($bruteForceKlasoru)) { @mkdir($bruteForceKlasoru, 0700, true); }

$ipAdresi = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$bruteForceDosyasi = $bruteForceKlasoru . DIRECTORY_SEPARATOR . md5($ipAdresi) . '.json';

// Brute force dosyasini oku (deneme sayisi ve kilit zamani)
function bruteForceOku($dosya) {
    if (!file_exists($dosya)) return ['deneme' => 0, 'kilit' => null];
    $veri = @json_decode(@file_get_contents($dosya), true);
    return is_array($veri) ? $veri : ['deneme' => 0, 'kilit' => null];
}

// Brute force dosyasina yaz
function bruteForceYaz($dosya, $veri) {
    @file_put_contents($dosya, json_encode($veri), LOCK_EX);
}

// F12: Eski brute force dosyalarini temizle (olasilik bazli GC)
// Her istekte %5 ihtimalle calisan pasif temizlik
function bruteForceTemizle($klasor, $sureSaniye = 300) {
    if (mt_rand(1, 20) !== 1) return; // %5 olasilik
    $dosyalar = @glob($klasor . DIRECTORY_SEPARATOR . '*.json');
    if (!$dosyalar) return;
    $simdi = time();
    foreach ($dosyalar as $d) {
        if (($simdi - @filemtime($d)) > $sureSaniye) {
            @unlink($d);
        }
    }
}

// F12: Her sayfa yuklemesinde eski kilit dosyalarini temizle
bruteForceTemizle($bruteForceKlasoru, $kilitlenmeSuresiSaniye * 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF dogrulama
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $hata = 'Geçersiz istek. Sayfayı yenileyip tekrar deneyin.';
    }

    // Token kullanildiktan sonra yenile
    unset($_SESSION['csrf_token']);

    $bruteForceVerisi = bruteForceOku($bruteForceDosyasi);

    // Kilit suresi dolduysa sifirla
    if ($bruteForceVerisi['kilit'] && (time() - $bruteForceVerisi['kilit']) >= $kilitlenmeSuresiSaniye) {
        @unlink($bruteForceDosyasi);
        $bruteForceVerisi = ['deneme' => 0, 'kilit' => null];
    }

    // Hala kilitliyse engelle
    if ($bruteForceVerisi['deneme'] >= $maksimumDenemeSayisi && $bruteForceVerisi['kilit']) {
        $kalanSaniye = $kilitlenmeSuresiSaniye - (time() - $bruteForceVerisi['kilit']);
        $hata = 'Çok fazla başarısız deneme. Lütfen ' . max(1, $kalanSaniye) . ' saniye bekleyin.';
    } elseif (empty($hata)) {

        $kullaniciAdi = trim($_POST['kullanici_adi'] ?? '');
        $sifre        = $_POST['sifre'] ?? '';

        if (empty($kullaniciAdi) || empty($sifre)) {
            $hata = 'Kullanıcı adı ve şifre zorunludur.';
        } else {
            try {
                $db = dbBaglan();

                $stmt = $db->prepare("SELECT * FROM kullanicilar WHERE kullanici_adi = ? AND aktif = 1");
                $stmt->execute([$kullaniciAdi]);
                $kullanici = $stmt->fetch();

                if ($kullanici && password_verify($sifre, $kullanici['sifre_hash'])) {
                    @unlink($bruteForceDosyasi);

                    session_regenerate_id(true);
                    $_SESSION['kullanici'] = [
                        'id'       => $kullanici['id'],
                        'kullanici_adi' => $kullanici['kullanici_adi'],
                        'ad_soyad' => $kullanici['ad_soyad'],
                        'rol'      => $kullanici['rol'],
                        'aktif'    => $kullanici['aktif'],
                    ];

                    girisLogYaz($db, $kullanici['id'], $kullanici['kullanici_adi'], $kullanici['ad_soyad'], $ipAdresi, 'Giriş', 'Başarılı giriş. Rol: ' . $kullanici['rol']);

                    $_SESSION['bildirim'] = [
                        'mesaj' => 'Hoş geldiniz, ' . htmlspecialchars($kullanici['ad_soyad']) . '!',
                        'tur'   => 'success'
                    ];
                    header("Location: index.php");
                    exit;
                } else {
                    $bruteForceVerisi['deneme'] = ($bruteForceVerisi['deneme'] ?? 0) + 1;
                    if ($bruteForceVerisi['deneme'] >= $maksimumDenemeSayisi) {
                        $bruteForceVerisi['kilit'] = time();
                    }
                    bruteForceYaz($bruteForceDosyasi, $bruteForceVerisi);

                    girisLogYaz($db, null, $kullaniciAdi, '', $ipAdresi, 'Başarısız Giriş', 'Hatalı kullanıcı adı veya şifre. Deneme: ' . $bruteForceVerisi['deneme']);

                    $hata = 'Kullanıcı adı veya şifre hatalı.';
                }
            } catch (PDOException $e) {
                $hata = 'Veritabanı hatası. Kurulum yapılmış mı kontrol edin.';
            }
        }
    }
}

// Bildirim mesaji varsa (setup'tan gelebilir)
// bildirimiGoster() config.php'de tanimli; giris.php config.php yuklemedigi icin ozel versiyon
$bildirim = '';
if (isset($_SESSION['bildirim'])) {
    $b = $_SESSION['bildirim'];
    unset($_SESSION['bildirim']);
    $izinliTurler = ['success', 'danger', 'warning', 'info'];
    $tur = in_array($b['tur'], $izinliTurler) ? $b['tur'] : 'info';
    $turSinif = ['success'=>'basari', 'danger'=>'tehlike', 'warning'=>'dikkat', 'info'=>'bilgi'];
    $sinifAdi = $turSinif[$tur] ?? 'bilgi';
    $bildirim = '<div class="bildirim bildirim-uyari-' . $sinifAdi . '">' . htmlspecialchars($b['mesaj'] ?? '', ENT_QUOTES, 'UTF-8') . '</div>';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş — Toner Takip Sistemi | Silivri Belediyesi</title>
    <link rel="stylesheet" href="css/fonts.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/lucide.min.js"></script>
</head>
<body>

    <div class="giris-kutu">
        <?= $bildirim ?>

        <div class="giris-kart">
            <div class="giris-logo">
                <img src="img/logo.svg" alt="Silivri Belediyesi">
                <h4>Toner Takip Sistemi</h4>
                <p>Lütfen kullanıcı adınızı ve şifrenizi giriniz.</p>
            </div>

            <div class="giris-form">
                <?php if ($hata): ?>
                    <div class="bildirim bildirim-uyari-tehlike">
                        <?= htmlspecialchars($hata) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrfToken() ?>
                    <div class="form-grup">
                        <label class="form-etiket" for="kullanici_adi">Kullanıcı Adı</label>
                        <input class="form-kontrol" type="text" id="kullanici_adi" name="kullanici_adi"
                               value="<?= htmlspecialchars($_POST['kullanici_adi'] ?? '') ?>"
                               placeholder="Kullanıcı adınızı girin" required autofocus>
                    </div>

                    <div class="form-grup">
                        <label class="form-etiket" for="sifre">Şifre</label>
                        <input class="form-kontrol" type="password" id="sifre" name="sifre"
                               placeholder="Şifrenizi girin" required>
                    </div>

                    <button type="submit" class="dugme dugme-ana dugme-buyuk tam-gen">GİRİŞ YAP</button>
                </form>
            </div>
        </div>

        <div class="giris-alt">
            <small>T.C. Silivri Belediyesi Bilgi İşlem Müdürlüğü &copy; <?= date('Y') ?></small>
        </div>
    </div>
    <script>
        if(window.lucide) lucide.createIcons();
    </script>
</body>
</html>
