            </main>

            <footer class="site-footer">
                T.C. Silivri Belediyesi Bilgi İşlem Müdürlüğü &middot; Toner Takip Sistemi &copy; <?= date('Y') ?>
            </footer>
        </div><!-- /.ana-icerik -->
    </div><!-- /.dashboard-kapsayici -->

    <script>
    (function(){
        /* ── Icons ── */
        if (window.lucide) lucide.createIcons();

        /* ── Theme toggle ── */
        var temaDugme = document.getElementById('temaDegistir');
        var html = document.documentElement;

        function temaIkonGuncelle() {
            if (!temaDugme) return;
            var karanlik = html.getAttribute('data-tema') === 'karanlik';
            temaDugme.innerHTML = karanlik
                ? '<i data-lucide="sun"></i>'
                : '<i data-lucide="moon"></i>';
            if (window.lucide) lucide.createIcons();
        }

        temaIkonGuncelle();

        if (temaDugme) {
            temaDugme.addEventListener('click', function () {
                var karanlik = html.getAttribute('data-tema') === 'karanlik';
                if (karanlik) {
                    html.removeAttribute('data-tema');
                    localStorage.setItem('tema', 'aydinlik');
                } else {
                    html.setAttribute('data-tema', 'karanlik');
                    localStorage.setItem('tema', 'karanlik');
                }
                temaIkonGuncelle();
            });
        }

        /* ── Mobile sidebar ── */
        var menuToggle   = document.getElementById('menuToggle');
        var sidebarKapat = document.getElementById('sidebarKapat');
        var overlay      = document.getElementById('sidebarOverlay');
        var sidebar      = document.getElementById('sidebar');

        function sidebarAc() {
            if (sidebar)  sidebar.classList.add('acik');
            if (overlay)  overlay.classList.add('aktif');
            document.body.style.overflow = 'hidden';
        }
        function sidebarKapatFn() {
            if (sidebar)  sidebar.classList.remove('acik');
            if (overlay)  overlay.classList.remove('aktif');
            document.body.style.overflow = '';
        }

        if (menuToggle)   menuToggle.addEventListener('click', sidebarAc);
        if (sidebarKapat) sidebarKapat.addEventListener('click', sidebarKapatFn);
        if (overlay)      overlay.addEventListener('click', sidebarKapatFn);

        /* Close sidebar on Escape */
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') sidebarKapatFn();
        });

        /* ── Alert close buttons ── */
        document.querySelectorAll('.uyari .dugme-kapat, .bildirim .dugme-kapat').forEach(function(b) {
            b.addEventListener('click', function() {
                var parent = b.closest('.uyari, .bildirim');
                if (parent) parent.remove();
            });
        });
    })();
    </script>
</body>
</html>
