<?php
require_once __DIR__ . '/includes/functions.php';

$el = election();
$state = election_state($el);
$rows = db()->query(
    'SELECT c.*, COUNT(v.id) AS total FROM candidates c LEFT JOIN votes v ON v.candidate_id = c.id GROUP BY c.id ORDER BY total DESC, c.name'
)->fetchAll();
$sum = array_sum(array_column($rows, 'total'));

page_head('Results');
?>
<div class="page-title">
  <h1>Election Results</h1>
  <p class="muted"><?= $state === 'closed' ? 'Final results' : 'Live results' ?> · <?= $sum ?> ballot<?= $sum === 1 ? '' : 's' ?> counted</p>
</div>

<section class="card">
  <?php foreach ($rows as $i => $r): $pct = $sum ? $r['total'] / $sum * 100 : 0; ?>
    <div class="result" style="--accent: <?= e($r['color']) ?>">
      <span class="avatar sm"><?= e(initials($r['name'])) ?></span>
      <div class="rbody">
        <div class="rhead">
          <strong><?= e($r['name']) ?><?= $i === 0 && $sum ? ' <span class="lead-tag">Leading</span>' : '' ?></strong>
          <span><?= (int) $r['total'] ?> · <?= number_format($pct, 1) ?>%</span>
        </div>
        <div class="muted small"><?= e($r['party']) ?></div>
        <div class="bar"><i style="width: <?= round($pct, 1) ?>%"></i></div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="muted">No candidates yet.</p><?php endif; ?>
</section>
<?php page_foot();
