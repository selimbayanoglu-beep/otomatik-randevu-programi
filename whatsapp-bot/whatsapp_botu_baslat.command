#!/bin/bash
export PATH="/usr/local/bin:/opt/homebrew/bin:$PATH"
cd "$(dirname "$0")"
clear
echo "=========================================================="
echo "🤖 WhatsApp Grubu Randevu & 3'lü Mutabakat Asistanı"
echo "=========================================================="
echo "Node versiyonu: $(node -v)"
echo "Bot başlatılıyor, QR kod yükleniyor..."
node bot.js
echo ""
echo "Program kapandı. Çıkmak için Enter'a basın."
read
