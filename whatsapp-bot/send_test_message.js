import makeWASocket, {
  useMultiFileAuthState
} from '@whiskeysockets/baileys';
import pino from 'pino';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const GROUP_JID = '120363414370318965@g.us';

async function sendTest() {
  const authDir = path.join(__dirname, 'auth_info');
  const { state, saveCreds } = await useMultiFileAuthState(authDir);

  const sock = makeWASocket({
    auth: state,
    logger: pino({ level: 'silent' }),
    printQRInTerminal: false
  });

  sock.ev.on('creds.update', saveCreds);

  sock.ev.on('connection.update', async (update) => {
    const { connection } = update;
    if (connection === 'open') {
      console.log('✅ Bağlantı açık, test mesajı gönderiliyor...');
      const msg = `👋 *Merhaba! Randevu & 3'lü Mutabakat Asistanı Devrede!*\n` +
        `━━━━━━━━━━━━━━━━━━━━\n` +
        `🤖 Sistem WhatsApp grubunuza başarıyla bağlandı.\n\n` +
        `Şimdi bu grupta dilediğiniz gün ve saati yazarak randevu diyaloğunu başlatabilirsiniz.\n` +
        `👉 *Örnek test:* _"12 Kasım Salı 14:00 için randevu rica ediyorum"_\n` +
        `👉 Danışman veya Sekreter Hanım _"Onay"_ veya karşı saat yazdığında sistem otomatik yönetecek.`;

      await sock.sendMessage(GROUP_JID, { text: msg });
      console.log('🚀 Test mesajı başarıyla WhatsApp grubuna gönderildi!');
      setTimeout(() => process.exit(0), 2000);
    }
  });
}

sendTest().catch(console.error);
