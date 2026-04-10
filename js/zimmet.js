/**
 * ============================================================
 * ZIMMET.JS - DAGITIM SAYFASI ISLEVSELLIGI
 * ============================================================
 *
 * Yazici secildiginde uyumlu toner/yedek parca butonlarini olusturur,
 * secim ve miktar yonetimini saglar, form verisini JSON olarak hazirlar.
 *
 * Gerekli global degiskenler (PHP tarafindan sayfada render edilmeli):
 *   - window.zimmetTonerler  : Toner listesi (JSON)
 *   - window.zimmetParcalar  : Yedek parca listesi (JSON)
 */

(function () {
    'use strict';

    var tonerler = window.zimmetTonerler || [];
    var parcalar = window.zimmetParcalar || [];

    var renkCss = {
        'Cyan':    { bg: '#0dcaf0', text: '#000' },
        'Magenta': { bg: '#FF00FF', text: '#fff' },
        'Yellow':  { bg: '#ffc107', text: '#000' },
        'Black':   { bg: '#212529', text: '#fff' },
        'Siyah':   { bg: '#212529', text: '#fff' },
        'Renkli':  { bg: '#6f42c1', text: '#fff' },
        '-':       { bg: '#6c757d', text: '#fff' }
    };

    var secilenler = { toners: {}, parcas: {} };

    // --- Form verisini guncelle ---
    function guncelleDagitimData() {
        var items = [];
        Object.entries(secilenler.toners).forEach(function (e) {
            if (e[1] > 0) items.push({ tip: 'toner', id: parseInt(e[0]), miktar: e[1] });
        });
        Object.entries(secilenler.parcas).forEach(function (e) {
            if (e[1] > 0) items.push({ tip: 'parca', id: parseInt(e[0]), miktar: e[1] });
        });
        document.getElementById('dagitimData').value = JSON.stringify(items);
        document.getElementById('dagitBtn').disabled = items.length === 0;
    }

    // --- Toner butonu olustur ---
    function tonerButonuOlustur(t) {
        var r = renkCss[t.renk] || renkCss['Siyah'];
        var div = document.createElement('div');
        div.className = 'esnek hizala-orta bosluk-1 esnek-sar ab-1';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dugme dugme-kucuk renk-toner-dugme';
        btn.dataset.id = t.id;
        btn.style.cssText = 'border:2px solid ' + r.bg + ';color:' + r.bg + ';background:transparent;padding:4px 12px;min-width:90px;';
        btn.textContent = t.toner_kodu;

        var inp = document.createElement('input');
        inp.type = 'number';
        inp.className = 'form-alan form-alan-kucuk miktar-input';
        inp.dataset.id = t.id;
        inp.dataset.tip = 'toner';
        inp.min = '0';
        inp.value = '0';
        inp.style.width = '55px';
        inp.title = 'Adet';

        var small = document.createElement('small');
        small.className = 'metin-soluk';
        small.textContent = 'Stok:' + t.stok_miktari;

        div.appendChild(btn);
        div.appendChild(inp);
        div.appendChild(small);

        function guncelle() {
            var v = Math.max(0, parseInt(inp.value) || 0);
            inp.value = v;
            if (v > 0) secilenler.toners[t.id] = v;
            else delete secilenler.toners[t.id];
            btn.style.background = v > 0 ? r.bg : 'transparent';
            btn.style.color = v > 0 ? r.text : r.bg;
            guncelleDagitimData();
        }

        btn.addEventListener('click', function () {
            inp.value = (parseInt(inp.value) || 0) + 1;
            guncelle();
        });
        inp.addEventListener('change', guncelle);
        inp.addEventListener('input', guncelle);

        return div;
    }

    // --- Yedek parca butonu olustur ---
    function parcaButonuOlustur(p) {
        var div = document.createElement('div');
        div.className = 'esnek hizala-orta bosluk-1 esnek-sar ab-1';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dugme dugme-kucuk dugme-cizgi-ikincil parca-dugme';
        btn.dataset.id = p.id;
        btn.style.minWidth = '140px';
        btn.textContent = p.parca_kodu;

        var inp = document.createElement('input');
        inp.type = 'number';
        inp.className = 'form-alan form-alan-kucuk miktar-input';
        inp.dataset.id = p.id;
        inp.dataset.tip = 'parca';
        inp.min = '0';
        inp.value = '0';
        inp.style.width = '55px';

        var small = document.createElement('small');
        small.className = 'metin-soluk';
        small.textContent = 'Stok:' + p.stok_miktari;

        div.appendChild(btn);
        div.appendChild(inp);
        div.appendChild(small);

        function guncelle() {
            var v = Math.max(0, parseInt(inp.value) || 0);
            inp.value = v;
            if (v > 0) secilenler.parcas[p.id] = v;
            else delete secilenler.parcas[p.id];
            btn.classList.toggle('dugme-koyu', v > 0);
            guncelleDagitimData();
        }

        btn.addEventListener('click', function () {
            inp.value = (parseInt(inp.value) || 0) + 1;
            guncelle();
        });
        inp.addEventListener('change', guncelle);
        inp.addEventListener('input', guncelle);

        return div;
    }

    // --- Yazici secildiginde uyumlu kalemleri goster ---
    document.getElementById('yaziciSec').addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        var tonerModel = opt.dataset.tonerModel || '';
        var yaziciModel = opt.dataset.model || '';
        var lokasyon = opt.dataset.lokasyon || '';

        // Secimleri sifirla
        secilenler.toners = {};
        secilenler.parcas = {};
        document.getElementById('tonerButonlari').textContent = '';
        document.getElementById('parcaButonlari').textContent = '';
        document.getElementById('tonerSecArea').style.display = 'none';
        document.getElementById('parcaSecArea').style.display = 'none';
        document.getElementById('dagitBtn').disabled = true;

        if (!this.value) {
            document.getElementById('yaziciBilgi').style.display = 'none';
            return;
        }

        // Yazici bilgilerini goster
        document.getElementById('bilgiLokasyon').textContent = lokasyon;
        document.getElementById('bilgiModel').textContent = opt.dataset.model;
        document.getElementById('yaziciBilgi').style.display = 'block';

        // Uyumlu tonerler
        var uyumluToners = tonerModel
            ? tonerler.filter(function (t) { return t.toner_model === tonerModel; })
            : [];

        if (uyumluToners.length > 0) {
            document.getElementById('tonerSecArea').style.display = 'block';
            var tonerKonteyner = document.getElementById('tonerButonlari');
            uyumluToners.forEach(function (t) {
                tonerKonteyner.appendChild(tonerButonuOlustur(t));
            });
        }

        // Uyumlu yedek parcalar
        var uyumluParcas = parcalar.filter(function (p) {
            if (!p.uyumlu_modeller || !yaziciModel) return false;
            var pm = p.uyumlu_modeller.split(',').map(function (m) { return m.trim(); });
            return pm.includes(yaziciModel);
        });

        if (uyumluParcas.length > 0) {
            document.getElementById('parcaSecArea').style.display = 'block';
            var parcaKonteyner = document.getElementById('parcaButonlari');
            uyumluParcas.forEach(function (p) {
                parcaKonteyner.appendChild(parcaButonuOlustur(p));
            });
        }
    });
})();
