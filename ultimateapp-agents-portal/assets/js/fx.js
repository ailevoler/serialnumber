(() => {
  // Currency picker: open the chosen currency right away.
  const picker = document.querySelector('[data-fx-picker]');
  if (picker) {
    const select = picker.querySelector('select');
    const go = picker.querySelector('[data-fx-go]');
    if (go) go.hidden = true;
    select.addEventListener('change', () => picker.submit());
  }

  // Live preview for the exchange form. Same integer math as includes/fx.php (fx_quote); the server decides.
  const form = document.querySelector('[data-fx-form]');
  if (!form) return;
  const scaled = BigInt(form.dataset.scaled || '0');
  const dec = Number(form.dataset.dec);
  const ccy = form.dataset.ccy;
  const unitDiv = 10n ** BigInt(dec) * 100000000n; // 10^dec x (10^10 / 100)
  const bp = {buy: BigInt(form.dataset.buyBp), sell: BigInt(form.dataset.sellBp)};
  const php = BigInt(form.dataset.php), held = BigInt(form.dataset.held), min = BigInt(form.dataset.min), max = BigInt(form.dataset.max);
  const input = form.querySelector('#fx-amount');
  const button = form.querySelector('[data-pay-submit]');
  const out = k => form.querySelector('[data-fx="' + k + '"]');
  const group = s => s.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const fmt = (minor, d) => {
    const neg = minor < 0n; const v = neg ? -minor : minor; const p = 10n ** BigInt(d);
    const whole = group((v / p).toString());
    return (neg ? '-' : '') + (d ? whole + '.' + (v % p).toString().padStart(d, '0') : whole);
  };
  const peso = c => 'PHP ' + fmt(c, 2);
  const pct = b => (Number(b) / 100).toString().replace(/\.?0+$/, '') + '%';
  const side = () => (form.querySelector('input[name=side]:checked') || {}).value || 'buy';
  const pattern = dec ? new RegExp('^\\d{1,12}(\\.\\d{1,' + dec + '})?$') : /^\d{1,12}$/;
  const canTrade = !button.disabled;
  const ceilDiv = (a, b) => (a + b - 1n) / b;

  const render = () => {
    const s = side();
    form.querySelectorAll('.fx-side label').forEach(l => l.classList.toggle('active', l.querySelector('input').checked));
    const raw = input.value.trim().replace(/,/g, '');
    const ok = pattern.test(raw);
    let amount = 0n;
    if (ok) { const [w, f = ''] = raw.split('.'); amount = BigInt(w) * 10n ** BigInt(dec) + BigInt((f + '0'.repeat(dec)).slice(0, dec) || '0'); }
    const gross = s === 'buy' ? ceilDiv(amount * scaled, unitDiv) : (amount * scaled) / unitDiv;
    const fee = ceilDiv(gross * bp[s], 10000n);
    const total = s === 'buy' ? gross + fee : gross - fee;
    out('gross').textContent = peso(gross);
    out('fee-label').textContent = 'Fee (' + pct(bp[s]) + ')';
    out('fee').textContent = peso(fee);
    out('total-label').textContent = s === 'buy' ? 'You pay from BCash' : 'You get in BCash';
    out('total').textContent = peso(total < 0n ? 0n : total);
    let note = '';
    if (raw && !ok) note = dec ? 'Enter a ' + ccy + ' amount with up to ' + dec + ' decimals.' : 'Enter a whole ' + ccy + ' amount (no decimals).';
    else if (amount && (amount < min || amount > max)) note = 'Enter from ' + ccy + ' ' + fmt(min, dec) + ' to ' + ccy + ' ' + fmt(max, dec) + '.';
    else if (amount && s === 'buy' && total > php) note = 'Not enough BCash. You have ' + peso(php) + '.';
    else if (amount && s === 'sell' && amount > held) note = 'Not enough ' + ccy + '. You have ' + ccy + ' ' + fmt(held, dec) + '.';
    out('note').textContent = note;
    out('note').classList.toggle('warn', !!note);
    button.textContent = (s === 'buy' ? 'Buy ' : 'Sell ') + ccy + (amount ? ' ' + fmt(amount, dec) : '');
    button.disabled = !canTrade || !amount || !!note;
  };
  form.addEventListener('input', render);
  form.addEventListener('change', render);
  form.addEventListener('submit', event => {
    if (button.disabled) { event.preventDefault(); return; }
    setTimeout(() => { button.disabled = true; button.textContent = 'Processing...'; }, 0);
  });
  render();
})();
