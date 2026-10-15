/* XGEN LIFE Intl — UI helpers */
(function () {
  'use strict';
  var body = document.body;

  // Sidebar (mobile off-canvas)
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-open-sidebar]')) { body.classList.add('sidebar-open'); }
    if (e.target.closest('[data-close-sidebar]')) { body.classList.remove('sidebar-open'); }

    // Dismiss alerts
    var close = e.target.closest('.alert-close');
    if (close) { close.parentElement.remove(); }

    // Copy to clipboard
    var copyBtn = e.target.closest('[data-copy]');
    if (copyBtn) {
      var target = document.querySelector(copyBtn.getAttribute('data-copy'));
      var text = target ? (target.value || target.textContent) : '';
      var done = function () {
        var old = copyBtn.innerHTML;
        copyBtn.textContent = 'Copied!';
        setTimeout(function () { copyBtn.innerHTML = old; }, 1500);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done);
      } else if (target) {
        target.select(); document.execCommand('copy'); done();
      }
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { body.classList.remove('sidebar-open'); }
  });

  // Confirm dangerous actions: <button data-confirm="Are you sure?">
  document.addEventListener('submit', function (e) {
    var btn = e.submitter;
    var msg = (btn && btn.getAttribute('data-confirm')) || e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    if (btn && !btn.hasAttribute('data-no-lock')) {
      setTimeout(function () { btn.disabled = true; }, 0);
    }
  });

  // Auto-hide success alerts
  setTimeout(function () {
    document.querySelectorAll('.alert-success[data-dismissible]').forEach(function (a) {
      a.style.transition = 'opacity .4s'; a.style.opacity = '0';
      setTimeout(function () { a.remove(); }, 400);
    });
  }, 5000);

  // Label table cells for stacked mobile tables
  document.querySelectorAll('table.table.stack').forEach(function (t) {
    var heads = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    t.querySelectorAll('tbody tr').forEach(function (tr) {
      Array.prototype.forEach.call(tr.children, function (td, i) {
        if (!td.hasAttribute('data-label') && heads[i]) { td.setAttribute('data-label', heads[i]); }
      });
    });
  });

  // Payout fee preview
  var amt = document.querySelector('[data-payout-amount]');
  if (amt) {
    var pct = parseFloat(amt.getAttribute('data-fee-pct')) || 0;
    var fixed = parseFloat(amt.getAttribute('data-fee-fixed')) || 0;
    var feeEl = document.getElementById('fee-preview');
    var netEl = document.getElementById('net-preview');
    var fmt = function (n) { return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var upd = function () {
      var v = parseFloat(amt.value) || 0;
      var fee = v > 0 ? Math.round((v * pct / 100 + fixed) * 100) / 100 : 0;
      feeEl.textContent = fmt(fee);
      netEl.textContent = fmt(Math.max(0, v - fee));
    };
    amt.addEventListener('input', upd); upd();
  }

  // Show/hide payment reference on checkout
  var pm = document.querySelectorAll('input[name="payment_method"]');
  var refBox = document.getElementById('payment-ref-box');
  if (pm.length && refBox) {
    var toggle = function () {
      var sel = document.querySelector('input[name="payment_method"]:checked');
      refBox.style.display = sel && sel.value !== 'wallet' ? '' : 'none';
    };
    pm.forEach(function (r) { r.addEventListener('change', toggle); }); toggle();
  }

  // Check/uncheck all
  var all = document.querySelector('[data-check-all]');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll(all.getAttribute('data-check-all')).forEach(function (c) { c.checked = all.checked; });
    });
  }
})();
