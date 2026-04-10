<?php
/**
 * ============================================================
 * YETKI.PHP - GIRIS VE YETKILENDIRME KONTROLU
 * ============================================================
 *
 * Her korunmali sayfada config.php'den SONRA yuklenir.
 *
 * Kullanim:
 *   require_once 'config.php';
 *   require_once 'yetki.php';
 *   yetkiKontrol(['super_admin', 'admin']);  // Bu sayfaya kimler girebilir
 */

// --- ADIM 1: Giris yapilmis mi? ---
if (!isset($_SESSION['kullanici'])) {
    header("Location: giris.php");
    exit;
}

// --- ADIM 1.5: Session zaman asimi kontrolu (30 dakika) ---
$oturumZamanAsimi = OTURUM_ZAMAN_ASIMI;
if (isset($_SESSION['son_aktivite']) && (time() - $_SESSION['son_aktivite']) > $oturumZamanAsimi) {
    $bildirimMesaj = ['mesaj' => 'Oturumunuz zaman asimina ugradi. Lutfen tekrar giris yapin.', 'tur' => 'warning'];
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cp = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cp['path'], $cp['domain'], $cp['secure'], $cp['httponly']);
    }
    session_destroy();
    session_start();
    $_SESSION['bildirim'] = $bildirimMesaj;
    header("Location: giris.php");
    exit;
}
$_SESSION['son_aktivite'] = time();

// --- ADIM 2: Kullanici hala aktif mi? (F4: Cache - 5 dakikada bir kontrol) ---
$yenidenDogrulamaArasi = YENIDEN_DOGRULAMA_ARASI;
if (!isset($_SESSION['son_dogrulama']) || (time() - $_SESSION['son_dogrulama']) > $yenidenDogrulamaArasi) {
    $yetki_stmt = $db->prepare("SELECT id, kullanici_adi, ad_soyad, rol, aktif FROM kullanicilar WHERE id = ?");
    $yetki_stmt->execute([$_SESSION['kullanici']['id']]);
    $oturumKullanici = $yetki_stmt->fetch();

    if (!$oturumKullanici || !$oturumKullanici['aktif']) {
        unset($_SESSION['kullanici']);
        session_destroy();
        header("Location: giris.php");
        exit;
    }

    // Session'daki kullanici bilgisini guncelle (rol degismis olabilir)
    $_SESSION['kullanici'] = $oturumKullanici;
    $_SESSION['son_dogrulama'] = time();
} else {
    $oturumKullanici = $_SESSION['kullanici'];
}

/**
 * YETKI KONTROL - Bu sayfaya erisim yetkisi var mi?
 * $izinliRoller: ['super_admin', 'admin'] gibi rol listesi
 * Yetkisi yoksa ana sayfaya yonlendirir
 */
function yetkiKontrol($izinliRoller) {
    global $oturumKullanici;
    if (!in_array($oturumKullanici['rol'], $izinliRoller)) {
        $_SESSION['bildirim'] = [
            'mesaj' => 'Bu sayfaya erisim yetkiniz yok.',
            'tur'   => 'danger'
        ];
        header("Location: index.php");
        exit;
    }
}

/**
 * ROL ADI - Rol kodunu Turkce metne cevirir
 * Ornek: 'super_admin' -> 'Super Admin'
 */
function rolAdi($rol) {
    $roller = [
        'super_admin' => 'Super Admin',
        'admin'       => 'Admin',
        'kullanici'   => 'Kullanici',
    ];
    return $roller[$rol] ?? $rol;
}
