<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

page_header('About', 'Name-brand yarn below its usual price.', '/about.php');
?>
<div class="wrap section narrow prose">
  <h1>About Discount Yarn</h1>
  <p>Discount Yarn sells name-brand yarn below its usual price. Stock changes often and quantities are limited, so when a colour sells out it may not come back. If you love one, buy enough for the whole project.</p>
  <p>Questions about a yarn, or about an order? <a href="/contact.php">Send us a message</a> or email <a href="mailto:<?= e(config('support_email')) ?>"><?= e(config('support_email')) ?></a> and a real person will answer.</p>

  <section class="callout" id="shops">
    <h2>Yarn shops: clearing out your stash?</h2>
    <p>If your shop has discontinued colors, overstock, odd lots or a whole back room of yarn you'd like to move, we'd love to hear from you. Discount Yarn finds good homes for name-brand yarn that needs to go, so you get your shelf space back and the yarn gets knit.</p>
    <p>Tell us the brands, roughly how many skeins, and anything else we should know. We'll get back to you to talk it through.</p>
    <p><a class="btn" href="/contact.php?topic=sell">Tell us about your yarn</a></p>
  </section>
</div>
<?php page_footer();
