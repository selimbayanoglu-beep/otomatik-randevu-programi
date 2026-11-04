# Otomatik Randevu Programı (v3.0.0) — Kurulum ve Kullanım Rehberi

Bu program, WordPress siteniz üzerinden danışanların **kendi iradesiyle onaylayıp WhatsApp grubuna katıldığı, gün ve saat önerdiği; Danışman ve Sekreter Hanım ile 3 kişi mutabık kalana kadar müzakerenin sürdüğü, mutabakat sağlandığında sitenin takviminde o gün ve saatin otomatik kapatıldığı ve e-posta bildirimlerinin gönderildiği** tam teşekküllü bir randevu sistemidir.

---

## 🌟 Sistem Nasıl Çalışır? (5 Aşamalı Akış)

1. **Siteden Başlatma (`[otomatik_randevu]`):**
   - Randevu almak isteyen danışan web sitenizdeki sayfaya girer.
2. **Kendi İradesiyle Gruba Katılım Beyanı & Onayı:**
   - Danışan, koordinasyon WhatsApp grubuna kendi hür iradesiyle katıldığını belirten hatırlatmayı okur ve onay kutusunu işaretler.
   - Ardından WhatsApp davet bağlantısına tıklayarak otomatik olarak gruba girer.
3. **İlk Tarih & Saat Teklifi:**
   - Danışan sitedeki boş saatlerden bir gün ve saat önerir.
4. **3'lü Mutabakat Masası (Danışan + Danışman + Sekreter Hanım):**
   - Danışman ve Sekreter Hanım öneriyi kabul ederse randevu **otomatik olarak onaylanır**.
   - Eğer taraflardan biri onaylamazsa, **karşı tarih ve saat önerisinde bulunur**.
   - 3 kişi de aynı tarih ve saatte mutabık kalana kadar sistem öneri döngüsünü sürdürür.
5. **Otomatik Kesinleşme, Takvim Kilitleme ve E-posta Gönderimi:**
   - 3 taraf da onay verdiğinde randevu kesinleşir.
   - **O gün ve o saat sitenizdeki takvimde otomatik olarak kilitlenir** ve başka danışanlara kapatılır.
   - Danışman(lar)a ve Sekreter Hanım'a anında onay e-postası iletilir (`wp_mail`).

---

## 📦 Dosyalar

1. **[`otomatik-randevu-programi.zip`](file:///Users/selim/Documents/Codex/2026-09-27/chatgpt-ads-manager-plugin-ads-manager-4/outputs/Anki-Hocam-ChatGPT-Antigravity/anki-hocam/otomatik-randevu-programi/otomatik-randevu-programi.zip)**: WordPress yönetim panelinden doğrudan yüklenebilir eklenti paketi.
2. **[`otomatik-randevu-programi.php`](file:///Users/selim/Documents/Codex/2026-09-27/chatgpt-ads-manager-plugin-ads-manager-4/outputs/Anki-Hocam-ChatGPT-Antigravity/anki-hocam/otomatik-randevu-programi/otomatik-randevu-programi.php)**: Eklentinin PHP kaynak kodu.
3. **[`otomatik-randevu-onizleme.html`](file:///Users/selim/Documents/Codex/2026-09-27/chatgpt-ads-manager-plugin-ads-manager-4/outputs/Anki-Hocam-ChatGPT-Antigravity/anki-hocam/otomatik-randevu-programi/otomatik-randevu-onizleme.html)**: WordPress olmadan da test edebileceğiniz interaktif önizleme / Custom HTML dosyası.

---

## 🚀 WordPress Kurulumu

1. `wp-admin` panelinde **Eklentiler ➔ Yeni Eklenti Ekle ➔ Eklenti Yükle** kısmından `otomatik-randevu-programi.zip` dosyasını yükleyin ve etkinleştirin.
2. Sol menüdeki **Randevu & Mutabakat ➔ Ayarlar** kısmına giderek:
   - 1. Danışman Adı, WhatsApp Numarası ve E-posta Adresini,
   - (İleride bir ortak alırsanız) 2. Danışman / Ortak bilgilerini,
   - Çalıştığınız Ofis Sekreterinin Adı, WhatsApp Numarası ve E-postasını,
   - WhatsApp Grup Davet Linkinizi kaydedin. (Ofis veya sekreter değiştiğinde yalnızca bu panelden güncellemeniz yeterlidir).
3. Sitenizde randevu vermek istediğiniz sayfaya `[otomatik_randevu]` kısa kodunu ekleyin.
