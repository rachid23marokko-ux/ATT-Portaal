<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Helpers/bootstrap.php';

use App\Services\PermissionService;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = $_SESSION['user'] ?? null;

if (!$user) {
    header('Location: login.php');
    exit;
}

$permissions = new PermissionService($user);

if (!$permissions->canAccessBrabo()) {
    http_response_code(403);
    exit('Geen toegang tot Brabo-adressen.');
}

$canManageAddresses =
    $permissions->isAdmin()
    || $permissions->isDeveloper();

$search = trim((string) ($_GET['q'] ?? ''));

$organizationStatement = db()->prepare(
    'SELECT id, name
     FROM organizations
     WHERE name = :name
     LIMIT 1'
);

$organizationStatement->execute([
    'name' => 'Brabo',
]);

$organization = $organizationStatement->fetch(PDO::FETCH_ASSOC);

if (!$organization) {
    exit('Organisatie Brabo werd niet gevonden.');
}

$sql = '
    SELECT
        a.id,
        a.company_name,
        a.contact_name,
        a.street,
        a.house_number,
        a.postal_code,
        a.city,
        a.country,
        a.phone,
        a.email,
        a.notes,
        a.latitude,
        a.longitude,
        CASE
            WHEN f.id IS NULL THEN 0
            ELSE 1
        END AS is_favorite
    FROM addresses a
    LEFT JOIN favorites f
        ON f.address_id = a.id
        AND f.user_id = :user_id
    WHERE a.organization_id = :organization_id
';

$params = [
    'user_id' => (int) $user['id'],
    'organization_id' => (int) $organization['id'],
];

if ($search !== '') {
    $sql .= '
        AND (
            a.company_name LIKE :search
            OR a.contact_name LIKE :search
            OR a.street LIKE :search
            OR a.postal_code LIKE :search
            OR a.city LIKE :search
            OR a.country LIKE :search
            OR a.phone LIKE :search
            OR a.email LIKE :search
        )
    ';

    $params['search'] = '%' . $search . '%';
}

$sql .= '
    ORDER BY
    a.name ASC
';

$statement = db()->prepare($sql);
$statement->execute($params);

$addresses = $statement->fetchAll(PDO::FETCH_ASSOC);

function addressLine(array $address): string
{
    return trim(
        ($address['street'] ?? '')
        . ' '
        . ($address['house_number'] ?? '')
    );
}

function navigationAddress(array $address): string
{
    return trim(implode(', ', array_filter([
        addressLine($address),
        $address['postal_code'] ?? '',
        $address['city'] ?? '',
        $address['country'] ?? '',
    ])));
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Brabo Adressen';

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= h($pageTitle) ?> - ATT Portaal</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f3f6fb;
            color: #1f2937;
            font-family: Arial, Helvetica, sans-serif;
        }

        .page {
            min-height: 100vh;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 24px;
            background: #ffffff;
            border-bottom: 1px solid #dbe3ef;
        }

        .topbar h1 {
            margin: 0;
            font-size: 24px;
        }

        .topbar a {
            color: #1d4ed8;
            text-decoration: none;
            font-weight: bold;
        }

        .content {
            width: min(1400px, calc(100% - 32px));
            margin: 24px auto;
        }

        .panel {
            padding: 20px;
            background: #ffffff;
            border: 1px solid #dbe3ef;
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .search-form {
            display: flex;
            gap: 10px;
            flex: 1;
            min-width: 260px;
        }

        .search-form input {
            width: 100%;
            min-height: 44px;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            font-size: 15px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 9px 14px;
            border: 0;
            border-radius: 9px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            white-space: nowrap;
        }

        .button-primary {
            background: #1d4ed8;
            color: #ffffff;
        }

        .button-secondary {
            background: #e2e8f0;
            color: #1e293b;
        }

        .button-success {
            background: #15803d;
            color: #ffffff;
        }

        .button-danger {
            background: #b91c1c;
            color: #ffffff;
        }

        .button-warning {
            background: #f59e0b;
            color: #111827;
        }

        .count {
            margin-bottom: 14px;
            color: #64748b;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
            text-align: left;
        }

        th {
            background: #eff6ff;
            color: #1e3a8a;
            font-size: 13px;
            text-transform: uppercase;
        }

        td {
            font-size: 14px;
        }

        .company {
            font-weight: bold;
            color: #0f172a;
        }

        .muted {
            color: #64748b;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .empty {
            padding: 36px;
            text-align: center;
            color: #64748b;
        }

        .favorite-star {
            font-size: 20px;
            line-height: 1;
        }

        @media (max-width: 700px) {
            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .content {
                width: min(100% - 18px, 1400px);
                margin: 12px auto;
            }

            .panel {
                padding: 12px;
            }

            .search-form {
                width: 100%;
                flex-direction: column;
            }

            .toolbar {
                align-items: stretch;
            }
        }
    </style>
</head>

<body>
<div class="page">

    <header class="topbar">
        <div>
            <h1>🟦 Brabo Adressen</h1>
            <div class="muted">
                Alleen adressen van organisatie Brabo
            </div>
        </div>

        <a href="dashboard.php">
            ← Terug naar dashboard
        </a>
    </header>

    <main class="content">
        <section class="panel">

            <div class="toolbar">

                <form
                    method="get"
                    action="brabo_addresses.php"
                    class="search-form"
                >
                    <input
                        type="search"
                        name="q"
                        value="<?= h($search) ?>"
                        placeholder="Zoek op bedrijf, straat, plaats, postcode..."
                    >

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Zoeken
                    </button>

                    <?php if ($search !== ''): ?>
                        <a
                            href="brabo_addresses.php"
                            class="button button-secondary"
                        >
                            Wissen
                        </a>
                    <?php endif; ?>
                </form>

                <?php if ($canManageAddresses): ?>
                    <a
                        href="brabo_address_form.php"
                        class="button button-primary"
                    >
                        + Nieuw adres
                    </a>
                <?php endif; ?>

            </div>

            <div class="count">
                <?= count($addresses) ?> adres(sen) gevonden
            </div>

            <?php if (!$addresses): ?>

                <div class="empty">
                    Geen Brabo-adressen gevonden.
                </div>

            <?php else: ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            <th>Favoriet</th>
                            <th>Bedrijf</th>
                            <th>Adres</th>
                            <th>Contact</th>
                            <th>Telefoon</th>
                            <th>Navigatie</th>

                            <?php if ($canManageAddresses): ?>
                                <th>Beheer</th>
                            <?php endif; ?>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($addresses as $address): ?>

                            <?php
                            $fullAddress = navigationAddress($address);
                            $encodedAddress = rawurlencode($fullAddress);

                            $googleMapsUrl =
                                'https://www.google.com/maps/search/?api=1&query='
                                . $encodedAddress;

                            $wazeUrl =
                                'https://www.waze.com/ul?q='
                                . $encodedAddress
                                . '&navigate=yes';
                            ?>

                            <tr>
                                <td>
                                    <form
                                        method="post"
                                        action="favorite_toggle.php"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= h(csrf_token()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="address_id"
                                            value="<?= (int) $address['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="return_to"
                                            value="brabo_addresses.php<?= $search !== '' ? '?q=' . rawurlencode($search) : '' ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="button button-warning favorite-star"
                                            title="Favoriet wijzigen"
                                        >
                                            <?= (int) $address['is_favorite'] === 1 ? '★' : '☆' ?>
                                        </button>
                                    </form>
                                </td>

                                <td>
                                    <div class="company">
                                        <?= h($address['company_name']) ?>
                                    </div>

                                    <?php if (!empty($address['notes'])): ?>
                                        <div class="muted">
                                            <?= nl2br(h($address['notes'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= h(addressLine($address)) ?><br>
                                    <?= h(trim(
                                        ($address['postal_code'] ?? '')
                                        . ' '
                                        . ($address['city'] ?? '')
                                    )) ?>

                                    <?php if (!empty($address['country'])): ?>
                                        <br>
                                        <span class="muted">
                                            <?= h($address['country']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= h($address['contact_name']) ?>

                                    <?php if (!empty($address['email'])): ?>
                                        <br>
                                        <a href="mailto:<?= h($address['email']) ?>">
                                            <?= h($address['email']) ?>
                                        </a>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (!empty($address['phone'])): ?>
                                        <a href="tel:<?= h($address['phone']) ?>">
                                            <?= h($address['phone']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="muted">Niet ingevuld</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="actions">
                                        <a
                                            href="<?= h($googleMapsUrl) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="button button-primary"
                                        >
                                            Google Maps
                                        </a>

                                        <a
                                            href="<?= h($wazeUrl) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="button button-success"
                                        >
                                            Waze
                                        </a>
                                    </div>
                                </td>

                                <?php if ($canManageAddresses): ?>
                                    <td>
                                        <div class="actions">
                                            <a
                                                href="brabo_address_form.php?id=<?= (int) $address['id'] ?>"
                                                class="button button-secondary"
                                            >
                                                Bewerken
                                            </a>

                                            <form
                                                method="post"
                                                action="brabo_address_delete.php"
                                                onsubmit="return confirm('Weet je zeker dat je dit adres wilt verwijderen?');"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= h(csrf_token()) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int) $address['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="button button-danger"
                                                >
                                                    Verwijderen
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>

                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

        </section>
    </main>

</div>
</body>
</html>
