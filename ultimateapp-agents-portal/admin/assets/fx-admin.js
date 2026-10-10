(() => {
  // Currencies tab: filter the checkbox list by code or name.
  const filter = document.querySelector('[data-fx-filter]');
  if (!filter) return;
  const items = [...document.querySelectorAll('[data-fx-item]')];
  filter.addEventListener('input', () => {
    const q = filter.value.trim().toLowerCase();
    items.forEach(el => { el.hidden = q !== '' && !el.dataset.fxItem.includes(q); });
  });
})();
