<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

page_header('About', 'Why our yarn costs less: closeouts, overstock and discontinued colorways from brands you know.', '/about.php');
?>
<div class="wrap section narrow prose">
  <p class="eyebrow">Our story</p>
  <h1>Why our yarn costs less</h1>
  <p class="lede">Every season, mills retire colorways, shops overorder, and perfectly good dye lots get left on the shelf. We buy those lots and sell them for less.</p>
  <p>Nothing here is a second or a seconds-quality skein. It's the same yarn you would find at a local shop, in shades that are being discontinued or quantities a shop couldn't use. When a lot sells out, it usually doesn't come back, so if you love a color, buy enough for the whole project.</p>
  <h2>How we keep prices low</h2>
  <ul>
    <li>We buy in bulk from mills, distributors and shops clearing space.</li>
    <li>We run a small operation with simple packaging.</li>
    <li>We keep every order's skeins from a single dye lot whenever stock allows.</li>
  </ul>
  <p>Questions about a yarn, or have a lot you want to sell? Email <a href="mailto:<?= e(config('support_email')) ?>"><?= e(config('support_email')) ?></a>.</p>
</div>
<?php page_footer();
