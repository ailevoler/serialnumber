(() => {
  const carousel = document.getElementById('wallet-carousel');
  const pages = [...document.querySelectorAll('[data-wallet-page]')];
  const hint = document.getElementById('wallet-swipe-hint');
  if (carousel) {
    // BCash theme: the home screen turns BCash blue while the BCash card is the one showing.
    const themeMeta = document.querySelector('meta[name="theme-color"]');
    const defaultThemeColor = themeMeta ? themeMeta.content : '#ffffff';
    const remember = value => { try { localStorage.setItem('ua.wallet', value); } catch (e) { /* storage blocked */ } };
    const remembered = () => { try { return localStorage.getItem('ua.wallet'); } catch (e) { return null; } };
    let current = -1;
    const setPage = index => {
      if (index !== current) {
        current = index;
        document.documentElement.classList.toggle('bcash-theme', index === 1);
        if (themeMeta) themeMeta.content = index === 1 ? '#0a6cf0' : defaultThemeColor;
        remember(index === 1 ? 'cash' : 'credits');
      }
      pages.forEach((button, i) => {
        button.classList.toggle('active', i === index);
        button.setAttribute('aria-pressed', String(i === index));
      });
      if (hint) hint.textContent = index === 0 ? 'Swipe left for BCash' : 'Swipe right for Credits';
    };
    pages.forEach((button, i) => button.addEventListener('click', () => {
      carousel.scrollTo({left: i * carousel.clientWidth, behavior: 'smooth'});
      setPage(i);
    }));
    let scheduled = false;
    carousel.addEventListener('scroll', () => {
      if (scheduled) return;
      scheduled = true;
      requestAnimationFrame(() => {
        setPage(Math.min(1, Math.max(0, Math.round(carousel.scrollLeft / carousel.clientWidth))));
        scheduled = false;
      });
    }, {passive:true});
    const wanted = new URLSearchParams(location.search).get('wallet') || remembered();
    if (wanted === 'cash') {
      document.documentElement.classList.add('bcash-theme');
      requestAnimationFrame(() => {
        carousel.scrollLeft = carousel.clientWidth;
        setPage(1);
      });
    } else {
      setPage(0);
    }
  }

  const card = document.querySelector('.wallet-gray, .home-wallet-cash');
  if (card) {
    const display = document.getElementById('boracay-cash-balance');
    const note = document.getElementById('boracay-cash-note');
    const currencyButtons = [...card.querySelectorAll('[data-currency]')];
    const privacy = card.querySelector('[data-cash-balance-toggle]');
    const centavos = Number(card.dataset.cashCentavos);
    const rate = Number(card.dataset.usdRate);
    const usdCents = Number(card.dataset.usdCents || 0);
    let currency = 'PHP';
    let hidden = false;
    const money = value => value.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    const render = () => {
      // PHP shows the BCash balance; USD shows the USD wallet (bought in USD ⇄ PHP) at today's rate.
      display.textContent = hidden ? '******' : currency === 'PHP' ? `PHP ${money(centavos / 100)}` : `USD ${money(usdCents / 100)}`;
      note.textContent = currency === 'PHP' ? 'PHP wallet balance' : rate > 0 ? `USD wallet · 1 USD = PHP ${money(rate)}` : 'USD wallet · rate updating';
      currencyButtons.forEach(button => {
        const active = button.dataset.currency === currency;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', String(active));
      });
    };
    currencyButtons.forEach(button => button.addEventListener('click', () => {
      currency = button.dataset.currency;
      render();
    }));
    privacy.addEventListener('click', () => {
      hidden = !hidden;
      privacy.setAttribute('aria-pressed', String(hidden));
      privacy.setAttribute('aria-label', hidden ? 'Show BCash balance' : 'Hide BCash balance');
      render();
    });
    render();
  }

  const input = document.getElementById('cash-credits');
  if (input) {
    const debit = document.getElementById('cash-debit');
    const receive = document.getElementById('cash-receive');
    const direction = document.getElementById('cash-direction');
    const buttons = [...document.querySelectorAll('[data-convert-direction]')];
    const render = () => {
      const toCredits = direction.value === 'cash_to_credits';
      const amount = Number(input.value);
      const valid = /^(?:[1-9][0-9]{0,4})(?:\.[0-9]{0,2})?$/.test(input.value) && amount >= 1 && amount <= 10000;
      const display = (valid ? amount : 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
      document.getElementById('cash-amount-label').textContent = toCredits ? 'BCash to convert (PHP)' : 'Credits to convert';
      document.getElementById('cash-debit-label').textContent = toCredits ? 'BCash deducted' : 'Credits deducted';
      document.getElementById('cash-receive-label').textContent = toCredits ? 'Credits added' : 'BCash added';
      debit.textContent = toCredits ? `PHP ${display}` : `${display} Credits`;
      receive.textContent = toCredits ? `${display} Credits` : `PHP ${display}`;
      buttons.forEach(button => {
        const active = button.dataset.convertDirection === direction.value;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', String(active));
      });
    };
    buttons.forEach(button => button.addEventListener('click', () => {
      direction.value = button.dataset.convertDirection;
      render();
    }));
    input.addEventListener('input', render);
    render();
  }
})();
