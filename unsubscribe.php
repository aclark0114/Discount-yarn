<?php
// Unsubscribe. A plain GET only shows a button, so link scanners in mail
// systems can't unsubscribe people. Mail apps' one-click unsubscribe
// (RFC 8058) POSTs "List-Unsubscribe=One-Click" and is handled straight away.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/subscribers.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
    subscriber_unsubscribe($token);
    http_response_code(200);
    exit('Unsubscribed');
}

require __DIR__ . '/includes/layout.php';
$done = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $done = subscriber_unsubscribe($token);
}
$s = $done ?? (preg_match('/^[a-f0-9]{32}$/', $token) ? subscriber_by('token', $token) : null);

page_header('Unsubscribe');
?>
<div class="wrap section narrow">
  <?php if (!$s): ?>
    <h1>That link didn't work</h1>
    <p>Email <a href="mailto:<?= e(config('support_email')) ?>"><?= e(config('support_email')) ?></a> and we'll take you off the list by hand.</p>
  <?php elseif ($s['status'] === 'unsubscribed'): ?>
    <h1>You're unsubscribed</h1>
    <p><?= e($s['email']) ?> won't get any more emails from us. <a href="/subscribe.php">Sign up again</a> any time.</p>
  <?php else: ?>
    <h1>Unsubscribe?</h1>
    <p>Stop sending emails to <?= e($s['email']) ?>.</p>
    <form method="post" action="/unsubscribe.php">
      <?= csrf_field() ?>
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <button class="btn" type="submit">Unsubscribe</button>
    </form>
  <?php endif; ?>
</div>
<?php page_footer();
