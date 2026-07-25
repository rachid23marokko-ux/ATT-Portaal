<?php
declare(strict_types=1);

namespace App\Models;

final class User
{
    public static function updatePresence(int $userId, bool $online): void
    {
        $stmt = \db()->prepare(
            'UPDATE users
             SET is_online = :online,
                 last_seen_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute([
            'online' => $online ? 1 : 0,
            'id' => $userId,
        ]);
    }

    public static function onlineUsers(): array
    {
        $stmt = \db()->query(
            'SELECT
                u.name,
                r.name AS role,
                u.last_seen_at
             FROM users u
             INNER JOIN roles r
                ON r.id = u.role_id
             WHERE u.is_active = 1
               AND (
                    u.is_online = 1
                    OR u.last_seen_at >= (NOW() - INTERVAL 3 MINUTE)
               )
             ORDER BY u.last_seen_at DESC'
        );

        return $stmt->fetchAll();
    }
}
