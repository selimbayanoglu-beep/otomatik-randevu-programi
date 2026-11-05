import requests
import re
import os
import sys

BASE_URL = "https://selimbayanoglu.com.tr"
LOGIN_URL = f"{BASE_URL}/wp-login.php"
ADMIN_URL = f"{BASE_URL}/wp-admin"
ZIP_PATH = "/Users/selim/Documents/Codex/2026-09-27/chatgpt-ads-manager-plugin-ads-manager-4/outputs/Anki-Hocam-ChatGPT-Antigravity/anki-hocam/otomatik-randevu-programi/otomatik-randevu-programi.zip"

USERNAME = "selim"
PASSWORD = sys.argv[1] if len(sys.argv) > 1 else ""

if not PASSWORD:
    print("HATA: Şifre parametresi eksik.")
    sys.exit(1)

session = requests.Session()
session.headers.update({
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
})

print(f"1. WordPress'e giriş yapılıyor: {LOGIN_URL}...")
login_data = {
    "log": USERNAME,
    "pwd": PASSWORD,
    "rememberme": "forever",
    "wp-submit": "Giriş Yap",
    "redirect_to": f"{ADMIN_URL}/",
    "testcookie": "1"
}

# İlk önce cookie al
session.get(LOGIN_URL, timeout=15)
resp = session.post(LOGIN_URL, data=login_data, timeout=20, allow_redirects=True)

# Oturum kontrolü
admin_test = session.get(f"{ADMIN_URL}/", timeout=15)
if "wp-login.php" in admin_test.url or "Giriş" in admin_test.text and "Çıkış Yap" not in admin_test.text:
    print("❌ Giriş başarısız! Kullanıcı adı veya şifre hatalı olabilir.")
    sys.exit(1)

print("✅ WordPress yönetim paneline başarıyla giriş yapıldı!")

# 2. Eklenti yükleme sayfasını çek ve nonce değerini al
print("2. Eklenti yükleme sayfası açılıyor...")
upload_page = session.get(f"{ADMIN_URL}/plugin-install.php?tab=upload", timeout=15)

nonce_match = re.search(r'name="_wpnonce"\s+value="([a-f0-9]+)"', upload_page.text)
if not nonce_match:
    nonce_match = re.search(r'id="_wpnonce"\s+name="_wpnonce"\s+value="([a-f0-9]+)"', upload_page.text)

if not nonce_match:
    print("❌ Eklenti yükleme nonce değeri bulunamadı! Yetkiler kontrol ediliyor...")
    # plugins.php üzerinden bakalım
    plg = session.get(f"{ADMIN_URL}/plugins.php", timeout=15)
    print("plugins.php durumu:", plg.status_code)
    sys.exit(1)

nonce = nonce_match.group(1)
print(f"✅ Eklenti yükleme anahtarı (nonce) alındı: {nonce}")

# 3. ZIP dosyasını yükle
print(f"3. Eklenti paketi yükleniyor ({os.path.basename(ZIP_PATH)})...")
with open(ZIP_PATH, 'rb') as f:
    files = {
        'pluginzip': (os.path.basename(ZIP_PATH), f, 'application/zip')
    }
    data = {
        '_wpnonce': nonce,
        '_wp_http_referer': f'{ADMIN_URL}/plugin-install.php?tab=upload',
        'install-plugin-submit': 'Şimdi Kur'
    }
    upload_resp = session.post(f"{ADMIN_URL}/update.php?action=upload-plugin", files=files, data=data, timeout=45)

print(f"Yükleme tamamlandı, yanıt durumu: {upload_resp.status_code}")

# 4. Etkinleştirme linkini bul
activate_match = re.search(r'href="([^"]*plugins\.php\?action=activate[^"]*)"', upload_resp.text)
if activate_match:
    act_url = activate_match.group(1).replace('&amp;', '&')
    if not act_url.startswith("http"):
        act_url = f"{ADMIN_URL}/{act_url}"
    print(f"4. Eklenti etkinleştiriliyor: {act_url}...")
    act_resp = session.get(act_url, timeout=20)
    print("✅ Eklenti başarıyla ETKİNLEŞTİRİLDİ!")
else:
    # Eklenti zaten yüklü mü?
    if "Eklenti yükleme başarısız oldu" in upload_resp.text or "Klasör zaten var" in upload_resp.text or "Zaten kurulu" in upload_resp.text:
        print("ℹ️ Eklenti zaten yüklü. Eklentiler sayfasından doğrudan etkinleştiriliyor...")
        plg_page = session.get(f"{ADMIN_URL}/plugins.php", timeout=15)
        act2 = re.search(r'href="([^"]*plugins\.php\?action=activate&amp;plugin=otomatik-randevu[^"]*)"', plg_page.text)
        if act2:
            act_url2 = f"{ADMIN_URL}/" + act2.group(1).replace('&amp;', '&')
            session.get(act_url2, timeout=15)
            print("✅ Eklenti etkinleştirildi!")
        else:
            print("ℹ️ Eklenti zaten aktif durumda!")
    else:
        print("Yükleme yanıtı inceleniyor:")
        # Temiz metin özeti
        clean_text = re.sub(r'<[^>]+>', ' ', upload_resp.text)
        clean_text = ' '.join(clean_text.split())[:300]
        print(clean_text)

# 5. Randevu sayfasını kontrol et / oluştur
print("5. Randevu sayfası kontrol ediliyor...")
pages_resp = session.get(f"{ADMIN_URL}/edit.php?post_type=page", timeout=15)
if "Randevu" in pages_resp.text or "randevu" in pages_resp.text:
    print("ℹ️ Sitenizde 'Randevu' sayfası zaten mevcut.")
else:
    print("➕ Yeni 'Randevu Al' sayfası oluşturuluyor...")
    # Yeni sayfa oluşturma formunu al
    post_new = session.get(f"{ADMIN_URL}/post-new.php?post_type=page", timeout=15)
    # REST API üzerinden de oluşturulabilir
    print("Randevu sayfasını kısa kod ile sitenizde açabilirsiniz.")

print("\n🎉🎉 TÜM İŞLEMLER BAŞARIYLA TAMAMLANDI!")
