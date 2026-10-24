<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'election') {
        $dt = fn ($k) => ($_POST[$k] ?? '') !== '' ? date('Y-m-d H:i:s', strtotime($_POST[$k])) : null;
        $pdo->prepare('UPDATE election SET title=?, is_open=?, starts_at=?, ends_at=? WHERE id=1')
            ->execute([trim($_POST['title']) ?: 'Presidential Election', isset($_POST['is_open']) ? 1 : 0, $dt('starts_at'), $dt('ends_at')]);
        flash('Election settings saved.', 'success');
    } elseif ($act === 'add_candidate' || $act === 'edit_candidate') {
        $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#1d4ed8';
        $f = [trim($_POST['name']), trim($_POST['party']), trim($_POST['slogan']), trim($_POST['bio']), $color];
        if ($f[0] === '' || $f[1] === '') {
            flash('Name and party are required.');
        } elseif ($act === 'add_candidate') {
            $pdo->prepare('INSERT INTO candidates (name, party, slogan, bio, color) VALUES (?,?,?,?,?)')->execute($f);
            flash('Candidate added.', 'success');
        } else {
            $pdo->prepare('UPDATE candidates SET name=?, party=?, slogan=?, bio=?, color=? WHERE id=?')->execute([...$f, (int) $_POST['id']]);
            flash('Candidate updated.', 'success');
        }
    } elseif ($act === 'delete_candidate') {
        $n = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE candidate_id = ?');
        $n->execute([(int) $_POST['id']]);
        if ($n->fetchColumn()) {
            flash('Cannot delete a candidate who has received votes.');
        } else {
            $pdo->prepare('DELETE FROM candidates WHERE id = ?')->execute([(int) $_POST['id']]);
            flash('Candidate removed.', 'success');
        }
    } elseif ($act === 'add_voter') {
        $vid = strtoupper(trim($_POST['voter_id']));
        $pin = $_POST['pin'];
        if ($vid === '' || trim($_POST['full_name']) === '' || strlen($pin) < 4) {
            flash('Voter ID, name and a PIN of at least 4 characters are required.');
        } else {
            try {
                $pdo->prepare('INSERT INTO voters (voter_id, full_name, pin_hash) VALUES (?,?,?)')
                    ->execute([$vid, trim($_POST['full_name']), password_hash($pin, PASSWORD_DEFAULT)]);
                flash('Voter registered.', 'success');
            } catch (PDOException) {
                flash('That Voter ID already exists.');
            }
        }
    } elseif ($act === 'delete_voter') {
        $pdo->prepare('DELETE FROM voters WHERE id = ? AND has_voted = 0')->execute([(int) $_POST['id']]);
        flash('Voter removed (voters who already voted are kept).', 'success');
    }
    redirect('index.php');
}

$el = election();
$candidates = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM votes v WHERE v.candidate_id=c.id) votes FROM candidates c ORDER BY name')->fetchAll();
$voters = $pdo->query('SELECT * FROM voters ORDER BY voter_id')->fetchAll();
$dtl = fn ($v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';

page_head('Admin', '../');
?>
<div class="page-title"><h1>Administration</h1><p class="muted">Manage the election, candidates and the voter roll.</p></div>

<section class="card">
  <h2>Election settings</h2>
  <form method="post" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="election">
    <label class="span2">Title<input name="title" value="<?= e($el['title']) ?>" required></label>
    <label>Opens (optional)<input type="datetime-local" name="starts_at" value="<?= e($dtl($el['starts_at'])) ?>"></label>
    <label>Closes (optional)<input type="datetime-local" name="ends_at" value="<?= e($dtl($el['ends_at'])) ?>"></label>
    <label class="check-row span2"><input type="checkbox" name="is_open" <?= $el['is_open'] ? 'checked' : '' ?>> Voting enabled (currently <b><?= e(election_state($el)) ?></b>)</label>
    <div class="span2"><button class="btn primary">Save settings</button></div>
  </form>
</section>

<section class="card">
  <h2>Candidates</h2>
  <?php foreach ($candidates as $c): ?>
    <form method="post" class="form-grid row-edit">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <label>Name<input name="name" value="<?= e($c['name']) ?>" required></label>
      <label>Party<input name="party" value="<?= e($c['party']) ?>" required></label>
      <label>Slogan<input name="slogan" value="<?= e($c['slogan']) ?>"></label>
      <label>Colour<input type="color" name="color" value="<?= e($c['color']) ?>"></label>
      <label class="span2">Biography<textarea name="bio" rows="2"><?= e($c['bio']) ?></textarea></label>
      <div class="span2 row"><span class="muted small"><?= (int) $c['votes'] ?> votes</span>
        <button name="action" value="edit_candidate" class="btn">Save</button>
        <button name="action" value="delete_candidate" class="btn danger" data-confirm="Remove this candidate?">Delete</button></div>
    </form>
  <?php endforeach; ?>
  <h3>Add candidate</h3>
  <form method="post" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_candidate">
    <label>Name<input name="name" required></label>
    <label>Party<input name="party" required></label>
    <label>Slogan<input name="slogan"></label>
    <label>Colour<input type="color" name="color" value="#1d4ed8"></label>
    <label class="span2">Biography<textarea name="bio" rows="2"></textarea></label>
    <div class="span2"><button class="btn primary">Add candidate</button></div>
  </form>
</section>

<section class="card">
  <h2>Voter roll <span class="muted small">(<?= count($voters) ?>)</span></h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Voter ID</th><th>Name</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($voters as $v): ?>
      <tr><td><?= e($v['voter_id']) ?></td><td><?= e($v['full_name']) ?></td>
        <td><span class="pill <?= $v['has_voted'] ? 'open' : 'scheduled' ?>"><?= $v['has_voted'] ? 'Voted' : 'Not voted' ?></span></td>
        <td><?php if (!$v['has_voted']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_voter"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="btn danger sm" data-confirm="Remove this voter?">Remove</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <h3>Register voter</h3>
  <form method="post" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_voter">
    <label>Voter ID<input name="voter_id" required></label>
    <label>Full name<input name="full_name" required></label>
    <label>PIN<input name="pin" type="password" minlength="4" required></label>
    <div><button class="btn primary">Register</button></div>
  </form>
</section>
<?php page_foot('../');
