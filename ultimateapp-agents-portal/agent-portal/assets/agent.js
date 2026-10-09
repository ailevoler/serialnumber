(() => {
  const card = document.querySelector('[data-agent-share]');
  if (!card) return;
  const link = card.dataset.link;
  const code = card.dataset.code;
  const status = card.querySelector('[data-share-status]');
  const say = text => { if (status) status.textContent = text; };
  const copy = async (text, label) => {
    try { await navigator.clipboard.writeText(text); say(label + ' copied.'); }
    catch (e) { const input = card.querySelector('[data-share-link]'); input.select(); say('Press Ctrl+C / long-press to copy.'); }
  };
  card.querySelectorAll('[data-copy]').forEach(btn => btn.addEventListener('click', () => {
    if (btn.dataset.copy === 'code') copy(code, card.dataset.codeLabel || 'Referral code'); else copy(link, card.dataset.label || 'Referral link');
  }));
  const share = card.querySelector('[data-share]');
  if (share) share.addEventListener('click', async () => {
    const data = {title: 'Ultimate App Boracay', text: card.dataset.text || ('Rides, food, tours and passes in Boracay in one app. Sign up with my code ' + code + ':'), url: link};
    if (navigator.share) { try { await navigator.share(data); } catch (e) {} } else { copy(link, 'Referral link'); }
  });

  const target = document.querySelector('[data-share-qr]');
  if (!target || typeof qrcode !== 'function') return;
  const qr = qrcode(0, 'M'); qr.addData(link); qr.make();
  target.innerHTML = qr.createSvgTag({cellSize: 6, margin: 2, scalable: true});
  const dl = document.querySelector('[data-qr-download]');
  if (dl) dl.addEventListener('click', () => {
    const n = qr.getModuleCount(), cell = 14, pad = 56, size = n * cell;
    const c = document.createElement('canvas'); c.width = size + pad * 2; c.height = size + pad * 2 + 130;
    const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.fillStyle = '#000';
    for (let r = 0; r < n; r++) for (let k = 0; k < n; k++) if (qr.isDark(r, k)) x.fillRect(pad + k * cell, pad + r * cell, cell, cell);
    x.textAlign = 'center'; x.font = '700 36px system-ui, sans-serif';
    x.fillText('Sign up to Ultimate App', c.width / 2, size + pad + 62);
    x.font = '500 26px system-ui, sans-serif'; x.fillStyle = '#555';
    x.fillText('Referral code ' + code, c.width / 2, size + pad + 104);
    c.toBlob(b => { const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'ultimate-app-agent-' + code + '.png'; document.body.appendChild(a); a.click(); a.remove(); });
  });
})();
