/* Passenger ride page: polls the ride status, moves the driver on the live map, reloads on each status change. */
(() => {
  const root = document.querySelector('[data-ride-status]');
  if (!root) return;
  const cancelForm = document.querySelector('[data-confirm-cancel]');
  if (cancelForm) cancelForm.addEventListener('submit', e => { if (!confirm('Cancel this ride?')) e.preventDefault(); });
  const active = ['requested', 'accepted', 'arrived', 'in_progress'].includes(root.dataset.status);
  if (!active) return;
  const nearby = root.querySelector('[data-rs-nearby]');
  const eta = document.querySelector('[data-rs-eta]');
  let busy = false;
  const poll = async () => {
    if (busy || document.hidden) return;
    busy = true;
    try {
      const r = await fetch('uride-status.php?code=' + encodeURIComponent(root.dataset.code), {credentials: 'same-origin', cache: 'no-store'});
      if (r.status === 401) { location.href = 'login.php'; return; }
      if (!r.ok) return;
      const j = await r.json();
      if (j.status !== root.dataset.status) {
        if (navigator.vibrate) navigator.vibrate([80, 50, 80]);
        location.reload();
        return;
      }
      if (nearby && typeof j.nearby === 'number') {
        const m = Math.floor((j.expires_in || 0) / 60), s = (j.expires_in || 0) % 60;
        nearby.textContent = (j.nearby ? j.nearby + ' driver' + (j.nearby === 1 ? '' : 's') + ' nearby' : 'No drivers online nearby yet') + ' · request open for ' + m + ':' + String(s).padStart(2, '0');
      }
      if (j.driver) {
        document.dispatchEvent(new CustomEvent('uride:driver-pos', {detail: {lat: j.driver.lat, lng: j.driver.lng}}));
        if (eta) eta.textContent = root.dataset.status === 'in_progress' && root.dataset.trip === 'rental'
          ? (root.dataset.ends ? 'Rental runs until about ' + root.dataset.ends + '.' : 'Your rental is running.')
          : root.dataset.status === 'in_progress'
          ? 'About ' + j.driver.km.toFixed(1) + ' km to your drop-off (~' + j.driver.eta_min + ' min).'
          : root.dataset.status === 'arrived' ? 'Your driver is waiting at the pick-up point.' : 'Driver is ' + j.driver.km.toFixed(1) + ' km away · arriving in about ' + j.driver.eta_min + ' min.';
      }
    } catch (e) { /* offline for a moment */ } finally { busy = false; }
  };
  poll();
  setInterval(poll, 8000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
