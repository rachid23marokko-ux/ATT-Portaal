<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Helpers/bootstrap.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$stmt = db()->prepare('SELECT pr.id,pr.user_id FROM password_resets pr JOIN users u ON u.id=pr.user_id WHERE pr.token_hash=:hash AND pr.used_at IS NULL AND pr.expires_at>NOW() AND u.is_active=1 LIMIT 1');
$stmt->execute(['hash' => hash('sha256', $token)]);
$reset = $stmt->fetch();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Ongeldige beveiligingstoken.');
    }
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    if (strlen($password) < 10) {
        $error = 'Gebruik minimaal 10 tekens.';
    } elseif ($password !== $confirm) {
        $error = 'De wachtwoorden zijn niet gelijk.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash=:hash,session_version=session_version+1,is_online=0 WHERE id=:id')->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $reset['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at=NOW() WHERE id=:id')->execute(['id' => $reset['id']]);
        $pdo->commit();
        $_SESSION['success'] = 'Je wachtwoord is gewijzigd.';
        redirect('/');
    }
}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nieuw wachtwoord</title><link rel="stylesheet" href="/assets/css/app.css"></head><body class="auth-page"><section class="auth-card"><h1>Nieuw wachtwoord</h1>
<?php if (!$reset): ?><div class="alert error">De resetlink is ongeldig of verlopen.</div><a href="/forgot-password.php">Nieuwe link aanvragen</a><?php else: ?><?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><label>Nieuw wachtwoord<input type="password" name="password" minlength="10" required></label><label>Herhaal wachtwoord<input type="password" name="confirm" minlength="10" required></label><button type="submit">Opslaan</button></form><?php endif; ?></section></body></html>
