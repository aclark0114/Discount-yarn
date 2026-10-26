<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../includes/subscribers.php';
require __DIR__ . '/../includes/products.php';

$pdo = db();
$draft = $_SESSION['blast_draft'] ?? ['subject' => '', 'body' => ''];
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'image') {
        try {
            $name = store_upload($_FILES['image'] ?? []);
            flash($name ? 'Uploaded. Put this line in your message on its own: [image:' . url('uploads/' . $name) . '|Describe the image]' : 'Choose an image first.');
        } catch (InvalidArgumentException | RuntimeException $ex) {
            flash($ex->getMessage());
        }
        redirect('/admin/email.php');
    }

    if (in_array($action, ['preview', 'test', 'send'], true)) {
        $subject = trim(mb_substr((string)($_POST['subject'] ?? ''), 0, 200));
        $body = trim((string)($_POST['body'] ?? ''));
        $_SESSION['blast_draft'] = $draft = ['subject' => $subject, 'body' => $body];
        $problems = blast_problems($body);
        if ($subject === '' || $body === '') {
            flash('Write a subject and a message first.');
        } elseif ($problems) {
            flash(implode(' ', $problems));
        } elseif ($action === 'preview') {
            $m = blast_message($subject, $body, ['email' => 'preview@example.com', 'first_name' => 'Jane', 'token' => str_repeat('0', 32)]);
            $preview = ['html' => $m['HtmlBody'], 'text' => $m['TextBody'], 'subject' => $subject];
        } elseif (($blocker = blast_blocker()) !== '') {
            flash($blocker);
        } elseif ($action === 'test') {
            // A test goes to one address with a placeholder unsubscribe link,
            // and isn't added to the list or the sent log.
            $to = strtolower(trim((string)($_POST['test_to'] ?? ''))) ?: (string)config('owner_email');
            $_SESSION['blast_test_to'] = $to;
            if (!subscriber_email_ok($to)) {
                flash('"' . $to . '" is not an email address.');
            } else {
                [$s, , $err] = blast_send_to($subject, $body, [['email' => $to, 'first_name' => trim(mb_substr((string)($_POST['test_name'] ?? ''), 0, 100)), 'token' => str_repeat('0', 32)]]);
                flash($s ? 'Test sent to ' . $to . '.' : 'Test failed: ' . $err);
            }
        } elseif (empty($_POST['confirm_send'])) {
            flash('Tick the box to confirm before sending to the whole list.');
        } else {
            set_time_limit(600);
            $subs = $pdo->query("SELECT email, first_name, token FROM subscribers WHERE status = 'confirmed' ORDER BY id")->fetchAll();
            if (!$subs) {
                flash('Nobody has confirmed yet, so there is no one to send to.');
            } else {
                $pdo->prepare('INSERT INTO email_blasts (subject, body, created_at) VALUES (?, ?, ?)')->execute([$subject, $body, date('Y-m-d H:i:s')]);
                $blastId = (int)$pdo->lastInsertId();
                [$sent, $failed, $err] = blast_send_to($subject, $body, $subs);
                $pdo->prepare('UPDATE email_blasts SET sent_at = ?, recipients = ?, failed = ? WHERE id = ?')
                    ->execute([date('Y-m-d H:i:s'), $sent, $failed, $blastId]);
                if ($sent > 0) {
                    unset($_SESSION['blast_draft']);
                }
                flash('Sent to ' . $sent . ' of ' . count($subs) . ' subscribers.' . ($failed ? ' ' . $failed . ' failed: ' . $err : ''));
            }
        }
        if ($preview === null) {
            redirect('/admin/email.php');
        }
    }

    if ($action === 'add_confirmed' && (empty($_POST['consent']) || trim((string)($_POST['consent_note'] ?? '')) === '')) {
        flash('To add people without a confirmation email, tick the permission box and say how they agreed.');
        redirect('/admin/email.php');
    }

    if ($action === 'invite' || $action === 'add_confirmed') {
        $lines = preg_split('/[\r\n]+/', (string)($_POST['invites'] ?? ''));
        $counts = ['sent' => 0, 'added' => 0, 'confirmed' => 0, 'already' => 0, 'unsubscribed' => 0, 'failed' => 0, 'invalid' => 0];
        $note = trim((string)($_POST['consent_note'] ?? ''));
        foreach (array_slice(array_filter(array_map('trim', $lines)), 0, 50) as $line) {
            // "Name, email" or just "email"
            $parts = array_map('trim', str_getcsv($line));
            $email = '';
            $name = '';
            foreach ($parts as $p) {
                if (subscriber_email_ok($p)) {
                    $email = $p;
                } elseif ($name === '' && $p !== '') {
                    $name = $p;
                }
            }
            if ($email === '') {
                $counts['invalid']++;
                continue;
            }
            $counts[$action === 'invite' ? subscriber_request($email, $name, 'invite') : subscriber_add_confirmed($email, $name, $note)]++;
        }
        flash($action === 'invite'
            ? sprintf('Invites: %d sent, %d already on the list, %d failed, %d lines without an email.', $counts['sent'], $counts['already'], $counts['failed'], $counts['invalid'])
            : sprintf('Added as confirmed: %d new, %d were waiting to confirm, %d already on the list, %d skipped because they unsubscribed, %d lines without an email.',
                $counts['added'], $counts['confirmed'], $counts['already'], $counts['unsubscribed'], $counts['invalid']));
        redirect('/admin/email.php');
    }

    if ($action === 'remove') {
        $pdo->prepare("UPDATE subscribers SET status = 'unsubscribed', unsubscribed_at = ? WHERE id = ? AND status <> 'unsubscribed'")
            ->execute([date('Y-m-d H:i:s'), (int)($_POST['id'] ?? 0)]);
        flash('Removed from the list.');
        redirect('/admin/email.php');
    }
}

try {
    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM subscribers GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $recent = $pdo->query('SELECT * FROM subscribers ORDER BY id DESC LIMIT 50')->fetchAll();
    $blasts = $pdo->query('SELECT * FROM email_blasts ORDER BY id DESC LIMIT 20')->fetchAll();
    $setupError = '';
} catch (PDOException $ex) {
    $setupError = 'The email list isn\'t set up yet. Run schema_v7_email.sql in phpMyAdmin.';
}
$confirmed = (int)($counts['confirmed'] ?? 0);
$blocker = blast_blocker();

admin_header('Email');
?>
<div class="head"><h1>Email list</h1></div>
<?php if ($setupError): ?>
  <p class="error"><?= e($setupError) ?></p>
<?php else: ?>
<div class="stats">
  <div><strong><?= $confirmed ?></strong><span>confirmed subscribers</span></div>
  <div><strong><?= (int)($counts['pending'] ?? 0) ?></strong><span>waiting to confirm</span></div>
  <div><strong><?= (int)($counts['unsubscribed'] ?? 0) ?></strong><span>unsubscribed</span></div>
  <div><strong><?= count($blasts) ?></strong><span>blasts sent</span></div>
</div>
<?php if ($blocker): ?><p class="error"><?= e($blocker) ?></p><?php endif; ?>

<h2>Write a blast</h2>
<form class="edit" method="post" action="/admin/email.php" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <label>Image for an email (JPG or PNG, for example a header made in Canva)
    <input type="file" name="image" accept="image/jpeg,image/png">
  </label>
  <div class="actions"><button type="submit" name="action" value="image" formnovalidate>Upload image</button></div>
</form>

<form class="edit" method="post" action="/admin/email.php">
  <?= csrf_field() ?>
  <label>Subject <input name="subject" maxlength="200" value="<?= e($draft['subject']) ?>" required></label>
  <label>Message
    <textarea name="body" rows="12" required placeholder="Hi {first_name},&#10;&#10;New yarn just came in...&#10;&#10;https://discount-yarn.com/shop.php?sale=1"><?= e($draft['body']) ?></textarea>
  </label>
  <p class="muted-note">Plain text. Leave a blank line between paragraphs; web addresses become links. <code>{first_name}</code> becomes each person's first name, or "there". On a line of its own, <code>[product:paladin]</code> adds a product card with its photo, current price and a Shop now button (use the part after <code>slug=</code> in the product link), <code>[image:https://...|description]</code> adds an uploaded image, <code>[banner:Last call]</code> adds a bright headline, and <code>[lastchance]</code> adds four yarns with how many skeins are left and tappable colorways (it picks the four in-stock yarns with the fewest left; to choose, write <code>[lastchance:paladin, cardiff-cashmere-classic-tweed]</code>). The store name, your postal address and an unsubscribe link are added automatically.</p>
  <div class="actions">
    <button type="submit" name="action" value="preview">Preview</button>
  </div>
  <div class="test-row">
    <label>Test to <input name="test_to" type="email" value="<?= e($_SESSION['blast_test_to'] ?? config('owner_email')) ?>"></label>
    <label>First name (optional) <input name="test_name" maxlength="100"></label>
    <button type="submit" name="action" value="test">Send one test</button>
  </div>
  <p class="muted-note">A test goes only to that address. It isn't added to the list, and its unsubscribe link does nothing.</p>
  <div class="actions">
    <label class="check"><input type="checkbox" name="confirm_send" value="1"> Send to all <?= $confirmed ?> confirmed subscribers</label>
    <button type="submit" name="action" value="send" class="danger"<?= $confirmed === 0 ? ' disabled' : '' ?>>Send blast</button>
  </div>
</form>

<?php if ($preview): ?>
<h2>Preview: <?= e($preview['subject']) ?></h2>
<iframe class="email-preview" sandbox srcdoc="<?= e($preview['html']) ?>" title="Email preview"></iframe>
<details><summary>Plain-text version</summary><pre class="email-text"><?= e($preview['text']) ?></pre></details>
<?php endif; ?>

<?php if ($blasts): ?>
<h2>Sent</h2>
<div class="table-wrap"><table>
  <thead><tr><th>Sent</th><th>Subject</th><th>Delivered to</th><th>Failed</th></tr></thead>
  <tbody>
  <?php foreach ($blasts as $b): ?>
    <tr><td class="nowrap"><?= $b['sent_at'] ? e(date('M j, Y g:ia', strtotime($b['sent_at']))) : 'Not sent' ?></td>
      <td><?= e($b['subject']) ?></td><td><?= (int)$b['recipients'] ?></td><td><?= (int)$b['failed'] ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<h2>Add people</h2>
<form class="edit" method="post" action="/admin/email.php">
  <?= csrf_field() ?>
  <label>One person per line: "First name, email" or just the email (up to 50 at a time)
    <textarea name="invites" rows="5" placeholder="Jen, jen@example.com"></textarea>
  </label>
  <p class="muted-note"><strong>Send invites:</strong> each person gets one email asking them to confirm, and only gets blasts after they click it.</p>
  <div class="actions"><button type="submit" name="action" value="invite">Send invites</button></div>
  <p class="muted-note"><strong>Add as confirmed:</strong> no confirmation email; they get the next blast. Use this only for people who already agreed to email from you. Every blast has an unsubscribe link, and anyone who unsubscribed is never added back.</p>
  <label>How did they agree? (saved with each person) <input name="consent_note" maxlength="255" placeholder="For example: signed up at the shop counter, 26 Oct 2026"></label>
  <label class="check"><input type="checkbox" name="consent" value="1"> These people gave permission to receive email from Discount Yarn</label>
  <div class="actions"><button type="submit" name="action" value="add_confirmed">Add as confirmed</button></div>
</form>

<h2>Latest sign-ups</h2>
<?php if (!$recent): ?><p class="empty">No one yet. The sign-up form is in the footer of every page and at <a href="/subscribe.php" target="_blank">/subscribe.php</a>.</p><?php else: ?>
<div class="table-wrap"><table>
  <thead><tr><th>Email</th><th>Name</th><th>Status</th><th>How</th><th>Since</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($recent as $s): ?>
    <tr>
      <td><?= e($s['email']) ?></td><td><?= e($s['first_name']) ?></td>
      <td><?= e(ucfirst($s['status'])) ?></td><td<?= !empty($s['consent_note']) ? ' title="' . e($s['consent_note']) . '"' : '' ?>><?= e(['invite' => 'Invited', 'manual' => 'Added by hand'][$s['source']] ?? 'Signed up') ?></td>
      <td class="nowrap"><?= e(date('M j, Y', strtotime($s['confirmed_at'] ?? $s['created_at']))) ?></td>
      <td><?php if ($s['status'] !== 'unsubscribed'): ?>
        <form method="post" action="/admin/email.php"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button type="submit" class="danger">Remove</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php endif; ?>
<?php admin_footer();
