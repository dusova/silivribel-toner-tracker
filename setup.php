<?php
/**
 * ============================================================
 * SETUP.PHP - ILK KURULUM
 * ============================================================
 *
 * Yapilan islemler:
 *   1. toner_takip veritabanini olusturur
 *   2. Tablolari olusturur (tonerler, yedek_parcalar, yazicilar, birimler, hareketler, kullanicilar)
 *   3. 57 toner, 52 yedek parca, 77 yazici, 20 birim ekler
 *   4. Super Admin hesabi olusturur
 *
 * UYARI: Mevcut veritabani varsa SIFIRLANIR!
 */

require_once __DIR__ . '/config_base.php';

$hata = '';

// --- ERISIM KORUMASI ---
$setupKilitDosyasi = __DIR__ . '/.setup_lock';

try {
    $kontrolPdo = dbBaglan();
    $adminSayisi = $kontrolPdo->query("SELECT COUNT(*) FROM kullanicilar WHERE rol = 'super_admin'")->fetchColumn();
    if ($adminSayisi > 0) {
        if (!isset($_SESSION['kullanici']) || $_SESSION['kullanici']['rol'] !== 'super_admin') {
            die('<div style="text-align:center;margin-top:100px;font-family:sans-serif;"><h2>Erisim Engellendi</h2><p>Kurulum sayfasina erisim icin Super Admin olarak giris yapin.</p><a href="giris.php">Giris Sayfasi</a></div>');
        }
    }
    unset($kontrolPdo);
} catch (PDOException $e) {
    if (file_exists($setupKilitDosyasi)) {
        die('<div style="text-align:center;margin-top:100px;font-family:sans-serif;"><h2>Kurulum Kilitli</h2><p>Kurulum daha once tamamlanmis ancak veritabani bulunamadi.<br>Yeniden kurulum icin sunucudaki <code>.setup_lock</code> dosyasini silin.</p></div>');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF kontrolu
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $hata = 'Gecersiz istek. Sayfayi yenileyip tekrar deneyin.';
    }
    // Token kullanildiktan sonra yenile (replay saldirisini onler)
    unset($_SESSION['csrf_token']);

    $adminAdi     = trim($_POST['admin_adi'] ?? '');
    $adminSifre   = $_POST['admin_sifre'] ?? '';
    $adminAdSoyad = trim($_POST['admin_ad_soyad'] ?? '');

    if (!empty($hata)) {
        // CSRF hatasi, asagida gosterilecek
    } elseif (empty($adminAdi) || empty($adminSifre) || empty($adminAdSoyad)) {
        $hata = 'Super Admin bilgilerini eksiksiz doldurun.';
    } elseif (!sifreKuralKontrol($adminSifre)) {
        $hata = SIFRE_KURAL_MESAJI;
    // F17: Guvenlik onay kelimesi kontrolu
    } elseif (trim($_POST['onay_kelime'] ?? '') !== 'SIFIRLA') {
        $hata = 'Guvenlik onayini gecmek icin SIFIRLA yazmaniz gerekmektedir.';
    } else {

        try {
            $pdo = new PDO(
                "mysql:host=" . DB_SUNUCU . ";charset=utf8mb4",
                DB_KULLANICI, DB_SIFRE,
                pdoSecenekleri()
            );

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `toner_takip`
                         CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci");
            $pdo->exec("USE `toner_takip`");

            // Tablolari sifirla
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $pdo->exec("DROP TABLE IF EXISTS `sistem_loglari`");
            $pdo->exec("DROP TABLE IF EXISTS `depo_hareketler`");
            $pdo->exec("DROP TABLE IF EXISTS `depo_urunler`");
            $pdo->exec("DROP TABLE IF EXISTS `hareketler`");
            $pdo->exec("DROP TABLE IF EXISTS `yedek_parcalar`");
            $pdo->exec("DROP TABLE IF EXISTS `tonerler`");
            $pdo->exec("DROP TABLE IF EXISTS `yazicilar`");
            $pdo->exec("DROP TABLE IF EXISTS `birimler`");
            $pdo->exec("DROP TABLE IF EXISTS `kullanicilar`");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            // ===== TONERLER =====
            $pdo->exec("CREATE TABLE `tonerler` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `toner_kodu`       VARCHAR(50) NOT NULL,
                `toner_model`      VARCHAR(50) NOT NULL,
                `marka`            VARCHAR(100) NOT NULL,
                `renk`             VARCHAR(20) NOT NULL DEFAULT 'Siyah',
                `uyumlu_modeller`  VARCHAR(500),
                `stok_miktari`     INT DEFAULT 0,
                `kritik_stok`      INT DEFAULT 3,
                `olusturma_tarihi` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

            // ===== YEDEK PARCALAR =====
            $pdo->exec("CREATE TABLE `yedek_parcalar` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `parca_kodu`       VARCHAR(100) NOT NULL,
                `parca_tipi`       ENUM('DRUM','DEVELOPER','TRANSFER_BELT','FUSER','DIGER') NOT NULL,
                `renk`             VARCHAR(30) DEFAULT '-',
                `uyumlu_modeller`  VARCHAR(500),
                `stok_miktari`     INT DEFAULT 0,
                `kritik_stok`      INT DEFAULT 2,
                `olusturma_tarihi` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

            // ===== YAZICILAR =====
            $pdo->exec("CREATE TABLE `yazicilar` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `marka`            VARCHAR(100) NOT NULL,
                `model`            VARCHAR(100) NOT NULL,
                `baglanti_tipi`    ENUM('IP','USB') NOT NULL,
                `ip_adresi`        VARCHAR(50),
                `lokasyon`         VARCHAR(200) NOT NULL,
                `toner_model`      VARCHAR(50),
                `renkli`           TINYINT(1) DEFAULT 1,
                `aktif`            TINYINT(1) DEFAULT 1,
                `olusturma_tarihi` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

            // ===== BIRIMLER =====
            $pdo->exec("CREATE TABLE `birimler` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `birim_adi`        VARCHAR(200) NOT NULL,
                `sorumlu_kisi`     VARCHAR(200),
                `telefon`          VARCHAR(20),
                `olusturma_tarihi` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

            // ===== HAREKETLER =====
            $pdo->exec("CREATE TABLE `hareketler` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `toner_id`         INT NULL,
                `yedek_parca_id`   INT NULL,
                `yazici_id`        INT NULL,
                `miktar`           INT NOT NULL,
                `hareket_tipi`     ENUM('giris','cikis') NOT NULL,
                `tarih`            DATE NOT NULL,
                `aciklama`         TEXT,
                `olusturma_tarihi` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`toner_id`) REFERENCES `tonerler`(`id`) ON DELETE RESTRICT,
                FOREIGN KEY (`yedek_parca_id`) REFERENCES `yedek_parcalar`(`id`) ON DELETE RESTRICT,
                FOREIGN KEY (`yazici_id`) REFERENCES `yazicilar`(`id`) ON DELETE SET NULL,
                INDEX `idx_tip_tarih` (`hareket_tipi`, `tarih`),
                INDEX `idx_tarih` (`tarih`),
                INDEX `idx_olusturma` (`olusturma_tarihi` DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

            // F1: Ek performans indexleri
            $pdo->exec("ALTER TABLE `tonerler` ADD INDEX `idx_stok_kritik` (`stok_miktari`, `kritik_stok`)");
            $pdo->exec("ALTER TABLE `yedek_parcalar` ADD INDEX `idx_stok_kritik_yp` (`stok_miktari`, `kritik_stok`)");

            // F16: Yazicilari birimlere baglayan FK (opsiyonel iliskilendirme)
            $pdo->exec("ALTER TABLE `yazicilar` ADD COLUMN `birim_id` INT NULL AFTER `lokasyon`");
            $pdo->exec("ALTER TABLE `yazicilar` ADD FOREIGN KEY `fk_yazici_birim` (`birim_id`) REFERENCES `birimler`(`id`) ON DELETE SET NULL");

            // ===== DEPO URUNLERI =====
            $pdo->exec("CREATE TABLE `depo_urunler` (
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

            // ===== DEPO HAREKETLERI =====
            $pdo->exec("CREATE TABLE `depo_hareketler` (
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

            // ===== SISTEM LOGLARI =====
            $pdo->exec("CREATE TABLE `sistem_loglari` (
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

            // ===== KULLANICILAR =====
            $pdo->exec("CREATE TABLE `kullanicilar` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `kullanici_adi`    VARCHAR(50) NOT NULL UNIQUE,
                `sifre_hash`       VARCHAR(255) NOT NULL,
                `ad_soyad`         VARCHAR(200) NOT NULL,
                `rol`              ENUM('super_admin','admin','kullanici') NOT NULL DEFAULT 'kullanici',
                `aktif`            TINYINT(1) DEFAULT 1,
                `olusturma_tarihi` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");


            // ========================================================
            // TONER ENVANTER VERILERI (Excel: TONER_YEDEK PARCA_ENVANTER_2026)
            // ========================================================
            $pdo->exec("INSERT INTO `tonerler` (`toner_kodu`,`toner_model`,`marka`,`renk`,`uyumlu_modeller`,`stok_miktari`,`kritik_stok`) VALUES
                ('TN321C','TN321','Konica Minolta','Cyan','C224E, C364',9,3),
                ('TN321M','TN321','Konica Minolta','Magenta','C224E, C364',10,3),
                ('TN321Y','TN321','Konica Minolta','Yellow','C224E, C364',8,3),
                ('TN321K','TN321','Konica Minolta','Black','C224E, C364',11,3),
                ('TN324C','TN324','Konica Minolta','Cyan','C258, C368',16,5),
                ('TN324M','TN324','Konica Minolta','Magenta','C258, C368',16,5),
                ('TN324Y','TN324','Konica Minolta','Yellow','C258, C368',17,5),
                ('TN324K','TN324','Konica Minolta','Black','C258, C368',9,5),
                ('TN328C','TN328','Konica Minolta','Cyan','C250i, C300i',28,5),
                ('TN328M','TN328','Konica Minolta','Magenta','C250i, C300i',31,5),
                ('TN328Y','TN328','Konica Minolta','Yellow','C250i, C300i',30,5),
                ('TN328K','TN328','Konica Minolta','Black','C250i, C300i',11,5),
                ('TN412','TN412','Konica Minolta','Siyah','40P',26,5),
                ('TN512C','TN512','Konica Minolta','Cyan','C554E',32,5),
                ('TN512M','TN512','Konica Minolta','Magenta','C554E',40,5),
                ('TN512Y','TN512','Konica Minolta','Yellow','C554E',31,5),
                ('TN512K','TN512','Konica Minolta','Black','C554E',18,5),
                ('TN514C','TN514','Konica Minolta','Cyan','C458',28,5),
                ('TN514M','TN514','Konica Minolta','Magenta','C458',43,5),
                ('TN514Y','TN514','Konica Minolta','Yellow','C458',31,5),
                ('TN514K','TN514','Konica Minolta','Black','C458',36,5),
                ('TN620C','TN620','Konica Minolta','Cyan','C3070L',11,3),
                ('TN620M','TN620','Konica Minolta','Magenta','C3070L',9,3),
                ('TN620Y','TN620','Konica Minolta','Yellow','C3070L',11,3),
                ('TN620K','TN620','Konica Minolta','Black','C3070L',12,3),
                ('TNP35','TNP35','Konica Minolta','Siyah','4000P',33,5),
                ('TNP48C','TNP48','Konica Minolta','Cyan','C3350',5,3),
                ('TNP48M','TNP48','Konica Minolta','Magenta','C3350',9,3),
                ('TNP48Y','TNP48','Konica Minolta','Yellow','C3350',7,3),
                ('TNP48K','TNP48','Konica Minolta','Black','C3350',10,3),
                ('TNP49C','TNP49','Konica Minolta','Cyan','C3351, C3851',3,2),
                ('TNP49M','TNP49','Konica Minolta','Magenta','C3351, C3851',3,2),
                ('TNP49Y','TNP49','Konica Minolta','Yellow','C3351, C3851',3,2),
                ('TNP49K','TNP49','Konica Minolta','Black','C3351, C3851',3,2),
                ('TNP50C','TNP50','Konica Minolta','Cyan','C3100P',2,2),
                ('TNP50M','TNP50','Konica Minolta','Magenta','C3100P',2,2),
                ('TNP50Y','TNP50','Konica Minolta','Yellow','C3100P',1,2),
                ('TNP50K','TNP50','Konica Minolta','Black','C3100P',9,2),
                ('TNP76K','TNP76','Konica Minolta','Siyah','4000i',47,5),
                ('TNP79C','TNP79','Konica Minolta','Cyan','C3350i',7,3),
                ('TNP79M','TNP79','Konica Minolta','Magenta','C3350i',8,3),
                ('TNP79Y','TNP79','Konica Minolta','Yellow','C3350i',9,3),
                ('TNP79K','TNP79','Konica Minolta','Black','C3350i',9,3),
                ('TNP81C','TNP81','Konica Minolta','Cyan','C3300i',1,2),
                ('TNP81M','TNP81','Konica Minolta','Magenta','C3300i',2,2),
                ('TNP81Y','TNP81','Konica Minolta','Yellow','C3300i',1,2),
                ('TNP81K','TNP81','Konica Minolta','Black','C3300i',2,2),
                ('M300H','M300H','Epson','Siyah','AL-M300',20,3),
                ('PA210','PA210','Pantum','Siyah','P2506PRO',48,3),
                ('PFI-1700','PFI-1700','Canon','Siyah','4000S',0,2),
                ('CE278','CE278','HP','Siyah','1536DNF',0,3),
                ('SV184A','SV184A','Samsung','Siyah','SCX4200',0,2),
                ('ST08285A','ST08285A','HP','Siyah','107A',0,3),
                ('CE285A','CE285A','HP','Siyah','M1132',0,3),
                ('MLT-D111S','MLT-D111S','Samsung','Siyah','M2070',0,2),
                ('Q5949A','Q5949A','HP','Siyah','1320N',0,2),
                ('C278044725','C278044725','Sunlight','Siyah','K3',0,2)
            ");

            // ========================================================
            // YEDEK PARCA ENVANTER VERILERI
            // ========================================================
            $pdo->exec("INSERT INTO `yedek_parcalar` (`parca_kodu`,`parca_tipi`,`renk`,`uyumlu_modeller`,`stok_miktari`,`kritik_stok`) VALUES
                ('IUP-22K','DRUM','Black','C3350',5,2),
                ('IUP-22C','DRUM','Cyan','C3350',1,1),
                ('IUP-22M','DRUM','Magenta','C3350',1,1),
                ('IUP-22Y','DRUM','Yellow','C3350',1,1),
                ('IUP-34','DRUM','Siyah','4000i',17,3),
                ('IUP-35K','DRUM','Black','C3350i',1,1),
                ('IUP-35C','DRUM','Cyan','C3350i',1,1),
                ('IUP-35M','DRUM','Magenta','C3350i',1,1),
                ('IUP-35Y','DRUM','Yellow','C3350i',1,1),
                ('DR313 Y-M-C','DRUM','Renkli','C258, C368, C458',33,5),
                ('DR313 K','DRUM','Black','C258, C368, C458',8,3),
                ('DR316 Y-M-C','DRUM','Renkli','C250i, C300i',2,1),
                ('DR316 K','DRUM','Black','C250i, C300i',1,1),
                ('DR512 Y-M-C','DRUM','Renkli','C224E, C364, C554E',15,3),
                ('DR512 K','DRUM','Black','C224E, C364, C554E',13,3),
                ('DU-106','DRUM','Siyah','C3070L',24,3),
                ('DV313C','DEVELOPER','Cyan','C258, C368',3,1),
                ('DV313M','DEVELOPER','Magenta','C258, C368',2,1),
                ('DV313Y','DEVELOPER','Yellow','C258, C368',2,1),
                ('DV313K','DEVELOPER','Black','C258, C368',5,2),
                ('DV315C','DEVELOPER','Cyan','C250i, C300i',3,1),
                ('DV315M','DEVELOPER','Magenta','C250i, C300i',3,1),
                ('DV315Y','DEVELOPER','Yellow','C250i, C300i',3,1),
                ('DV315K','DEVELOPER','Black','C250i, C300i',3,1),
                ('DV512C','DEVELOPER','Cyan','C224E, C364, C554E',2,1),
                ('DV512M','DEVELOPER','Magenta','C224E, C364, C554E',3,1),
                ('DV512Y','DEVELOPER','Yellow','C224E, C364, C554E',4,1),
                ('DV512K','DEVELOPER','Black','C224E, C364, C554E',4,1),
                ('DV614C','DEVELOPER','Cyan','C3070L',1,1),
                ('DV614M','DEVELOPER','Magenta','C3070L',1,1),
                ('DV614Y','DEVELOPER','Yellow','C3070L',1,1),
                ('DV614K','DEVELOPER','Black','C3070L',1,1),
                ('DV619C','DEVELOPER','Cyan','C458',8,2),
                ('DV619M','DEVELOPER','Magenta','C458',8,2),
                ('DV619Y','DEVELOPER','Yellow','C458',8,2),
                ('DV619K','DEVELOPER','Black','C458',15,3),
                ('A1DU504203','TRANSFER_BELT','-','C3070L',2,1),
                ('TF-P07','TRANSFER_BELT','-','C3350, C3850',10,2),
                ('FU-P05','FUSER','-','C3100P',1,1),
                ('A79JR73211','TRANSFER_BELT','-','C458',8,2),
                ('A161R73311','TRANSFER_BELT','-','C554E',1,1),
                ('A0DXPP1X00','FUSER','-','40P',3,1),
                ('AA2JR75300','FUSER','-','C250i',4,1),
                ('A79MR70333','FUSER','-','C458',2,1),
                ('AAJRR70100','TRANSFER_BELT','-','C3350i, C3300i',0,1),
                ('IUP-35','DRUM','Siyah','C3350i',0,1),
                ('IUP-36','DRUM','Siyah','C3300i',0,1),
                ('AA2JR73700','TRANSFER_BELT','-','C250i, C300i',0,1),
                ('A161R73300','TRANSFER_BELT','-','C224E, C258, C364, C368, C554E',0,2),
                ('IUP-17','DRUM','Siyah','4000P',0,2),
                ('IUP-23','DRUM','Siyah','C3100P',0,1),
                ('A50UR70323','DIGER','-','C2060, C1060, C71',4,1)
            ");

            // ========================================================
            // YAZICI VERILERI (Excel: Yazici Listesi)
            // ========================================================

            // --- IP BAGLANTILI YAZICILAR ---
            $pdo->exec("INSERT INTO `yazicilar` (`marka`,`model`,`baglanti_tipi`,`ip_adresi`,`lokasyon`,`toner_model`,`renkli`) VALUES
                ('Konica Minolta','C458','IP','192.168.195.141','Baskanlik','TN514',1),
                ('Konica Minolta','C458','IP','192.168.195.219','Grafik Karsisi K.','TN514',1),
                ('Konica Minolta','C458','IP','192.168.195.214','Gelirler Sefligi K.','TN514',1),
                ('Konica Minolta','C554E','IP','192.168.195.222','Muhasebe','TN512',1),
                ('Konica Minolta','C458','IP','192.168.195.223','Plan Proje','TN514',1),
                ('Konica Minolta','C458','IP','192.168.195.224','Imar','TN514',1),
                ('Konica Minolta','C258','IP','192.168.195.228','Imar Arsiv','TN324',1),
                ('Konica Minolta','C3070L','IP','192.168.195.213','Grafik Buyuk Baski','TN620',1),
                ('Konica Minolta','C368','IP','192.168.195.47','Grafik','TN324',1),
                ('Konica Minolta','C224E','IP','192.168.195.69','Tek Durak','TN321',1),
                ('Konica Minolta','C250i','IP','192.168.195.221','Yazi Isleri','TN328',1),
                ('Konica Minolta','C458','IP','192.168.195.29','Hukuk','TN514',1),
                ('Konica Minolta','C258','IP','192.168.195.12','Emlak Istimlak','TN324',1),
                ('Konica Minolta','C458','IP','192.168.195.31','Araclar Otogar','TN514',1),
                ('Konica Minolta','C3350','IP','192.168.195.133','Kariyer Istihdam','TNP48',1),
                ('Konica Minolta','C250i','IP','192.168.195.13','Ezgi Egitim','TN328',1),
                ('Konica Minolta','C458','IP','192.168.195.218','Kultur Merkezi','TN514',1),
                ('Konica Minolta','C224E','IP','192.168.195.70','Barinaklar','TN321',1),
                ('Konica Minolta','C3350i','IP','192.168.195.55','Afet Koordinasyon','TNP79',1),
                ('Konica Minolta','C368','IP','192.168.195.227','Havuz','TN324',1),
                ('Konica Minolta','C458','IP','192.168.195.115','Zabita','TN514',1),
                ('Konica Minolta','C3350i','IP','192.168.195.211','Zabita Kacak Yapi','TNP79',1),
                ('Konica Minolta','C364E','IP','192.168.195.155','EYKOM Egitim A.','TN321',1),
                ('Konica Minolta','C458','IP','192.168.195.216','EYKOM A Blok','TN514',1),
                ('Konica Minolta','C250i','IP','192.168.195.32','EYKOM Danisma','TN328',1),
                ('Konica Minolta','C250i','IP','192.168.195.134','Gida Bankasi','TN328',1),
                ('Konica Minolta','C250i','IP','192.168.195.41','Temizlik Santiye','TN328',1),
                ('Konica Minolta','C300i','IP','192.168.195.229','Cevre Koruma - Alipasa','TN328',1),
                ('Konica Minolta','C250i','IP','192.168.195.36','Tarimsal Hiz. - Alipasa','TN328',1),
                ('Konica Minolta','C258','IP','192.168.195.1','Veterinerlik - Alipasa','TN324',1),
                ('Konica Minolta','C458','IP','192.168.195.226','Fen Isleri - Alipasa','TN514',1),
                ('Konica Minolta','C458','IP','192.168.195.217','Park Bahce - Alipasa','TN514',1),
                ('Konica Minolta','C458','IP','192.168.195.215','Destek Hiz. - Alipasa','TN514',1),
                ('Konica Minolta','C258','IP','LOKAL AG','Gumusyaka Turam Lise','TN324',1),
                ('Konica Minolta','C3300i','IP','192.168.195.145','ITM Ofis','TNP81',1),
                ('Canon','4000S','IP','192.168.0.45','Emlak Istimlak Ek Bina','PFI-1700',0)
            ");

            // --- USB BAGLANTILI YAZICILAR ---
            $pdo->exec("INSERT INTO `yazicilar` (`marka`,`model`,`baglanti_tipi`,`ip_adresi`,`lokasyon`,`toner_model`,`renkli`) VALUES
                ('Konica Minolta','40P','USB',NULL,'Emlak Sefligi - Sef','TN412',0),
                ('Konica Minolta','4000P','USB',NULL,'Emlak Sefligi - 1','TNP35',0),
                ('Konica Minolta','4000i','USB',NULL,'Emlak Sefligi - 2','TNP76',0),
                ('Konica Minolta','4000i','USB',NULL,'Emlak Sefligi - 3','TNP76',0),
                ('Konica Minolta','4000i','USB',NULL,'Emlak Sefligi - 4','TNP76',0),
                ('Konica Minolta','4000i','USB',NULL,'Emlak Sefligi - 5','TNP76',0),
                ('Konica Minolta','C3350','USB',NULL,'Emlak Sefligi - 6','TNP48',1),
                ('Konica Minolta','40P','USB',NULL,'Muhasebe','TN412',0),
                ('Konica Minolta','40P','USB',NULL,'Gelirler Sefligi Sef','TN412',0),
                ('Konica Minolta','4000i','USB',NULL,'Gelirler Sefligi GAC','TNP76',0),
                ('Konica Minolta','C3350','USB',NULL,'B.Cavuslu Iletisim','TNP48',1),
                ('Konica Minolta','C3350i','USB',NULL,'Selimpasa Iletisim','TNP79',1),
                ('Konica Minolta','4000P','USB',NULL,'Degirmenkoy Iletisim','TNP35',0),
                ('Konica Minolta','C3350','USB',NULL,'Kavakli Iletisim','TNP48',1),
                ('Konica Minolta','40P','USB',NULL,'Ortakoy Iletisim','TN412',0),
                ('Konica Minolta','4000i','USB',NULL,'Esra Iletisim','TNP76',0),
                ('Konica Minolta','4000i','USB',NULL,'Sevval Iletisim','TNP76',0),
                ('Konica Minolta','4000i','USB',NULL,'Alper Iletisim','TNP76',0),
                ('Konica Minolta','4000i','USB',NULL,'Eylul Iletisim','TNP35',0),
                ('Konica Minolta','4000P','USB',NULL,'Atalay Iletisim','TNP35',0),
                ('Konica Minolta','C3100P','USB',NULL,'Baskan Yrd. Cakir','TNP50',1),
                ('Konica Minolta','C3350','USB',NULL,'Araclar Kademe','TNP48',1),
                ('HP','107A','USB',NULL,'Otogar Sandalye','ST08285A',0),
                ('HP','107A','USB',NULL,'Selimpasa Kutuphane','ST08285A',0),
                ('HP','1320N','USB',NULL,'Etut Proje Kalepark','Q5949A',0),
                ('HP','DeskJet 2135','USB',NULL,'Benzinlik',NULL,1),
                ('HP','1536DNF','USB',NULL,'Kurfalli Muhtarlik','CE278',0),
                ('HP','1536DNF','USB',NULL,'B.Cavuslu Muhtarlik','CE278',0),
                ('Samsung','SCX4200','USB',NULL,'Cayirdere Muhtarlik','SV184A',0),
                ('HP','1536DNF','USB',NULL,'Sayalar Muhtarlik','CE278',0),
                ('HP','107A','USB',NULL,'Hayvan Satis Yeri','ST08285A',0),
                ('HP','107A','USB',NULL,'Yemekhane Ozlem','ST08285A',0),
                ('Pantum','P2506PRO','USB',NULL,'Vezne 1','PA210',0),
                ('Pantum','P2506PRO','USB',NULL,'Vezne 2','PA210',0),
                ('Pantum','P2506PRO','USB',NULL,'Zabita Arac','PA210',0),
                ('Tally','T5040','USB',NULL,'Evlendirme Sefligi - 1',NULL,0),
                ('Tally','T5040','USB',NULL,'Evlendirme Sefligi - 2',NULL,0),
                ('HP','M111CW','USB',NULL,'AKP Grup Odasi',NULL,0),
                ('Samsung','ML-2165','USB',NULL,'Degirmenkoy Fen Isleri',NULL,0),
                ('Zywell','ZY308','USB',NULL,'Kalepark Fis - Mutfak',NULL,0),
                ('Xprinter','DKT-8823','USB',NULL,'Kalepark Fis - Cayocagi',NULL,0)
            ");

            // ===== BIRIMLER =====
            $pdo->exec("INSERT INTO `birimler` (`birim_adi`,`sorumlu_kisi`,`telefon`) VALUES
                ('Baskanlik',NULL,NULL),
                ('Yazi Isleri Mudurlugu',NULL,NULL),
                ('Mali Hizmetler Mudurlugu',NULL,NULL),
                ('Imar ve Sehircilik Mudurlugu',NULL,NULL),
                ('Hukuk Isleri Mudurlugu',NULL,NULL),
                ('Fen Isleri Mudurlugu',NULL,NULL),
                ('Park ve Bahceler Mudurlugu',NULL,NULL),
                ('Destek Hizmetleri Mudurlugu',NULL,NULL),
                ('Zabita Mudurlugu',NULL,NULL),
                ('Emlak ve Istimlak Mudurlugu',NULL,NULL),
                ('Cevre Koruma Mudurlugu',NULL,NULL),
                ('Veterinerlik Mudurlugu',NULL,NULL),
                ('Tarimsal Hizmetler Mudurlugu',NULL,NULL),
                ('Temizlik Isleri Mudurlugu',NULL,NULL),
                ('Kultur ve Sosyal Isler Mudurlugu',NULL,NULL),
                ('Bilgi Islem Mudurlugu (ITM)',NULL,NULL),
                ('Insan Kaynaklari Mudurlugu',NULL,NULL),
                ('Grafik Tasarim Birimi',NULL,NULL),
                ('EYKOM',NULL,NULL),
                ('Iletisim Merkezleri',NULL,NULL)
            ");

            // ===== SUPER ADMIN =====
            $mevcutAdmin = $pdo->query("SELECT COUNT(*) FROM kullanicilar WHERE rol = 'super_admin'")->fetchColumn();
            if ($mevcutAdmin == 0) {
                $stmt = $pdo->prepare("INSERT INTO `kullanicilar` (`kullanici_adi`,`sifre_hash`,`ad_soyad`,`rol`) VALUES (?,?,?,'super_admin')");
                $stmt->execute([$adminAdi, password_hash($adminSifre, PASSWORD_DEFAULT), $adminAdSoyad]);
            }

            @file_put_contents($setupKilitDosyasi, date('Y-m-d H:i:s'));

            $_SESSION['bildirim'] = [
                'mesaj' => 'Kurulum basariyla tamamlandi! 57 toner, 52 yedek parca, 77 yazici kaydedildi.',
                'tur' => 'success'
            ];
            header("Location: giris.php");
            exit;

        } catch (PDOException $e) {
            $hata = 'Veritabani islemi sirasinda bir hata olustu. MySQL servisinin calistigini kontrol edin.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kurulum - Toner Takip</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="kurulum-body">
    <div class="kurulum-kutu">
        <div class="kurulum-logo">
            <img src="img/logo.svg" alt="T.C. Silivri Belediyesi" style="height:120px;">
        </div>
        <div class="kurulum-kart" style="border-top: 4px solid var(--vurgu);">
            <div class="giris-logo" style="padding-bottom: 0;">
                <h4 style="color:var(--ana); font-weight:800; font-size:1.3rem;">Toner Takip Sistemi - Kurulum</h4>
            </div>
            <div class="kurulum-icerik">
                <?php if (isset($hata)): ?>
                    <div class="bildirim bildirim-uyari-tehlike">Hata: <?= htmlspecialchars($hata, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <div class="bildirim bildirim-uyari-bilgi">
                    <strong>Bu islem su adimlari yapacaktir:</strong>
                    <ul>
                        <li><strong>toner_takip</strong> veritabanini olusturur</li>
                        <li>Gerekli tablolari olusturur (tonerler, yedek parcalar, yazicilar, birimler, hareketler, kullanicilar)</li>
                        <li>Excel envanter listesinden <strong>57 toner</strong>, <strong>52 yedek parca</strong>, <strong>77 yazici</strong> kaydini ekler</li>
                        <li>Super Admin hesabi olusturur</li>
                    </ul>
                </div>
                <div class="bildirim bildirim-uyari-dikkat">
                    <strong>Dikkat:</strong> Mevcut veritabani varsa sifirlanacaktir!
                </div>

                <hr>
                <p style="font-weight:600; margin-bottom:10px;">Super Admin Bilgileri</p>
                <form method="POST">
                    <?= csrfToken() ?>
                    <div class="form-grup">
                        <label class="form-etiket" for="admin_ad_soyad" class="form-etiket">Ad Soyad</label>
                        <input class="form-kontrol" type="text" class="form-kontrol" id="admin_ad_soyad" name="admin_ad_soyad"
                               value="<?= htmlspecialchars($_POST['admin_ad_soyad'] ?? '') ?>" required>
                    </div>
                    <div class="form-grup">
                        <label class="form-etiket" for="admin_adi" class="form-etiket">Kullanici Adi</label>
                        <input class="form-kontrol" type="text" class="form-kontrol" id="admin_adi" name="admin_adi"
                               value="<?= htmlspecialchars($_POST['admin_adi'] ?? '') ?>" required>
                    </div>
                    <div class="form-grup">
                        <label class="form-etiket" for="admin_sifre">Sifre (en az 8 karakter, buyuk/kucuk harf + rakam)</label>
                        <input class="form-kontrol" type="password" id="admin_sifre" name="admin_sifre" minlength="8" required>
                    </div>
                    <div class="form-grup">
                        <label class="form-etiket" for="onay_kelime" class="form-etiket">Guvenlik Onayi</label>
                        <input class="form-kontrol" type="text" class="form-kontrol" id="onay_kelime" name="onay_kelime"
                               placeholder="Onaylamak icin SIFIRLA yazin" required autocomplete="off">
                        <small class="kucuk-yazi">Mevcut veritabani sifirlanacaktir. Onaylamak icin <strong>SIFIRLA</strong> yazin.</small>
                    </div>
                    <p class="kucuk-yazi">Not: XAMPP'ta Apache ve MySQL calisir durumda olmali.</p>
                    <button type="submit" class="dugme dugme-ana dugme-buyuk tam-gen" style="font-weight:700; letter-spacing:0.5px;">KURULUMU BAŞLAT</button>
                </form>
            </div>
        </div>
    </div>
    <script>
        if(window.lucide) lucide.createIcons();
    </script>
</body>
</html>
