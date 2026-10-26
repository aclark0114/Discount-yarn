<?php
// Find products whose text mentions a phrase (Stars Hollow by default) and
// take them out of the store or put them back.
require __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/products.php';

const HIDE_DEFAULT_PHRASE = 'Stars Hollow';

if (!products_have_hide_columns()) {
    admin_header('Hide products');
    echo '<h1>Hide products</h1><p class="error">Run <code>schema_v9_hide.sql</code> in phpMyAdmin first, then run a Shopify sync so tags and SEO text are saved.</p>';
    admin_footer();
    exit;
}

$phrase = trim(mb_substr((string)($_REQUEST['phrase'] ?? HIDE_DEFAULT_PHRASE), 0, 80));
$showAll = !empty($_REQUEST['all']);
$back = '/admin/hide.php?' . http_build_query(array_filter(['phrase' => $phrase, 'all' => $showAll ? 1 : null]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'hide_list') {
        // Product addresses, store links (product.php?slug=...) or Shopify
        // links (/products/handle), separated by lines, commas or spaces.
        $wanted = [];
        foreach (preg_split('/[\s,]+/', (string)($_POST['list'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $item) {
            if (preg_match('/[?&]slug=([^&#\s]+)/', $item, $m) || preg_match('~/products/([^/?#\s]+)~', $item, $m)) {
                $item = rawurldecode($m[1]);
            }
            $wanted[slugify($item)] = $item;
        }
        $found = [];
        if ($wanted) {
            $in = implode(', ', array_fill(0, count($wanted), '?'));
            $st = db()->prepare("SELECT DISTINCT group_slug FROM products WHERE group_slug IN ($in)");
            $st->execute(array_keys($wanted));
            $found = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        $missing = array_diff_key($wanted, array_flip($found));
        products_set_hidden($found, true);
        flash(!$wanted ? 'Paste at least one product address first.'
            : sprintf('Hid %d of %d products.', count($found), count($wanted))
              . ($missing ? ' Not found in the store: ' . implode(', ', array_slice(array_values($missing), 0, 20)) . (count($missing) > 20 ? ' and ' . (count($missing) - 20) . ' more' : '') . '.' : ''));
        redirect($back);
    }
    $groups = (array)($_POST['groups'] ?? []);
    if (preg_match('/^(hide|show):(.+)$/', $action, $m)) {   // the button on one row
        [$action, $groups] = [$m[1], [$m[2]]];
    }
    if (!in_array($action, ['hide', 'show'], true) || !$groups) {
        flash('Tick at least one product first.');
    } else {
        $n = products_set_hidden($groups, $action === 'hide');
        $count = count(array_unique($groups));
        flash($action === 'hide'
            ? sprintf('Took %d %s out of the store (%d colorways). They stay hidden through Shopify syncs until you put them back.', $count, $count === 1 ? 'product' : 'products', $n)
            : sprintf('Put %d %s back in the store (%d colorways).', $count, $count === 1 ? 'product' : 'products', $n));
    }
    redirect($back);
}

// Words of the phrase with anything between them, ignoring case and
// apostrophes, so "Stars Hollow" also finds StarsHollow, starshollowyarns.com
// and Star's Hollow.
$words = preg_split('/\s+/', str_replace(["'", '’'], '', $phrase), -1, PREG_SPLIT_NO_EMPTY);
$wordRe = fn($w) => implode("['’]?", array_map(fn($c) => preg_quote($c, '/'), mb_str_split($w)));
$re = $words ? '/' . implode('[\W_]*', array_map($wordRe, $words)) . '/iu' : null;
$fields = ['name' => 'Name', 'brand' => 'Brand', 'colorway' => 'Colorway', 'product_type' => 'Type', 'description' => 'Description', 'meta_text' => 'Tags, SEO or photo text'];

$groups = [];
foreach (db()->query('SELECT * FROM products ORDER BY name, colorway, id') as $p) {
    $key = (string)($p['group_slug'] ?: $p['slug']);
    $g = &$groups[$key];
    $g ??= ['slug' => $key, 'rep' => $p, 'colorways' => 0, 'stock' => 0, 'hidden' => 0, 'live' => 0, 'hits' => []];
    $g['colorways']++;
    $g['stock'] += max(0, (int)$p['stock']);
    $g['hidden'] += (int)$p['admin_hidden'];
    $g['live'] += (int)$p['is_active'];
    if ($re) {
        foreach ($fields as $col => $label) {
            $text = (string)($p[$col] ?? '');
            if (!isset($g['hits'][$label]) && preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $start = max(0, $m[0][1] - 50);
                $snip = substr($text, $start, strlen($m[0][0]) + 100);
                $snip = mb_convert_encoding($snip, 'UTF-8', 'UTF-8');   // a cut may split a character
                $g['hits'][$label] = ($start > 0 ? '...' : '') . trim(preg_replace('/\s+/', ' ', $snip)) . '...';
            }
        }
    }
    unset($g);
}
$matches = array_filter($groups, fn($g) => $g['hits']);
$list = $showAll ? $groups : $matches;
$hiddenCount = count(array_filter($groups, fn($g) => $g['hidden'] > 0));

$highlight = function (string $snip) use ($re): string {
    if (!$re || !preg_match_all($re, $snip, $m, PREG_OFFSET_CAPTURE)) {
        return e($snip);
    }
    $out = '';
    $at = 0;
    foreach ($m[0] as [$hit, $pos]) {
        $out .= e(substr($snip, $at, $pos - $at)) . '<mark>' . e($hit) . '</mark>';
        $at = $pos + strlen($hit);
    }
    return $out . e(substr($snip, $at));
};

admin_header('Hide products');
?>
<h1>Hide products</h1>
<p>Find products that mention a phrase anywhere in their name, description, tags, SEO text or photo descriptions, then take them out of the store or put them back. A hidden product stays hidden through Shopify syncs. To check the pictures themselves, use <a href="/admin/photos.php">Review photos</a>.</p>
<form class="search" method="get" action="/admin/hide.php">
  <input type="search" name="phrase" value="<?= e($phrase) ?>" placeholder="<?= e(HIDE_DEFAULT_PHRASE) ?>" aria-label="Phrase">
  <label class="check"><input type="checkbox" name="all" value="1"<?= $showAll ? ' checked' : '' ?>> List every product</label>
  <button type="submit">Search</button>
</form>
<p><strong><?= count($matches) ?></strong> of <?= count($groups) ?> products mention "<?= e($phrase) ?>". <?= $hiddenCount ?> <?= $hiddenCount === 1 ? 'product is' : 'products are' ?> hidden by you right now.
  <?php if (!$showAll && $hiddenCount > count(array_filter($matches, fn($g) => $g['hidden'] > 0))): ?><a href="/admin/hide.php?all=1">See them all</a><?php endif; ?></p>

<details class="hide-list"<?= isset($_GET['paste']) ? ' open' : '' ?>>
  <summary>Hide a list of products</summary>
  <form class="edit" method="post" action="<?= e($back) ?>">
    <?= csrf_field() ?>
    <label>Product addresses, one per line (the part after "slug=", a store product link, or a Stars Hollow product link)
      <textarea name="list" rows="6" placeholder="paladin&#10;cardiff-cashmere-classic-tweed&#10;https://starshollowyarns.com/products/some-yarn"></textarea>
    </label>
    <div class="actions"><button type="submit" name="action" value="hide_list" class="danger">Hide these products</button></div>
  </form>
</details>

<?php if (!$list): ?>
  <p class="empty">Nothing found.<?= $groups && !array_filter($groups, fn($g) => $g['rep']['meta_text'] !== null) ? ' Run a Shopify sync so tags and SEO text are saved, then look again.' : '' ?></p>
<?php else: ?>
<form method="post" action="<?= e($back) ?>">
  <?= csrf_field() ?>
  <div class="actions">
    <label class="check"><input type="checkbox" data-check-all> Select all</label>
    <button type="submit" name="action" value="hide" class="danger">Hide selected</button>
    <button type="submit" name="action" value="show">Show selected</button>
  </div>
  <div class="table-wrap">
  <table class="hide-table">
    <thead><tr><th></th><th></th><th>Product</th><th>Where it says "<?= e($phrase) ?>"</th><th>In store</th></tr></thead>
    <tbody>
    <?php foreach ($list as $g): $p = $g['rep']; $hidden = $g['hidden'] > 0; ?>
      <tr class="<?= $hidden ? 'is-hidden' : '' ?>">
        <td class="pick"><input type="checkbox" name="groups[]" value="<?= e($g['slug']) ?>" aria-label="Select <?= e($p['name']) ?>"></td>
        <td class="thumb"><img src="<?= e(product_image($p, 100)) ?>" alt="" width="44" height="44" loading="lazy"></td>
        <td><a href="/admin/product_edit.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a><br><small><?= e(implode(' · ', array_filter([$p['brand'], $g['colorways'] . ' ' . ($g['colorways'] === 1 ? 'colorway' : 'colorways'), $g['stock'] . ' in stock']))) ?></small></td>
        <td class="hits" data-label="Where it says it"><?php foreach ($g['hits'] as $label => $snip): ?><div><strong><?= e($label) ?>:</strong> <?= $highlight($snip) ?></div><?php endforeach; ?><?= $g['hits'] ? '' : '<span class="muted-note">Not mentioned</span>' ?></td>
        <td class="status"><span><?= $hidden ? '<strong>Hidden by you</strong>' : ($g['live'] ? 'Showing' : 'Not live') ?></span>
          <?php if ($hidden): ?><button type="submit" name="action" value="show:<?= e($g['slug']) ?>">Show</button><?php else: ?><button type="submit" name="action" value="hide:<?= e($g['slug']) ?>" class="danger">Hide</button><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</form>
<script>
document.querySelector('[data-check-all]')?.addEventListener('change', function () {
  document.querySelectorAll('input[name="groups[]"]').forEach(b => { b.checked = this.checked; });
});
</script>
<?php endif; ?>
<?php admin_footer();
