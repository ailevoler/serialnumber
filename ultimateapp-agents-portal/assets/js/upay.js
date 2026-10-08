(() => {
  const form = document.querySelector('[data-upay-form]');
  if (!form) return;
  const select = form.querySelector('[data-provider-select]');
  const limits = form.querySelector('[data-provider-limits]');
  const amount = form.querySelector('[data-amount]');
  const all = Array.from(select.options).slice(1).map(o => o.cloneNode(true));
  const showLimits = () => {
    const o = select.selectedOptions[0];
    const min = o && o.dataset.min, max = o && o.dataset.max;
    limits.textContent = min || max ? 'Amount ' + (min ? 'from ₱' + min : '') + (min && max ? ' ' : '') + (max ? 'up to ₱' + max : '') : '';
    document.querySelectorAll('[data-pick]').forEach(b => b.classList.toggle('on', b.dataset.pick === select.value));
  };
  select.addEventListener('change', showLimits);
  const filter = form.querySelector('[data-provider-filter]');
  if (filter) filter.addEventListener('input', () => {
    const q = filter.value.trim().toLowerCase();
    const keep = select.value;
    select.length = 1;
    all.filter(o => !q || o.textContent.toLowerCase().includes(q) || o.value === keep).forEach(o => select.add(o.cloneNode(true)));
    select.value = keep;
  });
  document.querySelectorAll('[data-pick]').forEach(b => b.addEventListener('click', () => {
    if (filter) { filter.value = ''; filter.dispatchEvent(new Event('input')); }
    select.value = b.dataset.pick; showLimits(); form.querySelector('[name=account_number]').focus();
  }));
  document.querySelectorAll('[data-amount-pick]').forEach(b => b.addEventListener('click', () => {
    amount.value = b.dataset.amountPick;
    document.querySelectorAll('[data-amount-pick]').forEach(x => x.classList.toggle('on', x === b));
  }));
  showLimits();
})();
