# Destek — Ticket / Yardım Masası Sistemi

Çok kaynaklı, rol tabanlı bir **destek talebi (ticket) yönetim paneli**. PHP 8.3 + MSSQL üzerine kurulu; AdminLTE 4 arayüzü, API entegrasyonu, çok kanallı bildirim (e‑posta / WhatsApp / Web Push) ve kurulabilir PWA desteği içerir.

> ⚠️ Bu depo, üretimdeki bir sistemin **maskeli** (örnek alan adı / IP / firma bilgisi) genel kopyasıdır. `config/` ve sırlar dahil değildir; `config/database.example.php` örnek olarak verilmiştir.

---

## Öne çıkan özellikler

| Alan | Açıklama |
|---|---|
| 🎫 **Ticket yönetimi** | Talep oluşturma, mesaj akışı, iç notlar, durum/öncelik/kategori, atama |
| ✅ **Alt görevler** | Ticket içinde checklist (ilerleme çubuğu, atama, durum) |
| 🔀 **Birleştir / Ayır** | Talepleri birleştirme ve mesaj/içerik bazlı ayırma |
| 👥 **CC & kaynak-bazlı yetki** | Bilgi (CC) listesi; kaynak ve atanan bazlı görünürlük yetkileri |
| 🔌 **API entegrasyonu** | Harici site/uygulamalardan merkezi sisteme ticket açma (kaynak + API key) |
| ✉️ **Çok kanallı bildirim** | E‑posta, WhatsApp ve **Web Push** (VAPID, bağımlılıksız) — şablon motoru |
| 📱 **Kurulabilir PWA** | Dinamik manifest, service worker (offline), masaüstü/mobil bildirim |
| 📝 **Cevap şablonları** | Değişkenli hazır cevaplar ({{ticket_no}}, {{konu}} …), Select2 ile aranabilir |
| ⏱️ **Cron yönetimi** | Zamanlanmış görevler panelden yönetilir; çalışma geçmişi + kilit + telafi |
| 🗂️ **Sürüm notları** | Panelden sürüm geçmişi takibi |

## Teknoloji

- **Backend:** PHP 8.3 (framework yok), MSSQL (`sqlsrv`)
- **Frontend:** AdminLTE 4, server-side DataTables, Select2, SweetAlert2
- **PWA/Push:** VAPID (ES256) + aes128gcm — yalnız openssl/curl, composer paketi gerektirmez

## Dizin yapısı

```
admin/            Yönetim paneli (pages, includes, api, cron, assets)
api/              Dış entegrasyon API'si (ticket açma, dokümantasyon)
config/           database.example.php (gerçek config hariç)
includes/         Uygulama bootstrap
index.php         Giriş
```

## Kurulum (özet)

1. `config/database.example.php` → `config/database.php` olarak kopyalayın, MSSQL bilgilerinizi girin.
2. `composer install`
3. Web kökünü `index.php`'ye yönlendirin (IIS `web.config` dahildir).
4. Veritabanı şemasını kurun, ilk yöneticiyi tanımlayın.

## Notlar

- Alan adı, IP, firma adı ve e‑posta değerleri örnektir (maskeli sürüm).
- Üretim ortamında DB şifresi ve diğer sırlar `config/` altında, web kökü dışına çıkarılarak tutulur.
- `.gitignore`: `vendor/`, `config/database.php`, loglar sürüm kontrolüne girmez.
