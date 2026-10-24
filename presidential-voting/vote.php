<?php
require_once __DIR__ . '/includes/functions.php';

$voter = require_voter();
$el = election();
$state = election_state($el);

if ($voter['has_voted']) {
    redirect('thanks.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($state !== 'open') {
        flash('Voting is not currently open.');
        redirect('vote.php');
    }
    $cid = (int) ($_POST['candidate'] ?? 0);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Atomic claim: only succeeds if this voter has not voted yet (prevents double voting).
        $claim = $pdo->prepare('UPDATE voters SET has_voted = 1, voted_at = NOW() WHERE id = ? AND has_voted = 0');
        $claim->execute([$voter['id']]);
        $exists = $pdo->prepare('SELECT COUNT(*) FROM candidates WHERE id = ?');
        $exists->execute([$cid]);
        if ($claim->rowCount() !== 1 || !$exists->fetchColumn()) {
            throw new RuntimeException('invalid');
        }
        $receipt = strtoupper(bin2hex(random_bytes(6)));
        $pdo->prepare('INSERT INTO votes (candidate_id, receipt) VALUES (?, ?)')->execute([$cid, $receipt]);
        $pdo->commit();
        $_SESSION['receipt'] = $receipt;
        redirect('thanks.php');
    } catch (RuntimeException) {
        $pdo->rollBack();
        flash('Your ballot could not be recorded. Please select a candidate.');
        redirect('vote.php');
    }
}

$candidates = db()->query('SELECT * FROM candidates ORDER BY name')->fetchAll();

page_head('Your Ballot');
?>
<div class="page-title">
  <h1>Official Ballot</h1>
  <p class="muted">Welcome, <?= e($voter['full_name']) ?>. Select one candidate, then confirm. This cannot be changed.</p>
</div>

<?php if ($state !== 'open'): ?>
  <div class="alert error">Voting is <?= $state === 'scheduled' ? 'not open yet' : 'closed' ?>.</div>
<?php else: ?>
<form method="post" id="ballot">
  <?= csrf_field() ?>
  <div class="grid">
    <?php foreach ($candidates as $c): ?>
      <label class="cand" style="--accent: <?= e($c['color']) ?>">
        <input type="radio" name="candidate" value="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>" required>
        <span class="avatar"><?= e(initials($c['name'])) ?></span>
        <span class="cname"><?= e($c['name']) ?></span>
        <span class="party"><?= e($c['party']) ?></span>
        <em class="slogan">“<?= e($c['slogan']) ?>”</em>
        <span class="bio"><?= e($c['bio']) ?></span>
        <span class="tick">✔ Selected</span>
      </label>
    <?php endforeach; ?>
  </div>
  <div class="actions"><button type="button" class="btn primary" id="review">Review &amp; submit vote</button></div>

  <div class="modal" id="confirm" hidden>
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="ctitle">
      <h2 id="ctitle">Confirm your vote</h2>
      <p>You are voting for <strong id="cname"></strong>. Your choice is final and secret.</p>
      <div class="row">
        <button type="button" class="btn" id="cancel">Go back</button>
        <button class="btn primary">Cast my vote</button>
      </div>
    </div>
  </div>
</form>
<?php endif;
page_foot();
