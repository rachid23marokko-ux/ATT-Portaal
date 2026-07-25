<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Helpers/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/layout.php';

use App\Middleware\Auth;
use App\Models\User;

Auth::requireLogin();

$currentUser = Auth::user();

if (!$currentUser) {
    redirect('/');
}

$userId = (int) $currentUser['id'];
$isManager = Auth::hasRole(['admin', 'developer']);

User::updatePresence($userId, true);

$addressStmt = db()->query(
    'SELECT
        o.slug,
        COUNT(a.id) AS total
     FROM organizations o
     LEFT JOIN addresses a
        ON a.organization_id = o.id
       AND a.is_active = 1
     GROUP BY o.id, o.slug'
);

$addressCounts = [
    'brabo' => 0,
    'nlw' => 0,
];

foreach ($addressStmt->fetchAll() as $row) {
    $slug = (string) $row['slug'];

    if (array_key_exists($slug, $addressCounts)) {
        $addressCounts[$slug] = (int) $row['total'];
    }
}

$profileStmt = db()->prepare(
    'SELECT
        driver_card_valid_until,
        driving_license_valid_until,
        medical_exam_valid_until,
        identity_card_valid_until
     FROM driver_profiles
     WHERE user_id = :user_id
     LIMIT 1'
);
$profileStmt->execute(['user_id' => $userId]);
$driverProfile = $profileStmt->fetch() ?: [];

function dashboard_expiry_status(?string $date): ?array
{
    if ($date === null || $date === '') {
        return null;
    }

    try {
        $today = new DateTimeImmutable('today');
        $expiryDate = new DateTimeImmutable($date);
        $daysRemaining = (int) $today
            ->diff($expiryDate)
            ->format('%r%a');
    } catch (Throwable) {
        return null;
    }

    if ($daysRemaining < 0) {
        return [
            'level' => 'expired',
            'days' => $daysRemaining,
        ];
    }

    if ($daysRemaining <= 30) {
        return [
            'level' => 'danger',
            'days' => $daysRemaining,
        ];
    }

    if ($daysRemaining <= 60) {
        return [
            'level' => 'warning',
            'days' => $daysRemaining,
        ];
    }

    return [
        'level' => 'valid',
        'days' => $daysRemaining,
    ];
}

$expiryFields = [
    'Chauffeurspas' => $driverProfile['driver_card_valid_until'] ?? null,
    'Rijbewijs' => $driverProfile['driving_license_valid_until'] ?? null,
    'Medische keuring' => $driverProfile['medical_exam_valid_until'] ?? null,
    'Identiteitskaart' => $driverProfile['identity_card_valid_until'] ?? null,
];

$expiryWarnings = [];
$validDocuments = 0;

foreach ($expiryFields as $documentName => $expiryDate) {
    $status = dashboard_expiry_status($expiryDate);

    if ($status === null) {
        continue;
    }

    $validDocuments++;

    if (in_array($status['level'], ['expired', 'danger', 'warning'], true)) {
        $expiryWarnings[] = [
            'name' => $documentName,
            'date' => $expiryDate,
            'level' => $status['level'],
            'days' => $status['days'],
        ];
    }
}

$onlineUsers = $isManager
    ? User::onlineUsers()
    : [];

$activityStmt = db()->prepare(
    'SELECT
        action,
        entity_type,
        created_at
     FROM activity_log
     WHERE user_id = :user_id
     ORDER BY created_at DESC
     LIMIT 6'
);
$activityStmt->execute(['user_id' => $userId]);
$recentActivities = $activityStmt->fetchAll();

render_header('Dashboard');
?>

<section class="dashboard-welcome">
    <div>
        <span class="dashboard-eyebrow">Welkom terug</span>

        <h2><?= e($currentUser['name']) ?></h2>

        <p>
            Dit is jouw centrale overzicht binnen ATT Portaal.
        </p>
    </div>

    <a class="button dashboard-profile-button" href="/my-profile.php">
        👤 Mijn profiel bekijken
    </a>
</section>

<section class="dashboard-stat-grid">

    <article class="dashboard-stat-card">
        <div class="dashboard-stat-icon blue">🟦</div>

        <div>
            <span class="dashboard-stat-label">
                Brabo-adressen
            </span>

            <strong><?= $addressCounts['brabo'] ?></strong>
        </div>
    </article>

    <article class="dashboard-stat-card">
        <div class="dashboard-stat-icon green">🟩</div>

        <div>
            <span class="dashboard-stat-label">
                NLW-adressen
            </span>

            <strong><?= $addressCounts['nlw'] ?></strong>
        </div>
    </article>

    <article class="dashboard-stat-card">
        <div class="dashboard-stat-icon purple">📄</div>

        <div>
            <span class="dashboard-stat-label">
                Ingevulde documenten
            </span>

            <strong><?= $validDocuments ?></strong>
        </div>
    </article>

    <article class="dashboard-stat-card">
        <div class="dashboard-stat-icon orange">📅</div>

        <div>
            <span class="dashboard-stat-label">
                Geldigheidswaarschuwingen
            </span>

            <strong><?= count($expiryWarnings) ?></strong>
        </div>
    </article>

</section>

<div class="dashboard-content-grid">

    <section class="dashboard-main-column">

        <section class="panel dashboard-status-panel">
            <div class="panel-heading-row">
                <div>
                    <span class="dashboard-eyebrow">
                        Documentstatus
                    </span>

                    <h2>Geldigheid van documenten</h2>
                </div>

                <a href="/my-profile.php">
                    Alles bekijken →
                </a>
            </div>

            <?php if (!$expiryWarnings): ?>
                <div class="dashboard-all-good">
                    <span class="dashboard-good-icon">✓</span>

                    <div>
                        <strong>Alles is in orde</strong>

                        <p>
                            Er zijn momenteel geen documenten die binnenkort
                            verlopen.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <div class="dashboard-warning-list">
                    <?php foreach ($expiryWarnings as $warning): ?>
                        <article class="dashboard-warning-item">
                            <div>
                                <strong><?= e($warning['name']) ?></strong>

                                <span>
                                    Geldig tot
                                    <?= e(
                                        (new DateTimeImmutable(
                                            $warning['date']
                                        ))->format('d-m-Y')
                                    ) ?>
                                </span>
                            </div>

                            <?php if ($warning['level'] === 'expired'): ?>
                                <span class="dashboard-status-badge expired">
                                    Verlopen
                                </span>
                            <?php elseif ($warning['level'] === 'danger'): ?>
                                <span class="dashboard-status-badge danger">
                                    Nog <?= (int) $warning['days'] ?> dagen
                                </span>
                            <?php else: ?>
                                <span class="dashboard-status-badge warning">
                                    Nog <?= (int) $warning['days'] ?> dagen
                                </span>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-heading-row">
                <div>
                    <span class="dashboard-eyebrow">
                        Snel starten
                    </span>

                    <h2>Veelgebruikte onderdelen</h2>
                </div>
            </div>

            <div class="dashboard-quick-grid">

                <a class="dashboard-quick-card" href="/my-profile.php">
                    <span>👤</span>

                    <div>
                        <strong>Mijn profiel</strong>
                        <small>Bekijk persoonlijke gegevens</small>
                    </div>
                </a>

                <a class="dashboard-quick-card" href="/addresses.php">
                    <span>📍</span>

                    <div>
                        <strong>Adressen</strong>
                        <small>Zoek Brabo- en NLW-adressen</small>
                    </div>
                </a>

                <a class="dashboard-quick-card" href="/chat.php">
                    <span>💬</span>

                    <div>
                        <strong>Chat</strong>
                        <small>Open gesprekken en groepen</small>
                    </div>
                </a>

                <a class="dashboard-quick-card" href="/announcements.php">
                    <span>📢</span>

                    <div>
                        <strong>Mededelingen</strong>
                        <small>Bekijk actuele berichten</small>
                    </div>
                </a>

                <?php if ($isManager): ?>
                    <a class="dashboard-quick-card" href="/users.php">
                        <span>👥</span>

                        <div>
                            <strong>Gebruikers</strong>
                            <small>Beheer chauffeurs en accounts</small>
                        </div>
                    </a>
                <?php endif; ?>

            </div>
        </section>

        <section class="panel">
            <div class="panel-heading-row">
                <div>
                    <span class="dashboard-eyebrow">
                        Laatste activiteit
                    </span>

                    <h2>Mijn recente acties</h2>
                </div>
            </div>

            <?php if (!$recentActivities): ?>
                <p class="dashboard-empty-text">
                    Er zijn nog geen recente activiteiten.
                </p>
            <?php else: ?>
                <div class="dashboard-activity-list">
                    <?php foreach ($recentActivities as $activity): ?>
                        <article class="dashboard-activity-item">
                            <span class="dashboard-activity-icon">✓</span>

                            <div>
                                <strong>
                                    <?= e(
                                        ucfirst(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $activity['action']
                                            )
                                        )
                                    ) ?>
                                </strong>

                                <small>
                                    <?= e(
                                        (new DateTimeImmutable(
                                            $activity['created_at']
                                        ))->format('d-m-Y H:i')
                                    ) ?>
                                </small>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </section>

    <?php if ($isManager): ?>
        <aside class="dashboard-side-column">

            <section class="panel">
                <div class="panel-heading-row">
                    <div>
                        <span class="dashboard-eyebrow">
                            Live
                        </span>

                        <h2>Online gebruikers</h2>
                    </div>

                    <span class="dashboard-online-count">
                        <?= count($onlineUsers) ?>
                    </span>
                </div>

                <?php if (!$onlineUsers): ?>
                    <p class="dashboard-empty-text">
                        Momenteel is niemand online.
                    </p>
                <?php else: ?>
                    <div class="dashboard-online-list">
                        <?php foreach ($onlineUsers as $onlineUser): ?>
                            <article class="dashboard-online-user">
                                <span class="online-dot"></span>

                                <div>
                                    <strong>
                                        <?= e($onlineUser['name']) ?>
                                    </strong>

                                    <small>
                                        <?= e(
                                            ucfirst($onlineUser['role'])
                                        ) ?>
                                    </small>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel dashboard-help-panel">
                <span class="dashboard-help-icon">💡</span>

                <h2>ATT Portaal</h2>

                <p>
                    Gebruik het menu links om chauffeurs, adressen,
                    documenten en communicatie te beheren.
                </p>
            </section>

        </aside>
    <?php endif; ?>

</div>

<style>
.dashboard-welcome {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 22px;
    padding: 25px;
    margin-bottom: 20px;
    border-radius: 16px;
    background:
        linear-gradient(
            120deg,
            #07345f,
            #0b4a86
        );
    color: #ffffff;
    box-shadow: 0 12px 30px rgba(7, 52, 95, .18);
}

.dashboard-welcome h2 {
    margin: 5px 0 6px;
    font-size: 29px;
}

.dashboard-welcome p {
    margin: 0;
    color: #dbeafe;
}

.dashboard-eyebrow {
    display: block;
    color: #6b7b8c;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.dashboard-welcome .dashboard-eyebrow {
    color: #bcd4e8;
}

.dashboard-profile-button {
    flex: 0 0 auto;
    background: #ffffff;
    color: #07345f;
}

.dashboard-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.dashboard-stat-card {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 19px;
    border: 1px solid #d9e2ea;
    border-radius: 15px;
    background: #ffffff;
    box-shadow: 0 5px 18px rgba(7, 52, 95, .05);
}

.dashboard-stat-icon {
    width: 52px;
    height: 52px;
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    border-radius: 13px;
    font-size: 24px;
}

.dashboard-stat-icon.blue {
    background: #e5f0fb;
}

.dashboard-stat-icon.green {
    background: #e4f7eb;
}

.dashboard-stat-icon.purple {
    background: #f0eafb;
}

.dashboard-stat-icon.orange {
    background: #fff0df;
}

.dashboard-stat-label {
    display: block;
    margin-bottom: 4px;
    color: #6b7b8c;
    font-size: 13px;
}

.dashboard-stat-card strong {
    color: #07345f;
    font-size: 28px;
}

.dashboard-content-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 320px;
    gap: 20px;
}

.dashboard-main-column,
.dashboard-side-column {
    display: grid;
    align-content: start;
    gap: 20px;
}

.panel-heading-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 17px;
}

.panel-heading-row h2 {
    margin: 5px 0 0;
}

.panel-heading-row a {
    color: #0b4a86;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
}

.dashboard-all-good {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 17px;
    border-radius: 12px;
    background: #e9f8ef;
    color: #166534;
}

.dashboard-all-good p {
    margin: 4px 0 0;
    color: #3f6f50;
}

.dashboard-good-icon {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    border-radius: 50%;
    background: #178346;
    color: #ffffff;
    font-size: 20px;
    font-weight: 900;
}

.dashboard-warning-list,
.dashboard-activity-list,
.dashboard-online-list {
    display: grid;
    gap: 11px;
}

.dashboard-warning-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 13px 0;
    border-bottom: 1px solid #edf1f5;
}

.dashboard-warning-item:last-child {
    border-bottom: 0;
}

.dashboard-warning-item strong,
.dashboard-warning-item span {
    display: block;
}

.dashboard-warning-item div > span {
    margin-top: 4px;
    color: #6b7b8c;
    font-size: 13px;
}

.dashboard-status-badge {
    flex: 0 0 auto;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
}

.dashboard-status-badge.warning {
    background: #fef3c7;
    color: #92400e;
}

.dashboard-status-badge.danger {
    background: #ffedd5;
    color: #9a3412;
}

.dashboard-status-badge.expired {
    background: #fee2e2;
    color: #991b1b;
}

.dashboard-quick-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 13px;
}

.dashboard-quick-card {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 15px;
    border: 1px solid #d9e2ea;
    border-radius: 12px;
    color: #1f2933;
    text-decoration: none;
    transition:
        transform .18s ease,
        border-color .18s ease,
        box-shadow .18s ease;
}

.dashboard-quick-card:hover {
    transform: translateY(-2px);
    border-color: #9fc0dc;
    box-shadow: 0 8px 20px rgba(7, 52, 95, .08);
}

.dashboard-quick-card > span {
    font-size: 25px;
}

.dashboard-quick-card strong,
.dashboard-quick-card small {
    display: block;
}

.dashboard-quick-card small {
    margin-top: 4px;
    color: #6b7b8c;
}

.dashboard-activity-item,
.dashboard-online-user {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 11px 0;
    border-bottom: 1px solid #edf1f5;
}

.dashboard-activity-item:last-child,
.dashboard-online-user:last-child {
    border-bottom: 0;
}

.dashboard-activity-item strong,
.dashboard-activity-item small,
.dashboard-online-user strong,
.dashboard-online-user small {
    display: block;
}

.dashboard-activity-item small,
.dashboard-online-user small {
    margin-top: 3px;
    color: #6b7b8c;
}

.dashboard-activity-icon {
    width: 30px;
    height: 30px;
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    border-radius: 50%;
    background: #e7f4fb;
    color: #0b4a86;
    font-weight: 900;
}

.dashboard-online-count {
    min-width: 31px;
    height: 31px;
    padding: 0 8px;
    border-radius: 999px;
    background: #e5f6ec;
    color: #178346;
    display: grid;
    place-items: center;
    font-weight: 800;
}

.dashboard-help-panel {
    background: linear-gradient(135deg, #f7fbff, #eaf3fa);
}

.dashboard-help-icon {
    font-size: 28px;
}

.dashboard-help-panel h2 {
    margin: 10px 0 8px;
}

.dashboard-help-panel p,
.dashboard-empty-text {
    color: #6b7b8c;
}

@media (max-width: 1180px) {
    .dashboard-stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 900px) {
    .dashboard-content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 650px) {
    .dashboard-welcome {
        align-items: flex-start;
        flex-direction: column;
    }

    .dashboard-stat-grid,
    .dashboard-quick-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-warning-item {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

<?php render_footer(); ?>
