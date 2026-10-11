const icons = {
  clock:'<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  download:'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
  'scan-line':'<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/>',
  x:'<path d="M18 6 6 18M6 6l12 12"/>',
  'check-circle':'<circle cx="12" cy="12" r="10"/><path d="m7 12 3 3 7-7"/>',
  'circle-alert':'<circle cx="12" cy="12" r="10"/><path d="M12 7v6M12 17h.01"/>',
  bike:'<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="m15 6 2 0 3 8M9 6h4l3 11H9l-3-7h5M10 6l-2-2"/>',
  wallet:'<rect x="2" y="5" width="20" height="16" rx="2"/><path d="M2 9h20M16 14h6"/>',
  banknote:'<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/>',
  'arrow-right':'<path d="M5 12h14m-6-6 6 6-6 6"/>',
  'arrow-left':'<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>', menu:'<path d="M4 6h16M4 12h16M4 18h16"/>', bell:'<path d="M10 21h4"/><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>',
  user:'<path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/>', mail:'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>', lock:'<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  eye:'<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>', phone:'<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
  home:'<path d="m3 11 9-8 9 8"/><path d="M5 10v11h14V10"/><path d="M9 21v-6h6v6"/>', receipt:'<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2Z"/><path d="M8 7h8M8 12h8M8 17h5"/>', 'qr-code':'<path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3z"/><path d="M14 14h2v2h-2zM19 14h2v4h-2zM14 19h4v2h-4zM21 21h0"/>',
  gift:'<path d="M20 12v10H4V12M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H8.5a2.5 2.5 0 1 1 2-4L12 7Zm0 0h3.5a2.5 2.5 0 1 0-2-4L12 7Z"/>', coins:'<circle cx="8" cy="8" r="6"/><path d="M18.1 10.6A6 6 0 1 1 10.6 18"/><path d="M8 6v4M6 8h4"/>',
  'refresh-cw':'<path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/>', 'shopping-cart':'<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 2h3l3 14h11l3-9H6"/>',
  car:'<path d="M19 17h2v-6l-2-5H5l-2 5v6h2"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/><path d="M5 11h14"/>', house:'<path d="m3 11 9-8 9 8"/><path d="M5 10v11h14V10"/><path d="M9 21v-6h6v6"/>', ticket:'<path d="M2 9a3 3 0 0 0 0 6v4h20v-4a3 3 0 0 1 0-6V5H2z"/><path d="M13 5v14"/>',
  'map-pin':'<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>', utensils:'<path d="M4 3v7a4 4 0 0 0 8 0V3M8 3v19M20 3v19M16 3h4"/>', send:'<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>', plane:'<path d="M17.8 19.2 16 11l6-6a2 2 0 0 0-3-3l-6 6-8.2-1.8-1.1 1.1 6.6 3.8-3.3 3.3-3-.5-.9.9 4.1 2 2 4.1.9-.9-.5-3 3.3-3.3 3.8 6.6z"/>',
  'shopping-bag':'<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>', smartphone:'<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>', megaphone:'<path d="m3 11 18-5v12L3 13z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
  'chevron-right':'<path d="m9 18 6-6-6-6"/>', 'credit-card':'<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>', zap:'<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>', image:'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
  'badge-percent':'<path d="M3.8 8.4 8.4 3.8h7.2l4.6 4.6v7.2l-4.6 4.6H8.4l-4.6-4.6z"/><path d="m9 15 6-6"/><circle cx="9" cy="9" r="1"/><circle cx="15" cy="15" r="1"/>', 'log-out':'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>', sparkles:'<path d="m12 3-1.9 5.8L4 11l6.1 2.2L12 19l1.9-5.8L20 11l-6.1-2.2Z"/>',
  search:'<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>', star:'<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9z"/>', bike2:'<circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h4l3-6h4l1 6M10 8h3"/>', store:'<path d="M3 9 5 3h14l2 6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v9h14v-9"/>'
};

document.querySelectorAll('[data-icon]').forEach((el) => {
  const name = el.dataset.icon;
  el.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[name] || ''}</svg>`;
});

document.querySelectorAll('.reveal').forEach((button) => {
  button.addEventListener('click', () => {
    const input = button.closest('.field').querySelector('input');
    input.type = input.type === 'password' ? 'text' : 'password';
  });
});

document.querySelectorAll('.segmented button').forEach((button) => {
  button.addEventListener('click', () => {
    document.querySelectorAll('.segmented button,.tab-panel').forEach((el) => el.classList.remove('active'));
    button.classList.add('active');
    document.getElementById(button.dataset.tab).classList.add('active');
  });
});

const bannerSlider = document.querySelector('.banner-slider');
if (bannerSlider) {
  const slides = [...bannerSlider.querySelectorAll('.banner-slide')];
  const dots = [...bannerSlider.querySelectorAll('.banner-dots button')];
  let activeBanner = 0;
  const showBanner = (index) => {
    activeBanner = (index + slides.length) % slides.length;
    slides.forEach((slide, i) => slide.classList.toggle('active', i === activeBanner));
    dots.forEach((dot, i) => dot.classList.toggle('active', i === activeBanner));
  };
  dots.forEach((dot, i) => dot.addEventListener('click', () => showBanner(i)));
  setInterval(() => showBanner(activeBanner + 1), 4500);
}
const balanceToggle = document.querySelector('[data-balance-toggle]');
if (balanceToggle) {
  const amount = balanceToggle.closest('.credit-card, .home-wallet').querySelector('[data-balance-amount], h1');
  const original = amount.textContent;
  balanceToggle.addEventListener('click', () => {
    const hidden = balanceToggle.getAttribute('aria-pressed') !== 'true';
    amount.textContent = hidden ? '****' : original;
    balanceToggle.setAttribute('aria-pressed', String(hidden));
    balanceToggle.setAttribute('aria-label', hidden ? 'Show balance' : 'Hide balance');
    balanceToggle.title = hidden ? 'Show balance' : 'Hide balance';
  });
}

/* Cancel forms: "Other" reason shows a text box. */
document.querySelectorAll('select[data-reason]').forEach(sel => {
  const other = sel.form && sel.form.querySelector('[data-reason-other]');
  if (!other) return;
  const sync = () => { const on = sel.value === 'Other'; other.hidden = !on; other.required = on; };
  sel.addEventListener('change', sync); sync();
});
