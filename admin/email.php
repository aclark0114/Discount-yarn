<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../includes/subscribers.php';

$pdo = db();
$draft = $_SESSION['blast_draft'] ?? ['subject' => '', 'body' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if (in_array($action, ['test', 'send'], true)) {
        $subject = trim(mb_substr((string)($_POST['subject'] ?? ''), 0, 200));
        $body = trim((string)($_POST['body'] ?? ''));
        $_SESSION['blast_draft'] = ['subject' => $subject, 'body' => $body];
        if ($subject === '' || $body === '') {
            flash('Write a subject and a message first.');
        } elseif (($blocker = blast_blocker()) !== '') {
            flash($blocker);
        } elseif ($action === 'test') {
            $owner = (string)config('owner_email');
            // A test uses a placeholder unsubscribe link and the first name "there".
            [$s, , $err] = blast_send_to('[Test] ' . $subject, $body, [['email' => $owner, 'first_name' => '', 'token' => str_repeat('0', 32)]]);
            flash($s ? 'Test sent to ' . $owner . '.' : 'Test failed: ' . $err);
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
        redirect('/admin/email.php');
    }

    if ($action === 'invite') {
        $lines = preg_split('/[\r\n]+/', (string)($_POST['invites'] ?? ''));
        $counts = ['sent' => 0, 'already' => 0, 'failed' => 0, 'invalid' => 0];
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
            $counts[subscriber_request($email, $name, 'invite')]++;
        }
        flash(sprintf('Invites: %d sent, %d already on the list, %d failed, %d lines without an email.', $counts['sent'], $counts['already'], $counts['failed'], $counts['invalid']));
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
<form class="edit" method="post" action="/admin/email.php">
  <?= csrf_field() ?>
  <label>Subject <input name="subject" maxlength="200" value="<?= e($draft['subject']) ?>" required></label>
  <label>Message
    <textarea name="body" rows="12" required placeholder="Hi {first_name},&#10;&#10;New yarn just came in...&#10;&#10;https://discount-yarn.com/shop.php?sale=1"><?= e($draft['body']) ?></textarea>
  </label>
  <p class="muted-note">Plain text. Leave a blank line between paragraphs; web addresses become links. <code>{first_name}</code> becomes each person's first name, or "there". The store name, your postal address and an unsubscribe link are added automatically.</p>
  <div class="actions">
    <button type="submit" name="action" value="test">Send a test to <?= e(config('owner_email')) ?></button>
  </div>
  <div class="actions">
    <label class="check"><input type="checkbox" name="confirm_send" value="1"> Send to all <?= $confirmed ?> confirmed subscribers</label>
    <button type="submit" name="action" value="send" class="danger"<?= $confirmed === 0 ? ' disabled' : '' ?>>Send blast</button>
  </div>
</form>

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

<h2>Invite people</h2>
<form class="edit" method="post" action="/admin/email.php">
  <?= csrf_field() ?>
  <label>One person per line: "First name, email" or just the email (up to 50 at a time)
    <textarea name="invites" rows="5" placeholder="Jen, jen@example.com"></textarea>
  </label>
  <p class="muted-note">Each person gets one email asking them to confirm. They only receive blasts after they click it, and hearing nothing means they're never emailed again. Only invite people who'd expect to hear from you, such as customers who agreed to email from Stars Hollow, with Stars Hollow's OK.</p>
  <div class="actions"><button type="submit" name="action" value="invite">Send invites</button></div>
</form>

<h2>Latest sign-ups</h2>
<?php if (!$recent): ?><p class="empty">No one yet. The sign-up form is in the footer of every page and at <a href="/subscribe.php" target="_blank">/subscribe.php</a>.</p><?php else: ?>
<div class="table-wrap"><table>
  <thead><tr><th>Email</th><th>Name</th><th>Status</th><th>How</th><th>Since</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($recent as $s): ?>
    <tr>
      <td><?= e($s['email']) ?></td><td><?= e($s['first_name']) ?></td>
      <td><?= e(ucfirst($s['status'])) ?></td><td><?= e($s['source'] === 'invite' ? 'Invited' : 'Signed up') ?></td>
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
