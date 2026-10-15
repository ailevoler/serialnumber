/* XGEN LIFE Intl — Live Chat (polling) */
(function () {
  'use strict';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }

  /**
   * A chat conversation bound to a container.
   * opts: api, csrf, viewer ('member'|'admin'), thread (admin only), list (messages element), form, onStatus
   */
  function ChatBox(opts) {
    this.o = opts;
    this.lastId = 0;
    this.timer = null;
    this.busy = false;
    var self = this;
    if (opts.form) {
      var ta = opts.form.querySelector('textarea');
      ta.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); opts.form.requestSubmit(); }
      });
      ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 120) + 'px'; });
      opts.form.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = ta.value.trim();
        if (!body) { return; }
        ta.value = ''; ta.style.height = 'auto';
        self.send(body);
      });
    }
  }

  ChatBox.prototype.url = function (params) {
    var q = Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
    return this.o.api + '?' + q;
  };

  ChatBox.prototype.render = function (msgs) {
    var list = this.o.list;
    var empty = list.querySelector('.chat-empty');
    if (msgs.length && empty) { empty.remove(); }
    var mine = this.o.viewer;
    msgs.forEach(function (m) {
      if (m.id <= this.lastId) { return; }
      this.lastId = m.id;
      var side = m.role === 'system' ? 'system' : (m.role === mine ? 'me' : 'them');
      var b = el('div', 'msg msg-' + side);
      if (side === 'them') { b.appendChild(el('span', 'msg-name', m.name)); }
      b.appendChild(el('div', 'msg-text', m.body));
      b.appendChild(el('span', 'msg-time', m.time));
      list.appendChild(b);
    }, this);
    if (msgs.length) { list.scrollTop = list.scrollHeight; }
  };

  ChatBox.prototype.poll = function () {
    var self = this;
    if (self.busy) { return Promise.resolve(); }
    self.busy = true;
    var p = { action: 'poll', after: self.lastId };
    if (self.o.thread) { p.thread = self.o.thread; }
    return fetch(self.url(p), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.error) { return; }
        self.render(d.messages || []);
        if (self.o.onStatus) { self.o.onStatus(d.status); }
      })
      .catch(function () {})
      .then(function () { self.busy = false; });
  };

  ChatBox.prototype.send = function (body) {
    var self = this;
    var fd = new FormData();
    fd.append('action', 'send');
    fd.append('body', body);
    fd.append('_csrf', self.o.csrf);
    if (self.o.thread) { fd.append('thread', self.o.thread); }
    var p = { action: 'send', after: self.lastId };
    if (self.o.thread) { p.thread = self.o.thread; }
    return fetch(self.url(p), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.error) { window.alert(d.error); return; }
        self.render(d.messages || []);
      })
      .catch(function () { window.alert('Message not sent. Please check your connection.'); });
  };

  ChatBox.prototype.start = function (ms) {
    var self = this;
    self.stop();
    self.poll();
    self.timer = setInterval(function () { if (!document.hidden) { self.poll(); } }, ms || 4000);
  };
  ChatBox.prototype.stop = function () { if (this.timer) { clearInterval(this.timer); this.timer = null; } };

  window.XgenChat = ChatBox;

  // ---- Floating widget for members
  var w = document.querySelector('[data-chat-widget]');
  if (w) {
    var panel = w.querySelector('[data-chat-panel]');
    var badge = w.querySelector('[data-chat-badge]');
    var box = new ChatBox({ api: w.dataset.api, csrf: w.dataset.csrf, viewer: 'member', list: w.querySelector('[data-chat-messages]'), form: w.querySelector('[data-chat-form]') });
    var setBadge = function (n) {
      badge.textContent = n; badge.hidden = !n;
      document.querySelectorAll('[data-chat-navbadge]').forEach(function (b) { b.textContent = n; b.hidden = !n; });
    };
    var unreadTimer = null;
    var watchUnread = function () {
      unreadTimer = setInterval(function () {
        if (document.hidden) { return; }
        fetch(box.url({ action: 'unread' }), { credentials: 'same-origin' }).then(function (r) { return r.json(); })
          .then(function (d) { if (typeof d.unread === 'number') { setBadge(d.unread); } }).catch(function () {});
      }, 20000);
    };
    w.querySelectorAll('[data-chat-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        panel.hidden = !panel.hidden;
        w.classList.toggle('open', !panel.hidden);
        if (!panel.hidden) {
          clearInterval(unreadTimer); setBadge(0); box.start(4000);
          setTimeout(function () { panel.querySelector('textarea').focus(); }, 50);
        } else {
          box.stop(); watchUnread();
        }
      });
    });
    watchUnread();
  }

  // ---- Full-page conversation (member support page / admin inbox)
  var page = document.querySelector('[data-chat-page]');
  if (page) {
    var statusEl = document.querySelector('[data-chat-status]');
    var pbox = new ChatBox({
      api: page.dataset.api, csrf: page.dataset.csrf, viewer: page.dataset.viewer,
      thread: page.dataset.thread || null,
      list: page.querySelector('[data-chat-messages]'), form: page.querySelector('[data-chat-form]'),
      onStatus: function (s) { if (statusEl && s) { statusEl.textContent = s === 'open' ? 'Open' : 'Closed'; } }
    });
    pbox.start(3000);
  }

  // ---- Admin inbox list auto-refresh
  var inbox = document.querySelector('[data-chat-inbox]');
  if (inbox) {
    var current = inbox.dataset.current;
    var refreshInbox = function () {
      if (document.hidden) { return; }
      fetch(inbox.dataset.api + '?action=inbox&status=' + encodeURIComponent(inbox.dataset.status), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.threads) { return; }
          inbox.innerHTML = '';
          if (!d.threads.length) { inbox.appendChild(el('p', 'text-muted chat-empty', 'No conversations.')); }
          d.threads.forEach(function (t) {
            var a = el('a', 'thread-item' + (String(t.id) === current ? ' active' : '') + (t.unread ? ' unread' : ''));
            a.href = '?status=' + encodeURIComponent(inbox.dataset.status) + '&thread=' + t.id;
            var top = el('div', 'thread-top');
            top.appendChild(el('strong', '', t.name));
            top.appendChild(el('small', '', t.time));
            a.appendChild(top);
            var bottom = el('div', 'thread-bottom');
            bottom.appendChild(el('span', '', '@' + t.username + ' · ' + t.last));
            if (t.unread) { bottom.appendChild(el('em', 'nav-count', String(t.unread))); }
            a.appendChild(bottom);
            inbox.appendChild(a);
          });
          document.querySelectorAll('[data-chat-navbadge]').forEach(function (b) { b.textContent = d.unread_threads; b.hidden = !d.unread_threads; });
        }).catch(function () {});
    };
    setInterval(refreshInbox, 8000);
  }

  // Quick replies (admin)
  document.querySelectorAll('[data-quick-reply]').forEach(function (b) {
    b.addEventListener('click', function () {
      var ta = document.querySelector('[data-chat-page] textarea');
      if (ta) { ta.value = b.getAttribute('data-quick-reply'); ta.focus(); }
    });
  });
})();
