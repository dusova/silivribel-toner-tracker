<?php
/**
 * ============================================================
 * CONFIG.PHP - ANA AYAR DOSYASI
 * ============================================================
 *
 * Bu dosya her sayfanin BASINDA require_once ile yuklenir.
 * Icerigi:
 *   1. Ortak altyapi (config_base.php: session, guvenlik, sabitler)
 *   2. Veritabani baglantisi
 *   3. Yardimci fonksiyonlar (yonlendir, temizle, csrf vb.)
 *
 * Kullanim: require_once 'config.php';
 */

require_once __DIR__ . '/config_base.php';

// --- VERITABANI BAGLANTISI ---
try {
    $db = dbBaglan();
} catch (PDOException $e) {
    error_log("Toner Takip DB baglanti hatasi: " . $e->getMessage());
    die("Bir hata olustu. Yonetici ile iletisime gecin.");
}

/**
 * YONLENDIR - Kullaniciyi baska sayfaya gonderir
 * Ornek: yonlendir('index.php');
 */
function yonlendir($url) {
    $izinliSayfalar = ['index.php', 'giris.php', 'tonerler.php', 'yedek_parcalar.php',
                       'yazicilar.php', 'stok_giris.php', 'birimler.php', 'zimmet.php',
                       'depo.php', 'rapor.php', 'kullanicilar.php', 'loglar.php', 'setup.php'];
    $parsed = parse_url($url);
    $sayfa = basename($parsed['path'] ?? '');
    if (!in_array($sayfa, $izinliSayfalar)) {
        $url = 'index.php';
    }
    header("Location: $url");
    exit;
}

/**
 * CSRF DOGRULA - Form gonderildiginde token kontrolu
 * POST isleminin BASINDA cagirin, gecersizse islemi durdurur
 */
function csrfDogrula() {
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        bildirim('Gecersiz istek. Lütfen sayfayi yenileyip tekrar deneyin.', 'danger');
        $guvenliSayfa = basename($_SERVER['SCRIPT_NAME']);
        header("Location: " . $guvenliSayfa);
        exit;
    }
    // Token kullanildiktan sonra yenile (replay saldirisini onler)
    unset($_SESSION['csrf_token']);
}

/**
 * BILDIRIM - Kullaniciya mesaj gostermek icin
 * Session'a yazar, sonraki sayfada bildirimiGoster() ile gorunur
 * $tur: 'success' (yesil), 'danger' (kirmizi), 'warning' (sari), 'info' (mavi)
 */
function bildirim($mesaj, $tur = 'success') {
    $_SESSION['bildirim'] = [
        'mesaj' => $mesaj,
        'tur'   => $tur
    ];
}

/**
 * BILDIRIMI GOSTER - Kaydedilmis mesaji ekranda gosterir
 * Header'dan sonra <?= bildirimiGoster() ?> cagirin
 * XSS korumali: Kullanici mesaji guvenli sekilde escape edilir
 */
function bildirimiGoster() {
    if (isset($_SESSION['bildirim'])) {
        $b = $_SESSION['bildirim'];
        unset($_SESSION['bildirim']);

        $izinliTurler = ['success', 'danger', 'warning', 'info'];
        $tur = in_array($b['tur'], $izinliTurler) ? $b['tur'] : 'info';

        $turSinif = ['success'=>'basari', 'danger'=>'tehlike', 'warning'=>'dikkat', 'info'=>'bilgi'];
        $sinif = $turSinif[$tur] ?? 'bilgi';

        $mesaj = nl2br(htmlspecialchars($b['mesaj'] ?? '', ENT_QUOTES, 'UTF-8'));

        return '<div class="uyari uyari-' . $sinif . '">
                    ' . $mesaj . '
                    <button class="dugme-kapat" type="button">&times;</button>
                </div>';
    }
    return '';
}

/**
 * TARIH FORMATLA - Veritabani tarihini Turkce formata cevirir
 * 2024-01-15 -> 15.01.2024
 */
function tarihFormatla($tarih) {
    return date('d.m.Y', strtotime($tarih));
}

/**
 * TARIH DOGRULA - Tarih gecerli mi kontrol eder
 * Y-m-d formati (ornek: 2024-01-15) beklenir
 */
function tarihDogrula($tarih) {
    $d = DateTime::createFromFormat('Y-m-d', $tarih);
    return $d && $d->format('Y-m-d') === $tarih;
}

/**
 * TEMIZLE - XSS saldirisina karsi metni guvenli hale getirir
 * Kullanicidan gelen HER veriyi ekranda gostermeden once cagirin
 * Ornek: <?= temizle($kullanici['ad_soyad']) ?>
 */
function temizle($metin) {
    return htmlspecialchars(trim($metin ?? ''), ENT_QUOTES, 'UTF-8');
}

// ============================================================
// LOG KAYIT SISTEMI
// ============================================================

/**
 * LOG KAYDET - Sistem islemlerini veritabanina kaydeder
 * Her onemli islemde cagirin (giris, cikis, ekleme, silme, stok hareketi vb.)
 *
 * @param string $modul   Hangi sayfa/bolum (Oturum, Toner, Depo, Kullanici vb.)
 * @param string $islem   Ne yapildi (Giriş, Ekleme, Silme, Stok Giriş vb.)
 * @param string $detay   Serbest metin detay bilgisi
 */
function logKaydet($modul, $islem, $detay = '') {
    global $db;
    static $tabloHazir = false;

    try {
        if (!$tabloHazir) {
            $db->exec("CREATE TABLE IF NOT EXISTS `sistem_loglari` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `kullanici_id`     INT NULL,
                `kullanici_adi`    VARCHAR(50),
                `ad_soyad`         VARCHAR(200),
                `ip_adresi`        VARCHAR(45),
                `modul`            VARCHAR(50) NOT NULL,
                `islem`            VARCHAR(50) NOT NULL,
                `detay`            TEXT,
                `olusturma_tarihi` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_log_tarih` (`olusturma_tarihi` DESC),
                INDEX `idx_log_modul` (`modul`),
                INDEX `idx_log_kullanici` (`kullanici_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");
            $tabloHazir = true;
        }

        $kullaniciId  = $_SESSION['kullanici']['id'] ?? null;
        $kullaniciAdi = $_SESSION['kullanici']['kullanici_adi'] ?? 'misafir';
        $adSoyad      = $_SESSION['kullanici']['ad_soyad'] ?? '';
        $ip           = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $stmt = $db->prepare("INSERT INTO sistem_loglari (kullanici_id, kullanici_adi, ad_soyad, ip_adresi, modul, islem, detay) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$kullaniciId, $kullaniciAdi, $adSoyad, $ip, $modul, $islem, $detay]);
    } catch (Exception $e) {
        // Log hatasi uygulamayi durdurmasin
    }
}

// ============================================================
// YARDIMCI FONKSIYONLAR (F8+F9+F20)
// ============================================================

/**
 * STOK SINIFI - Stok durumuna gore CSS sinifi dondurur
 * Ornek: stokSinifi(0, 3) => 'stok-kritik'
 */
function stokSinifi($miktar, $kritik) {
    if ($miktar == 0) return 'stok-kritik';
    if ($miktar <= $kritik) return 'stok-dusuk';
    return 'stok-normal';
}

/**
 * RENK BADGE - Renk koduna gore rozet CSS sinifi dondurur
 * Ornek: renkBadge('Cyan') => 'renk-bilgi metin-koyu'
 */
function renkBadge($renk) {
    $map = [
        'Cyan'    => 'renk-bilgi metin-koyu',
        'Magenta' => 'renk-magenta',
        'Yellow'  => 'renk-uyari metin-koyu',
        'Black'   => 'renk-koyu',
        'Siyah'   => 'renk-koyu',
        'Renkli'  => 'renk-ana',
    ];
    return $map[$renk] ?? 'renk-ikincil';
}

// ============================================================
// VERI ERISIM FONKSIYONLARI (F20)
// ============================================================

/**
 * Tum tonerleri getirir (filtre dropdown, stok giris vb. icin)
 */
function tumTonerleriGetir($db) {
    return $db->query("SELECT id, toner_kodu, toner_model, marka, renk, uyumlu_modeller, stok_miktari, kritik_stok FROM tonerler ORDER BY toner_model, renk")->fetchAll();
}

/**
 * Tum yedek parcalari getirir
 */
function tumParcalariGetir($db) {
    return $db->query("SELECT id, parca_kodu, parca_tipi, renk, uyumlu_modeller, stok_miktari, kritik_stok FROM yedek_parcalar ORDER BY parca_tipi, parca_kodu")->fetchAll();
}

/**
 * Aktif yazicilari getirir
 */
function aktifYazicilariGetir($db) {
    return $db->query("SELECT id, marka, model, baglanti_tipi, ip_adresi, lokasyon, toner_model, renkli FROM yazicilar WHERE aktif = 1 ORDER BY lokasyon, marka, model")->fetchAll();
}
