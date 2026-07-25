<?php
declare(strict_types=1);

namespace App\Services;

use App\Middleware\Auth;
use RuntimeException;

final class PermissionService
{
    private static ?array $context = null;

    public static function refresh(): void
    {
        self::$context = null;
    }

    public static function context(): array
    {
        if (self::$context !== null) {
            return self::$context;
        }

        $user = Auth::user();

        if (!$user) {
            throw new RuntimeException('Geen ingelogde gebruiker.');
        }

        $userId = (int) ($user['id'] ?? 0);
        $role = (string) ($user['role'] ?? '');

        $stmt = \db()->prepare(
            'SELECT
                u.is_multivalent,
                u.can_message_everyone,
                GROUP_CONCAT(
                    DISTINCT o.slug
                    ORDER BY o.slug
                    SEPARATOR ","
                ) AS organization_slugs
             FROM users u
             LEFT JOIN user_organizations uo
                ON uo.user_id = u.id
             LEFT JOIN organizations o
                ON o.id = uo.organization_id
               AND o.is_active = 1
             WHERE u.id = :user_id
             GROUP BY u.id
             LIMIT 1'
        );

        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch() ?: [];

        $organizations = [];

        if (!empty($row['organization_slugs'])) {
            $organizations = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $row['organization_slugs'])
            )));
        }

        return self::$context = [
            'user_id' => $userId,
            'role' => $role,
            'is_admin' => $role === 'admin',
            'is_developer' => $role === 'developer',
            'is_manager' => in_array($role, ['admin', 'developer'], true),
            'is_multivalent' => (bool) ($row['is_multivalent'] ?? false),
            'can_message_everyone' => (bool) (
                $row['can_message_everyone'] ?? false
            ),
            'organizations' => $organizations,
        ];
    }

    public static function isAdmin(): bool
    {
        return (bool) self::context()['is_admin'];
    }

    public static function isDeveloper(): bool
    {
        return (bool) self::context()['is_developer'];
    }

    public static function isManager(): bool
    {
        return (bool) self::context()['is_manager'];
    }

    public static function isMultivalent(): bool
    {
        return (bool) self::context()['is_multivalent'];
    }

    public static function belongsTo(string $slug): bool
    {
        return in_array(
            $slug,
            self::context()['organizations'],
            true
        );
    }

    public static function canAccessBrabo(): bool
    {
        return self::isManager()
            || self::isMultivalent()
            || self::belongsTo('brabo');
    }

    public static function canAccessNlw(): bool
    {
        return self::isManager()
            || self::isMultivalent()
            || self::belongsTo('nlw');
    }

    public static function canAccessCodesGent(): bool
    {
        return self::canAccessNlw();
    }
public static function canMessageEveryone(): bool
{
    return self::isManager()
        || self::isMultivalent()
        || (bool) self::context()['can_message_everyone'];
}

public static function canUseBraboChat(): bool
{
    return self::canAccessBrabo();
}

public static function canUseNlwChat(): bool
{
    return self::canAccessNlw();
}

/**
 * Bepaalt of de ingelogde gebruiker een privébericht
 * naar een andere gebruiker mag sturen.
 *
 * Verwachte gegevens van $recipient:
 * - role
 * - is_multivalent
 * - organizations
 */
public static function canMessageUser(array $recipient): bool
{
    $recipientId = (int) ($recipient['user_id'] ?? $recipient['id'] ?? 0);

    if ($recipientId <= 0) {
        return false;
    }

    // Jezelf niet als privégesprek tonen.
    if ($recipientId === (int) self::context()['user_id']) {
        return false;
    }

    // Admin, developer, multivalent of expliciet recht:
    // mag iedereen berichten.
    if (self::canMessageEveryone()) {
        return true;
    }

    $recipientRole = strtolower(
        trim((string) ($recipient['role'] ?? ''))
    );

    $recipientIsManager = in_array(
        $recipientRole,
        ['admin', 'developer'],
        true
    );

    $recipientIsMultivalent = !empty(
        $recipient['is_multivalent']
    );

    // Chauffeurs mogen altijd Admin, Developer
    // en Multivalent bereiken.
    if ($recipientIsManager || $recipientIsMultivalent) {
        return true;
    }

    $recipientOrganizations = $recipient['organizations'] ?? [];

    if (is_string($recipientOrganizations)) {
        $recipientOrganizations = array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(',', $recipientOrganizations)
                )
            )
        );
    }

    if (!is_array($recipientOrganizations)) {
        return false;
    }

    $ownOrganizations = self::context()['organizations'];

    // Privéchat is toegestaan wanneer beide gebruikers
    // bij minimaal één dezelfde organisatie horen.
    return count(
        array_intersect(
            $ownOrganizations,
            $recipientOrganizations
        )
    ) > 0;
}

public static function canUseChatGroup(string $organizationSlug): bool
{
    $organizationSlug = strtolower(trim($organizationSlug));

    return match ($organizationSlug) {
        'brabo' => self::canUseBraboChat(),
        'nlw' => self::canUseNlwChat(),
        default => false,
    };
}
    public static function can(string $permission): bool
    {
        return match ($permission) {
            'brabo.addresses.view',
            'brabo.chat.use',
            'brabo.announcements.view',
            'brabo.announcements.post'
                => self::canAccessBrabo(),

            'nlw.addresses.view',
            'nlw.chat.use',
            'nlw.announcements.view',
            'nlw.announcements.post'
                => self::canAccessNlw(),

            'codes_gent.view'
                => self::canAccessCodesGent(),

            'documents.view'
                => true,

            'users.manage',
            'user_rights.manage',
            'organizations.manage'
                => self::isManager(),

            'reports.view'
                => self::isAdmin(),

            'activity_log.view',
            'system_status.view',
            'settings.manage'
                => self::isDeveloper(),

            default => false,
        };
    }

    public static function require(string $permission): void
    {
        if (self::can($permission)) {
            return;
        }

        http_response_code(403);
        exit('Geen toegang tot dit onderdeel.');
    }
}
