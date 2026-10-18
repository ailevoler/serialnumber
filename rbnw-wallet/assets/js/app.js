/**
 * RBNW WALLET — shared front-end utilities
 */

const RBNW = (() => {
  async function api(endpoint, method = 'GET', body = null) {
    const opts = {
      method,
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
    };
    if (body) opts.body = JSON.stringify(body);

    let res;
    try {
      res = await fetch(`api/${endpoint}`, opts);
    } catch (err) {
      return { success: false, message: 'Network error. Please check your connection.' };
    }

    // Parse defensively: a PHP fatal or host error page is not JSON
    const raw = await res.text();
    let data = null;
    try { data = JSON.parse(raw); } catch (_) { /* not JSON */ }

    if (!data || typeof data !== 'object') {
      console.error(`[RBNW] ${endpoint} -> HTTP ${res.status}`, raw.slice(0, 500));
      return { success: false, message: `Server error (${res.status}). Please try again in a moment.` };
    }
    if (!res.ok && !data.message) {
      data.message = `Request failed (${res.status}).`;
    }
    return data;
  }

  function peso(amount) {
    const n = Number(amount || 0);
    return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function toast(message, type = 'info') {
    let t = document.getElementById('rbnw-toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'rbnw-toast';
      t.style.cssText = `
        position: fixed; left: 50%; bottom: 100px; transform: translateX(-50%);
        z-index: 999; padding: 12px 20px; border-radius: 12px; font-size: 13.5px;
        font-weight: 600; color: #fff; max-width: 90%; text-align: center;
        box-shadow: 0 8px 20px rgba(0,0,0,0.2); transition: opacity .25s, transform .25s;
        opacity: 0;
      `;
      document.body.appendChild(t);
    }
    const colors = { info: '#0f4c92', success: '#2fa84f', error: '#e0453f' };
    t.style.background = colors[type] || colors.info;
    t.textContent = message;
    t.style.opacity = '1';
    t.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => {
      t.style.opacity = '0';
      t.style.transform = 'translateX(-50%) translateY(10px)';
    }, 2600);
  }

  function digitsOnly(str) {
    return (str || '').replace(/\D/g, '');
  }

  function formatMobile(str) {
    // normalizes +63 / 63 / 9XX input to 09XXXXXXXXX
    let d = digitsOnly(str);
    if (d.startsWith('63')) d = '0' + d.slice(2);
    else if (d.startsWith('9')) d = '0' + d;
    return d.slice(0, 11);
  }

  function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function alertHtml(message, type = 'error') {
    const icons = { error: 'fa-circle-exclamation', success: 'fa-circle-check', info: 'fa-circle-info' };
    return `<div class="alert alert-${type}" role="alert"><i class="fa-solid ${icons[type] || icons.info}"></i><span>${escapeHtml(message)}</span></div>`;
  }

  // PH mobile helpers for the "+63 | 917 123 4567" field: the input shows
  // the 10 local digits grouped; mobileFromField() returns 09XXXXXXXXX.
  function localMobile(v) {
    let d = digitsOnly(v);
    if (d.startsWith('63')) d = d.slice(2);
    if (d.startsWith('0')) d = d.slice(1);
    return d.slice(0, 10);
  }

  function prettyMobile(v) {
    const d = localMobile(v);
    return [d.slice(0, 3), d.slice(3, 6), d.slice(6, 10)].filter(Boolean).join(' ');
  }

  function bindMobileInput(input) {
    input.addEventListener('input', () => { input.value = prettyMobile(input.value); });
    if (input.value) input.value = prettyMobile(input.value);
  }

  function mobileFromField(input) {
    return '0' + localMobile(input.value);
  }

  // Segmented MPIN: wrap is a .pin-input holding one <input> and six .pin-box.
  function mountPinInput(wrap, onChange) {
    const input = wrap.querySelector('input');
    const boxes = wrap.querySelectorAll('.pin-box');
    const render = () => {
      const v = input.value;
      const reveal = wrap.classList.contains('reveal');
      boxes.forEach((b, i) => {
        b.classList.toggle('filled', i < v.length);
        b.classList.toggle('active', i === Math.min(v.length, boxes.length - 1));
        b.textContent = reveal && v[i] ? v[i] : '';
      });
    };
    input.addEventListener('input', () => {
      input.value = digitsOnly(input.value).slice(0, boxes.length);
      render();
      if (onChange) onChange(input.value);
    });
    ['focus', 'blur', 'keyup', 'click'].forEach(ev => input.addEventListener(ev, render));
    render();
    return {
      input,
      render,
      clear() { input.value = ''; render(); },
      toggleReveal() { const r = wrap.classList.toggle('reveal'); render(); return r; },
    };
  }

  // ---------- Biometric login (WebAuthn / passkeys) ----------
  const b64u = {
    toBuf: (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), c => c.charCodeAt(0)).buffer,
    fromBuf: (b) => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
  };
  const biometrics = {
    supported: () => !!(window.PublicKeyCredential && navigator.credentials),
    async available() {
      try { return this.supported() && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(); }
      catch (_) { return false; }
    },
    // options from the server use base64url strings; WebAuthn wants ArrayBuffers
    async create(pk) {
      const cred = await navigator.credentials.create({ publicKey: {
        ...pk, challenge: b64u.toBuf(pk.challenge),
        user: { ...pk.user, id: b64u.toBuf(pk.user.id) },
        excludeCredentials: (pk.excludeCredentials || []).map(c => ({ ...c, id: b64u.toBuf(c.id) })),
      } });
      return { id: cred.id, clientDataJSON: b64u.fromBuf(cred.response.clientDataJSON), attestationObject: b64u.fromBuf(cred.response.attestationObject) };
    },
    async get(pk) {
      const cred = await navigator.credentials.get({ publicKey: {
        ...pk, challenge: b64u.toBuf(pk.challenge),
        allowCredentials: (pk.allowCredentials || []).map(c => ({ ...c, id: b64u.toBuf(c.id) })),
      } });
      return {
        id: cred.id,
        clientDataJSON: b64u.fromBuf(cred.response.clientDataJSON),
        authenticatorData: b64u.fromBuf(cred.response.authenticatorData),
        signature: b64u.fromBuf(cred.response.signature),
      };
    },
    // Friendly text for the browser's WebAuthn errors
    errorText(e) {
      if (!e) return 'Biometric login failed.';
      if (e.name === 'NotAllowedError') return 'Biometric check was cancelled or timed out.';
      if (e.name === 'InvalidStateError') return 'This phone is already set up for biometric login.';
      if (e.name === 'SecurityError') return 'Biometric login needs the secure (https) site.';
      return e.message || 'Biometric login failed.';
    },
  };

  function goBack(fallbackUrl) {
    if (document.referrer && document.referrer.includes(location.host)) {
      history.back();
    } else {
      location.href = fallbackUrl || 'dashboard.php';
    }
  }

  return {
    api, peso, toast, digitsOnly, formatMobile, escapeHtml, alertHtml, goBack,
    localMobile, prettyMobile, bindMobileInput, mobileFromField, mountPinInput, biometrics,
  };
})();
