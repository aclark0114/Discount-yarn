<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/subscribers.php';

$s = subscriber_confirm((string)($_GET['t'] ?? ''));
page_header('Email list');
?>
<div class="wrap section narrow">
  <?php if ($s && $s['status'] === 'confirmed'): ?>
    <h1>You're on the list</h1>
    <p class="lede">Thanks<?= $s['first_name'] !== '' ? ', ' . e($s['first_name']) : '' ?>. We'll email you when new yarn comes in or prices drop.</p>
    <a class="btn" href="/shop.php?cat=yarn">Browse yarn</a>
  <?php elseif ($s && $s['status'] === 'unsubscribed'): ?>
    <h1>You've unsubscribed</h1>
    <p>This address was taken off the list. <a href="/subscribe.php">Sign up again</a> if you change your mind.</p>
  <?php else: ?>
    <h1>That link didn't work</h1>
    <p>It may be old or incomplete. <a href="/subscribe.php">Sign up again</a> and we'll send a fresh one.</p>
  <?php endif; ?>
</div>
<?php page_footer();
