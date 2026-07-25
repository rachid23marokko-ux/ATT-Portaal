<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Helpers/bootstrap.php';

use App\Services\MailService;

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Ongeldige beveiligingstoken.');
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $stmt = db()->prepare('SELECT id,name,email FROM users WHERE email=:email AND is_active=1 LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        try {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            db()->prepare('DELETE FROM password_resets WHERE user_id=:user_id OR expires_at<NOW() OR used_at IS NOT NULL')->execute(['user_id' => $user['id']]);
            db()->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at,requested_ip) VALUES(:user_id,:token_hash,DATE_ADD(NOW(),INTERVAL 30 MINUTE),:ip)')->execute([
                'user_id' => $user['id'],
                'token_hash' => $hash,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $mailConfig = require dirname(__DIR__) . '/config/mail.php';
            $url = rtrim((string) $mailConfig['password_reset']['url'], '?') . '?token=' . urlencode($token);
            (new MailService())->sendPasswordReset($user['email'], $user['name'], $url);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $error = 'De resetmail kon niet worden verzonden.';
        }
    }

    if (!$error) {
        $message = 'Als het account bestaat, is een resetlink verzonden.';
    }
}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Wachtwoord vergeten</title><link rel="stylesheet" href="/assets/css/app.css"></head><body class="auth-page"><section class="auth-card"><h1>Wachtwoord vergeten</h1>
<?php if ($message): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>E-mailadres<input type="email" name="email" required></label><button type="submit">Resetlink versturen</button></form><a href="/">Terug naar inloggen</a></section></body></html>
