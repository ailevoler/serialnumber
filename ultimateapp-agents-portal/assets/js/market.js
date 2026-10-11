/* UGo / ULocal booking form and UEat (Grab Food style) interactions. Works without JavaScript too (plain forms). */
(() => {
  const peso = c => '₱' + (c / 100).toLocaleString('en-PH', {minimumFractionDigits: c % 100 ? 2 : 0, maximumFractionDigits: 2});

  /* confirm before cancelling */
  document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));

  /* ---------- booking form: guests stepper + live total */
  const book = document.querySelector('[data-mk-book]');
  if (book) {
    const qty = book.querySelector('[name=quantity]');
    const price = Number(book.dataset.price), group = book.dataset.group === '1';
    let rules = {}; try { rules = JSON.parse(book.dataset.fee || '{}'); } catch (e) {}
    const fee = (amount, method) => { const rule = rules[method] || {}; return (!rule.enabled || rule.bearer !== 'customer') ? 0 : Math.floor((amount * rule.percent_bp + 5000) / 10000) + Number(rule.fixed_centavos || 0); };
    const update = () => {
      const n = Math.max(Number(qty.min) || 1, Math.min(Number(qty.max) || 99, Number(qty.value) || 1));
      const amount = price * (group ? 1 : n);
      const method = (book.querySelector('[name=pay_method]:checked') || {}).value;
      const f = method === 'credits' || method === 'bcash' ? fee(amount, method) : 0;
      book.querySelectorAll('[data-total]').forEach(el => { el.textContent = peso(amount + f); });
      const note = book.querySelector('[data-fee-note]'); if (note) note.hidden = !f;
    };
    book.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => {
      const v = (Number(qty.value) || 0) + Number(b.dataset.step);
      qty.value = Math.max(Number(qty.min) || 1, Math.min(Number(qty.max) || 99, v)); update();
    }));
    book.addEventListener('input', update); book.addEventListener('change', update);
    book.addEventListener('submit', () => { const b = book.querySelector('button[type=submit]'); setTimeout(() => { b.disabled = true; }, 0); });
    update();
  }

  /* ---------- UEat: add to basket without leaving the menu */
  const basket = document.querySelector('[data-ue-basket]');
  const post = async (form, extra) => {
    const body = new FormData(form); Object.entries(extra || {}).forEach(([k, v]) => body.set(k, v));
    const r = await fetch(form.action || location.href, {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}});
    return r.json();
  };
  document.querySelectorAll('form[data-ue-add]').forEach(form => form.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = form.querySelector('button'); btn.disabled = true;
    try {
      let res = await post(form);
      if (!res.ok && res.conflict && confirm(res.error)) res = await post(form, {replace: '1'});
      if (!res.ok) { if (!res.conflict) alert(res.error || 'Could not add this item.'); return; }
      btn.classList.add('has'); btn.innerHTML = '<span data-n>' + res.qty + '</span>';
      btn.classList.remove('pop'); void btn.offsetWidth; btn.classList.add('pop');
      if (basket) { basket.hidden = false; basket.querySelector('[data-ue-count]').textContent = res.count; basket.querySelector('[data-ue-subtotal]').textContent = res.subtotal; }
      if (navigator.vibrate) navigator.vibrate(15);
    } catch (err) { form.submit(); }
    finally { btn.disabled = false; }
  }));

  /* ---------- UEat: menu section tabs follow the scroll */
  const tabs = document.querySelector('[data-ue-tabs]');
  if (tabs && 'IntersectionObserver' in window) {
    const links = [...tabs.querySelectorAll('a')];
    const io = new IntersectionObserver(entries => {
      entries.forEach(en => {
        if (!en.isIntersecting) return;
        links.forEach(a => a.classList.toggle('on', a.getAttribute('href') === '#' + en.target.id));
        const on = tabs.querySelector('.on'); if (on) tabs.scrollTo({left: on.offsetLeft - 16, behavior: 'smooth'});
      });
    }, {rootMargin: '-120px 0px -65% 0px'});
    document.querySelectorAll('.ue-sec').forEach(s => io.observe(s));
  }

  /* ---------- UEat order tracking: refresh while the kitchen works */
  const live = document.querySelector('[data-ue-refresh]');
  if (live) setInterval(() => { if (document.visibilityState === 'visible') location.reload(); }, Number(live.dataset.ueRefresh) * 1000);
})();
