/* Orders page: refresh while nothing is being typed, so new orders and bookings appear by themselves. */
(() => {
  const el = document.querySelector('[data-mk-refresh]');
  if (!el) return;
  const every = Number(el.dataset.mkRefresh) * 1000;
  let last = Date.now();
  document.addEventListener('input', () => { last = Date.now(); });
  setInterval(() => {
    const typing = document.activeElement && ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName);
    if (document.visibilityState === 'visible' && !typing && Date.now() - last > every) location.reload();
  }, every);
})();
