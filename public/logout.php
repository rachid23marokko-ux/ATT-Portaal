<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Helpers/bootstrap.php';
if (!empty($_SESSION['user']['id'])) {
    db()->prepare('UPDATE users SET is_online=0,last_seen_at=NOW() WHERE id=:id')->execute(['id' => $_SESSION['user']['id']]);
}
$_SESSION = [];
session_destroy();
redirect('/');
