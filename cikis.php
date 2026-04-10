<?php
/**
 * CIKIS.PHP - Oturumu kapatir
 * Session ve cookie temizlenir, giris sayfasina yonlendirilir
 * POST + CSRF token gerektirir (CSRF koruması)
 */

require_once __DIR__ . '/config_base.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    header("Location: giris.php");
    exit;
}

if (isset($_SESSION['kullanici'])) {
    try {
        $db = dbBaglan();
        $logStmt = $db->prepare("INSERT INTO sistem_loglari (kullanici_id, kullanici_adi, ad_soyad, ip_adresi, modul, islem, detay) VALUES (?,?,?,?,?,?,?)");
        $logStmt->execute([
            $_SESSION['kullanici']['id'],
            $_SESSION['kullanici']['kullanici_adi'],
            $_SESSION['kullanici']['ad_soyad'],
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'Oturum', 'Çıkış', 'Kullanıcı oturumu kapattı.'
        ]);
    } catch (Exception $e) {}
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $cookieParam = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $cookieParam['path'], $cookieParam['domain'],
        $cookieParam['secure'], $cookieParam['httponly']
    );
}

session_destroy();
header("Location: giris.php");
exit;
