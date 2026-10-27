<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/postmark.php';
require __DIR__ . '/includes/subscribers.php';
require __DIR__ . '/includes/contact.php';

$form = ['name' => '', 'email' => '', 'shop' => '', 'phone' => '', 'topic' => isset(CONTACT_TOPICS[$_GET['topic'] ?? '']) ? $_GET['topic'] : 'order', 'message' => ''];
$errors = [];
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['name' => 100, 'email' => 200, 'shop' => 150, 'phone' => 40, 'message' => 5000] as $k => $max) {
        $form[$k] = trim(mb_substr((string)($_POST[$k] ?? ''), 0, $max));
    }
    $form['topic'] = isset(CONTACT_TOPICS[$_POST['topic'] ?? '']) ? $_POST['topic'] : 'other';
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $sent = true;   // only bots fill in the hidden field: pretend it worked
    } else {
        if ($form['name'] === '') {
            $errors[] = 'Please tell us your name.';
        }
        if (!subscriber_email_ok($form['email'])) {
            $errors[] = 'That email address doesn\'t look right.';
        }
        if (mb_strlen($form['message']) < 10) {
            $errors[] = 'Please write a little more in your message.';
        }
        if (!$errors && !contact_allowed()) {
            $errors[] = 'Too many messages from here in the last hour. Please email us at ' . config('support_email') . ' instead.';
        }
        if (!$errors) {
            if (contact_send($form)) {
                $sent = true;
            } else {
                $errors[] = 'Sorry, your message didn\'t send. Please email us at ' . config('support_email') . ' instead.';
            }
        }
    }
}

page_header('Contact us', 'Questions about yarn or an order, or a shop with yarn to clear out? Get in touch with Discount Yarn.', '/contact.php');
?>
<div class="wrap section narrow">
  <h1>Contact us</h1>
  <?php if ($sent): ?>
    <p class="notice">Thanks, <?= e(trim(explode(' ', $form['name'])[0]) ?: 'we got it') ?>. Your message is on its way to us, and we've sent you a confirmation email. We'll reply soon.</p>
    <p><a class="btn" href="/shop.php?cat=yarn">Back to the yarn</a></p>
  <?php else: ?>
  <p class="lede">Questions about a yarn or an order, or a shop with yarn to clear out? Send us a note and a real person will reply. You can also email <a href="mailto:<?= e(config('support_email')) ?>"><?= e(config('support_email')) ?></a>.</p>
  <?php if ($errors): ?><div class="notice" role="alert"><?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?></div><?php endif; ?>
  <form class="contact-form" method="post" action="/contact.php" novalidate>
    <?= csrf_field() ?>
    <label for="c-topic">What's it about?</label>
    <select id="c-topic" name="topic">
      <?php foreach (CONTACT_TOPICS as $k => $label): ?><option value="<?= e($k) ?>"<?= $form['topic'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <div class="contact-cols">
      <div><label for="c-name">Your name</label><input id="c-name" name="name" autocomplete="name" required value="<?= e($form['name']) ?>"></div>
      <div><label for="c-email">Email</label><input id="c-email" name="email" type="email" autocomplete="email" required value="<?= e($form['email']) ?>"></div>
    </div>
    <div class="contact-cols">
      <div><label for="c-shop">Shop name <span class="muted">(optional)</span></label><input id="c-shop" name="shop" autocomplete="organization" value="<?= e($form['shop']) ?>"></div>
      <div><label for="c-phone">Phone <span class="muted">(optional)</span></label><input id="c-phone" name="phone" type="tel" autocomplete="tel" value="<?= e($form['phone']) ?>"></div>
    </div>
    <label for="c-message">Message</label>
    <textarea id="c-message" name="message" rows="7" required placeholder="<?= $form['topic'] === 'sell' ? 'Brands, roughly how many skeins, and whether it is discontinued colors, overstock or a whole shop' : '' ?>"><?= e($form['message']) ?></textarea>
    <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
    <button class="btn" type="submit">Send message</button>
  </form>
  <?php endif; ?>
</div>
<?php page_footer();
