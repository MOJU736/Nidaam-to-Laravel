<?php
require_once __DIR__ . '/includes/functions.php';
unset($_SESSION['voter_id'], $_SESSION['receipt']);
session_regenerate_id(true);
redirect('index.php');
