<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Helpers/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/layout.php';

use App\Middleware\Auth;
use App\Services\PermissionService;

Auth::requireLogin();

PermissionService::require('nlw.addresses.view');

$currentUser = Auth::user();

if (!$currentUser) {
    redirect('/');
}

$search = trim((string) ($_GET['q'] ?? ''));

/*
|--------------------------------------------------------------------------
| NLW-organisatie ophalen
|--------------------------------------------------------------------------
*/

$organizationStmt = db()->prepare(
    'SELECT id, name, slug
     FROM organizations
     WHERE slug = :slug
       AND is_active = 1
     LIMIT 1'
);

$organizationStmt->execute([
    'slug' => 'nlw',
]);

$organization = $organizationStmt->fetch();

if (!$organization) {
    http_response_code(404);
    exit('De organisatie NLW werd niet gevonden.');
}

$organizationId = (int) $organization['id'];

/*
|--------------------------------------------------------------------------
| Adressen ophalen
|--------------------------------------------------------------------------
*/

$sql = '
    SELECT
        id,
        organization_id,
        name,
        department,
        street,
        house_number,
        postal_code,
        city,
        country,
        phone,
        mobile,
        email,
        website,
        contact_person,
        latitude,
        longitude,
        notes,
        loading_place,
        unloading_place,
        driver_entrance,
        gate_number,
        access_code,
        safety_instructions,
        is_active,
        created_at,
        updated_at
    FROM addresses
    WHERE organization_id = :organization_id
      AND is_active = 1
';

$params = [
    'organization_id' => $organizationId,
];

if ($search !== '') {
    $sql .= '
        AND (
            name LIKE :search
            OR department LIKE :search
            OR street LIKE :search
            OR house_number LIKE :search
            OR postal_code LIKE :search
            OR city LIKE :search
            OR country LIKE :search
            OR contact_person LIKE :search
            OR phone LIKE :search
            OR mobile LIKE :search
            OR email LIKE :search
            OR notes LIKE :search
        )
    ';

    $params['search'] = '%' . $search . '%';
}

$sql .= '
    ORDER BY
        name ASC,
        city ASC
';

$addressStmt = db()->prepare($sql);
$addressStmt->execute($params);

$addresses = $addressStmt->fetchAll();

$isManager = PermissionService::isManager();

render_header('NLW-adressen');
?>

<section class="panel">

    <div class="panel-heading-row">
        <div>
            <span class="dashboard-eyebrow">
                🟦 NLW
            </span>

            <h2>NLW-adressen</h2>

            <p>
                Zoek en bekijk alle actieve adressen van NLW.
            </p>
        </div>

        <a href="/dashboard.php">
            ← Terug naar dashboard
        </a>
    </div>

    <form
        method="get"
        action="/nlw_addresses.php"
        style="
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-bottom:20px;
        "
    >
        <input
            type="search"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Zoek op naam, straat, plaats of contactpersoon"
            style="
                flex:1;
                min-width:260px;
                padding:11px 13px;
                border:1px solid #cbd5e1;
                border-radius:8px;
                font-size:15px;
            "
        >

        <button
            type="submit"
            class="button"
        >
            🔍 Zoeken
        </button>

        <?php if ($search !== ''): ?>
            <a
                href="/nlw_addresses.php"
                class="button"
            >
                Wissen
            </a>
        <?php endif; ?>
    </form>

    <p style="color:#64748b;">
        <strong><?= count($addresses) ?></strong>
        <?= count($addresses) === 1
            ? 'adres gevonden'
            : 'adressen gevonden'
        ?>
    </p>

    <?php if (!$addresses): ?>

        <div
            style="
                padding:30px;
                background:#f8fafc;
                border:1px solid #e2e8f0;
                border-radius:10px;
                text-align:center;
                color:#64748b;
            "
        >
            Geen NLW-adressen gevonden.
        </div>

    <?php else: ?>

        <div style="overflow-x:auto;">

            <table
                style="
                    width:100%;
                    min-width:1100px;
                    border-collapse:collapse;
                "
            >
                <thead>
                    <tr style="background:#eff6ff;">
                        <th style="padding:12px;text-align:left;">
                            Naam
                        </th>

                        <th style="padding:12px;text-align:left;">
                            Adres
                        </th>

                        <th style="padding:12px;text-align:left;">
                            Contact
                        </th>

                        <th style="padding:12px;text-align:left;">
                            Informatie
                        </th>

                        <th style="padding:12px;text-align:left;">
                            Navigatie
                        </th>

                        <?php if ($isManager): ?>
                            <th style="padding:12px;text-align:left;">
                                Beheer
                            </th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($addresses as $address): ?>

                    <?php
                    $streetAddress = trim(
                        (string) $address['street']
                        . ' '
                        . (string) $address['house_number']
                    );

                    $cityAddress = trim(
                        (string) $address['postal_code']
                        . ' '
                        . (string) $address['city']
                    );

                    $navigationAddress = implode(', ', array_filter([
                        $streetAddress,
                        $cityAddress,
                        (string) $address['country'],
                    ]));

                    $googleMapsUrl =
                        'https://www.google.com/maps/search/?api=1&query='
                        . rawurlencode($navigationAddress);

                    $wazeUrl =
                        'https://www.waze.com/ul?q='
                        . rawurlencode($navigationAddress)
                        . '&navigate=yes';
                    ?>

                    <tr style="border-bottom:1px solid #e2e8f0;">

                        <td style="padding:12px;vertical-align:top;">
                            <strong>
                                <?= e((string) $address['name']) ?>
                            </strong>

                            <?php if (!empty($address['department'])): ?>
                                <br>

                                <small style="color:#64748b;">
                                    <?= e((string) $address['department']) ?>
                                </small>
                            <?php endif; ?>
                        </td>

                        <td style="padding:12px;vertical-align:top;">
                            <?= e($streetAddress) ?>

                            <br>

                            <?= e($cityAddress) ?>

                            <br>

                            <small style="color:#64748b;">
                                <?= e((string) $address['country']) ?>
                            </small>
                        </td>

                        <td style="padding:12px;vertical-align:top;">

                            <?php if (!empty($address['contact_person'])): ?>
                                <strong>
                                    <?= e((string) $address['contact_person']) ?>
                                </strong>
                                <br>
                            <?php endif; ?>

                            <?php if (!empty($address['phone'])): ?>
                                ☎
                                <a href="tel:<?= e((string) $address['phone']) ?>">
                                    <?= e((string) $address['phone']) ?>
                                </a>
                                <br>
                            <?php endif; ?>

                            <?php if (!empty($address['mobile'])): ?>
                                📱
                                <a href="tel:<?= e((string) $address['mobile']) ?>">
                                    <?= e((string) $address['mobile']) ?>
                                </a>
                                <br>
                            <?php endif; ?>

                            <?php if (!empty($address['email'])): ?>
                                ✉
                                <a href="mailto:<?= e((string) $address['email']) ?>">
                                    <?= e((string) $address['email']) ?>
                                </a>
                            <?php endif; ?>

                            <?php if (
                                empty($address['contact_person'])
                                && empty($address['phone'])
                                && empty($address['mobile'])
                                && empty($address['email'])
                            ): ?>
                                <span style="color:#94a3b8;">
                                    Geen contactgegevens
                                </span>
                            <?php endif; ?>
                        </td>

                        <td style="padding:12px;vertical-align:top;">

                            <?php if (!empty($address['gate_number'])): ?>
                                <strong>Poort:</strong>
                                <?= e((string) $address['gate_number']) ?>
                                <br>
                            <?php endif; ?>

                            <?php if (!empty($address['access_code'])): ?>
                                <strong>Toegangscode:</strong>
                                <?= e((string) $address['access_code']) ?>
                                <br>
                            <?php endif; ?>

                            <?php if (!empty($address['notes'])): ?>
                                <div style="margin-top:6px;">
                                    <?= nl2br(e((string) $address['notes'])) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (
                                empty($address['gate_number'])
                                && empty($address['access_code'])
                                && empty($address['notes'])
                            ): ?>
                                <span style="color:#94a3b8;">
                                    Geen extra informatie
                                </span>
                            <?php endif; ?>
                        </td>

                        <td style="padding:12px;vertical-align:top;">

                            <div
                                style="
                                    display:flex;
                                    flex-wrap:wrap;
                                    gap:7px;
                                "
                            >
                                <a
                                    href="<?= e($googleMapsUrl) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="button"
                                >
                                    Google Maps
                                </a>

                                <a
                                    href="<?= e($wazeUrl) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="button"
                                >
                                    Waze
                                </a>
                            </div>
                        </td>

                        <?php if ($isManager): ?>
                            <td style="padding:12px;vertical-align:top;">
                                <span style="color:#64748b;">
                                    Beheer volgt in stap 2
                                </span>
                            </td>
                        <?php endif; ?>

                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>

        </div>

    <?php endif; ?>

</section>

<?php render_footer(); ?>
