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
      refBox.style.display = sel && sel.value !== 'wallet' && sel.value !== 'paymongo' ? '' : 'none';
    };
    pm.forEach(function (r) { r.addEventListener('change', toggle); }); toggle();
  }

  // Compensation plan: add / remove level rows
  document.querySelectorAll('[data-levels]').forEach(function (ed) {
    var rows = ed.querySelector('.level-rows');
    var max = parseInt(ed.getAttribute('data-max'), 10) || 30;
    var name = ed.getAttribute('data-name');
    var count = ed.querySelector('[data-level-count]');
    var refresh = function () {
      var n = rows.children.length;
      Array.prototype.forEach.call(rows.children, function (r, i) { r.querySelector('.level-label').textContent = 'Level ' + (i + 1); });
      count.textContent = n + ' level(s)';
      ed.querySelector('[data-level-add]').disabled = n >= max;
      ed.querySelector('[data-level-remove]').disabled = n <= 1;
    };
    ed.querySelector('[data-level-add]').addEventListener('click', function () {
      if (rows.children.length >= max) { return; }
      var row = document.createElement('div');
      row.className = 'level-row';
      row.innerHTML = '<span class="level-label"></span><input class="input" type="number" step="0.01" min="0" required value="0"><span class="level-suffix"></span>';
      row.querySelector('input').name = name + '[]';
      row.querySelector('.level-suffix').textContent = ed.getAttribute('data-suffix');
      rows.appendChild(row);
      refresh();
      row.querySelector('input').focus();
    });
    ed.querySelector('[data-level-remove]').addEventListener('click', function () {
      if (rows.children.length > 1) { rows.removeChild(rows.lastElementChild); refresh(); }
    });
    refresh();
  });
  document.querySelectorAll('[data-suffix-target]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var ed = document.querySelector('[data-levels][data-name="' + sel.getAttribute('data-suffix-target') + '"]');
      if (!ed) { return; }
      var suffix = sel.value === 'percent' ? '%' : sel.getAttribute('data-currency');
      ed.setAttribute('data-suffix', suffix);
      ed.querySelectorAll('.level-suffix').forEach(function (s) { s.textContent = suffix; });
    });
  });

  // Manual journal entry: add lines, live totals, balance check
  var je = document.querySelector('[data-je-form]');
  if (je) {
    var body = je.querySelector('[data-je-lines]');
    var status = je.querySelector('[data-je-status]');
    var submit = je.querySelector('[data-je-submit]');
    var n = body.children.length;
    var fmt2 = function (v) { return v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var recalc = function () {
      var dr = 0, cr = 0;
      body.querySelectorAll('[data-je-dr]').forEach(function (i) { dr += parseFloat(i.value) || 0; });
      body.querySelectorAll('[data-je-cr]').forEach(function (i) { cr += parseFloat(i.value) || 0; });
      je.querySelector('[data-je-total-dr]').textContent = fmt2(dr);
      je.querySelector('[data-je-total-cr]').textContent = fmt2(cr);
      var ok = dr > 0 && Math.abs(dr - cr) < 0.005;
      status.textContent = ok ? '✓ Balanced' : 'Difference: ' + fmt2(dr - cr) + ' — debits must equal credits';
      status.className = 'small mt-1 ' + (ok ? 'text-green' : 'text-danger');
      submit.disabled = !ok;
    };
    body.addEventListener('input', recalc);
    je.querySelector('[data-je-add]').addEventListener('click', function () {
      var row = body.children[0].cloneNode(true);
      row.querySelectorAll('input, select').forEach(function (f) {
        f.name = f.name.replace(/line\[\d+\]/, 'line[' + n + ']');
        f.value = '';
      });
      n++;
      body.appendChild(row);
    });
    body.addEventListener('click', function (e) {
      if (e.target.closest('[data-je-remove]') && body.children.length > 2) { e.target.closest('tr').remove(); recalc(); }
    });
    recalc();
  }

  // Check/uncheck all
  var all = document.querySelector('[data-check-all]');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll(all.getAttribute('data-check-all')).forEach(function (c) { c.checked = all.checked; });
    });
  }
})();
