<!--
  Destek Talepleri Header Bildirim Mimarisi
  Kaynak: {{KAYNAK_AD}}
  Bu dosya, yönetim panellerinin header (navbar) kısmında bildirim çanının veya profilin yanına dahil (include) edilmelidir.
  
  MİMARİ ÖZETİ:
  - Frontend, `destek-bildirim.php` (proxy) dosyasına her 60 sn'de bir AJAX atar.
  - Proxy, API Key'i sunucu tarafında koruyarak dışarıya sızdırmadan kendi API'mizle haberleşir.
  - Tarayıcının localStorage alanında tutulan `destek_son_kontrol` değeri baz alınarak (String kıyası) zaman dilimi (Timezone) sorunları yaşanmadan "Okunmamış Yeni Yanıt" tespiti yapılır.
  - Eğer yeni yanıt varsa Header'daki Dropdown içine bu biletler yerleştirilir ve badge sayısı artırılır.
-->
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="destekDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-headset fs-5"></i>
        <!-- Yeni Bildirim Rozeti (Badge) -->
        <span id="destek-header-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" style="font-size: 0.65rem;">
            0
        </span>
    </a>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="destekDropdown" style="width: 320px; max-height: 400px; overflow-y: auto;">
        <li><h6 class="dropdown-header">Destek Talepleri</h6></li>
        <!-- AJAX ile doldurulacak liste -->
        <div id="destek-header-liste">
            <li><span class="dropdown-item text-muted small">Yeni bildirim yok</span></li>
        </div>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-center small text-primary fw-bold" href="destek.php">Tüm Talepleri Gör</a></li>
    </ul>
</li>

<script>
document.addEventListener("DOMContentLoaded", function() {
    function destekKontrolEt() {
        // localStorage üzerinde son okuma tarihi tutulur. (Zaman dilimi uyumsuzluğunu önlemek için doğrudan String olarak karşılaştırılır)
        var sonKontrol = localStorage.getItem('destek_son_kontrol') || '';
        
        fetch('destek-bildirim.php?son_kontrol=' + encodeURIComponent(sonKontrol))
            .then(res => res.json())
            .then(data => {
                if (!data.success) return;
                
                var badge = document.getElementById('destek-header-badge');
                var liste = document.getElementById('destek-header-liste');
                
                if (data.yeni_sayisi > 0) {
                    badge.textContent = data.yeni_sayisi;
                    badge.classList.remove('d-none');
                    // Badge üzerine titreşim veya dikkat çekici küçük animasyon (isteğe bağlı eklenebilir)
                    
                    var html = '';
                    data.ticketler.forEach(function(t) {
                        html += '<li><a class="dropdown-item py-2" href="destek-detay.php?id=' + t.id + '">' +
                                '<div class="fw-bold small text-truncate">#' + t.no + ' ' + t.konu + '</div>' +
                                '<div class="text-muted" style="font-size:0.75rem;"><i class="bi bi-clock me-1"></i>' + t.son_yanit + '</div>' +
                                '</a></li>';
                    });
                    liste.innerHTML = html;
                } else {
                    badge.classList.add('d-none');
                    liste.innerHTML = '<li><span class="dropdown-item text-muted small">Yeni bildirim yok</span></li>';
                }
            }).catch(err => console.error('Destek Bildirim Proxy Hatası:', err));
    }
    
    // Sayfa yüklendiğinde bir kez kontrol et ve ardından 60 saniyede bir polling (sorgulama) yap
    destekKontrolEt();
    setInterval(destekKontrolEt, 60000);
});
</script>
