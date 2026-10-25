// Mounts Stripe Embedded Checkout on /checkout.php.
(function () {
  var el = document.getElementById('checkout');
  if (!el) return;
  var fail = function (msg) {
    el.innerHTML = '';
    var p = document.createElement('p');
    p.className = 'notice';
    p.textContent = msg;
    el.appendChild(p);
  };
  if (typeof window.Stripe !== 'function') {
    fail('The secure payment form could not load. Check your connection or ad blocker, then refresh this page.');
    return;
  }
  var stripe = window.Stripe(el.dataset.pk);
  var secret = el.dataset.secret;
  stripe.initEmbeddedCheckout({ fetchClientSecret: function () { return Promise.resolve(secret); } })
    .then(function (checkout) {
      var loading = document.getElementById('checkout-loading');
      if (loading) loading.remove();
      checkout.mount(el);
    })
    .catch(function () {
      fail('The payment form could not start. Go back to your cart and try again.');
    });
})();
