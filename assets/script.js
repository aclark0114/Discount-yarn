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
