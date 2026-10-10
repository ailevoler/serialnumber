/* PCEC Community Platform — front-end behaviour (vanilla JS, no dependencies). */
(function () {
  'use strict';

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const meta = (n) => (document.querySelector(`meta[name="${n}"]`) || {}).content || '';
  const BASE = meta('base-url');
  const CSRF = meta('csrf-token');

  function toast(msg) {
    const t = $('#toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._h);
    t._h = setTimeout(() => t.classList.remove('show'), 2200);
  }

  async function api(path, data) {
    const body = new URLSearchParams(data);
    body.append('csrf', CSRF);
    const res = await fetch(`${BASE}/api/${path}`, {
      method: 'POST', body, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' },
    });
    if (res.status === 401) { location.href = `${BASE}/login.php`; throw new Error('auth'); }
    const json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Request failed');
    return json;
  }

  /* ---------- Modals ---------- */
  function openModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.hidden = false;
    document.body.style.overflow = 'hidden';
    const f = m.querySelector('textarea, input:not([type=hidden]):not([type=file])');
    if (f && window.matchMedia('(min-width: 640px)').matches) setTimeout(() => f.focus(), 50);
  }
  function closeModal(m) {
    m.hidden = true;
    if (!$$('.modal:not([hidden])').length) document.body.style.overflow = '';
  }
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-open]');
    if (opener) { e.preventDefault(); openModal(opener.dataset.open); return; }
    const closer = e.target.closest('[data-close]');
    if (closer) { closeModal(closer.closest('.modal')); return; }
    if (e.target.classList.contains('modal')) closeModal(e.target);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') $$('.modal:not([hidden])').forEach(closeModal);
  });
  // Modals rendered visible by the server (e.g. form errors) should lock scroll too.
  if ($$('.modal:not([hidden])').length) document.body.style.overflow = 'hidden';

  /* ---------- Dropdowns ---------- */
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-dropdown]');
    $$('.dropdown.open').forEach((d) => { if (!btn || d !== btn.parentElement) d.classList.remove('open'); });
    if (btn) btn.parentElement.classList.toggle('open');
  });

  /* ---------- Confirm forms ---------- */
  document.addEventListener('submit', (e) => {
    const f = e.target.closest('form[data-confirm]');
    if (f && !confirm(f.dataset.confirm)) e.preventDefault();
  });

  /* ---------- Password visibility ---------- */
  const EYE = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  $$('.pw-toggle').forEach((b) => {
    const off = b.innerHTML;
    b.addEventListener('click', () => {
      const i = b.parentElement.querySelector('input');
      const show = i.type === 'password';
      i.type = show ? 'text' : 'password';
      b.innerHTML = show ? EYE : off;
      b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  /* ---------- Copy link ---------- */
  async function copy(text) {
    const full = new URL(text, location.href).href;
    try { await navigator.clipboard.writeText(full); toast('Link copied'); }
    catch { prompt('Copy this link:', full); }
  }
  document.addEventListener('click', (e) => {
    const c = e.target.closest('[data-copy]');
    if (c) { e.preventDefault(); copy(c.dataset.copy); }
  });

  /* ---------- Composer ---------- */
  $$('.composer-form').forEach((form) => {
    const input = form.querySelector('input[type=file]');
    const preview = form.querySelector('.composer-preview');
    const card = form.closest('.composer-card');
    const ta = form.querySelector('textarea');
    const expand = () => card && card.classList.add('expanded');

    if (ta) ta.addEventListener('focus', expand);
    form.querySelectorAll('label[data-accept]').forEach((l) => {
      l.addEventListener('click', () => { input.accept = l.dataset.accept; });
    });
    input.addEventListener('change', () => {
      const f = input.files[0];
      preview.innerHTML = '';
      if (!f) { preview.hidden = true; return; }
      if (f.size > 20 * 1024 * 1024) { toast('File is too large (max 20 MB)'); input.value = ''; preview.hidden = true; return; }
      expand();
      let el;
      if (f.type.startsWith('image/')) { el = new Image(); el.src = URL.createObjectURL(f); }
      else if (f.type.startsWith('video/')) { el = document.createElement('video'); el.src = URL.createObjectURL(f); el.controls = true; }
      else { el = document.createElement('div'); el.className = 'file-chip'; el.textContent = '📎 ' + f.name; }
      const rm = document.createElement('button');
      rm.type = 'button'; rm.className = 'remove'; rm.setAttribute('aria-label', 'Remove'); rm.textContent = '✕';
      rm.addEventListener('click', () => { input.value = ''; preview.innerHTML = ''; preview.hidden = true; });
      preview.append(el, rm);
      preview.hidden = false;
    });
    form.addEventListener('submit', (e) => {
      if (!ta.value.trim() && !input.files.length) { e.preventDefault(); toast('Write something or attach a file'); return; }
      const b = form.querySelector('.composer-submit');
      if (b) { b.disabled = true; b.style.opacity = .7; }
    });
  });

  /* ---------- Post actions ---------- */
  document.addEventListener('click', async (e) => {
    const like = e.target.closest('[data-like]');
    if (like) {
      like.disabled = true;
      try {
        const r = await api('post_action.php', { action: 'like', id: like.dataset.like });
        like.classList.toggle('on', r.on);
        like.classList.remove('pop'); void like.offsetWidth; if (r.on) like.classList.add('pop');
        like.querySelector('span').textContent = r.count;
      } catch (err) { toast(err.message); }
      like.disabled = false;
      return;
    }
    const save = e.target.closest('[data-save]');
    if (save) {
      try {
        const r = await api('post_action.php', { action: 'save', id: save.dataset.save });
        save.classList.toggle('on', r.on);
        toast(r.on ? 'Saved to your bookmarks' : 'Removed from bookmarks');
      } catch (err) { toast(err.message); }
      return;
    }
    const share = e.target.closest('[data-share]');
    if (share) {
      const url = new URL(share.dataset.url, location.href).href;
      let shared = false;
      if (navigator.share) {
        try { await navigator.share({ title: 'PCEC Community', url }); shared = true; } catch { /* cancelled */ }
      } else { await copy(url); shared = true; }
      if (shared) {
        try { const r = await api('post_action.php', { action: 'share', id: share.dataset.share }); share.querySelector('span').textContent = r.count; } catch { /* ignore */ }
      }
      return;
    }
    const follow = e.target.closest('[data-follow]');
    if (follow) {
      follow.disabled = true;
      try {
        const r = await api('follow.php', { id: follow.dataset.follow });
        $$(`[data-follow="${follow.dataset.follow}"]`).forEach((b) => {
          b.textContent = r.on ? 'Following' : 'Follow';
          b.classList.toggle('btn-outline', r.on);
          b.classList.toggle('btn-gradient', !r.on);
        });
        const fc = $('[data-followers]');
        if (fc) fc.textContent = r.followers;
        toast(r.on ? 'You are now following' : 'Unfollowed');
      } catch (err) { toast(err.message); }
      follow.disabled = false;
      return;
    }
    const pray = e.target.closest('[data-pray]');
    if (pray) {
      try {
        const r = await api('pray.php', { id: pray.dataset.pray });
        pray.classList.toggle('btn-soft-on', r.on);
        pray.classList.toggle('btn-soft', !r.on);
        pray.querySelector('.pray-label').textContent = r.on ? 'Prayed' : 'I prayed';
        pray.querySelector('.pray-count').textContent = r.count;
        if (r.on) toast('🙏 Thank you for praying');
      } catch (err) { toast(err.message); }
    }
  });

  /* ---------- Onboarding slider ---------- */
  const slider = $('[data-slider]');
  if (slider) {
    const slides = $$('.slide', slider);
    const dots = $$('[data-dots] button');
    const next = $('[data-next]');
    let i = 0;
    const go = (n) => {
      i = n;
      slides.forEach((s, k) => { s.classList.toggle('active', k === i); s.setAttribute('aria-hidden', k === i ? 'false' : 'true'); });
      dots.forEach((d, k) => d.classList.toggle('on', k === i));
    };
    dots.forEach((d, k) => d.addEventListener('click', () => go(k)));
    next.addEventListener('click', (e) => {
      if (i < slides.length - 1) { e.preventDefault(); go(i + 1); }
    });
    let x0 = null;
    slider.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
    slider.addEventListener('touchend', (e) => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 40) go(Math.max(0, Math.min(slides.length - 1, i + (dx < 0 ? 1 : -1))));
      x0 = null;
    });
  }

  /* ---------- Contact filter ---------- */
  $$('[data-filter-list]').forEach((inp) => {
    const list = $(inp.dataset.filterList);
    inp.addEventListener('input', () => {
      const q = inp.value.trim().toLowerCase();
      $$('[data-name]', list).forEach((r) => { r.hidden = q && !r.dataset.name.includes(q); });
    });
  });

  /* ---------- Chat ---------- */
  const box = $('#chat-messages');
  if (box) {
    const cid = box.dataset.conversation;
    const form = $('#chat-form');
    let last = 0;
    let busy = false;

    const render = (msgs) => {
      const nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
      msgs.forEach((m) => {
        if (m.id <= last) return;
        last = m.id;
        const div = document.createElement('div');
        div.className = 'msg' + (m.mine ? ' mine' : '');
        div.textContent = m.body;
        const t = document.createElement('small');
        t.textContent = m.time;
        div.appendChild(t);
        box.appendChild(div);
      });
      if (msgs.length && (nearBottom || msgs.some((m) => m.mine))) box.scrollTop = box.scrollHeight;
    };
    const poll = async () => {
      if (busy || document.hidden) return;
      busy = true;
      try {
        const res = await fetch(`${BASE}/api/messages.php?c=${cid}&after=${last}`, { credentials: 'same-origin' });
        if (res.ok) render((await res.json()).messages);
      } catch { /* offline — retry next tick */ }
      busy = false;
    };
    poll().then(() => { box.scrollTop = box.scrollHeight; });
    setInterval(poll, 3000);
    document.addEventListener('visibilitychange', poll);

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const input = form.body;
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      busy = true;
      try {
        const r = await api('messages.php', { c: cid, body: text, after: last });
        render(r.messages);
      } catch (err) { toast(err.message); input.value = text; }
      busy = false;
      input.focus();
    });
  }

  /* ---------- Church picker (enhances <select data-picker-select>) ---------- */
  const svg = (d, cls = '') => `<svg class="icon ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d}</svg>`;
  const IC = {
    church: '<path d="M12 2v4M10 4h4"/><path d="M6 21V11l6-5 6 5v10"/><path d="M3 21h18"/><path d="M10 21v-4a2 2 0 0 1 4 0v4"/>',
    pin: '<path d="M12 22s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
    check: '<path d="M5 12l5 5 9-10"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-5-5"/>',
    chev: '<path d="M6 9l6 6 6-6"/>',
    x: '<path d="M6 6l12 12M18 6L6 18"/>',
    skip: '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>',
  };
  const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const initialsOf = (name) => name.replace(/[^A-Za-z ]/g, '').split(/\s+/).filter((w) => w && !/^(of|the|and)$/i.test(w)).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
  const hueOf = (str) => { let h = 0; for (const ch of str) h = (h * 31 + ch.charCodeAt(0)) % 360; return h; };

  $$('[data-picker]').forEach((wrap) => {
    const select = $('[data-picker-select]', wrap);
    if (!select) return;
    const opts = Array.from(select.options);
    const placeholder = opts[0] && opts[0].value === '' ? opts[0].text : 'Select…';
    const listId = 'pk-' + Math.random().toString(36).slice(2, 8);

    const items = opts.map((o, i) => {
      const empty = o.value === '';
      const meta = [o.dataset.city, o.dataset.region].filter(Boolean).join(', ');
      return {
        value: o.value, name: empty ? 'No church / Skip for now' : o.text, meta, denom: o.dataset.denomination || '', empty,
        search: (o.text + ' ' + meta + ' ' + (o.dataset.denomination || '')).toLowerCase(), index: i,
      };
    });

    wrap.classList.add('picker-ready');
    wrap.insertAdjacentHTML('beforeend', `
      <button type="button" class="field picker-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="${listId}">
        ${svg(IC.church)}<span class="picker-value"></span>${svg(IC.chev, 'picker-caret')}
      </button>
      <div class="picker-backdrop" hidden></div>
      <div class="picker-panel" hidden>
        <div class="picker-sheet-head"><strong>${esc(select.dataset.sheetTitle || placeholder)}</strong>
          <button type="button" class="icon-btn picker-close" aria-label="Close">${svg(IC.x)}</button></div>
        <label class="picker-search">${svg(IC.search)}<input type="search" placeholder="${esc(select.dataset.searchPlaceholder || 'Search…')}" autocomplete="off" aria-controls="${listId}"></label>
        <ul class="picker-list" role="listbox" id="${listId}">
          ${items.map((it) => `
            <li role="option" class="picker-option${it.empty ? ' is-empty' : ''}" data-index="${it.index}" aria-selected="false">
              ${it.empty
                ? `<span class="picker-badge picker-badge-skip">${svg(IC.skip)}</span>`
                : `<span class="picker-badge" style="--h:${hueOf(it.name)}">${esc(initialsOf(it.name))}</span>`}
              <span class="picker-text"><strong>${esc(it.name)}</strong>
                ${it.empty ? '<small>You can add your church later in your profile</small>'
                  : `<small>${it.meta ? svg(IC.pin) + esc(it.meta) : ''}${it.denom ? `<em>${esc(it.denom)}</em>` : ''}</small>`}</span>
              ${svg(IC.check, 'picker-check')}
            </li>`).join('')}
        </ul>
        <p class="picker-none" hidden>No church matches your search.</p>
      </div>`);

    const trigger = $('.picker-trigger', wrap);
    const valueEl = $('.picker-value', wrap);
    const panel = $('.picker-panel', wrap);
    const backdrop = $('.picker-backdrop', wrap);
    const search = $('.picker-search input', wrap);
    const optionEls = $$('.picker-option', wrap);
    const none = $('.picker-none', wrap);
    let active = -1;

    const render = () => {
      const it = items[select.selectedIndex];
      valueEl.innerHTML = !it || it.empty
        ? `<span class="picker-placeholder">${esc(placeholder)}</span>`
        : `<strong>${esc(it.name)}</strong>${it.meta ? `<small>${esc(it.meta)}</small>` : ''}`;
      wrap.classList.toggle('has-value', !!it && !it.empty);
      optionEls.forEach((el) => el.setAttribute('aria-selected', String(+el.dataset.index === select.selectedIndex)));
    };
    const visible = () => optionEls.filter((el) => !el.hidden);
    const setActive = (el) => {
      optionEls.forEach((o) => o.classList.remove('active'));
      active = el ? +el.dataset.index : -1;
      if (el) { el.classList.add('active'); el.scrollIntoView({ block: 'nearest' }); }
    };
    const isSheet = () => window.matchMedia('(max-width: 640px)').matches;

    const open = () => {
      panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      wrap.classList.add('open');
      search.value = '';
      filter();
      if (isSheet()) {
        backdrop.hidden = false;
        document.body.style.overflow = 'hidden';
      } else {
        // Open upwards when there is not enough room below the field.
        const r = trigger.getBoundingClientRect();
        wrap.classList.toggle('drop-up', window.innerHeight - r.bottom < 340 && r.top > window.innerHeight - r.bottom);
      }
      setActive(optionEls[select.selectedIndex] || optionEls[0]);
      if (!isSheet() || window.matchMedia('(hover: hover)').matches) setTimeout(() => search.focus(), 30);
    };
    const close = (focusTrigger = true) => {
      if (panel.hidden) return;
      panel.hidden = true;
      backdrop.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      wrap.classList.remove('open', 'drop-up');
      if (!$$('.modal:not([hidden])').length) document.body.style.overflow = '';
      if (focusTrigger) trigger.focus();
    };
    const choose = (index) => {
      select.selectedIndex = index;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      render();
      close();
    };
    function filter() {
      const q = search.value.trim().toLowerCase();
      optionEls.forEach((el) => {
        const it = items[+el.dataset.index];
        el.hidden = !!q && (it.empty || !q.split(/\s+/).every((w) => it.search.includes(w)));
      });
      const vis = visible();
      none.hidden = vis.length > 0;
      if (!vis.some((el) => +el.dataset.index === active)) setActive(vis[0] || null);
    }

    trigger.addEventListener('click', () => (panel.hidden ? open() : close()));
    $('.picker-close', wrap).addEventListener('click', () => close());
    backdrop.addEventListener('click', () => close());
    search.addEventListener('input', filter);
    optionEls.forEach((el) => {
      el.addEventListener('click', () => choose(+el.dataset.index));
      el.addEventListener('mousemove', () => { if (active !== +el.dataset.index) setActive(el); });
    });
    const onKey = (e) => {
      const vis = visible();
      const pos = vis.findIndex((el) => +el.dataset.index === active);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (panel.hidden) { open(); return; }
        const next = e.key === 'ArrowDown' ? Math.min(vis.length - 1, pos + 1) : Math.max(0, pos - 1);
        setActive(vis[next]);
      } else if (e.key === 'Enter' && !panel.hidden) {
        e.preventDefault();
        if (active >= 0) choose(active);
      } else if (e.key === 'Escape' && !panel.hidden) {
        e.preventDefault(); e.stopPropagation(); close();
      } else if (e.key === 'Tab' && !panel.hidden) {
        close(false);
      }
    };
    trigger.addEventListener('keydown', onKey);
    search.addEventListener('keydown', onKey);
    document.addEventListener('click', (e) => { if (!wrap.contains(e.target)) close(false); });
    render();
  });

  /* ---------- Auto-hide flash alerts ---------- */
  $$('.content > .alert-success').forEach((a) => setTimeout(() => { a.style.transition = 'opacity .4s'; a.style.opacity = 0; setTimeout(() => a.remove(), 400); }, 4000));
})();
