<?php
require __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $on = ($_POST['action'] ?? '') === 'close';
    if (!maintenance_set($on, (string)($_POST['message'] ?? ''))) {
        flash('Couldn\'t save the setting. Check that the folder holding the config file is writable.');
    } else {
        flash($on ? 'The store is now under construction. Shoppers see the back-soon page; you still see the store while logged in.' : 'The store is open again.');
    }
    redirect('/admin/site.php');
}

$state = maintenance_state();
$fromConfig = $state !== null && !is_file(maintenance_file());
admin_header('Store status');
?>
<h1>Store status</h1>
<?php if ($state === null): ?>
  <p><strong>Open.</strong> Shoppers can browse and buy.</p>
  <h2>Put up an "under construction" page</h2>
  <form class="edit" method="post" action="/admin/site.php">
    <?= csrf_field() ?>
    <label>Message for shoppers (optional)
      <textarea name="message" rows="3" maxlength="500" placeholder="We're making some changes to the store and will be back soon. Leave your email and we'll let you know when the shelves are open again."></textarea>
    </label>
    <p class="muted-note">Shoppers see a "We'll be back soon" page with your message and an email sign-up box. You keep seeing the real store while you're logged in here, so you can check your changes. Orders already paid still come through, and email list links still work.</p>
    <div class="actions"><button type="submit" name="action" value="close" class="danger">Close the store</button></div>
  </form>
<?php else: ?>
  <p class="error"><strong>Under construction</strong><?= $state['since'] !== '' ? ' since ' . e(date('M j, g:ia', strtotime($state['since']))) : '' ?>. Shoppers see the back-soon page.</p>
  <?php if ($state['message'] !== ''): ?><p>Message: <?= e($state['message']) ?></p><?php endif; ?>
  <p><a href="/" target="_blank" rel="noopener">View the store as you see it</a>. To see what shoppers see, open the store in a private window.</p>
  <?php if ($fromConfig): ?>
    <p class="muted-note">This was turned on by <code>'maintenance' =&gt; true</code> in the config file. Remove that line to open the store.</p>
  <?php else: ?>
  <form method="post" action="/admin/site.php">
    <?= csrf_field() ?>
    <div class="actions"><button type="submit" name="action" value="open">Open the store</button></div>
  </form>
  <?php endif; ?>
<?php endif; ?>
<?php admin_footer();
