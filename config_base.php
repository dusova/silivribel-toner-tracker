<?php
/**
 * ============================================================
 * CONFIG_BASE.PHP - ORTAK ALTYAPI
 * ============================================================
 *
 * Session, guvenlik basliklari ve DB bilgilerini tek noktada tutar.
 * Hem config.php hem giris.php hem setup.php bu dosyayi yukler.
 *
 * Kullanim: require_once 'config_base.php';
 */

// --- SABITLER ---
define('DB_SUNUCU',    'localhost');
define('DB_ADI',       'toner_takip');
define('DB_KULLANICI', 'root');
define('DB_SIFRE',     '');

// Zaman asimi & guvenlik sabitleri
define('OTURUM_ZAMAN_ASIMI',     1800);  // 30 dakika
define('YENIDEN_DOGRULAMA_ARASI', 300);  // 5 dakika
define('MAKS_GIRIS_DENEMESI',      5);   // Brute force limiti
define('KILIT_SURESI_SANIYE',    120);   // Brute force kilitleme suresi
define('SAYFA_BASINA_KAYIT',     200);   // Rapor sayfalama
define('MAKS_MIKTAR',           9999);   // Form miktar limiti

// --- SESSION AYARLARI ---
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'path'     => '/',
    ]);
    session_start();
}

// --- GUVENLIK BASLIKLARI ---
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self' 'unsafe-inline'; img-src 'self' data:;");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

/**
 * PDO baglanti secenekleri (DRY - tekrar etmesin)
 */
function pdoSecenekleri(): array {
    return [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
}

/**
 * Veritabanina baglan ve PDO nesnesi dondur
 */
function dbBaglan(): PDO {
    $dsn = "mysql:host=" . DB_SUNUCU . ";dbname=" . DB_ADI . ";charset=utf8mb4";
    return new PDO($dsn, DB_KULLANICI, DB_SIFRE, pdoSecenekleri());
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}

function sifreKuralKontrol($sifre) {
    if (strlen($sifre) < 8) return false;
    if (!preg_match('/[A-Z]/', $sifre)) return false;
    if (!preg_match('/[a-z]/', $sifre)) return false;
    if (!preg_match('/[0-9]/', $sifre)) return false;
    return true;
}
define('SIFRE_KURAL_MESAJI', 'Sifre en az 8 karakter olmali ve buyuk harf, kucuk harf, rakam icermelidir.');

/**
 * GIRIS LOG YAZ - Giris sayfasina ozel log fonksiyonu
 * config.php yuklenmeden calisabilir; config.php'deki logKaydet() ile ayni isi yapar.
 */
function girisLogYaz(PDO $dbConn, $kullaniciId, $kullaniciAdi, $adSoyad, $ip, $islem, $detay = '') {
    try {
        $dbConn->exec("CREATE TABLE IF NOT EXISTS `sistem_loglari` (
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
        $stmt = $dbConn->prepare("INSERT INTO sistem_loglari (kullanici_id, kullanici_adi, ad_soyad, ip_adresi, modul, islem, detay) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$kullaniciId, $kullaniciAdi, $adSoyad, $ip, 'Oturum', $islem, $detay]);
    } catch (Exception $e) {
        // Log hatasi uygulamayi durdurmasin
    }
}
