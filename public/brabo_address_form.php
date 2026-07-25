<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Helpers/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/layout.php';

use App\Middleware\Auth;
use App\Services\PermissionService;

Auth::requireLogin();

$currentUser = Auth::user();

if (!$currentUser) {
    redirect('/');
}

if (!PermissionService::isManager()) {
    http_response_code(403);
    exit('Alleen Admin en Developer mogen adressen toevoegen of bewerken.');
}

$userId = (int) $currentUser['id'];
$addressId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$isEdit = is_int($addressId) && $addressId > 0;

/*
|--------------------------------------------------------------------------
| Brabo-organisatie ophalen
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
    'slug' => 'brabo',
]);

$organization = $organizationStmt->fetch();

if (!$organization) {
    http_response_code(404);
    exit('De organisatie Brabo werd niet gevonden.');
}

$organizationId = (int) $organization['id'];

/*
|--------------------------------------------------------------------------
| Standaardwaarden
|--------------------------------------------------------------------------
*/

$form = [
    'name' => '',
    'department' => '',
    'street' => '',
    'house_number' => '',
    'postal_code' => '',
    'city' => '',
    'country' => 'België',
    'phone' => '',
    'mobile' => '',
    'email' => '',
    'website' => '',
    'contact_person' => '',
    'latitude' => '',
    'longitude' => '',
    'notes' => '',
    'loading_place' => '',
    'unloading_place' => '',
    'driver_entrance' => '',
    'gate_number' => '',
    'access_code' => '',
    'safety_instructions' => '',
];

$errors = [];

/*
|--------------------------------------------------------------------------
| Bestaand adres ophalen
|--------------------------------------------------------------------------
*/

if ($isEdit) {
    $addressStmt = db()->prepare(
        'SELECT
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
            is_active
         FROM addresses
         WHERE id = :id
           AND organization_id = :organization_id
         LIMIT 1'
    );

    $addressStmt->execute([
        'id' => $addressId,
        'organization_id' => $organizationId,
    ]);

    $address = $addressStmt->fetch();

    if (!$address) {
        http_response_code(404);
        exit('Het Brabo-adres werd niet gevonden.');
    }

    foreach (array_keys($form) as $field) {
        $form[$field] = (string) ($address[$field] ?? '');
    }
}

/*
|--------------------------------------------------------------------------
| Formulier verwerken
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'De beveiligingscontrole is mislukt. Probeer opnieuw.';
    }

    foreach (array_keys($form) as $field) {
        $form[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    if ($form['name'] === '') {
        $errors[] = 'Kaai is verplicht.';
    }

    if (
        $form['email'] !== ''
        && filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors[] = 'Vul een geldig e-mailadres in.';
    }

    if (
        $form['website'] !== ''
        && filter_var($form['website'], FILTER_VALIDATE_URL) === false
    ) {
        $errors[] = 'Vul een geldige website in, bijvoorbeeld https://voorbeeld.be.';
    }

    if (
        $form['latitude'] !== ''
        && !is_numeric($form['latitude'])
    ) {
        $errors[] = 'Breedtegraad moet een geldig getal zijn.';
    }

    if (
        $form['longitude'] !== ''
        && !is_numeric($form['longitude'])
    ) {
        $errors[] = 'Lengtegraad moet een geldig getal zijn.';
    }

    if (!$errors) {
        $data = [
            'organization_id' => $organizationId,
            'name' => $form['name'],
            'department' => $form['department'] !== ''
                ? $form['department']
                : null,
            'street' => null,
            'house_number' => null,
            'postal_code' => null,
            'city' => null,
            'country' => 'België',
            'phone' => $form['phone'] !== ''
                ? $form['phone']
                : null,
            'mobile' => $form['mobile'] !== ''
                ? $form['mobile']
                : null,
            'email' => $form['email'] !== ''
                ? $form['email']
                : null,
            'website' => $form['website'] !== ''
                ? $form['website']
                : null,
            'contact_person' => $form['contact_person'] !== ''
                ? $form['contact_person']
                : null,
            'latitude' => $form['latitude'] !== ''
                ? $form['latitude']
                : null,
            'longitude' => $form['longitude'] !== ''
                ? $form['longitude']
                : null,
            'notes' => $form['notes'] !== ''
                ? $form['notes']
                : null,
            'loading_place' => $form['loading_place'] !== ''
                ? $form['loading_place']
                : null,
            'unloading_place' => $form['unloading_place'] !== ''
                ? $form['unloading_place']
                : null,
            'driver_entrance' => $form['driver_entrance'] !== ''
                ? $form['driver_entrance']
                : null,
            'gate_number' => $form['gate_number'] !== ''
                ? $form['gate_number']
                : null,
            'access_code' => $form['access_code'] !== ''
                ? $form['access_code']
                : null,
            'safety_instructions' => $form['safety_instructions'] !== ''
                ? $form['safety_instructions']
                : null,
            'user_id' => $userId,
        ];

        try {
            if ($isEdit) {
                $data['id'] = $addressId;

                $updateStmt = db()->prepare(
                    'UPDATE addresses
                     SET
                        name = :name,
                        department = :department,
                        street = :street,
                        house_number = :house_number,
                        postal_code = :postal_code,
                        city = :city,
                        country = :country,
                        phone = :phone,
                        mobile = :mobile,
                        email = :email,
                        website = :website,
                        contact_person = :contact_person,
                        latitude = :latitude,
                        longitude = :longitude,
                        notes = :notes,
                        loading_place = :loading_place,
                        unloading_place = :unloading_place,
                        driver_entrance = :driver_entrance,
                        gate_number = :gate_number,
                        access_code = :access_code,
                        safety_instructions = :safety_instructions,
                        updated_by = :user_id
                     WHERE id = :id
                       AND organization_id = :organization_id'
                );

                $updateStmt->execute($data);

                log_activity(
                    $userId,
                    'address_updated',
                    'address',
                    $addressId,
                    [
                        'organization' => 'brabo',
                        'name' => $form['name'],
                    ]
                );

                flash('success', 'Het Brabo-adres is bijgewerkt.');
            } else {
                $insertStmt = db()->prepare(
                    'INSERT INTO addresses (
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
                        created_by,
                        updated_by
                     ) VALUES (
                        :organization_id,
                        :name,
                        :department,
                        :street,
                        :house_number,
                        :postal_code,
                        :city,
                        :country,
                        :phone,
                        :mobile,
                        :email,
                        :website,
                        :contact_person,
                        :latitude,
                        :longitude,
                        :notes,
                        :loading_place,
                        :unloading_place,
                        :driver_entrance,
                        :gate_number,
                        :access_code,
                        :safety_instructions,
                        1,
                        :user_id,
                        :user_id
                     )'
                );

                $insertStmt->execute($data);

                $newAddressId = (int) db()->lastInsertId();

                log_activity(
                    $userId,
                    'address_created',
                    'address',
                    $newAddressId,
                    [
                        'organization' => 'brabo',
                        'name' => $form['name'],
                    ]
                );

                flash('success', 'Het nieuwe Brabo-adres is toegevoegd.');
            }

            redirect('/brabo_addresses.php');
        } catch (Throwable $exception) {
            error_log(
                'Brabo-adres opslaan mislukt: '
                . $exception->getMessage()
            );

            $errors[] = 'Het adres kon niet worden opgeslagen. Controleer de gegevens en probeer opnieuw.';
        }
    }
}

render_header(
    $isEdit
        ? 'Brabo-adres bewerken'
        : 'Nieuw Brabo-adres'
);
?>

<style>
.address-form-page {
    display: grid;
    gap: 20px;
}

.address-form-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
}

.address-form-heading h2 {
    margin: 6px 0 6px;
}

.address-form-heading p {
    margin: 0;
    color: #64748b;
}

.address-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.address-form-section {
    grid-column: 1 / -1;
    margin-top: 8px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e2e8f0;
    color: #0b4a86;
    font-size: 17px;
}

.address-form-group {
    display: grid;
    gap: 7px;
}

.address-form-group.full-width {
    grid-column: 1 / -1;
}

.address-form-group label {
    font-weight: 700;
}

.address-form-group input,
.address-form-group textarea {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #ffffff;
    color: #1f2937;
    font: inherit;
}

.address-form-group textarea {
    min-height: 115px;
    resize: vertical;
}

.address-form-group input:focus,
.address-form-group textarea:focus {
    outline: 2px solid #bfdbfe;
    border-color: #2563eb;
}

.address-required {
    color: #b91c1c;
}

.address-form-errors {
    padding: 15px 18px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #991b1b;
}

.address-form-errors strong {
    display: block;
    margin-bottom: 8px;
}

.address-form-errors ul {
    margin: 0;
    padding-left: 20px;
}

.address-form-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 22px;
}

.address-form-note {
    color: #64748b;
    font-size: 13px;
}

@media (max-width: 800px) {
    .address-form-grid {
        grid-template-columns: 1fr;
    }

    .address-form-group.full-width,
    .address-form-section {
        grid-column: auto;
    }

    .address-form-heading {
        flex-direction: column;
    }
}
</style>

<section class="panel address-form-page">

    <div class="address-form-heading">
        <div>
            <span class="dashboard-eyebrow">
                🟦 Brabo
            </span>

            <h2>
                <?= $isEdit
                    ? 'Brabo-adres bewerken'
                    : 'Nieuw Brabo-adres toevoegen'
                ?>
            </h2>

            <p>
                Velden met een rood sterretje zijn verplicht.
            </p>
        </div>

        <a href="/brabo_addresses.php" class="button">
            ← Terug naar adressen
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="address-form-errors">
            <strong>Controleer de volgende punten:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= e(csrf_token()) ?>"
    >

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <strong>Controleer de volgende punten:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="address-form-grid">

        <div class="address-form-group full-width">
            <label for="name">
                Kaai
            </label>

            <input
                id="name"
                name="name"
                type="text"
                value="<?= e($form['name']) ?>"
                placeholder="Bijvoorbeeld: Kaai 330"
                required
            >
        </div>

        <div class="address-form-group">
            <label for="latitude">
                Breedtegraad
            </label>

            <input
                id="latitude"
                name="latitude"
                type="text"
                inputmode="decimal"
                value="<?= e($form['latitude']) ?>"
                placeholder="Bijvoorbeeld: 51.3470470"
            >
        </div>

        <div class="address-form-group">
            <label for="longitude">
                Lengtegraad
            </label>

            <input
                id="longitude"
                name="longitude"
                type="text"
                inputmode="decimal"
                value="<?= e($form['longitude']) ?>"
                placeholder="Bijvoorbeeld: 4.2834200"
            >
        </div>

        <div class="address-form-group full-width">
            <label for="notes">
                Opmerkingen
            </label>

            <textarea
                id="notes"
                name="notes"
                rows="7"
                placeholder="Bijvoorbeeld: aanmelden bij de portier, ingang via poort 2..."
            ><?= e($form['notes']) ?></textarea>
        </div>

    </div>

    <div class="form-actions">
        <button
            class="button button-primary"
            type="submit"
        >
            <?= $isEdit ? 'Wijzigingen opslaan' : 'Kaai toevoegen' ?>
        </button>

        <a
            class="button button-secondary"
            href="brabo_addresses.php"
        >
            Annuleren
        </a>
    </div>
<form method="post">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= e(csrf_token()) ?>"
    >

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <strong>Controleer de volgende punten:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="address-form-grid">

        <div class="address-form-group full-width">
            <label for="name">
                Kaai
            </label>

            <input
                id="name"
                name="name"
                type="text"
                value="<?= e($form['name']) ?>"
                placeholder="Bijvoorbeeld: Kaai 330"
                required
            >
        </div>

        <div class="address-form-group">
            <label for="latitude">
                Breedtegraad
            </label>

            <input
                id="latitude"
                name="latitude"
                type="text"
                inputmode="decimal"
                value="<?= e($form['latitude']) ?>"
                placeholder="Bijvoorbeeld: 51.3470470"
            >
        </div>

        <div class="address-form-group">
            <label for="longitude">
                Lengtegraad
            </label>

            <input
                id="longitude"
                name="longitude"
                type="text"
                inputmode="decimal"
                value="<?= e($form['longitude']) ?>"
                placeholder="Bijvoorbeeld: 4.2834200"
            >
        </div>

        <div class="address-form-group full-width">
            <label for="notes">
                Opmerkingen
            </label>

            <textarea
                id="notes"
                name="notes"
                rows="7"
                placeholder="Bijvoorbeeld: aanmelden bij de portier, ingang via poort 2..."
            ><?= e($form['notes']) ?></textarea>
        </div>

    </div>

    <div class="form-actions">
        <button
            class="button button-primary"
            type="submit"
        >
            <?= $isEdit ? 'Wijzigingen opslaan' : 'Kaai toevoegen' ?>
        </button>

        <a
            class="button button-secondary"
            href="brabo_addresses.php"
        >
            Annuleren
        </a>
    </div>
</form>

</section>

<?php render_footer(); ?>
