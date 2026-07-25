<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Helpers/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/layout.php';

use App\Middleware\Auth;
use App\Services\PermissionService;

Auth::requireLogin();
PermissionService::require('companies.view');

$currentUser = Auth::user();

if (!$currentUser) {
    redirect('/');
}

$search = trim((string) ($_GET['q'] ?? ''));

$sql = '
    SELECT
        c.id,
        c.name,
        c.description,
        c.phone,
        c.email,
        c.website,
        c.notes,
        c.active,
        c.created_at,
        c.updated_at,
        COUNT(a.id) AS address_count
    FROM companies c
    LEFT JOIN addresses a ON a.company_id = c.id
';

$params = [];

if ($search !== '') {
    $sql .= '
        WHERE (
            c.name LIKE :search
            OR c.description LIKE :search
            OR c.phone LIKE :search
            OR c.email LIKE :search
            OR c.website LIKE :search
            OR c.notes LIKE :search
        )
    ';

    $params['search'] = '%' . $search . '%';
}

$sql .= '
    GROUP BY
        c.id,
        c.name,
        c.description,
        c.phone,
        c.email,
        c.website,
        c.notes,
        c.active,
        c.created_at,
        c.updated_at
    ORDER BY c.name ASC
';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll();

$isManager = PermissionService::isManager();

render_header('Bedrijven');
?>

<section class="panel">

    <div class="panel-heading-row">
        <div>
            <span class="dashboard-eyebrow">🏢 Bedrijven</span>
            <h2>Bedrijven</h2>
            <p>Beheer alle bedrijven binnen het ATT Portaal.</p>
        </div>

        <a href="/dashboard.php">← Terug naar dashboard</a>
    </div>

    <form
        method="get"
        action="/companies.php"
        style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;"
    >
        <input
            type="search"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Zoek op bedrijfsnaam, omschrijving, telefoon of e-mail"
            style="flex:1;min-width:260px;padding:11px 13px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;"
        >

        <button type="submit" class="button">🔍 Zoeken</button>

        <?php if ($search !== ''): ?>
            <a href="/companies.php" class="button">Wissen</a>
        <?php endif; ?>

        <?php if ($isManager): ?>
            <a href="/company_form.php" class="button">➕ Nieuw bedrijf</a>
        <?php endif; ?>
    </form>

    <p style="color:#64748b;">
        <strong><?= count($companies) ?></strong>
        <?= count($companies) === 1 ? 'bedrijf gevonden' : 'bedrijven gevonden' ?>
    </p>

    <?php if (!$companies): ?>
        <div style="padding:30px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;text-align:center;color:#64748b;">
            Geen bedrijven gevonden.
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;min-width:1000px;border-collapse:collapse;">
                <thead>
                    <tr style="background:#eff6ff;">
                        <th style="padding:12px;text-align:left;">Bedrijf</th>
                        <th style="padding:12px;text-align:left;">Contact</th>
                        <th style="padding:12px;text-align:left;">Website</th>
                        <th style="padding:12px;text-align:left;">Adressen</th>
                        <th style="padding:12px;text-align:left;">Status</th>

                        <?php if ($isManager): ?>
                            <th style="padding:12px;text-align:left;">Beheer</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($companies as $company): ?>
                    <?php
                    $website = trim((string) ($company['website'] ?? ''));

                    if (
                        $website !== ''
                        && !str_starts_with($website, 'http://')
                        && !str_starts_with($website, 'https://')
                    ) {
                        $websiteUrl = 'https://' . $website;
                    } else {
                        $websiteUrl = $website;
                    }
                    ?>

                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <td style="padding:12px;vertical-align:top;">
                            <strong><?= e((string) $company['name']) ?></strong>

                            <?php if (!empty($company['description'])): ?>
                                <br>
                                <small style="color:#64748b;">
                                    <?= nl2br(e((string) $company['description'])) ?>
                                </small>
                            <?php endif; ?>

                            <?php if (!empty($company['notes'])): ?>
                                <div style="margin-top:8px;color:#475569;">
                                    <?= nl2br(e((string) $company['notes'])) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td style="padding:12px;vertical-align:top;">
                            <?php if (!empty($company['phone'])): ?>
                                ☎ <a href="tel:<?= e((string) $company['phone']) ?>"><?= e((string) $company['phone']) ?></a><br>
                            <?php endif; ?>

                            <?php if (!empty($company['email'])): ?>
                                ✉ <a href="mailto:<?= e((string) $company['email']) ?>"><?= e((string) $company['email']) ?></a>
                            <?php endif; ?>

                            <?php if (empty($company['phone']) && empty($company['email'])): ?>
                                <span style="color:#94a3b8;">Geen contactgegevens</span>
                            <?php endif; ?>
                        </td>

                        <td style="padding:12px;vertical-align:top;">
                            <?php if ($websiteUrl !== ''): ?>
                                <a href="<?= e($websiteUrl) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= e($website) ?>
                                </a>
                            <?php else: ?>
                                <span style="color:#94a3b8;">Geen website</span>
                            <?php endif; ?>
                        </td>

                        <td style="padding:12px;vertical-align:top;">
                            <strong><?= (int) $company['address_count'] ?></strong>
                            <?= (int) $company['address_count'] === 1 ? 'adres' : 'adressen' ?>
                        </td>

                        <td style="padding:12px;vertical-align:top;">
                            <?php if ((int) $company['active'] === 1): ?>
                                <span style="display:inline-block;padding:5px 9px;background:#dcfce7;color:#166534;border-radius:999px;font-weight:700;font-size:13px;">
                                    Actief
                                </span>
                            <?php else: ?>
                                <span style="display:inline-block;padding:5px 9px;background:#f1f5f9;color:#475569;border-radius:999px;font-weight:700;font-size:13px;">
                                    Inactief
                                </span>
                            <?php endif; ?>
                        </td>

                        <?php if ($isManager): ?>
                            <td style="padding:12px;vertical-align:top;">
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <a
                                        href="/company_form.php?id=<?= (int) $company['id'] ?>"
                                        style="display:inline-block;padding:8px 12px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:700;"
                                    >
                                        ✏️ Bewerken
                                    </a>

                                    <form
                                        method="post"
                                        action="/company_delete.php"
                                        onsubmit="return confirm('Weet u zeker dat u dit bedrijf wilt verwijderen?');"
                                        style="margin:0;"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $company['id'] ?>">

                                        <button
                                            type="submit"
                                            style="padding:8px 12px;background:#dc2626;color:#ffffff;border:0;border-radius:8px;font-weight:700;cursor:pointer;"
                                        >
                                            🗑️ Verwijderen
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

<?php render_footer(); ?>
