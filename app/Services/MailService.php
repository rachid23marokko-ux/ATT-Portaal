<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;

final class MailService
{
    private array $config;

    public function __construct()
    {
        $this->config = require dirname(__DIR__, 2) . '/config/mail.php';
    }

    public function sendPasswordReset(string $email, string $name, string $url): void
    {
        $smtp = $this->config['smtp'];
        $sender = $this->config['sender'];
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $smtp['host'];
        $mail->Port = (int) $smtp['port'];
        $mail->SMTPAuth = (bool) $smtp['authentication'];
        $mail->Username = (string) $smtp['username'];
        $mail->Password = (string) $smtp['password'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = (int) ($smtp['timeout'] ?? 20);
        $mail->SMTPSecure = in_array(strtolower((string) $smtp['encryption']), ['ssl', 'smtps'], true)
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom((string) $sender['email'], (string) $sender['name']);
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = 'Wachtwoord opnieuw instellen - ATT Portaal';
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mail->Body = "<h2>ATT Portaal</h2><p>Hallo {$safeName},</p><p>Klik op onderstaande knop om je wachtwoord opnieuw in te stellen.</p><p><a href=\"{$safeUrl}\" style=\"background:#0b4a86;color:#fff;padding:12px 18px;text-decoration:none;border-radius:8px\">Nieuw wachtwoord instellen</a></p><p>Deze link is 30 minuten geldig.</p>";
        $mail->AltBody = "Open deze link om je wachtwoord opnieuw in te stellen:\n{$url}";
        $mail->send();
    }
}
