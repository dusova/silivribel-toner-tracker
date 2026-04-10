<?php
/**
 * ============================================================
 * LOGLAR_EXCEL.PHP - LOG KAYITLARINI EXCEL'E AKTAR
 * ============================================================
 *
 * loglar.php ile ayni filtreleri alir, limit koymadan
 * tum eslesen kayitlari guzek formatlanmis SpreadsheetML
 * (Excel XML - .xls) olarak indirir.
 *
 * Yetki: super_admin
 */

require_once 'config.php';
require_once 'yetki.php';
yetkiKontrol(['super_admin']);

// OPCache temizle — IIS'te eski compile edilmis kod kalmasin
if (function_exists('opcache_reset')) { opcache_reset(); }

// --- FILTRELERI OKU (loglar.php ile ayni parametreler) ---
$filtreModul     = trim($_GET['modul']     ?? '');
$filtreKullanici = trim($_GET['kullanici'] ?? '');
$filtreTarihBas  = trim($_GET['tarih_bas'] ?? '');
$filtreTarihBit  = trim($_GET['tarih_bit'] ?? '');
$filtreArama     = trim($_GET['arama']     ?? '');

// Tarih dogrulama
if ($filtreTarihBas && !tarihDogrula($filtreTarihBas)) $filtreTarihBas = '';
if ($filtreTarihBit && !tarihDogrula($filtreTarihBit)) $filtreTarihBit = '';

// --- WHERE KOSULU ---
$where  = [];
$params = [];

if ($filtreModul) {
    $where[] = 'modul = ?';
    $params[] = $filtreModul;
}
if ($filtreKullanici) {
    $where[] = 'kullanici_adi = ?';
    $params[] = $filtreKullanici;
}
if ($filtreTarihBas) {
    $where[] = 'olusturma_tarihi >= ?';
    $params[] = $filtreTarihBas . ' 00:00:00';
}
if ($filtreTarihBit) {
    $where[] = 'olusturma_tarihi <= ?';
    $params[] = $filtreTarihBit . ' 23:59:59';
}
if ($filtreArama) {
    $esc      = str_replace(['%', '_'], ['\\%', '\\_'], $filtreArama);
    $where[]  = '(detay LIKE ? OR islem LIKE ?)';
    $params[] = "%$esc%";
    $params[] = "%$esc%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- TUM KAYITLARI CEK (pagination yok) ---
$stmt = $db->prepare("SELECT * FROM sistem_loglari $whereSQL ORDER BY olusturma_tarihi DESC");
$stmt->execute($params);
$loglar = $stmt->fetchAll();

$toplamKayit = count($loglar);

// --- FILTRE OZET METNI ---
$filtreMetni = [];
if ($filtreModul)     $filtreMetni[] = 'Modül: ' . $filtreModul;
if ($filtreKullanici) $filtreMetni[] = 'Kullanıcı: ' . $filtreKullanici;
if ($filtreTarihBas)  $filtreMetni[] = 'Başlangıç: ' . date('d.m.Y', strtotime($filtreTarihBas));
if ($filtreTarihBit)  $filtreMetni[] = 'Bitiş: ' . date('d.m.Y', strtotime($filtreTarihBit));
if ($filtreArama)     $filtreMetni[] = 'Arama: ' . $filtreArama;
$filtreOzet = $filtreMetni ? implode('  |  ', $filtreMetni) : 'Tüm Kayıtlar';

// --- HTTP BASLIK ---
$dosyaAdi = 'log_kayitlari_' . date('Y-m-d') . '.xls';
// Content-Disposition injection koruması: sadece güvenli karakterler
$dosyaAdi = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $dosyaAdi);
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $dosyaAdi . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

// --- YARDIMCI FONKSIYONLAR ---

/**
 * xlSanitize — Formula/CSV Injection koruması + XML escape
 *
 * SpreadsheetML'de ss:Type="String" zaten formülü önlemeli,
 * ama bazı Excel sürümleri bunu görmezden gelebilir.
 * Çift koruma: tehlikeli karakterlerin önüne boşluk eklenir.
 * Boşluk log okunabilirliğini bozmaz, formülü tamamen iptal eder.
 */
function xlSanitize($v) {
    $v = (string)($v ?? '');
    // Formül başlangıç karakterlerini nötralize et:
    // Excel'in formül olarak yorumladığı karakterler (OWASP listesi)
    if (strlen($v) > 0 && in_array($v[0], ['=', '+', '-', '@', "\t", "\r", '|', '%', '"', ';'])) {
        // Başa boşluk ekle: formülü kesinlikle iptal eder, metin okunabilir kalır
        $v = ' ' . $v;
    }
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Geriye dönük uyumluluk için xlStr de xlSanitize kullanır */
function xlStr($v) {
    return xlSanitize($v);
}

function xlCell($val, $style = 'sN') {
    return '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . xlSanitize($val) . '</Data></Cell>';
}
function xlRowStyle($islem) {
    if (strpos((string)$islem, 'Başarısız') !== false || strpos((string)$islem, 'Silme') !== false) return 'sTeh';
    if (strpos((string)$islem, 'Giriş') !== false    || strpos((string)$islem, 'Ekleme') !== false) return 'sBas';
    return 'sN';
}

// Satirlari grupla: normal / alternatif
$satir = 0;

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns="urn:schemas-microsoft-com:office:spreadsheet">

  <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
    <Title>Log Kayıtları</Title>
    <Author>Toner Takip Sistemi – Silivri Belediyesi Bilgi İşlem</Author>
    <Created><?= date('Y-m-d\TH:i:s\Z') ?></Created>
  </DocumentProperties>

  <Styles>
    <!-- Ana baslik satiri -->
    <Style ss:ID="sTitle">
      <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="14" ss:FontName="Calibri"/>
      <Interior ss:Color="#1e3a8a" ss:Pattern="Solid"/>
    </Style>
    <!-- Filtre/meta satiri -->
    <Style ss:ID="sMeta">
      <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
      <Font ss:Italic="1" ss:Color="#1e3a8a" ss:Size="9" ss:FontName="Calibri"/>
      <Interior ss:Color="#dbeafe" ss:Pattern="Solid"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#93c5fd"/>
      </Borders>
    </Style>
    <!-- Sutun basliklari -->
    <Style ss:ID="sH">
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="10" ss:FontName="Calibri"/>
      <Interior ss:Color="#1e40af" ss:Pattern="Solid"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#1d4ed8"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#3b82f6"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#3b82f6"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#3b82f6"/>
      </Borders>
    </Style>
    <!-- Normal satir (beyaz) -->
    <Style ss:ID="sN">
      <Alignment ss:Vertical="Center" ss:WrapText="0"/>
      <Font ss:Size="9" ss:FontName="Calibri" ss:Color="#111827"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#e5e7eb"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#e5e7eb"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#e5e7eb"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#e5e7eb"/>
      </Borders>
    </Style>
    <!-- Alternatif satir (acik mavi) -->
    <Style ss:ID="sA">
      <Alignment ss:Vertical="Center" ss:WrapText="0"/>
      <Font ss:Size="9" ss:FontName="Calibri" ss:Color="#111827"/>
      <Interior ss:Color="#eff6ff" ss:Pattern="Solid"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#bfdbfe"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#bfdbfe"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#bfdbfe"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#bfdbfe"/>
      </Borders>
    </Style>
    <!-- Tehlike satiri: basarisiz giris / silme (acik kirmizi) -->
    <Style ss:ID="sTeh">
      <Alignment ss:Vertical="Center" ss:WrapText="0"/>
      <Font ss:Size="9" ss:FontName="Calibri" ss:Color="#991b1b"/>
      <Interior ss:Color="#fef2f2" ss:Pattern="Solid"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#fca5a5"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#fca5a5"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#fca5a5"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#fca5a5"/>
      </Borders>
    </Style>
    <!-- Basari satiri: giris / ekleme (acik yesil) -->
    <Style ss:ID="sBas">
      <Alignment ss:Vertical="Center" ss:WrapText="0"/>
      <Font ss:Size="9" ss:FontName="Calibri" ss:Color="#166534"/>
      <Interior ss:Color="#f0fdf4" ss:Pattern="Solid"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#86efac"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#86efac"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#86efac"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#86efac"/>
      </Borders>
    </Style>
    <!-- Detay hucre (wrap text acik) -->
    <Style ss:ID="sD">
      <Alignment ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Size="9" ss:FontName="Calibri" ss:Color="#374151"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#e5e7eb"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#e5e7eb"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#e5e7eb"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#e5e7eb"/>
      </Borders>
    </Style>
    <Style ss:ID="sDA">
      <Alignment ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Size="9" ss:FontName="Calibri" ss:Color="#374151"/>
      <Interior ss:Color="#eff6ff" ss:Pattern="Solid"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#bfdbfe"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#bfdbfe"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#bfdbfe"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#bfdbfe"/>
      </Borders>
    </Style>
  </Styles>

  <Worksheet ss:Name="Log Kayıtları">
    <Table>
      <Column ss:Index="1" ss:Width="35"/>
      <Column ss:Index="2" ss:Width="85"/>
      <Column ss:Index="3" ss:Width="80"/>
      <Column ss:Index="4" ss:Width="100"/>
      <Column ss:Index="5" ss:Width="85"/>
      <Column ss:Index="6" ss:Width="110"/>
      <Column ss:Index="7" ss:Width="270"/>

      <!-- Baslik satiri -->
      <Row ss:Height="32">
        <Cell ss:StyleID="sTitle" ss:MergeAcross="6">
          <Data ss:Type="String">🖨 Silivri Belediyesi Bilgi İşlem – Sistem Log Kayıtları</Data>
        </Cell>
      </Row>

      <!-- Filtre ve ozet bilgisi -->
      <Row ss:Height="20">
        <Cell ss:StyleID="sMeta" ss:MergeAcross="3">
          <Data ss:Type="String">Filtre: <?= xlStr($filtreOzet) ?></Data>
        </Cell>
        <Cell ss:StyleID="sMeta" ss:MergeAcross="2">
          <Data ss:Type="String">Toplam: <?= $toplamKayit ?> kayıt  |  Oluşturulma: <?= date('d.m.Y H:i') ?></Data>
        </Cell>
      </Row>

      <!-- Bos ayrac -->
      <Row ss:Height="6"><Cell ss:StyleID="sN"/></Row>

      <!-- Sutun basliklari -->
      <Row ss:Height="22">
        <?= xlCell('#',           'sH') ?>
        <?= xlCell('Tarih',       'sH') ?>
        <?= xlCell('Saat',        'sH') ?>
        <?= xlCell('Kullanıcı',   'sH') ?>
        <?= xlCell('IP Adresi',   'sH') ?>
        <?= xlCell('Modül / İşlem','sH') ?>
        <?= xlCell('Detay',       'sH') ?>
      </Row>

      <?php foreach ($loglar as $i => $log):
          $satir++;
          $isAlt   = ($satir % 2 === 0);
          $rowStyle = xlRowStyle($log['islem']);
          // detay hucre: tehlike/basari renk de koru, alternatif detay
          $detayStyle = ($rowStyle !== 'sN') ? $rowStyle : ($isAlt ? 'sDA' : 'sD');
          $baseStyle  = ($rowStyle !== 'sN') ? $rowStyle : ($isAlt ? 'sA' : 'sN');

          $tarih    = !empty($log['olusturma_tarihi']) ? date('d.m.Y', strtotime($log['olusturma_tarihi'])) : '-';
          $saat     = !empty($log['olusturma_tarihi']) ? date('H:i:s', strtotime($log['olusturma_tarihi'])) : '-';
          $kullanici = trim(($log['kullanici_adi'] ?? '') . "\n" . ($log['ad_soyad'] ?? ''));
          $modulIslem = ($log['modul'] ?? '') . ' / ' . ($log['islem'] ?? '');
      ?>
      <Row ss:Height="18">
        <?= xlCell($satir,         $baseStyle) ?>
        <?= xlCell($tarih,         $baseStyle) ?>
        <?= xlCell($saat,          $baseStyle) ?>
        <?= xlCell($kullanici,     $baseStyle) ?>
        <?= xlCell($log['ip_adresi'] ?? '-', $baseStyle) ?>
        <?= xlCell($modulIslem,    $baseStyle) ?>
        <?= xlCell($log['detay'] ?? '', $detayStyle) ?>
      </Row>
      <?php endforeach; ?>

      <?php if ($toplamKayit === 0): ?>
      <Row>
        <Cell ss:StyleID="sN" ss:MergeAcross="6">
          <Data ss:Type="String">Hiç kayıt bulunamadı.</Data>
        </Cell>
      </Row>
      <?php endif; ?>

    </Table>

    <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
      <Selected/>
      <FreezePanes/>
      <FrozenNoSplit/>
      <SplitHorizontal>4</SplitHorizontal>
      <TopRowBottomPane>4</TopRowBottomPane>
      <ActivePane>2</ActivePane>
      <Panes>
        <Pane><Number>3</Number></Pane>
        <Pane><Number>2</Number><ActiveRow>0</ActiveRow></Pane>
      </Panes>
      <Print>
        <ValidPrinterInfo/>
        <HorizontalResolution>600</HorizontalResolution>
        <VerticalResolution>600</VerticalResolution>
        <FitWidth>1</FitWidth>
        <FitHeight>0</FitHeight>
      </Print>
    </WorksheetOptions>
  </Worksheet>
</Workbook>
