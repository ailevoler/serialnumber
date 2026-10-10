(() => {
  // Live preview for the USD ⇄ PHP form. Same integer math as includes/fx.php (fx_quote); the server decides.
  const form = document.querySelector('[data-fx-form]');
  if (!form) return;
  const micro = BigInt(form.dataset.micro || '0');
  const bp = {buy_usd: BigInt(form.dataset.buyBp), sell_usd: BigInt(form.dataset.sellBp)};
  const php = Number(form.dataset.php), usd = Number(form.dataset.usd), min = Number(form.dataset.min), max = Number(form.dataset.max);
  const input = form.querySelector('#fx-usd');
  const button = form.querySelector('[data-pay-submit]');
  const out = k => form.querySelector('[data-fx="' + k + '"]');
  const money = c => (Number(c) / 100).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const pct = b => (Number(b) / 100).toString().replace(/\.?0+$/, '') + '%';
  const side = () => (form.querySelector('input[name=side]:checked') || {}).value || 'buy_usd';
  const canTrade = !button.disabled;

  const render = () => {
    const s = side();
    form.querySelectorAll('.fx-side label').forEach(l => l.classList.toggle('active', l.querySelector('input').checked));
    const raw = input.value.trim().replace(/,/g, '');
    const ok = /^\d{1,7}(\.\d{1,2})?$/.test(raw);
    const cents = ok ? Math.round(Number(raw) * 100) : 0;
    const c = BigInt(cents);
    const gross = s === 'buy_usd' ? (c * micro + 999999n) / 1000000n : (c * micro) / 1000000n;
    const fee = (gross * bp[s] + 9999n) / 10000n;
    const total = s === 'buy_usd' ? gross + fee : gross - fee;
    out('gross').textContent = 'PHP ' + money(gross);
    out('fee-label').textContent = 'Fee (' + pct(bp[s]) + ')';
    out('fee').textContent = 'PHP ' + money(fee);
    out('total-label').textContent = s === 'buy_usd' ? 'You pay from BCash' : 'You get in BCash';
    out('total').textContent = 'PHP ' + money(total < 0n ? 0n : total);
    let note = '';
    if (raw && !ok) note = 'Enter a USD amount like 25 or 25.50.';
    else if (cents && (cents < min || cents > max)) note = 'Enter from USD ' + money(min) + ' to USD ' + money(max) + '.';
    else if (cents && s === 'buy_usd' && Number(total) > php) note = 'Not enough BCash. You have PHP ' + money(php) + '.';
    else if (cents && s === 'sell_usd' && cents > usd) note = 'Not enough USD. You have USD ' + money(usd) + '.';
    out('note').textContent = note;
    out('note').classList.toggle('warn', !!note);
    button.textContent = (s === 'buy_usd' ? 'Buy USD ' : 'Sell USD ') + (cents ? money(cents) : '');
    button.disabled = !canTrade || !cents || !!note;
  };
  form.addEventListener('input', render);
  form.addEventListener('change', render);
  form.addEventListener('submit', event => {
    if (button.disabled) { event.preventDefault(); return; }
    setTimeout(() => { button.disabled = true; button.textContent = 'Processing...'; }, 0);
  });
  render();
})();
