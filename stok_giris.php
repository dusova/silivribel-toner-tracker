<?php
/**
 * ============================================================
 * STOK_GIRIS.PHP - DEPOYA STOK GIRISI
 * ============================================================
 */

require_once 'config.php';
require_once 'yetki.php';
yetkiKontrol(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();

    $turu      = $_POST['turu'] ?? 'toner';
    if (!in_array($turu, ['toner', 'parca'])) {
        $turu = 'toner';
    }
    $tarih     = trim($_POST['tarih'] ?? '');
    $aciklama  = trim($_POST['aciklama'] ?? '');

    if (!tarihDogrula($tarih)) {
        bildirim('Geçersiz tarih formatı!', 'danger');
        yonlendir('stok_giris.php');
        exit;
    }

    if ($turu === 'toner') {
        $tonerId = (int) $_POST['toner_id'];
        $miktar  = (int) $_POST['miktar'];
        if ($tonerId <= 0 || $miktar <= 0 || $miktar > 9999) {
            bildirim('Toner seçin ve geçerli miktar girin (1-9999)!', 'danger');
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO hareketler (toner_id, yedek_parca_id, yazici_id, miktar, hareket_tipi, tarih, aciklama) VALUES (?, NULL, NULL, ?, 'giris', ?, ?)")
                    ->execute([$tonerId, $miktar, $tarih, $aciklama]);
                $db->prepare("UPDATE tonerler SET stok_miktari = stok_miktari + ? WHERE id = ?")->execute([$miktar, $tonerId]);
                $db->commit();
                $tonerAdi = $db->prepare("SELECT toner_kodu FROM tonerler WHERE id = ?");
                $tonerAdi->execute([$tonerId]);
                $tKodu = $tonerAdi->fetchColumn();
                logKaydet('Stok Giriş', 'Toner Giriş', "Toner stok girişi: $tKodu x$miktar adet. Tarih: $tarih" . ($aciklama ? ". Açıklama: $aciklama" : ''));
                bildirim('Toner stok girişi kaydedildi. (' . $miktar . ' adet)');
            } catch (Exception $e) {
                $db->rollBack();
                bildirim('Toner stok girişi sırasında bir hata oluştu.', 'danger');
            }
        }
    } else {
        $parcaId = (int) $_POST['parca_id'];
        $miktar  = (int) $_POST['miktar'];
        if ($parcaId <= 0 || $miktar <= 0 || $miktar > 9999) {
            bildirim('Yedek parça seçin ve geçerli miktar girin (1-9999)!', 'danger');
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO hareketler (toner_id, yedek_parca_id, yazici_id, miktar, hareket_tipi, tarih, aciklama) VALUES (NULL, ?, NULL, ?, 'giris', ?, ?)")
                    ->execute([$parcaId, $miktar, $tarih, $aciklama]);
                $db->prepare("UPDATE yedek_parcalar SET stok_miktari = stok_miktari + ? WHERE id = ?")->execute([$miktar, $parcaId]);
                $db->commit();
                $parcaAdi = $db->prepare("SELECT parca_kodu FROM yedek_parcalar WHERE id = ?");
                $parcaAdi->execute([$parcaId]);
                $pKodu = $parcaAdi->fetchColumn();
                logKaydet('Stok Giriş', 'Yedek Parça Giriş', "Yedek parça stok girişi: $pKodu x$miktar adet. Tarih: $tarih" . ($aciklama ? ". Açıklama: $aciklama" : ''));
                bildirim('Yedek parça stok girişi kaydedildi. (' . $miktar . ' adet)');
            } catch (Exception $e) {
                $db->rollBack();
                bildirim('Yedek parça stok girişi sırasında bir hata oluştu.', 'danger');
            }
        }
    }
    yonlendir('stok_giris.php');
}

$tonerler  = tumTonerleriGetir($db);
$parcalar  = tumParcalariGetir($db);

$sonGirisler = $db->query("
    SELECT h.*, t.toner_kodu, t.renk as toner_renk, p.parca_kodu, p.parca_tipi
    FROM hareketler h
    LEFT JOIN tonerler t ON h.toner_id = t.id
    LEFT JOIN yedek_parcalar p ON h.yedek_parca_id = p.id
    WHERE h.hareket_tipi = 'giris'
    ORDER BY h.olusturma_tarihi DESC
    LIMIT 20
")->fetchAll();

$sayfaBasligi = 'Stok Giriş';
require_once 'header.php';
?>

<div class="sayfa-ust stagger-item">
    <div>
        <h2>Stok Giriş</h2>
        <p class="metin-soluk ub-1">Depoya toner veya yedek parça girişi yapın.</p>
    </div>
</div>

<div class="satir ab-3">
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-1">
        <div class="kart stat-kart aksan-yesil">
            <div class="stat-etiket"><i data-lucide="package"></i> Toner Çeşidi</div>
            <div class="stat-deger"><?= count($tonerler) ?></div>
        </div>
    </div>
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-2">
        <div class="kart stat-kart aksan-mavi">
            <div class="stat-etiket"><i data-lucide="settings-2"></i> Yedek Parça</div>
            <div class="stat-deger"><?= count($parcalar) ?></div>
        </div>
    </div>
    <div class="sutun-orta-4 sutun-6 ab-2 stagger-item stagger-delay-3">
        <div class="kart stat-kart aksan-turuncu">
            <div class="stat-etiket"><i data-lucide="clock"></i> Son Girişler</div>
            <div class="stat-deger"><?= count($sonGirisler) ?></div>
            <div class="stat-alt">Son 20 kayıt</div>
        </div>
    </div>
</div>

<div class="satir">
    <div class="sutun-buyuk-5 ab-3 stagger-item stagger-delay-1">

        <div class="sekmeler">
            <button class="sekme-dugme aktif" onclick="sekmeGoster('toner',this)">Toner</button>
            <button class="sekme-dugme" onclick="sekmeGoster('parca',this)">Yedek Parça</button>
        </div>

        <!-- TONER FORMU -->
        <div class="sekme-icerik aktif" id="sekme-toner">
            <div class="kart">
                <div class="kart-baslik"><span>Toner Stok Girişi</span></div>
                <div class="kart-icerik">
                    <form method="POST">
                        <?= csrfToken() ?>
                        <input type="hidden" name="turu" value="toner">
                        <div class="ab-3">
                            <label class="form-etiket">Toner *</label>
                            <select class="form-secim" name="toner_id" required>
                                <option value="">-- Toner Seçin --</option>
                                <?php
                                $oncekiModel = '';
                                foreach ($tonerler as $t):
                                    if ($oncekiModel && $oncekiModel !== $t['toner_model']): ?>
                                        <option disabled>──────────</option>
                                    <?php endif; $oncekiModel = $t['toner_model']; ?>
                                    <option value="<?= $t['id'] ?>">
                                        <?= temizle($t['toner_kodu']) ?> (<?= temizle($t['renk']) ?>) - Stok: <?= $t['stok_miktari'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Miktar *</label>
                            <input type="number" class="form-alan" name="miktar" min="1" max="9999" placeholder="Kaç adet?" required>
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Tarih *</label>
                            <input type="date" class="form-alan" name="tarih" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Açıklama</label>
                            <textarea class="form-alan" name="aciklama" rows="2" placeholder="Fatura no, tedarikçi bilgisi vb."></textarea>
                        </div>
                        <button type="submit" class="dugme dugme-basari tam-gen">Toner Stok Girişi Kaydet</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- YEDEK PARCA FORMU -->
        <div class="sekme-icerik" id="sekme-parca">
            <div class="kart">
                <div class="kart-baslik"><span>Yedek Parça Stok Girişi</span></div>
                <div class="kart-icerik">
                    <form method="POST">
                        <?= csrfToken() ?>
                        <input type="hidden" name="turu" value="parca">
                        <div class="ab-3">
                            <label class="form-etiket">Yedek Parça *</label>
                            <select class="form-secim" name="parca_id" required>
                                <option value="">-- Yedek Parça Seçin --</option>
                                <?php
                                $oncekiTip = '';
                                foreach ($parcalar as $p):
                                    if ($oncekiTip && $oncekiTip !== $p['parca_tipi']): ?>
                                        <option disabled>──────────</option>
                                    <?php endif; $oncekiTip = $p['parca_tipi']; ?>
                                    <option value="<?= $p['id'] ?>">
                                        <?= temizle($p['parca_kodu']) ?> (<?= temizle($p['parca_tipi']) ?>) - Stok: <?= $p['stok_miktari'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Miktar *</label>
                            <input type="number" class="form-alan" name="miktar" min="1" max="9999" placeholder="Kaç adet?" required>
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Tarih *</label>
                            <input type="date" class="form-alan" name="tarih" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="ab-3">
                            <label class="form-etiket">Açıklama</label>
                            <textarea class="form-alan" name="aciklama" rows="2" placeholder="Fatura no, tedarikçi bilgisi vb."></textarea>
                        </div>
                        <button type="submit" class="dugme dugme-basari tam-gen">Yedek Parça Stok Girişi Kaydet</button>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <div class="sutun-buyuk-7 ab-3 stagger-item stagger-delay-2">
        <div class="kart">
            <div class="kart-baslik"><span>Son Stok Giriş Kayıtları</span></div>
            <div class="kart-icerik ic-0">
                <?php if (count($sonGirisler) > 0): ?>
                    <table class="tablo">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Kalem</th>
                                <th>Tür</th>
                                <th>Adet</th>
                                <th>Açıklama</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sonGirisler as $g): ?>
                                <tr>
                                    <td><?= tarihFormatla($g['tarih']) ?></td>
                                    <td>
                                        <?php if ($g['toner_kodu']): ?>
                                            <strong><?= temizle($g['toner_kodu']) ?></strong>
                                            <small class="metin-soluk">(<?= temizle($g['toner_renk']) ?>)</small>
                                        <?php else: ?>
                                            <strong><?= temizle($g['parca_kodu']) ?></strong>
                                            <small class="metin-soluk">(<?= temizle($g['parca_tipi']) ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $g['toner_id'] ? '<span class="rozet renk-ana">Toner</span>' : '<span class="rozet renk-ikincil">Yedek Parça</span>' ?>
                                    </td>
                                    <td><span class="rozet renk-basari"><?= $g['miktar'] ?></span></td>
                                    <td><small><?= temizle($g['aciklama']) ?: '-' ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="metin-orta metin-soluk ic-3">Henüz stok girişi yapılmamış</p>
                <?php endif; ?>
            </div>
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
