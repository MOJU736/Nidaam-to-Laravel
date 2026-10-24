<?php
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = strtoupper(trim($_POST['voter_id'] ?? ''));
    $pin = $_POST['pin'] ?? '';
    // Throttle brute force: 5 attempts per 10 minutes per session.
    $_SESSION['tries'] = array_filter($_SESSION['tries'] ?? [], fn ($t) => $t > time() - 600);
    if (count($_SESSION['tries']) >= 5) {
        flash('Too many attempts. Please wait a few minutes.');
        redirect('index.php');
    }
    $st = db()->prepare('SELECT * FROM voters WHERE voter_id = ?');
    $st->execute([$id]);
    $v = $st->fetch();
    if ($v && password_verify($pin, $v['pin_hash'])) {
        session_regenerate_id(true);
        $_SESSION['voter_id'] = $v['id'];
        redirect('vote.php');
    }
    $_SESSION['tries'][] = time();
    flash('Invalid Voter ID or PIN.');
    redirect('index.php');
}

$el = election();
$state = election_state($el);
$counts = db()->query('SELECT (SELECT COUNT(*) FROM voters) v, (SELECT COUNT(*) FROM voters WHERE has_voted=1) c')->fetch();
$labels = ['open' => 'Voting is open', 'closed' => 'Voting is closed', 'scheduled' => 'Voting opens soon'];

page_head('Voter Login');
?>
<section class="hero">
  <div>
    <span class="pill <?= e($state) ?>"><?= e($labels[$state]) ?></span>
    <h1><?= e($el['title']) ?></h1>
    <p class="lead">Your vote is your voice. Sign in with your Voter ID and PIN to cast one secret, secure ballot.</p>
    <?php if ($el['ends_at'] && $state === 'open'): ?><p class="muted">Polls close <?= e(date('M j, Y · g:i A', strtotime($el['ends_at']))) ?></p><?php endif; ?>
    <ul class="stats">
      <li><b><?= (int) $counts['v'] ?></b><span>Registered voters</span></li>
      <li><b><?= (int) $counts['c'] ?></b><span>Ballots cast</span></li>
      <li><b><?= $counts['v'] ? round($counts['c'] / $counts['v'] * 100) : 0 ?>%</b><span>Turnout</span></li>
    </ul>
  </div>
  <form class="card login" method="post" autocomplete="off">
    <h2>Voter sign in</h2>
    <?= csrf_field() ?>
    <label>Voter ID<input name="voter_id" required placeholder="e.g. V1001" autofocus></label>
    <label>PIN<input name="pin" type="password" inputmode="numeric" required placeholder="••••"></label>
    <button class="btn primary block">Continue to ballot</button>
    <p class="muted small">Demo voters: V1001 / 1111 · V1002 / 2222 · V1003 / 3333</p>
  </form>
</section>
<?php page_foot();
