<?php

declare(strict_types=1);

namespace App\Middleware;

final class Auth
{
    private const SESSION_TIMEOUT = 120;

    public static function requireLogin(): void
    {
        if (empty($_SESSION['user'])) {
            \redirect('/');
        }

        self::refreshSessionUser();
    }

    public static function requireRole(array|string $roles): void
    {
        self::requireLogin();

        if (!self::hasRole($roles)) {
            http_response_code(403);
            exit('Geen toegang.');
        }
    }

    public static function hasRole(array|string $roles): bool
    {
        $roles = (array) $roles;
        $currentRole = (string) ($_SESSION['user']['role'] ?? '');

        return in_array($currentRole, $roles, true);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    private static function refreshSessionUser(): void
    {
        $userId = (int) ($_SESSION['user']['id'] ?? 0);

        if ($userId <= 0) {
            self::logoutSession();
        }

        if (
            isset($_SESSION['last_activity'])
            && (time() - (int) $_SESSION['last_activity']) > self::SESSION_TIMEOUT
        ) {
            self::logoutSession();
        }

        $stmt = \db()->prepare(
            'SELECT
                u.id,
                u.name,
                u.email,
                u.is_active,
                u.session_version,
                r.name AS role
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $userId,
        ]);

        $user = $stmt->fetch();

        if (!$user || !(bool) $user['is_active']) {
            self::logoutSession();
        }

        $storedSessionVersion = (int) (
            $_SESSION['user']['session_version'] ?? 0
        );

        if ((int) $user['session_version'] !== $storedSessionVersion) {
            self::logoutSession();
        }

        $_SESSION['last_activity'] = time();

        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'session_version' => (int) $user['session_version'],
        ];

        \db()->prepare(
            'UPDATE users
             SET
                is_online = 1,
                last_seen_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => $userId,
        ]);
    }

    private static function logoutSession(): never
    {
        $userId = (int) ($_SESSION['user']['id'] ?? 0);

        if ($userId > 0) {
            \db()->prepare(
                'UPDATE users
                 SET
                    is_online = 0,
                    last_seen_at = NOW()
                 WHERE id = :id'
            )->execute([
                'id' => $userId,
            ]);
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 3600,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        \redirect('/');
    }
}
