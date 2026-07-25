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

$isManager =
    $permissions->isAdmin()
    || $permissions->isDeveloper();

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectToList(string $message = '', string $type = 'success'): never
{
    $url = 'brabo_addresses.php';

    if ($message !== '') {
        $url .= '?' . http_build_query([
            'message' => $message,
            'type' => $type,
        ]);
    }

    header('Location: ' . $url);
    exit;
}

/*
|--------------------------------------------------------------------------
| Organisatie Brabo ophalen
|--------------------------------------------------------------------------
*/

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
    http_response_code(500);
    exit('Organisatie Brabo werd niet gevonden.');
}

$organizationId = (int) $organization['id'];
$userId = (int) $user['id'];

/*
|--------------------------------------------------------------------------
| Acties verwerken
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirectToList(
            'De beveiligingscontrole is mislukt. Probeer opnieuw.',
            'error'
        );
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $addressId = filter_input(
        INPUT_POST,
        'address_id',
        FILTER_VALIDATE_INT
    );

    if (!$addressId) {
        redirectToList('Ongeldig adres geselecteerd.', 'error');
    }

    $addressCheck = db()->prepare(
        'SELECT id
         FROM addresses
         WHERE id = :id
           AND organization_id = :organization_id
         LIMIT 1'
    );

    $addressCheck->execute([
        'id' => $addressId,
        'organization_id' => $organizationId,
    ]);

    if (!$addressCheck->fetchColumn()) {
        redirectToList('De geselecteerde kaai werd niet gevonden.', 'error');
    }

    if ($action === 'toggle_favorite') {
        $favoriteCheck = db()->prepare(
            'SELECT address_id
             FROM favorites
             WHERE user_id = :user_id
               AND address_id = :address_id
             LIMIT 1'
        );

        $favoriteCheck->execute([
            'user_id' => $userId,
            'address_id' => $addressId,
        ]);

        if ($favoriteCheck->fetchColumn()) {
            $deleteFavorite = db()->prepare(
                'DELETE FROM favorites
                 WHERE user_id = :user_id
                   AND address_id = :address_id'
            );

            $deleteFavorite->execute([
                'user_id' => $userId,
                'address_id' => $addressId,
            ]);
        } else {
            $insertFavorite = db()->prepare(
                'INSERT INTO favorites (
                    user_id,
                    address_id,
                    created_at
                ) VALUES (
                    :user_id,
                    :address_id,
                    NOW()
                )'
            );

            $insertFavorite->execute([
                'user_id' => $userId,
                'address_id' => $addressId,
            ]);
        }

        redirectToList('Favoriet bijgewerkt.');
    }

    if ($action === 'delete') {
        if (!$isManager) {
            http_response_code(403);
            exit('Je hebt geen toestemming om kaaien te verwijderen.');
        }

        try {
            db()->beginTransaction();

            $deleteFavorites = db()->prepare(
                'DELETE FROM favorites
                 WHERE address_id = :address_id'
            );

            $deleteFavorites->execute([
                'address_id' => $addressId,
            ]);

            $deleteAddress = db()->prepare(
                'DELETE FROM addresses
                 WHERE id = :id
                   AND organization_id = :organization_id'
            );

            $deleteAddress->execute([
                'id' => $addressId,
                'organization_id' => $organizationId,
            ]);

            db()->commit();

            redirectToList('De kaai werd verwijderd.');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            error_log($exception->getMessage());

            redirectToList(
                'De kaai kon niet worden verwijderd.',
                'error'
            );
        }
    }

    redirectToList('Onbekende actie.', 'error');
}

/*
|--------------------------------------------------------------------------
| Zoekopdracht en adressen ophalen
|--------------------------------------------------------------------------
*/

$search = trim((string) ($_GET['q'] ?? ''));

$sql = '
    SELECT
        a.id,
        a.name,
        a.latitude,
        a.longitude,
        a.notes,
        CASE
            WHEN f.address_id IS NULL THEN 0
            ELSE 1
        END AS is_favorite
    FROM addresses a
    LEFT JOIN favorites f
        ON f.address_id = a.id
       AND f.user_id = :user_id
    WHERE a.organization_id = :organization_id
';

$params = [
    'user_id' => $userId,
    'organization_id' => $organizationId,
];

if ($search !== '') {
    $sql .= '
        AND (
            a.name LIKE :search
            OR a.notes LIKE :search
        )
    ';

    $params['search'] = '%' . $search . '%';
}

$sql .= '
    ORDER BY
        is_favorite DESC,
        a.name ASC
';

$statement = db()->prepare($sql);
$statement->execute($params);

$addresses = $statement->fetchAll(PDO::FETCH_ASSOC);

$message = trim((string) ($_GET['message'] ?? ''));
$messageType = ($_GET['type'] ?? '') === 'error'
    ? 'error'
    : 'success';

$pageTitle = 'Brabo Kaaien';

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

        a {
            color: inherit;
        }

        button,
        input {
            font: inherit;
        }

        .page {
            min-height: 100vh;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 18px 24px;
            background: #ffffff;
            border-bottom: 1px solid #dbe3ef;
        }

        .topbar-title h1 {
            margin: 0;
            font-size: 25px;
        }

        .topbar-title p {
            margin: 5px 0 0;
            color: #64748b;
            font-size: 14px;
        }

        .topbar-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .container {
            width: min(1180px, calc(100% - 32px));
            margin: 26px auto;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 42px;
            padding: 9px 15px;
            border: 1px solid transparent;
            border-radius: 9px;
            background: #e2e8f0;
            color: #1e293b;
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
        }

        .button-primary {
            background: #1d4ed8;
            color: #ffffff;
        }

        .button-success {
            background: #047857;
            color: #ffffff;
        }

        .button-danger {
            background: #dc2626;
            color: #ffffff;
        }

        .button-light {
            background: #ffffff;
            border-color: #cbd5e1;
        }

        .button:hover {
            filter: brightness(0.96);
        }

        .message {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 10px;
            font-weight: 700;
        }

        .message-success {
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
        }

        .message-error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 22px;
            padding: 18px;
            background: #ffffff;
            border: 1px solid #dbe3ef;
            border-radius: 13px;
            box-shadow: 0 5px 18px rgba(15, 23, 42, 0.05);
        }

        .search-form {
            display: flex;
            flex: 1;
            gap: 9px;
        }

        .search-form input {
            width: 100%;
            min-height: 43px;
            padding: 10px 13px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            outline: none;
        }

        .search-form input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .result-count {
            white-space: nowrap;
            color: #475569;
            font-weight: 700;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(
                auto-fill,
                minmax(300px, 1fr)
            );
            gap: 18px;
        }

        .card {
            display: flex;
            flex-direction: column;
            min-height: 260px;
            padding: 20px;
            background: #ffffff;
            border: 1px solid #dbe3ef;
            border-radius: 14px;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06);
        }

        .card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
        }

        .card-title {
            margin: 0;
            color: #0f172a;
            font-size: 21px;
            word-break: break-word;
        }

        .favorite-form {
            flex: 0 0 auto;
        }

        .favorite-button {
            width: 42px;
            height: 42px;
            padding: 0;
            border: 1px solid #cbd5e1;
            border-radius: 50%;
            background: #ffffff;
            font-size: 22px;
            cursor: pointer;
        }

        .favorite-button.active {
            background: #fff7cc;
            border-color: #facc15;
        }

        .coordinates {
            margin: 18px 0;
            padding: 13px;
            background: #f8fafc;
            border-radius: 9px;
            color: #334155;
            font-size: 14px;
            line-height: 1.7;
        }

        .coordinates strong {
            color: #0f172a;
        }

        .notes {
            flex: 1;
            margin: 0 0 18px;
            color: #475569;
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .no-notes {
            color: #94a3b8;
            font-style: italic;
        }

        .card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        .card-actions .button {
            min-height: 38px;
            padding: 8px 11px;
            font-size: 13px;
        }

        .delete-form {
            display: inline-flex;
        }

        .empty-state {
            padding: 48px 25px;
            background: #ffffff;
            border: 1px dashed #94a3b8;
            border-radius: 14px;
            text-align: center;
        }

        .empty-state h2 {
            margin: 0 0 10px;
        }

        .empty-state p {
            margin: 0 0 20px;
            color: #64748b;
        }

        @media (max-width: 760px) {
            .topbar,
            .toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .topbar-actions,
            .search-form {
                width: 100%;
            }

            .topbar-actions .button {
                flex: 1;
            }

            .search-form {
                flex-wrap: wrap;
            }

            .search-form .button {
                flex: 1;
            }

            .result-count {
                white-space: normal;
            }

            .cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
<div class="page">

    <header class="topbar">
        <div class="topbar-title">
            <h1>⚓ Brabo Kaaien</h1>
            <p>
                Overzicht van kaainummers, coördinaten en opmerkingen
            </p>
        </div>

        <div class="topbar-actions">
            <a class="button button-light" href="dashboard.php">
                ← Dashboard
            </a>

            <?php if ($isManager): ?>
                <a
                    class="button button-primary"
                    href="brabo_address_form.php"
                >
                    ➕ Kaai toevoegen
                </a>
            <?php endif; ?>
        </div>
    </header>

    <main class="container">

        <?php if ($message !== ''): ?>
            <div
                class="message <?= $messageType === 'error'
                    ? 'message-error'
                    : 'message-success' ?>"
            >
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <section class="toolbar">
            <form class="search-form" method="get">
                <input
                    type="search"
                    name="q"
                    value="<?= h($search) ?>"
                    placeholder="Zoeken op kaai of opmerkingen..."
                    aria-label="Zoeken"
                >

                <button class="button button-primary" type="submit">
                    Zoeken
                </button>

                <?php if ($search !== ''): ?>
                    <a
                        class="button button-light"
                        href="brabo_addresses.php"
                    >
                        Wissen
                    </a>
                <?php endif; ?>
            </form>

            <div class="result-count">
                <?= count($addresses) ?>
                <?= count($addresses) === 1 ? 'kaai' : 'kaaien' ?>
            </div>
        </section>

        <?php if (!$addresses): ?>
            <section class="empty-state">
                <h2>Geen kaaien gevonden</h2>

                <p>
                    Er zijn nog geen Brabo-kaaien toegevoegd
                    of er zijn geen resultaten voor je zoekopdracht.
                </p>

                <?php if ($isManager): ?>
                    <a
                        class="button button-primary"
                        href="brabo_address_form.php"
                    >
                        ➕ Eerste kaai toevoegen
                    </a>
                <?php endif; ?>
            </section>
        <?php else: ?>

            <section class="cards">
                <?php foreach ($addresses as $address): ?>
                    <?php
                    $latitude = trim((string) ($address['latitude'] ?? ''));
                    $longitude = trim((string) ($address['longitude'] ?? ''));
                    $hasCoordinates =
                        $latitude !== ''
                        && $longitude !== '';

                    $googleMapsUrl = $hasCoordinates
                        ? 'https://www.google.com/maps/search/?api=1&query='
                            . rawurlencode($latitude . ',' . $longitude)
                        : '';

                    $wazeUrl = $hasCoordinates
                        ? 'https://waze.com/ul?ll='
                            . rawurlencode($latitude . ',' . $longitude)
                            . '&navigate=yes'
                        : '';
                    ?>

                    <article class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <?= h($address['name']) ?>
                            </h2>

                            <form class="favorite-form" method="post">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= h(csrf_token()) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="toggle_favorite"
                                >

                                <input
                                    type="hidden"
                                    name="address_id"
                                    value="<?= (int) $address['id'] ?>"
                                >

                                <button
                                    class="favorite-button <?= (int) $address['is_favorite'] === 1
                                        ? 'active'
                                        : '' ?>"
                                    type="submit"
                                    title="Favoriet aanpassen"
                                    aria-label="Favoriet aanpassen"
                                >
                                    <?= (int) $address['is_favorite'] === 1
                                        ? '★'
                                        : '☆' ?>
                                </button>
                            </form>
                        </div>

                        <div class="coordinates">
                            <div>
                                <strong>Breedtegraad:</strong>
                                <?= $latitude !== ''
                                    ? h($latitude)
                                    : 'Niet ingevuld' ?>
                            </div>

                            <div>
                                <strong>Lengtegraad:</strong>
                                <?= $longitude !== ''
                                    ? h($longitude)
                                    : 'Niet ingevuld' ?>
                            </div>
                        </div>

                        <?php if (trim((string) ($address['notes'] ?? '')) !== ''): ?>
                            <p class="notes"><?= h($address['notes']) ?></p>
                        <?php else: ?>
                            <p class="notes no-notes">
                                Geen opmerkingen toegevoegd.
                            </p>
                        <?php endif; ?>

                        <div class="card-actions">
                            <?php if ($hasCoordinates): ?>
                                <a
                                    class="button button-success"
                                    href="<?= h($googleMapsUrl) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    📍 Google Maps
                                </a>

                                <a
                                    class="button button-light"
                                    href="<?= h($wazeUrl) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    🚗 Waze
                                </a>
                            <?php endif; ?>

                            <?php if ($isManager): ?>
                                <a
                                    class="button button-light"
                                    href="brabo_address_form.php?id=<?= (int) $address['id'] ?>"
                                >
                                    ✏️ Bewerken
                                </a>

                                <form
                                    class="delete-form"
                                    method="post"
                                    onsubmit="return confirm('Weet je zeker dat je deze kaai wilt verwijderen?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h(csrf_token()) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >

                                    <input
                                        type="hidden"
                                        name="address_id"
                                        value="<?= (int) $address['id'] ?>"
                                    >

                                    <button
                                        class="button button-danger"
                                        type="submit"
                                    >
                                        🗑️ Verwijderen
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

        <?php endif; ?>

    </main>
</div>
</body>
</html>
