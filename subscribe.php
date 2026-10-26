<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/subscribers.php';

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $name = trim(mb_substr((string)($_POST['first_name'] ?? ''), 0, 100));
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        // Hidden field only bots fill in: pretend it worked.
        $message = 'Check your inbox for a confirmation link.';
    } elseif (!subscriber_email_ok($email)) {
        $error = 'That email address doesn\'t look right.';
    } elseif (!signup_allowed()) {
        $error = 'Too many sign-ups from here in the last hour. Please try again later.';
    } else {
        // Same answer whether or not the address is already on the list.
        $result = subscriber_request($email, $name, 'signup');
        $message = $result === 'failed'
            ? 'We couldn\'t send the confirmation email just now. Please try again in a few minutes.'
            : 'Almost done. Check your inbox for a confirmation link.';
    }
}

page_header('Email list', 'Get an email when name-brand yarn goes on sale.');
?>
<div class="wrap section narrow">
  <h1>Join the email list</h1>
  <p class="lede">An occasional email when new yarn comes in or prices drop. Unsubscribe any time.</p>
  <?php if ($message): ?><p class="notice"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="notice"><?= e($error) ?></p><?php endif; ?>
  <?php if (!$message): ?>
  <form class="signup-form" method="post" action="/subscribe.php">
    <?= csrf_field() ?>
    <label for="su-name">First name <span class="muted">(optional)</span></label>
    <input id="su-name" name="first_name" autocomplete="given-name" value="<?= e($_POST['first_name'] ?? '') ?>">
    <label for="su-email">Email</label>
    <input id="su-email" name="email" type="email" autocomplete="email" required value="<?= e($_POST['email'] ?? '') ?>">
    <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
    <button class="btn" type="submit">Sign up</button>
  </form>
  <?php endif; ?>
</div>
<?php page_footer();
