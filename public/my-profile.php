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

User::updatePresence($userId, true);

$stmt = db()->prepare(
    'SELECT
        u.id,
        u.name,
        u.email,
        u.is_active,
        u.is_multivalent,
        r.name AS role,

        GROUP_CONCAT(
            DISTINCT o.name
            ORDER BY o.name
            SEPARATOR ", "
        ) AS organizations,

        dp.first_name,
        dp.middle_name,
        dp.last_name,
        dp.address,
        dp.postal_code,
        dp.city,
        dp.country,
        dp.phone,
        dp.mobile,
        dp.emergency_contact_name,
        dp.emergency_contact_phone,
        dp.birth_date,
        dp.employee_number,
        dp.driver_number,
        dp.employment_start_date,
        dp.department,
        dp.driver_card_number,
        dp.driver_card_valid_until,
        dp.driving_license_number,
        dp.driving_license_categories,
        dp.driving_license_valid_until,
        dp.code_95_valid_until,
        dp.medical_exam_valid_until,
        dp.identity_card_number,
        dp.identity_card_valid_until,
        dp.profile_photo,
        dp.notes

     FROM users u

     INNER JOIN roles r
        ON r.id = u.role_id

     LEFT JOIN user_organizations uo
        ON uo.user_id = u.id

     LEFT JOIN organizations o
        ON o.id = uo.organization_id

     LEFT JOIN driver_profiles dp
        ON dp.user_id = u.id

     WHERE u.id = :user_id

     GROUP BY u.id

     LIMIT 1'
);

$stmt->execute(['user_id' => $userId]);
$profile = $stmt->fetch();

if (!$profile) {
    http_response_code(404);
    exit('Profiel niet gevonden.');
}

function profile_text(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'Niet ingevuld';
    }

    return e($value);
}

function profile_date(?string $value): string
{
    if ($value === null || $value === '') {
        return 'Niet ingevuld';
    }

    try {
        return (new DateTimeImmutable($value))->format('d-m-Y');
    } catch (Throwable) {
        return e($value);
    }
}

function document_status(?string $date): array
{
    if ($date === null || $date === '') {
        return [
            'class' => 'empty',
            'label' => 'Niet ingevuld',
            'detail' => '',
        ];
    }

    try {
        $today = new DateTimeImmutable('today');
        $expiry = new DateTimeImmutable($date);
        $days = (int) $today->diff($expiry)->format('%r%a');
    } catch (Throwable) {
        return [
            'class' => 'empty',
            'label' => 'Ongeldige datum',
            'detail' => '',
        ];
    }

    if ($days < 0) {
        return [
            'class' => 'expired',
            'label' => 'Verlopen',
            'detail' => abs($days) . ' dagen geleden',
        ];
    }

    if ($days <= 30) {
        return [
            'class' => 'danger',
            'label' => 'Verloopt binnenkort',
            'detail' => 'Nog ' . $days . ' dagen',
        ];
    }

    if ($days <= 60) {
        return [
            'class' => 'warning',
            'label' => 'Let op',
            'detail' => 'Nog ' . $days . ' dagen',
        ];
    }

    return [
        'class' => 'valid',
        'label' => 'Geldig',
        'detail' => 'Nog ' . $days . ' dagen',
    ];
}

$displayName = trim(
    implode(' ', array_filter([
        $profile['first_name'],
        $profile['middle_name'],
        $profile['last_name'],
    ]))
);

if ($displayName === '') {
    $displayName = $profile['name'];
}

$initial = mb_strtoupper(mb_substr($displayName, 0, 1));

$documents = [
    [
        'title' => 'Chauffeurspas',
        'icon' => '🪪',
        'number' => $profile['driver_card_number'],
        'date' => $profile['driver_card_valid_until'],
        'extra' => null,
    ],
    [
        'title' => 'Rijbewijs',
        'icon' => '🚘',
        'number' => $profile['driving_license_number'],
        'date' => $profile['driving_license_valid_until'],
        'extra' => $profile['driving_license_categories'],
    ],
    [
        'title' => 'Code 95',
        'icon' => '📘',
        'number' => null,
        'date' => $profile['code_95_valid_until'],
        'extra' => null,
    ],
    [
        'title' => 'Medische keuring',
        'icon' => '🩺',
        'number' => null,
        'date' => $profile['medical_exam_valid_until'],
        'extra' => null,
    ],
    [
        'title' => 'Identiteitskaart',
        'icon' => '🆔',
        'number' => $profile['identity_card_number'],
        'date' => $profile['identity_card_valid_until'],
        'extra' => null,
    ],
];

render_header('Mijn profiel');
?>

<section class="profile-summary-card">

    <div class="profile-avatar-large">
        <?php if (!empty($profile['profile_photo'])): ?>
            <img
                src="<?= e($profile['profile_photo']) ?>"
                alt="Profielfoto"
            >
        <?php else: ?>
            <?= e($initial) ?>
        <?php endif; ?>
    </div>

    <div class="profile-summary-main">
        <span class="profile-eyebrow">Persoonlijk profiel</span>

        <h2><?= e($displayName) ?></h2>

        <div class="profile-summary-tags">
            <span class="profile-tag">
                <?= e(ucfirst($profile['role'])) ?>
            </span>

            <?php if ((bool) $profile['is_multivalent']): ?>
                <span class="profile-tag multivalent">
                    Multivalent
                </span>
            <?php endif; ?>

            <span class="profile-tag active">
                <?= (bool) $profile['is_active'] ? 'Actief' : 'Geblokkeerd' ?>
            </span>
        </div>

        <p>
            Organisatie:
            <strong><?= profile_text($profile['organizations']) ?></strong>
        </p>
    </div>

    <div class="profile-summary-actions">
        <a class="button" href="/profile-edit.php">
            ✏️ Profiel bewerken
        </a>
    </div>

</section>

<nav class="profile-tabs">
    <a href="#persoonlijk">👤 Persoonlijk</a>
    <a href="#contact">📞 Contact</a>
    <a href="#werk">📋 Werk</a>
    <a href="#documenten">📄 Documenten</a>
    <a href="#opmerkingen">📝 Opmerkingen</a>
</nav>

<div class="profile-content-grid">

    <section class="panel" id="persoonlijk">
        <div class="profile-section-heading">
            <div>
                <span class="profile-eyebrow">Persoonlijk</span>
                <h2>Accountgegevens</h2>
            </div>
        </div>

        <div class="profile-detail-list">

            <div class="profile-detail-row">
                <span>Accountnaam</span>
                <strong><?= e($profile['name']) ?></strong>
            </div>

            <div class="profile-detail-row">
                <span>E-mailadres</span>
                <strong><?= e($profile['email']) ?></strong>
            </div>

            <div class="profile-detail-row">
                <span>Voornaam</span>
                <strong><?= profile_text($profile['first_name']) ?></strong>
            </div>

            <div class="profile-detail-row">
                <span>Tussenvoegsel</span>
                <strong><?= profile_text($profile['middle_name']) ?></strong>
            </div>

            <div class="profile-detail-row">
                <span>Achternaam</span>
                <strong><?= profile_text($profile['last_name']) ?></strong>
            </div>

            <div class="profile-detail-row">
                <span>Geboortedatum</span>
                <strong><?= profile_date($profile['birth_date']) ?></strong>
            </div>

        </div>
    </section>

    <section class="panel" id="contact">
        <div class="profile-section-heading">
            <div>
                <span class="profile-eyebrow">Contact</span>
                <h2>Contactgegevens</h2>
            </div>
        </div>

        <div class="profile-detail-list">

            <div class="profile-detail-row">
                <span>Adres</span>
                <strong><?= profile_text($profile['address']) ?></strong>
            </div>

            <div class="profile-detail-row">
                <span>Postcode</span>
                <strong><?= profile_text($profile['postal_code']) ?></strong>
            </div>

            <div class="profile-detail-row">
                <span>Woonplaats</span>
                <strong><?= profile_text($profile['city']) ?></strong>
            </div>

            <div class="profile-detail-row">
                <span>Land</span>
                <strong><?= profile_text($profile['country']) ?></strong>
            </div>

            <div class="profile-detail-row">
                <span>Telefoon</span>
                <strong><?= profile_text($profile['phone']) ?></strong>
            </div>

            <div class="profile-detail-row">
                <span>Mobiel</span>
                <strong><?= profile_text($profile['mobile']) ?></strong>
            </div>

            <div class="profile-detail-row">
                <span>Noodcontactpersoon</span>
                <strong>
                    <?= profile_text($profile['emergency_contact_name']) ?>
                </strong>
            </div>

            <div class="profile-detail-row">
                <span>Noodnummer</span>
                <strong>
                    <?= profile_text($profile['emergency_contact_phone']) ?>
                </strong>
            </div>

        </div>
    </section>

    <section class="panel profile-full-width" id="werk">
        <div class="profile-section-heading">
            <div>
                <span class="profile-eyebrow">Werk</span>
                <h2>Werkgegevens</h2>
            </div>
        </div>

        <div class="profile-work-grid">

            <article class="profile-info-card">
                <span>Personeelsnummer</span>
                <strong><?= profile_text($profile['employee_number']) ?></strong>
            </article>

            <article class="profile-info-card">
                <span>Chauffeursnummer</span>
                <strong><?= profile_text($profile['driver_number']) ?></strong>
            </article>

            <article class="profile-info-card">
                <span>In dienst sinds</span>
                <strong>
                    <?= profile_date($profile['employment_start_date']) ?>
                </strong>
            </article>

            <article class="profile-info-card">
                <span>Afdeling</span>
                <strong><?= profile_text($profile['department']) ?></strong>
            </article>

        </div>
    </section>

    <section class="panel profile-full-width" id="documenten">
        <div class="profile-section-heading">
            <div>
                <span class="profile-eyebrow">Documenten</span>
                <h2>Documenten en geldigheid</h2>
            </div>
        </div>

        <div class="profile-document-grid">

            <?php foreach ($documents as $document): ?>
                <?php $status = document_status($document['date']); ?>

                <article class="profile-document-card">

                    <div class="profile-document-top">
                        <span class="profile-document-icon">
                            <?= e($document['icon']) ?>
                        </span>

                        <span class="profile-status <?= e($status['class']) ?>">
                            <?= e($status['label']) ?>
                        </span>
                    </div>

                    <h3><?= e($document['title']) ?></h3>

                    <div class="profile-document-details">

                        <?php if ($document['number'] !== null): ?>
                            <div>
                                <span>Nummer</span>
                                <strong>
                                    <?= profile_text($document['number']) ?>
                                </strong>
                            </div>
                        <?php endif; ?>

                        <?php if ($document['extra'] !== null): ?>
                            <div>
                                <span>Categorieën</span>
                                <strong>
                                    <?= profile_text($document['extra']) ?>
                                </strong>
                            </div>
                        <?php endif; ?>

                        <div>
                            <span>Geldig tot</span>
                            <strong>
                                <?= profile_date($document['date']) ?>
                            </strong>
                        </div>

                    </div>

                    <?php if ($status['detail'] !== ''): ?>
                        <p class="profile-document-status-detail">
                            <?= e($status['detail']) ?>
                        </p>
                    <?php endif; ?>

                </article>
            <?php endforeach; ?>

        </div>
    </section>

    <section class="panel profile-full-width" id="opmerkingen">
        <div class="profile-section-heading">
            <div>
                <span class="profile-eyebrow">Overig</span>
                <h2>Interne opmerkingen</h2>
            </div>
        </div>

        <div class="profile-notes">
            <?= nl2br(profile_text($profile['notes'])) ?>
        </div>
    </section>

</div>

<style>
.profile-summary-card {
    display: flex;
    align-items: center;
    gap: 22px;
    padding: 24px;
    margin-bottom: 18px;
    border: 1px solid #d9e2ea;
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 7px 22px rgba(7, 52, 95, .07);
}

.profile-avatar-large {
    width: 96px;
    height: 96px;
    flex: 0 0 auto;
    display: grid;
    place-items: center;
    overflow: hidden;
    border-radius: 50%;
    background: #0b4a86;
    color: #ffffff;
    font-size: 38px;
    font-weight: 800;
}

.profile-avatar-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-summary-main {
    flex: 1;
    min-width: 0;
}

.profile-summary-main h2 {
    margin: 5px 0 9px;
    color: #07345f;
    font-size: 29px;
}

.profile-summary-main p {
    margin: 10px 0 0;
    color: #667788;
}

.profile-eyebrow {
    display: block;
    color: #6b7b8c;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.profile-summary-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.profile-tag {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 999px;
    background: #e7f0f8;
    color: #07345f;
    font-size: 12px;
    font-weight: 800;
}

.profile-tag.multivalent {
    background: #f1eafe;
    color: #6b21a8;
}

.profile-tag.active {
    background: #dcfce7;
    color: #166534;
}

.profile-tabs {
    position: sticky;
    top: 94px;
    z-index: 100;
    display: flex;
    gap: 7px;
    padding: 10px;
    margin-bottom: 18px;
    overflow-x: auto;
    border: 1px solid #d9e2ea;
    border-radius: 13px;
    background: rgba(255, 255, 255, .96);
    backdrop-filter: blur(8px);
}

.profile-tabs a {
    flex: 0 0 auto;
    padding: 9px 13px;
    border-radius: 9px;
    color: #334155;
    text-decoration: none;
    font-weight: 700;
}

.profile-tabs a:hover {
    background: #eef4f9;
    color: #07345f;
}

.profile-content-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.profile-full-width {
    grid-column: 1 / -1;
}

.profile-section-heading {
    margin-bottom: 15px;
}

.profile-section-heading h2 {
    margin: 5px 0 0;
}

.profile-detail-list {
    display: grid;
}

.profile-detail-row {
    display: grid;
    grid-template-columns: minmax(150px, 40%) 1fr;
    gap: 14px;
    padding: 12px 0;
    border-bottom: 1px solid #edf1f5;
}

.profile-detail-row:last-child {
    border-bottom: 0;
}

.profile-detail-row span {
    color: #6b7b8c;
}

.profile-detail-row strong {
    overflow-wrap: anywhere;
}

.profile-work-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 13px;
}

.profile-info-card {
    padding: 16px;
    border: 1px solid #d9e2ea;
    border-radius: 12px;
    background: #f8fafc;
}

.profile-info-card span,
.profile-info-card strong {
    display: block;
}

.profile-info-card span {
    margin-bottom: 7px;
    color: #6b7b8c;
    font-size: 13px;
}

.profile-info-card strong {
    color: #07345f;
}

.profile-document-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
}

.profile-document-card {
    padding: 18px;
    border: 1px solid #d9e2ea;
    border-radius: 13px;
    background: #ffffff;
}

.profile-document-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.profile-document-icon {
    font-size: 27px;
}

.profile-document-card h3 {
    margin: 14px 0;
    color: #07345f;
}

.profile-document-details {
    display: grid;
    gap: 11px;
}

.profile-document-details span,
.profile-document-details strong {
    display: block;
}

.profile-document-details span {
    margin-bottom: 4px;
    color: #6b7b8c;
    font-size: 12px;
}

.profile-status {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
}

.profile-status.valid {
    background: #dcfce7;
    color: #166534;
}

.profile-status.warning {
    background: #fef3c7;
    color: #92400e;
}

.profile-status.danger {
    background: #ffedd5;
    color: #9a3412;
}

.profile-status.expired {
    background: #fee2e2;
    color: #991b1b;
}

.profile-status.empty {
    background: #e2e8f0;
    color: #475569;
}

.profile-document-status-detail {
    margin: 14px 0 0;
    color: #6b7b8c;
    font-size: 13px;
}

.profile-notes {
    min-height: 80px;
    padding: 16px;
    border-radius: 11px;
    background: #f8fafc;
    color: #334155;
    line-height: 1.55;
}

@media (max-width: 1100px) {
    .profile-document-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .profile-work-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 760px) {
    .profile-summary-card {
        align-items: flex-start;
        flex-direction: column;
    }

    .profile-summary-actions {
        width: 100%;
    }

    .profile-summary-actions .button {
        display: block;
        text-align: center;
    }

    .profile-content-grid,
    .profile-document-grid,
    .profile-work-grid {
        grid-template-columns: 1fr;
    }

    .profile-full-width {
        grid-column: auto;
    }

    .profile-detail-row {
        grid-template-columns: 1fr;
        gap: 5px;
    }
}
</style>

<?php render_footer(); ?>
