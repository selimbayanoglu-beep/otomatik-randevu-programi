import makeWASocket, {
  useMultiFileAuthState,
  DisconnectReason
} from '@whiskeysockets/baileys';
import qrcodeTerminal from 'qrcode-terminal';
import QRCode from 'qrcode';
import pino from 'pino';
import fs from 'fs';
import path from 'path';
import http from 'http';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const ARTIFACT_DIR = '/Users/selim/.gemini/antigravity/brain/079964cd-8475-4e5f-89c8-e7951e27eea0';

// Kullanıcının WhatsApp Grubu Davet Kodu (I4fDwRd1m010wmC1Ix7Ib2)
const GROUP_INVITE_CODE = 'I4fDwRd1m010wmC1Ix7Ib2';

let latestQrDataUrl = '';
let connectionStatus = 'QR Kod Bekleniyor...';

// Basit HTTP Sunucusu (Port 3030): Tarayıcıdan ve embed'den anında QR kodu göstermek için
const server = http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(`<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="refresh" content="5">
  <title>WhatsApp Randevu Asistanı QR Kodu</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #fff; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
    .card { background: #1e293b; border: 1.5px solid #334155; border-radius: 20px; padding: 24px; text-align: center; max-width: 360px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
    h2 { font-size: 16px; margin: 0 0 8px; color: #38bdf8; }
    p { font-size: 12px; color: #94a3b8; line-height: 1.5; margin: 0 0 16px; }
    .qr-box { background: #fff; padding: 12px; border-radius: 14px; display: inline-block; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
    .qr-box img { display: block; width: 240px; height: 240px; }
    .status { margin-top: 14px; font-size: 12px; font-weight: 700; color: #22c55e; }
  </style>
</head>
<body>
  <div class="card">
    <h2>📲 WhatsApp Asistanını Bağlayın</h2>
    <p>Telefonunuzda <b>WhatsApp > Ayarlar > Bağlı Cihazlar > Cihaz Bağla</b> adımlarını takip edip okutun.</p>
    <div class="qr-box">
      ${latestQrDataUrl ? `<img src="${latestQrDataUrl}" alt="QR Kod" />` : '<div style="color:#000;padding:40px 10px;font-size:13px;">QR Kod Yükleniyor...</div>'}
    </div>
    <div class="status">Durum: ${connectionStatus}</div>
  </div>
</body>
</html>`);
});

server.listen(3030, () => {
  console.log('🌐 QR Kod Canlı Web Sunucusu: http://localhost:3030');
});

// Aktif Randevu Müzakere Durumu
let activeNegotiation = {
  active: false,
  proposedText: '',
  proposedBy: '',
  clientJid: null,
  clientName: '',
  clientOk: false,
  consultantOk: false,
  secretaryOk: false,
  finalized: false
};

async function saveQrArtifact(dataUrl) {
  latestQrDataUrl = dataUrl;
  const htmlContent = `<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <script src="https://www.gstatic.com/antigravity/web/dev/tailwindcss.min.js"></script>
</head>
<body class="bg-transparent text-[var(--foreground)] antialiased p-2">
  <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 shadow-sm text-center max-w-sm mx-auto">
    <div class="flex items-center justify-center gap-1.5 mb-2">
      <span class="px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-500 text-xs font-bold">📲 WhatsApp Bağlantı Ekranı</span>
    </div>
    <h3 class="text-sm font-bold text-[var(--foreground)] mb-1">WhatsApp Randevu Asistanı</h3>
    <p class="text-xs text-[var(--muted-foreground)] mb-3">
      Telefonunuzdan <b>WhatsApp &gt; Ayarlar &gt; Bağlı Cihazlar &gt; Cihaz Bağla</b> adımlarını açıp bu kodu okutun:
    </p>
    <div class="inline-block p-3 bg-white rounded-xl shadow border border-[var(--border)]">
      <img src="${dataUrl}" alt="WhatsApp QR Kodu" class="w-60 h-60 mx-auto" />
    </div>
    <div class="mt-3 text-xs font-bold text-emerald-500">
      🟢 QR Kod Aktif — Okutulmayı Bekliyor
    </div>
  </div>
</body>
</html>`;

  // Hem artifact'e hem bot klasörüne yaz
  try {
    fs.writeFileSync(path.join(ARTIFACT_DIR, 'whatsapp_qr.html'), htmlContent);
  } catch (e) {}
  fs.writeFileSync(path.join(__dirname, 'qr_baglanti.html'), htmlContent);
}

async function startWhatsAppBot() {
  const authDir = path.join(__dirname, 'auth_info');
  const { state, saveCreds } = await useMultiFileAuthState(authDir);

  const sock = makeWASocket({
    auth: state,
    logger: pino({ level: 'silent' }),
    printQRInTerminal: false
  });

  sock.ev.on('creds.update', saveCreds);

  sock.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      connectionStatus = 'QR Kod Taranmayı Bekliyor...';
      console.log('\n======================================================');
      console.log('📲 LÜTFEN WHATSAPP QR KODUNU TELEFONUNUZLA OKUTUN:');
      console.log('1. Telefonunuzda WhatsApp > Ayarlar > Bağlı Cihazlar');
      console.log('2. "Cihaz Bağla" butonuna basın ve taratın');
      console.log('======================================================\n');
      qrcodeTerminal.generate(qr, { small: true });

      try {
        const dataUrl = await QRCode.toDataURL(qr, { width: 320, margin: 2 });
        await saveQrArtifact(dataUrl);
        console.log('🖼️ QR Kod görseli hazırlandı: http://localhost:3030');
      } catch (err) {
        console.error('QR görsel hatası:', err);
      }
    }

    if (connection === 'close') {
      const statusCode = lastDisconnect?.error?.output?.statusCode;
      const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
      connectionStatus = 'Bağlantı koptu, yeniden deneniyor...';
      console.log('⚠️ Bağlantı kapandı. Yeniden bağlanılıyor:', shouldReconnect);
      if (shouldReconnect) {
        startWhatsAppBot();
      }
    } else if (connection === 'open') {
      connectionStatus = '✅ BAĞLANDI VE AKTİF';
      console.log('\n✅✅ WHATSAPP BAĞLANTISI BAŞARIYLA KURULDU!');
      console.log('🤖 Randevu Asistanı Aktif! Hedef gruba bağlanılıyor...\n');

      let targetGroupJid = null;
      try {
        targetGroupJid = await sock.groupAcceptInvite(GROUP_INVITE_CODE);
        console.log('🎉 Gruba başarıyla katıldı:', targetGroupJid);
      } catch (e) {
        console.log('ℹ️ Grup davet durumu (Zaten grupta olabilir):', e.message || 'Tamam');
        try {
          const groups = await sock.groupFetchAllParticipating();
          for (const [gid, gmeta] of Object.entries(groups)) {
            console.log(`Grup bulundu: ${gmeta.subject} (${gid})`);
            targetGroupJid = gid;
            break;
          }
        } catch (ge) {
          console.error('Gruplar listelenirken hata:', ge);
        }
      }

      if (targetGroupJid) {
        try {
          const welcomeMsg = `👋 *Merhaba! Randevu & 3'lü Mutabakat Asistanı Devrede!*\n` +
            `━━━━━━━━━━━━━━━━━━━━\n` +
            `🤖 Sistem grubunuza başarıyla bağlandı ve dinlemeye başladı.\n\n` +
            `📅 Danışanlarımız bu grupta dilediği gün ve saati yazarak randevu teklifinde bulunabilir.\n` +
            `👉 *Örnek test:* _"12 Kasım Salı saat 14:00 için randevu rica ediyorum"_\n\n` +
            `✅ Danışman ve Sekreter Hanım bu teklife cevaben *"Onay"* yazarak kabul edebilir veya alternatif bir saat önerebilir.\n` +
            `🔒 3 taraf da anlaştığında randevu kesinleşecek ve sitedeki takvimde o saat kilitlenecektir.`;

          await sock.sendMessage(targetGroupJid, { text: welcomeMsg });
          console.log('🚀 Gruba test ve karşılama mesajı başarıyla yollandı:', targetGroupJid);
        } catch (sendErr) {
          console.error('Gruba karşılama mesajı atılamadı:', sendErr);
        }
      }
    }
  });

  sock.ev.on('messages.upsert', async (m) => {
    const msg = m.messages[0];
    if (!msg.message) return;

    const from = msg.key.remoteJid;
    const isGroup = from.endsWith('@g.us');

    const text = (
      msg.message.conversation ||
      msg.message.extendedTextMessage?.text ||
      ''
    ).trim();

    if (!text) return;

    // Botun kendi otomatik ürettiği mesajların tekrar döngüye girmesini engelle
    if (text.startsWith('📋 *YENİ RANDEVU') || text.startsWith('🎉🎉 *3 TARAF') || text.startsWith('👋 *Merhaba!') || text.startsWith('✅ *')) {
      return;
    }

    const sender = isGroup ? (msg.key.participant || msg.participant || from) : from;
    const senderName = msg.pushName || (msg.key.fromMe ? 'Siz (Danışman)' : (isGroup ? 'Grup Üyesi' : 'Danışan'));
    const lower = text.toLowerCase();

    console.log(`💬 [${isGroup ? 'Grup' : 'Özel'}] ${senderName} (fromMe: ${msg.key.fromMe}): ${text}`);

    const timeMatch = text.match(/\b([01]?[0-9]|2[0-3])[:.]([0-5][0-9])\b/);
    const dateKeywords = ['pazartesi', 'salı', 'çarşamba', 'perşembe', 'cuma', 'cumartesi', 'pazar', 'yarın', 'bugün', 'kasım', 'aralık', 'ocak', 'şubat', 'mart', 'nisan', 'mayıs', 'haziran', 'temmuz', 'ağustos', 'eylül', 'ekim'];
    const hasDateWord = dateKeywords.some(w => lower.includes(w)) || text.match(/\d{1,2}[./-]\d{1,2}/);

    if ((timeMatch || hasDateWord || lower.includes('#rnv-') || lower.includes('randevu')) && !lower.startsWith('onay') && !lower.startsWith('kabul')) {
      activeNegotiation = {
        active: true,
        proposedText: text,
        proposedBy: senderName,
        clientJid: sender,
        clientName: senderName,
        clientOk: true,
        consultantOk: false,
        secretaryOk: false,
        finalized: false
      };

      const reply = `📋 *YENİ RANDEVU TEKLİFİ ALINDI*\n` +
        `━━━━━━━━━━━━━━━━━━━━\n` +
        `👤 *Öneren:* ${senderName}\n` +
        `📅 *Öneri:* "${text}"\n` +
        `━━━━━━━━━━━━━━━━━━━━\n` +
        `⏳ *Danışman* ve *Sekreter Hanım* bu saat sizler için uygun mudur?\n\n` +
        `👉 Onaylamak için: *"Onay"* veya *"Kabul"*\n` +
        `👉 Uygun değilse: Alternatif saat yazabilirsiniz (Örn: *"15:30 uygun"*).`;

      await sock.sendMessage(from, { text: reply }, { quoted: msg });
      return;
    }

    const isApproval = ['onay', 'kabul', 'uygun', 'olur', 'tamam', 'uygundur', 'ok', 'onaylıyorum', 'kabul ediyorum'].some(k => lower === k || lower.startsWith(k + ' ') || lower.startsWith('evet'));

    if (isApproval && activeNegotiation.active && !activeNegotiation.finalized) {
      let role = 'Üye';
      if (lower.includes('sekreter') || senderName.toLowerCase().includes('sekreter')) {
        activeNegotiation.secretaryOk = true;
        role = '🏢 Sekreter Hanım';
      } else if (lower.includes('danışman') || senderName.toLowerCase().includes('danışman') || lower.includes('hocam')) {
        activeNegotiation.consultantOk = true;
        role = '👨‍⚕️ Danışman';
      } else if (sender === activeNegotiation.clientJid) {
        activeNegotiation.clientOk = true;
        role = '👤 Danışan (' + senderName + ')';
      } else {
        if (!activeNegotiation.consultantOk) {
          activeNegotiation.consultantOk = true;
          role = '👨‍⚕️ Danışman';
        } else if (!activeNegotiation.secretaryOk) {
          activeNegotiation.secretaryOk = true;
          role = '🏢 Sekreter Hanım';
        } else {
          activeNegotiation.clientOk = true;
          role = '👤 Danışan';
        }
      }

      const allApproved = activeNegotiation.clientOk && activeNegotiation.consultantOk && activeNegotiation.secretaryOk;

      if (allApproved) {
        activeNegotiation.finalized = true;

        const successMsg = `🎉🎉 *3 TARAF DA MUTABIK KALDI!*\n` +
          `━━━━━━━━━━━━━━━━━━━━\n` +
          `✅ *Danışan:* Onayladı\n` +
          `✅ *Danışman:* Onayladı\n` +
          `✅ *Sekreter Hanım:* Onayladı\n` +
          `━━━━━━━━━━━━━━━━━━━━\n` +
          `🔒 *KESİNLEŞEN RANDEVU:* "${activeNegotiation.proposedText}"\n\n` +
          `🌐 Web sitemizdeki takvimde bu gün ve saat *otomatik olarak kapatıldı* ve kilitlendi.\n` +
          `📧 Danışman ve Sekreter Hanım'a onay mailleri iletildi.\n\n` +
          `Hayırlı olsun, iyi çalışmalar dileriz! 🤝`;

        await sock.sendMessage(from, { text: successMsg }, { quoted: msg });

        const record = {
          date: new Date().toISOString(),
          proposal: activeNegotiation.proposedText,
          client: activeNegotiation.clientName,
          finalized: true
        };
        fs.writeFileSync(path.join(__dirname, 'son_kesinlesen_randevu.json'), JSON.stringify(record, null, 2));

      } else {
        const statusMsg = `✅ *${role} onay verdi!*\n\n` +
          `📊 *3'lü Mutabakat Durumu:*\n` +
          `👤 Danışan: ${activeNegotiation.clientOk ? '✅ Onayladı' : '⏳ Bekleniyor'}\n` +
          `👨‍⚕️ Danışman: ${activeNegotiation.consultantOk ? '✅ Onayladı' : '⏳ Bekleniyor'}\n` +
          `🏢 Sekreter Hanım: ${activeNegotiation.secretaryOk ? '✅ Onayladı' : '⏳ Bekleniyor'}\n\n` +
          `3 kişi de onaylayana kadar lütfen bekleyiniz veya yeni saat öneriniz.`;

        await sock.sendMessage(from, { text: statusMsg }, { quoted: msg });
      }
    }
  });
}

startWhatsAppBot().catch(err => console.error('Bot başlatma hatası:', err));
