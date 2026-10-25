<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

page_header('About', 'Name-brand yarn below its usual price.', '/about.php');
?>
<div class="wrap section narrow prose">
  <h1>About Discount Yarn</h1>
  <p>Discount Yarn sells name-brand yarn below its usual price. Stock changes often and quantities are limited, so when a colour sells out it may not come back. If you love one, buy enough for the whole project.</p>
  <p>Questions about a yarn, or about an order? Email <a href="mailto:<?= e(config('support_email')) ?>"><?= e(config('support_email')) ?></a> and a real person will answer.</p>
</div>
<?php page_footer();
