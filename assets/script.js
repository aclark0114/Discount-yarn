(function () {
  document.documentElement.classList.add('js');

  // Mobile menu
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.getElementById('site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Quantity steppers on the product page
  document.querySelectorAll('.qty').forEach(function (box) {
    var input = box.querySelector('input');
    box.querySelectorAll('button[data-step]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var min = parseInt(input.min || '1', 10);
        var max = parseInt(input.max || '99', 10);
        var next = (parseInt(input.value, 10) || min) + parseInt(btn.dataset.step, 10);
        input.value = Math.max(min, Math.min(max, next));
      });
    });
  });

  // Quantity boxes never go past what's in stock, even when typed.
  document.querySelectorAll('input[type="number"][max]').forEach(function (input) {
    input.addEventListener('change', function () {
      var max = parseInt(input.max, 10), min = parseInt(input.min || '0', 10), v = parseInt(input.value, 10);
      if (isNaN(v) || v < min) input.value = min;
      else if (!isNaN(max) && v > max) input.value = max;
    });
  });

  // Product gallery: a thumbnail shows its photo in the main spot.
  var main = document.getElementById('main-img');
  document.querySelectorAll('.thumbs a').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      if (!main) return;
      ev.preventDefault();
      main.src = a.dataset.full;
      main.alt = a.dataset.alt;
      document.querySelectorAll('.thumbs a').forEach(function (x) { x.removeAttribute('aria-current'); });
      a.setAttribute('aria-current', 'true');
    });
  });

  // Colorway picker on the product page: swap photo, price and stock.
  var form = document.getElementById('add-form');
  if (form && form.querySelector('.swatches')) {
    var $ = function (id) { return document.getElementById(id); };
    var show = function (el, text) { if (!el) return; el.hidden = !text; if (text) el.textContent = text; };
    var update = function () {
      var radio = form.querySelector('input[name="product_id"]:checked');
      if (!radio) return;
      var d = radio.closest('.swatch').dataset;
      var stock = parseInt(d.stock, 10), inCart = parseInt(d.incart, 10);
      $('main-img').src = d.image;
      $('main-img').alt = d.name;
      show($('colorway-name'), d.name);
      $('price-now').textContent = d.now;
      show($('price-was'), d.was);
      show($('price-save'), d.save ? 'You save ' + d.save : '');
      show($('off-badge'), d.off !== '0' ? d.off + '% off' : '');
      $('sku').textContent = d.sku;
      var note = $('stock-note');
      var left = stock - inCart;
      note.textContent = inCart <= 0 ? stock + ' in stock'
        : (left > 0 ? stock + ' in stock, ' + inCart + ' already in your cart' : 'All ' + stock + ' in stock are in your cart');
      note.classList.toggle('stock-low', stock <= 5);
      var qty = $('qty');
      qty.max = Math.max(1, left);
      if (parseInt(qty.value, 10) > qty.max) qty.value = qty.max;
      $('add-btn').disabled = left <= 0;
      $('add-btn').textContent = left <= 0 ? 'All in your cart' : 'Add to cart';
      var ic = $('in-cart');
      if (ic) { ic.hidden = inCart === 0; ic.querySelector('span').textContent = inCart; }
    };
    form.addEventListener('change', function (ev) { if (ev.target.name === 'product_id') update(); });
  }

  // First-party page view beacon (see track.php). Respects Do Not Track.
  try {
    if (navigator.doNotTrack !== '1') {
      var beacon = new URLSearchParams();
      beacon.set('p', location.pathname);
      beacon.set('r', document.referrer || '');
      beacon.set('q', location.search.slice(1, 400));
      if (navigator.sendBeacon) {
        navigator.sendBeacon('/track.php', beacon);
      } else {
        fetch('/track.php', { method: 'POST', body: beacon, keepalive: true });
      }
    }
  } catch (e) {}

  // Gentle fade-in for marked sections
  var items = document.querySelectorAll('.reveal');
  if (!('IntersectionObserver' in window)) {
    items.forEach(function (el) { el.classList.add('in'); });
    return;
  }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('in');
        io.unobserve(entry.target);
      }
    });
  }, { rootMargin: '0px 0px -40px 0px' });
  items.forEach(function (el) { io.observe(el); });
})();
