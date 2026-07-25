<?php

declare(strict_types=1);

use App\Middleware\Auth;
use App\Services\PermissionService;

function menu_is_active(array|string $paths): bool
{
    $paths = (array) $paths;

    $currentPath = basename(
        parse_url(
            $_SERVER['REQUEST_URI'] ?? '',
            PHP_URL_PATH
        ) ?: ''
    );

    return in_array($currentPath, $paths, true);
}

function menu_is_active_for_organization(
    array|string $paths,
    string $organization
): bool {
    if (!menu_is_active($paths)) {
        return false;
    }

    $currentPath = basename(
        parse_url(
            $_SERVER['REQUEST_URI'] ?? '',
            PHP_URL_PATH
        ) ?: ''
    );

    if ($currentPath !== 'address_form.php') {
        return true;
    }

    return strtolower(
        trim((string) ($_GET['organization'] ?? ''))
    ) === strtolower($organization);
}

function menu_class(array|string $paths): string
{
    return menu_is_active($paths)
        ? 'sidebar-link active'
        : 'sidebar-link';
}

function menu_class_for_organization(
    array|string $paths,
    string $organization
): string {
    return menu_is_active_for_organization($paths, $organization)
        ? 'sidebar-link active'
        : 'sidebar-link';
}

function render_header(string $title): void
{
    $user = Auth::user();

    if (!$user) {
        redirect('/');
    }

    $role = (string) ($user['role'] ?? '');
    $name = (string) ($user['name'] ?? '');
    $email = (string) ($user['email'] ?? '');

    $isManager = PermissionService::isManager();
    $isDeveloper = PermissionService::isDeveloper();
    $canBrabo = PermissionService::canAccessBrabo();
    $canNlw = PermissionService::canAccessNlw();
    $canCodesGent = PermissionService::canAccessCodesGent();

    $flashes = pull_flashes();

    $initial = mb_strtoupper(
        mb_substr(
            trim($name) !== '' ? trim($name) : 'A',
            0,
            1
        )
    );
    ?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta name="theme-color" content="#07345f">

    <title><?= e($title) ?> · ATT Portaal</title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css?v=3.0.2"
    >
</head>

<body class="app-body">

<div class="app-shell">

    <aside class="sidebar" id="app-sidebar">

        <div class="sidebar-brand">

            <div class="sidebar-logo">
                ATT
            </div>

            <div class="sidebar-brand-text">
                <strong>ATT Portaal</strong>
                <small>Brabo &amp; NLW</small>
            </div>

            <button
                type="button"
                class="sidebar-close"
                id="sidebar-close"
                aria-label="Menu sluiten"
            >
                ×
            </button>

        </div>

        <nav class="sidebar-navigation">

            <div class="sidebar-section">

                <span class="sidebar-section-title">
                    Algemeen
                </span>

                <a
                    class="<?= menu_class('dashboard.php') ?>"
                    href="/dashboard.php"
                >
                    <span class="sidebar-icon">🏠</span>
                    <span>Dashboard</span>
                </a>

                <a
                    class="<?= menu_class([
                        'my-profile.php',
                        'profile-edit.php',
                    ]) ?>"
                    href="/my-profile.php"
                >
                    <span class="sidebar-icon">👤</span>
                    <span>Mijn profiel</span>
                </a>

            </div>

            <?php if ($canBrabo || $canNlw || $canCodesGent): ?>

                <div class="sidebar-section">

                    <span class="sidebar-section-title">
                        Locaties
                    </span>

                    <?php if ($canBrabo): ?>

                        <a
                            class="<?= menu_class_for_organization(
                                [
                                    'brabo_addresses.php',
                                    'brabo_address_view.php',
                                    'address_form.php',
                                ],
                                'brabo'
                            ) ?>"
                            href="/brabo_addresses.php"
                        >
                            <span class="sidebar-icon">🟦</span>
                            <span>Brabo Adressen</span>
                        </a>

                    <?php endif; ?>

                    <?php if ($canNlw): ?>

                        <a
                            class="<?= menu_class_for_organization(
                                [
                                    'nlw_addresses.php',
                                    'nlw_address_view.php',
                                    'address_form.php',
                                ],
                                'nlw'
                            ) ?>"
                            href="/nlw_addresses.php"
                        >
                            <span class="sidebar-icon">🟩</span>
                            <span>NLW Adressen</span>
                        </a>

                    <?php endif; ?>

                    <?php if ($canCodesGent): ?>

                        <a
                            class="<?= menu_class([
                                'codes-gent.php',
                                'code-gent-view.php',
                            ]) ?>"
                            href="/codes-gent.php"
                        >
                            <span class="sidebar-icon">🔐</span>
                            <span>Codes Gent</span>
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

            <?php if ($canBrabo || $canNlw): ?>

                <div class="sidebar-section">

                    <span class="sidebar-section-title">
                        Communicatie
                    </span>

                    <a
                        class="<?= menu_class([
                            'chat.php',
                            'chat-conversation.php',
                        ]) ?>"
                        href="/chat.php"
                    >
                        <span class="sidebar-icon">💬</span>
                        <span>Chat</span>

                        <span
                            class="sidebar-counter"
                            id="sidebar-chat-count"
                            hidden
                        >
                            0
                        </span>
                    </a>

                    <a
                        class="<?= menu_class([
                            'announcements.php',
                            'announcement-view.php',
                        ]) ?>"
                        href="/announcements.php"
                    >
                        <span class="sidebar-icon">📢</span>
                        <span>Mededelingen</span>

                        <span
                            class="sidebar-counter"
                            id="sidebar-announcement-count"
                            hidden
                        >
                            0
                        </span>
                    </a>

                </div>

            <?php endif; ?>

            <?php if ($isManager): ?>

                <div class="sidebar-section">

                    <span class="sidebar-section-title">
                        Beheer
                    </span>

                    <a
                        class="<?= menu_class([
                            'users.php',
                            'user-create.php',
                            'user-edit.php',
                        ]) ?>"
                        href="/users.php"
                    >
                        <span class="sidebar-icon">👥</span>
                        <span>Chauffeurs en gebruikers</span>
                    </a>

                    <a
                        class="<?= menu_class([
                            'organizations.php',
                            'organization-edit.php',
                        ]) ?>"
                        href="/organizations.php"
                    >
                        <span class="sidebar-icon">🏢</span>
                        <span>Organisaties</span>
                    </a>
<a
    class="<?= menu_class('online-users.php') ?>"
    href="/online-users.php"
>
    <span class="sidebar-icon">🟢</span>
    <span>Online gebruikers</span>
</a>

<a
    class="<?= menu_class('settings.php') ?>"
    href="/settings.php"
>
    <span class="sidebar-icon">⚙️</span>
    <span>Instellingen</span>
</a>

                </div>

            <?php endif; ?>

            <?php if ($isDeveloper): ?>

                <div class="sidebar-section">

                    <span class="sidebar-section-title">
                        Developer
                    </span>

                    <a
                        class="<?= menu_class('activity-log.php') ?>"
                        href="/activity-log.php"
                    >
                        <span class="sidebar-icon">📋</span>
                        <span>Activiteitenlog</span>
                    </a>

                    <a
                        class="<?= menu_class('system-status.php') ?>"
                        href="/system-status.php"
                    >
                        <span class="sidebar-icon">🖥️</span>
                        <span>Systeemstatus</span>

                    </a>

                </div>

            <?php endif; ?>

        </nav>

        <div class="sidebar-footer">

            <a
                class="sidebar-link sidebar-logout"
                href="/logout.php"
            >
                <span class="sidebar-icon">🚪</span>
                <span>Uitloggen</span>
            </a>

            <small>ATT Portaal v3.0.2</small>

        </div>

    </aside>

    <div
        class="sidebar-backdrop"
        id="sidebar-backdrop"
    ></div>

    <section class="app-main">

        <header class="topbar">

            <div class="topbar-left">

                <button
                    type="button"
                    class="mobile-menu-button"
                    id="mobile-menu-button"
                    aria-label="Menu openen"
                >
                    ☰
                </button>

                <div class="page-heading">
                    <h1><?= e($title) ?></h1>
                    <p>Antwerp Team Transport NV</p>
                </div>

            </div>

            <div class="topbar-center">

                <form
                    class="global-search"
                    action="/users.php"
                    method="get"
                >
                    <label
                        class="sr-only"
                        for="global-search-input"
                    >
                        Zoeken
                    </label>

                    <span class="global-search-icon">
                        🔍
                    </span>

                    <input
                        id="global-search-input"
                        name="q"
                        type="search"
                        placeholder="Zoek adres, chauffeur of contactpersoon"
                        autocomplete="off"
                    >
                </form>

            </div>

            <div class="topbar-actions">

                <button
                    type="button"
                    class="topbar-action-button"
                    id="notification-button"
                    aria-label="Meldingen"
                    title="Meldingen"
                >
                    🔔

                    <span
                        class="topbar-badge"
                        id="notification-count"
                        hidden
                    >
                        0
                    </span>
                </button>

                <a
                    class="topbar-action-button"
                    href="/chat.php"
                    aria-label="Chat"
                    title="Chat"
                >
                    💬

                    <span
                        class="topbar-badge"
                        id="chat-count"
                        hidden
                    >
                        0
                    </span>
                </a>

                <div class="user-menu">

                    <button
                        type="button"
                        class="user-menu-button"
                        id="user-menu-button"
                        aria-expanded="false"
                    >
                        <span class="user-avatar">
                            <?= e($initial) ?>
                        </span>

                        <span class="user-menu-details">
                            <strong><?= e($name) ?></strong>
                            <small><?= e(ucfirst($role)) ?></small>
                        </span>

                        <span class="user-menu-arrow">
                            ▾
                        </span>
                    </button>

                    <div
                        class="user-dropdown"
                        id="user-dropdown"
                        hidden
                    >
                        <div class="user-dropdown-header">
                            <strong><?= e($name) ?></strong>
                            <small><?= e($email) ?></small>
                        </div>

                        <a href="/my-profile.php">
                            👤 Mijn profiel
                        </a>

                        <a href="/profile-edit.php">
                            ✏️ Profiel bewerken
                        </a>

                        <a href="/forgot-password.php">
                            🔑 Wachtwoord wijzigen
                        </a>

                        <div class="user-dropdown-divider"></div>

                        <a
                            class="dropdown-logout"
                            href="/logout.php"
                        >
                            🚪 Uitloggen
                        </a>

                    </div>

                </div>

            </div>

        </header>

        <div
            class="notification-panel"
            id="notification-panel"
            hidden
        >
            <div class="notification-panel-header">

                <strong>Meldingen</strong>

                <button
                    type="button"
                    id="notification-close"
                    aria-label="Meldingen sluiten"
                >
                    ×
                </button>

            </div>

            <div class="notification-panel-content">

                <p class="notification-empty">
                    Er zijn geen nieuwe meldingen.
                </p>

            </div>

        </div>

        <main class="page-content">

            <?php foreach ($flashes as $flash): ?>

                <div
                    class="alert <?= e(
                        (string) ($flash['type'] ?? 'info')
                    ) ?>"
                >
                    <?= e(
                        (string) ($flash['message'] ?? '')
                    ) ?>
                </div>

            <?php endforeach; ?>

    <?php
}

function render_footer(): void
{
    ?>
        </main>

        <footer class="app-footer">
            <span>
                ATT Portaal · Antwerp Team Transport NV
            </span>

            <span>
                Versie 3.0.2
            </span>
        </footer>

    </section>

</div>

<div
    class="connection-notice"
    id="connection-notice"
    role="status"
    aria-live="polite"
></div>

<div
    class="toast-container"
    id="toast-container"
    aria-live="polite"
></div>

<script src="/assets/js/app.js?v=3.0.2"></script>

</body>
</html>
    <?php
}
