<?php
require_once __DIR__ . '/includes/functions.php';

$voter = require_voter();
if (!$voter['has_voted']) {
    redirect('vote.php');
}
$receipt = $_SESSION['receipt'] ?? null;

page_head('Vote Recorded');
?>
<section class="card center narrow">
  <div class="check">✔</div>
  <h1>Thank you for voting</h1>
  <p class="lead">Your ballot has been securely recorded<?= $voter['voted_at'] ? ' on ' . e(date('M j, Y · g:i A', strtotime($voter['voted_at']))) : '' ?>.</p>
  <?php if ($receipt): ?>
    <p class="muted">Your anonymous receipt code (save it — it is shown only once):</p>
    <div class="receipt"><?= e($receipt) ?></div>
  <?php endif; ?>
  <a class="btn primary" href="results.php">View live results</a>
</section>
<?php
unset($_SESSION['receipt']);
page_foot();
