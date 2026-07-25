<?php
require dirname(__DIR__) . '/app/Helpers/bootstrap.php';

if (empty($_SESSION['user'])) {
    header('Location: /index.php');
    exit;
}

$user = $_SESSION['user'];
$role = strtolower($user['role'] ?? '');

$canManageAddresses = in_array($role, ['admin', 'developer'], true);
$userName = $user['name'] ?? $user['username'] ?? 'Gebruiker';

/*
 * Tijdelijke voorbeeldgegevens.
 * Later vervangen we dit door adressen uit de database.
 */
$addresses = [
    [
        'id' => 1,
        'company' => 'Damen Shipyards',
        'street' => 'Ritthemsestraat 500',
        'postal_code' => '4389 PA',
        'city' => 'Vlissingen-Oost',
        'country' => 'Nederland',
        'contact' => '',
        'phone' => '',
        'group' => 'NLW',
        'notes' => 'Controleer vooraf de juiste ingang en meldprocedure.',
    ],
    [
        'id' => 2,
        'company' => 'ArcelorMittal',
        'street' => 'John Kennedylaan 51',
        'postal_code' => '9042',
        'city' => 'Gent',
        'country' => 'België',
        'contact' => '',
        'phone' => '',
        'group' => 'Brabo',
        'notes' => 'Volg de lokale veiligheids- en aanmeldinstructies.',
    ],
];

$search = trim($_GET['search'] ?? '');
$groupFilter = trim($_GET['group'] ?? '');

$filteredAddresses = array_filter(
    $addresses,
    function (array $address) use ($search, $groupFilter): bool {
        if ($groupFilter !== '' && $address['group'] !== $groupFilter) {
            return false;
        }

        if ($search === '') {
            return true;
        }

        $haystack = implode(' ', [
            $address['company'],
            $address['street'],
            $address['postal_code'],
            $address['city'],
            $address['country'],
            $address['contact'],
            $address['phone'],
            $address['group'],
            $address['notes'],
        ]);

        return stripos($haystack, $search) !== false;
    }
);

function mapsUrl(array $address): string
{
    $destination = implode(', ', array_filter([
        $address['street'],
        $address['postal_code'],
        $address['city'],
        $address['country'],
    ]));

    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($destination);
}

function wazeUrl(array $address): string
{
    $destination = implode(', ', array_filter([
        $address['street'],
        $address['postal_code'],
        $address['city'],
        $address['country'],
    ]));

    return 'https://www.waze.com/ul?q=' . rawurlencode($destination) . '&navigate=yes';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Adressen | ATT Portaal</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f3f6fa;
            color: #182230;
            font-family: Arial, Helvetica, sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            min-height: 100vh;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 18px 30px;
            background: #0d2d4d;
            color: #ffffff;
            box-shadow: 0 5px 18px rgba(13, 45, 77, 0.18);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-icon {
            display: grid;
            width: 42px;
            height: 42px;
            place-items: center;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.14);
            font-size: 22px;
        }

        .brand strong {
            display: block;
            font-size: 19px;
        }

        .brand small {
            display: block;
            margin-top: 3px;
            color: rgba(255, 255, 255, 0.72);
        }

        .topbar-user {
            text-align: right;
        }

        .topbar-user strong,
        .topbar-user span {
            display: block;
        }

        .topbar-user span {
            margin-top: 4px;
            color: rgba(255, 255, 255, 0.72);
            font-size: 13px;
            text-transform: capitalize;
        }

        .container {
            width: min(1200px, calc(100% - 32px));
            margin: 0 auto;
            padding: 34px 0 50px;
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 25px;
        }

        .page-header h1 {
            margin: 0 0 8px;
            font-size: 32px;
        }

        .page-header p {
            margin: 0;
            color: #667085;
            line-height: 1.6;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 10px 16px;
            border: 0;
            border-radius: 10px;
            background: #1769aa;
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .button.secondary {
            background: #ffffff;
            color: #344054;
            border: 1px solid #d0d5dd;
        }

        .button.small {
            min-height: 38px;
            padding: 8px 12px;
            font-size: 13px;
        }

        .filters {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 220px auto;
            gap: 14px;
            margin-bottom: 22px;
            padding: 18px;
            border: 1px solid #e2e8f0;
            border-radius: 15px;
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(16, 24, 40, 0.06);
        }

        .filters input,
        .filters select {
            width: 100%;
            min-height: 44px;
            padding: 10px 13px;
            border: 1px solid #d0d5dd;
            border-radius: 9px;
            background: #ffffff;
            color: #182230;
            font-size: 15px;
            outline: none;
        }

        .filters input:focus,
        .filters select:focus {
            border-color: #1769aa;
            box-shadow: 0 0 0 4px rgba(23, 105, 170, 0.12);
        }

        .address-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .address-card {
            display: flex;
            flex-direction: column;
            min-height: 100%;
            overflow: hidden;
            border: 1px solid #e1e7ef;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 12px 34px rgba(16, 24, 40, 0.07);
        }

        .address-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 20px 20px 16px;
            border-bottom: 1px solid #eef2f6;
        }

        .address-card h2 {
            margin: 0 0 6px;
            font-size: 21px;
        }

        .address-card-header p {
            margin: 0;
            color: #667085;
            line-height: 1.5;
        }

        .badge {
            flex: 0 0 auto;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eaf4fd;
            color: #175f96;
            font-size: 12px;
            font-weight: 800;
        }

        .address-card-body {
            display: grid;
            gap: 14px;
            padding: 18px 20px;
        }

        .info-row {
            display: grid;
            grid-template-columns: 120px minmax(0, 1fr);
            gap: 12px;
            line-height: 1.5;
        }

        .info-row strong {
            color: #475467;
            font-size: 14px;
        }

        .info-row span {
            color: #182230;
        }

        .notes {
            padding: 14px;
            border-radius: 10px;
            background: #f8fafc;
            color: #475467;
            line-height: 1.6;
        }

        .address-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: auto;
            padding: 16px 20px 20px;
        }

        .empty-state {
            grid-column: 1 / -1;
            padding: 45px 25px;
            border: 1px dashed #cbd5e1;
            border-radius: 16px;
            background: #ffffff;
            text-align: center;
            color: #667085;
        }

        .back-link {
            display: inline-flex;
            margin-bottom: 18px;
            color: #1769aa;
            font-weight: 700;
        }

        @media (max-width: 850px) {
            .address-grid {
                grid-template-columns: 1fr;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
            }
        }

        @media (max-width: 600px) {
            .topbar {
                align-items: flex-start;
                padding: 16px 18px;
            }

            .topbar-user {
                display: none;
            }

            .container {
                width: min(100% - 22px, 1200px);
                padding-top: 24px;
            }

            .page-header h1 {
                font-size: 27px;
            }

            .info-row {
                grid-template-columns: 1fr;
                gap: 3px;
            }

            .address-actions .button {
                width: 100%;
            }
        }
    </style>
</head>

<body>
<div class="page">

    <header class="topbar">
        <div class="brand">
            <div class="brand-icon">📍</div>

            <div>
                <strong>ATT Portaal</strong>
                <small>Brabo &amp; NLW</small>
            </div>
        </div>

        <div class="topbar-user">
            <strong><?= htmlspecialchars($userName) ?></strong>
            <span><?= htmlspecialchars($role) ?></span>
        </div>
    </header>

    <main class="container">

        <a class="back-link" href="/dashboard.php">← Terug naar dashboard</a>

        <div class="page-header">
            <div>
                <h1>Adressen</h1>
                <p>
                    Zoek adressen, bekijk locatiegegevens en start direct
                    navigatie met Google Maps of Waze.
                </p>
            </div>

            <?php if ($canManageAddresses): ?>
                <a class="button" href="/adres-toevoegen.php">
                    ＋ Nieuw adres
                </a>
            <?php endif; ?>
        </div>

        <form class="filters" method="get">
            <input
                type="search"
                name="search"
                value="<?= htmlspecialchars($search) ?>"
                placeholder="Zoeken op bedrijf, plaats, straat of contactpersoon"
            >

            <select name="group">
                <option value="">Alle groepen</option>

                <option
                    value="Brabo"
                    <?= $groupFilter === 'Brabo' ? 'selected' : '' ?>
                >
                    Brabo
                </option>

                <option
                    value="NLW"
                    <?= $groupFilter === 'NLW' ? 'selected' : '' ?>
                >
                    NLW
                </option>

                <option
                    value="Beide"
                    <?= $groupFilter === 'Beide' ? 'selected' : '' ?>
                >
                    Beide
                </option>
            </select>

            <button class="button" type="submit">
                🔍 Zoeken
            </button>
        </form>

        <section class="address-grid">

            <?php if (empty($filteredAddresses)): ?>

                <div class="empty-state">
                    <h2>Geen adressen gevonden</h2>
                    <p>Pas de zoekopdracht of het groepsfilter aan.</p>
                </div>

            <?php else: ?>

                <?php foreach ($filteredAddresses as $address): ?>

                    <article class="address-card">

                        <div class="address-card-header">
                            <div>
                                <h2>
                                    <?= htmlspecialchars($address['company']) ?>
                                </h2>

                                <p>
                                    <?= htmlspecialchars($address['city']) ?>,
                                    <?= htmlspecialchars($address['country']) ?>
                                </p>
                            </div>

                            <span class="badge">
                                <?= htmlspecialchars($address['group']) ?>
                            </span>
                        </div>

                        <div class="address-card-body">

                            <div class="info-row">
                                <strong>Adres</strong>

                                <span>
                                    <?= htmlspecialchars($address['street']) ?><br>
                                    <?= htmlspecialchars($address['postal_code']) ?>
                                    <?= htmlspecialchars($address['city']) ?>
                                </span>
                            </div>

                            <div class="info-row">
                                <strong>Contactpersoon</strong>

                                <span>
                                    <?= htmlspecialchars(
                                        $address['contact'] !== ''
                                            ? $address['contact']
                                            : 'Niet ingevuld'
                                    ) ?>
                                </span>
                            </div>

                            <div class="info-row">
                                <strong>Telefoon</strong>

                                <span>
                                    <?= htmlspecialchars(
                                        $address['phone'] !== ''
                                            ? $address['phone']
                                            : 'Niet ingevuld'
                                    ) ?>
                                </span>
                            </div>

                            <?php if ($address['notes'] !== ''): ?>
                                <div class="notes">
                                    <strong>Opmerking</strong><br>
                                    <?= nl2br(htmlspecialchars($address['notes'])) ?>
                                </div>
                            <?php endif; ?>

                        </div>

                        <div class="address-actions">

                            <a
                                class="button small"
                                href="<?= htmlspecialchars(mapsUrl($address)) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                🗺️ Google Maps
                            </a>

                            <a
                                class="button secondary small"
                                href="<?= htmlspecialchars(wazeUrl($address)) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                🚗 Waze
                            </a>

                            <?php if ($canManageAddresses): ?>
                                <a
                                    class="button secondary small"
                                    href="/adres-bewerken.php?id=<?= (int) $address['id'] ?>"
                                >
                                    ✏️ Bewerken
                                </a>
                            <?php endif; ?>

                        </div>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </section>

    </main>
</div>
</body>
</html>
