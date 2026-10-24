<?php
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT * FROM admins WHERE username = ?');
    $st->execute([trim($_POST['username'] ?? '')]);
    $a = $st->fetch();
    if ($a && password_verify($_POST['password'] ?? '', $a['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $a['id'];
        redirect('index.php');
    }
    flash('Invalid credentials.');
    redirect('login.php');
}

page_head('Admin Login', '../');
?>
<form class="card login narrow" method="post">
  <h2>Administrator sign in</h2>
  <?= csrf_field() ?>
  <label>Username<input name="username" required autofocus></label>
  <label>Password<input name="password" type="password" required></label>
  <button class="btn primary block">Sign in</button>
</form>
<?php page_foot('../');
