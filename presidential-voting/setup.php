<?php
// One-time installer. Open http://localhost/presidential-voting/setup.php, then delete this file.
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/plain; charset=utf-8');
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(__DIR__ . '/database.sql'));
    $pdo->exec('USE ' . DB_NAME);

    $pdo->prepare('INSERT IGNORE INTO admins (username, password_hash) VALUES (?, ?)')
        ->execute([ADMIN_USER, password_hash(ADMIN_PASS, PASSWORD_DEFAULT)]);
    $pdo->exec("INSERT IGNORE INTO election (id, title, is_open) VALUES (1, 'Presidential Election', 1)");

    if (!$pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn()) {
        $c = $pdo->prepare('INSERT INTO candidates (name, party, slogan, bio, color) VALUES (?,?,?,?,?)');
        foreach ([
            ['Amina Hassan', 'Unity Alliance', 'Together, Forward.', 'Former health minister focused on healthcare access and national unity.', '#1d4ed8'],
            ['David Okoye', 'Progress Party', 'Build. Create. Prosper.', 'Economist and entrepreneur promoting jobs, infrastructure and innovation.', '#b91c1c'],
            ['Layla Mansour', 'Green Future', 'A Cleaner Tomorrow.', 'Environmental lawyer championing clean energy and education reform.', '#15803d'],
        ] as $row) {
            $c->execute($row);
        }
    }

    if (!$pdo->query('SELECT COUNT(*) FROM voters')->fetchColumn()) {
        $v = $pdo->prepare('INSERT INTO voters (voter_id, full_name, pin_hash) VALUES (?,?,?)');
        foreach ([['V1001', 'Demo Voter One', '1111'], ['V1002', 'Demo Voter Two', '2222'], ['V1003', 'Demo Voter Three', '3333']] as [$id, $name, $pin]) {
            $v->execute([$id, $name, password_hash($pin, PASSWORD_DEFAULT)]);
        }
    }
    echo "Setup complete.\n\nAdmin:  " . ADMIN_USER . " / " . ADMIN_PASS . "\nDemo voters: V1001/1111, V1002/2222, V1003/3333\n\nDELETE setup.php now, then open index.php\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Setup failed: " . $e->getMessage() . "\nIs MySQL running in the XAMPP Control Panel?";
}
