<?php
require_once __DIR__ . '/config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Session expired. Please go back and try again.');
    }
}

function redirect(string $to): never
{
    header("Location: $to");
    exit;
}

function flash(?string $msg = null, string $type = 'error'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function election(): array
{
    return db()->query('SELECT * FROM election WHERE id = 1')->fetch();
}

/** open | closed | scheduled — respects the manual switch and the optional dates. */
function election_state(array $el): string
{
    if (!$el['is_open']) {
        return 'closed';
    }
    $now = time();
    if ($el['starts_at'] && strtotime($el['starts_at']) > $now) {
        return 'scheduled';
    }
    if ($el['ends_at'] && strtotime($el['ends_at']) < $now) {
        return 'closed';
    }
    return 'open';
}

function require_voter(): array
{
    if (empty($_SESSION['voter_id'])) {
        redirect('index.php');
    }
    $st = db()->prepare('SELECT * FROM voters WHERE id = ?');
    $st->execute([$_SESSION['voter_id']]);
    $v = $st->fetch();
    if (!$v) {
        unset($_SESSION['voter_id']);
        redirect('index.php');
    }
    return $v;
}

function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        redirect('login.php');
    }
}

function initials(string $name): string
{
    $out = '';
    foreach (preg_split('/\s+/', trim($name)) as $w) {
        $out .= mb_strtoupper(mb_substr($w, 0, 1));
        if (mb_strlen($out) === 2) {
            break;
        }
    }
    return $out;
}

function page_head(string $title, string $base = ''): void
{
    $voter = !empty($_SESSION['voter_id']);
    $admin = !empty($_SESSION['admin_id']);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="<?= $base ?>assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="wrap bar">
    <a class="brand" href="<?= $base ?>index.php">
      <span class="seal" aria-hidden="true">★</span>
      <span><strong><?= e(SITE_NAME) ?></strong><small>Official Voting Portal</small></span>
    </a>
    <nav>
      <a href="<?= $base ?>index.php">Home</a>
      <a href="<?= $base ?>results.php">Results</a>
      <?php if ($voter): ?><a href="<?= $base ?>logout.php">Sign out</a><?php endif; ?>
      <?php if ($admin): ?><a href="<?= $base ?>admin/index.php">Admin</a><a href="<?= $base ?>admin/logout.php">Admin sign out</a>
      <?php else: ?><a href="<?= $base ?>admin/login.php">Admin</a><?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap">
<?php if ($f = flash()): ?>
  <div class="alert <?= e($f['type']) ?>" role="alert"><?= e($f['msg']) ?></div>
<?php endif;
}

function page_foot(string $base = ''): void
{
    ?>
</main>
<footer class="foot"><div class="wrap">© <?= date('Y') ?> <?= e(SITE_NAME) ?> · Secure, secret-ballot voting</div></footer>
<script src="<?= $base ?>assets/app.js"></script>
</body>
</html>
<?php
}
