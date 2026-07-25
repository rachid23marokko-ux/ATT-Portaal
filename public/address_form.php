<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Helpers/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/layout.php';

use App\Middleware\Auth;
use App\Services\PermissionService;

Auth::requireLogin();

$currentUser = Auth::user();

if (!$currentUser) {
    redirect('/login.php');
}

if (!PermissionService::isManager()) {
    http_response_code(403);
    exit('Alleen Admin en Developer mogen adressen toevoegen of bewerken.');
}

$userId = (int) $currentUser['id'];

$organizationSlug = strtolower(
    trim((string) ($_GET['organization'] ?? $_POST['organization'] ?? ''))
);

if (!in_array($organizationSlug, ['brabo', 'nlw'], true)) {
    http_response_code(400);
    exit('Ongeldige organisatie.');
}

$organizationStmt = db()->prepare(
    'SELECT id, name, slug
     FROM organizations
     WHERE slug = :slug
       AND is_active = 1
     LIMIT 1'
);

$organizationStmt->execute([
    'slug' => $organizationSlug,
]);

$organization = $organizationStmt->fetch();

if (!$organization) {
    http_response_code(404);
    exit('De organisatie werd niet gevonden.');
}

$organizationId = (int) $organization['id'];
$organizationName = (string) $organization['name'];

$listPage = $organizationSlug === 'brabo'
    ? '/brabo_addresses.php'
    : '/nlw_addresses.php';

$addressId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$addressId && isset($_POST['address_id'])) {
    $addressId = filter_var(
        $_POST['address_id'],
        FILTER_VALIDATE_INT
    );
}

$isEdit = is_int($addressId) && $addressId > 0;

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
$isFavorite = false;

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
        exit('Het adres werd niet gevonden.');
    }

    foreach (array_keys($form) as $field) {
        $form[$field] = (string) ($address[$field] ?? '');
    }

    $favoriteStmt = db()->prepare(
        'SELECT 1
         FROM favorites
         WHERE user_id = :user_id
           AND address_id = :address_id
         LIMIT 1'
    );

    $favoriteStmt->execute([
        'user_id' => $userId,
        'address_id' => $addressId,
    ]);

    $isFavorite = (bool) $favoriteStmt->fetchColumn();
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

    $isFavorite = isset($_POST['is_favorite']);

    if ($form['name'] === '') {
        $errors[] = 'Naam is verplicht.';
    }

    if ($form['country'] === '') {
        $errors[] = 'Land is verplicht.';
    }

    $hasAnyAddressPart =
        $form['street'] !== ''
        || $form['house_number'] !== ''
        || $form['postal_code'] !== ''
        || $form['city'] !== '';

    $hasCompleteAddress =
        $form['street'] !== ''
        && $form['house_number'] !== ''
        && $form['postal_code'] !== ''
        && $form['city'] !== '';

    $hasAnyCoordinate =
        $form['latitude'] !== ''
        || $form['longitude'] !== '';

    $hasCompleteCoordinates =
        $form['latitude'] !== ''
        && $form['longitude'] !== '';

    if (!$hasCompleteAddress && !$hasCompleteCoordinates) {
        $errors[] =
            'Vul een volledig adres in of vul beide GPS-coördinaten in.';
    }

    if ($hasAnyAddressPart && !$hasCompleteAddress && !$hasCompleteCoordinates) {
        $errors[] =
            'Vul straat, huisnummer, postcode en plaats volledig in.';
    }

    if ($hasAnyCoordinate && !$hasCompleteCoordinates) {
        $errors[] =
            'Breedtegraad en lengtegraad moeten allebei ingevuld zijn.';
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
        $errors[] =
            'Vul een geldige website in, bijvoorbeeld https://voorbeeld.be.';
    }

    if (
        $form['latitude'] !== ''
        && !is_numeric(str_replace(',', '.', $form['latitude']))
    ) {
        $errors[] = 'Breedtegraad moet een geldig getal zijn.';
    }

    if (
        $form['longitude'] !== ''
        && !is_numeric(str_replace(',', '.', $form['longitude']))
    ) {
        $errors[] = 'Lengtegraad moet een geldig getal zijn.';
    }

    if (!$errors) {
        $latitude = $form['latitude'] !== ''
            ? str_replace(',', '.', $form['latitude'])
            : null;

        $longitude = $form['longitude'] !== ''
            ? str_replace(',', '.', $form['longitude'])
            : null;

        $data = [
            'organization_id' => $organizationId,
            'name' => $form['name'],
            'department' => $form['department'] ?: null,
            'street' => $form['street'] ?: null,
            'house_number' => $form['house_number'] ?: null,
            'postal_code' => $form['postal_code'] ?: null,
            'city' => $form['city'] ?: null,
            'country' => $form['country'],
            'phone' => $form['phone'] ?: null,
            'mobile' => $form['mobile'] ?: null,
            'email' => $form['email'] ?: null,
            'website' => $form['website'] ?: null,
            'contact_person' => $form['contact_person'] ?: null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'notes' => $form['notes'] ?: null,
            'loading_place' => $form['loading_place'] ?: null,
            'unloading_place' => $form['unloading_place'] ?: null,
            'driver_entrance' => $form['driver_entrance'] ?: null,
            'gate_number' => $form['gate_number'] ?: null,
            'access_code' => $form['access_code'] ?: null,
            'safety_instructions' => $form['safety_instructions'] ?: null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ];

        try {
            db()->beginTransaction();

            if ($isEdit) {
                $updateData = $data;
                $updateData['id'] = $addressId;
                $updateData['user_id'] = $userId;
                unset($updateData['created_by'], $updateData['updated_by']);

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
                        updated_by = :user_id,
                        updated_at = NOW()
                     WHERE id = :id
                       AND organization_id = :organization_id'
                );

                $updateStmt->execute($updateData);

                $savedAddressId = (int) $addressId;
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
                        updated_by,
                        created_at,
                        updated_at
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
                        :created_by,
                        :updated_by,
                        NOW(),
                        NOW()
                     )'
                );

                $insertStmt->execute($data);

                $savedAddressId = (int) db()->lastInsertId();
            }

            if ($isFavorite) {
                $favoriteInsertStmt = db()->prepare(
                    'INSERT IGNORE INTO favorites (
                        user_id,
                        address_id,
                        created_at
                     ) VALUES (
                        :user_id,
                        :address_id,
                        NOW()
                     )'
                );

                $favoriteInsertStmt->execute([
                    'user_id' => $userId,
                    'address_id' => $savedAddressId,
                ]);
            } else {
                $favoriteDeleteStmt = db()->prepare(
                    'DELETE FROM favorites
                     WHERE user_id = :user_id
                       AND address_id = :address_id'
                );

                $favoriteDeleteStmt->execute([
                    'user_id' => $userId,
                    'address_id' => $savedAddressId,
                ]);
            }

            db()->commit();

            $_SESSION['success_message'] = $isEdit
                ? 'Het adres is bijgewerkt.'
                : 'Het adres is toegevoegd.';

            redirect($listPage);
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            error_log('Adres opslaan mislukt: ' . $exception->getMessage());
            $errors[] = 'Het adres kon niet worden opgeslagen. Probeer opnieuw.';
        }
    }
}

$pageTitle = $isEdit
    ? $organizationName . '-adres bewerken'
    : 'Nieuw ' . $organizationName . '-adres';

render_header($pageTitle);
?>

<style>
.address-page {
    display: grid;
    gap: 20px;
}

.address-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
}

.address-heading h2 {
    margin: 6px 0;
}

.address-heading p {
    margin: 0;
    color: #64748b;
}

.address-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.form-section {
    grid-column: 1 / -1;
    margin: 12px 0 0;
    padding-bottom: 9px;
    border-bottom: 1px solid #e2e8f0;
    color: #0b4a86;
}

.form-group {
    display: grid;
    gap: 7px;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-group label {
    font-weight: 700;
}

.form-group input,
.form-group textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font: inherit;
}

.form-group textarea {
    min-height: 105px;
    resize: vertical;
}

.form-group input:focus,
.form-group textarea:focus {
    outline: 2px solid #bfdbfe;
    border-color: #2563eb;
}

.required {
    color: #b91c1c;
}

.form-note {
    color: #64748b;
    font-size: 13px;
}

.location-choice {
    grid-column: 1 / -1;
    padding: 14px;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    background: #eff6ff;
}

.location-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.form-errors {
    padding: 15px 18px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #991b1b;
}

.form-errors ul {
    margin-bottom: 0;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 22px;
}

.favorite-option {
    display: flex;
    align-items: center;
    gap: 10px;
}

.favorite-option input {
    width: auto;
}

@media (max-width: 800px) {
    .address-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full,
    .form-section,
    .location-choice {
        grid-column: auto;
    }

    .address-heading {
        flex-direction: column;
    }
}
</style>

<section class="panel address-page">

    <div class="address-heading">
        <div>
            <span class="dashboard-eyebrow">
                <?= $organizationSlug === 'brabo' ? '🟦' : '🟩' ?>
                <?= e($organizationName) ?>
            </span>

            <h2><?= e($pageTitle) ?></h2>

            <p>
                Vul een volledig adres of GPS-coördinaten in.
            </p>
        </div>

        <a href="<?= e($listPage) ?>" class="button">
            ← Terug naar adressen
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="form-errors">
            <strong>Controleer de volgende punten:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" id="address-form">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrf_token()) ?>"
        >

        <input
            type="hidden"
            name="organization"
            value="<?= e($organizationSlug) ?>"
        >

        <?php if ($isEdit): ?>
            <input
                type="hidden"
                name="address_id"
                value="<?= (int) $addressId ?>"
            >
        <?php endif; ?>

        <div class="address-grid">

            <h3 class="form-section">Bedrijfsgegevens</h3>

            <div class="form-group">
                <label for="name">
                    Naam <span class="required">*</span>
                </label>

                <input
                    id="name"
                    name="name"
                    type="text"
                    maxlength="180"
                    required
                    value="<?= e($form['name']) ?>"
                >
            </div>

            <div class="form-group">
                <label for="department">Afdeling</label>

                <input
                    id="department"
                    name="department"
                    type="text"
                    maxlength="180"
                    value="<?= e($form['department']) ?>"
                >
            </div>

            <div class="form-group">
                <label for="contact_person">Contactpersoon</label>

                <input
                    id="contact_person"
                    name="contact_person"
                    type="text"
                    maxlength="180"
                    value="<?= e($form['contact_person']) ?>"
                >
            </div>

            <div class="form-group">
                <label for="website">Website</label>

                <input
                    id="website"
                    name="website"
                    type="url"
                    maxlength="255"
                    placeholder="https://"
                    value="<?= e($form['website']) ?>"
                >
            </div>

            <h3 class="form-section">Adres of GPS-locatie</h3>

            <div class="location-choice">
                Vul straat, huisnummer, postcode en plaats volledig in,
                <strong>of</strong> vul breedtegraad en lengtegraad in.
            </div>

            <div class="form-group">
                <label for="street">Straat</label>

                <input
                    id="street"
                    name="street"
                    type="text"
                    maxlength="180"
                    value="<?= e($form['street']) ?>"
                >
            </div>

            <div class="form-group">
                <label for="house_number">Huisnummer</label>

                <input
                    id="house_number"
                    name="house_number"
                    type="text"
                    maxlength="30"
                    value="<?= e($form['house_number']) ?>"
                >
            </div>

            <div class="form-group">
                <label for="postal_code">Postcode</label>

                <input
                    id="postal_code"
                    name="postal_code"
                    type="text"
                    maxlength="20"
                    value="<?= e($form['postal_code']) ?>"
                >
            </div>

            <div class="form-group">
                <label for="city">Plaats</label>

                <input
                    id="city"
                    name="city"
                    type="text"
                    maxlength="120"
                    value="<?= e($form['city']) ?>"
                >
            </div>

            <div class="form-group">
                <label for="country">
                    Land <span class="required">*</span>
                </label>

                <input
                    id="country"
                    name="country"
                    type="text"
                    maxlength="80"
                    required
                    value="<?= e($form['country']) ?>"
                >
            </div>

            <div></div>

            <div class="form-group">
                <label for="latitude">Breedtegraad</label>

                <input
                    id="latitude"
                    name="latitude"
                    type="text"
                    inputmode="decimal"
                    placeholder="51.2194475"
                    value="<?= e($form['latitude']) ?>"
                >
            </div>

            <div class="form-group">
                <label for="longitude">Lengtegraad</label>

                <input
                    id="longitude"
                    name="longitude"
                    type="text"
                    inputmode="decimal"
                    placeholder="4.4024643"
                    value="<?= e($form['longitude']) ?>"
                >
            </div>

            <div class="location-buttons full form-group">
                <button
                    type="button"
                    class="button"
                    id="use-current-location"
                >
                    📌 Huidige locatie gebruiken
                </button>

                <button
                    type="button"
                    class="button"
                    id="open-google-maps"
                >
                    🗺️ Controleren in Google Maps
                </button>

                <span id="location-status" class="form-note"></span>
            </div>

            <h3 class="form-section">Contactgegevens</h3>

            <div class="form-group">
                <label for="phone">Telefoon</label>

                <input
                    id="phone"
                    name="phone"
                    type="tel"
                    maxlength="50"
                    value="<?= e($form['phone']) ?>"
                >
            </div>

            <div class="form-group">
                <label for="mobile">Mobiel</label>

                <input
                    id="mobile"
                    name="mobile"
                    type="tel"
                    maxlength="50"
                    value="<?= e($form['mobile']) ?>"
                >
            </div>

            <div class="form-group full">
                <label for="email">E-mailadres</label>

                <input
                    id="email"
                    name="email"
                    type="email"
                    maxlength="190"
                    value="<?= e($form['email']) ?>"
                >
            </div>

            <h3 class="form-section">
                Chauffeursinformatie
            </h3>

            <div class="form-group">
                <label for="gate_number">Poortnummer</label>

                <input
                    id="gate_number"
                    name="gate_number"
                    type="text"
                    maxlength="80"
                    value="<?= e($form['gate_number']) ?>"
                >
            </div>

            <div class="form-group">
                <label for="access_code">Toegangscode</label>

                <input
                    id="access_code"
                    name="access_code"
                    type="text"
                    maxlength="120"
                    value="<?= e($form['access_code']) ?>"
                >
            </div>

            <div class="form-group full">
                <label for="driver_entrance">Chauffeursingang</label>

                <textarea
                    id="driver_entrance"
                    name="driver_entrance"
                ><?= e($form['driver_entrance']) ?></textarea>
            </div>

            <div class="form-group full">
                <label for="loading_place">Laadplaats</label>

                <textarea
                    id="loading_place"
                    name="loading_place"
                ><?= e($form['loading_place']) ?></textarea>
            </div>

            <div class="form-group full">
                <label for="unloading_place">Losplaats</label>

                <textarea
                    id="unloading_place"
                    name="unloading_place"
                ><?= e($form['unloading_place']) ?></textarea>
            </div>

            <div class="form-group full">
                <label for="safety_instructions">
                    Veiligheidsinstructies
                </label>

                <textarea
                    id="safety_instructions"
                    name="safety_instructions"
                ><?= e($form['safety_instructions']) ?></textarea>
            </div>

            <h3 class="form-section">Extra informatie</h3>

            <div class="form-group full">
                <label for="notes">Opmerkingen</label>

                <textarea
                    id="notes"
                    name="notes"
                ><?= e($form['notes']) ?></textarea>
            </div>

            <div class="form-group full">
                <label class="favorite-option">
                    <input
                        type="checkbox"
                        name="is_favorite"
                        value="1"
                        <?= $isFavorite ? 'checked' : '' ?>
                    >

                    ⭐ Toevoegen aan mijn favorieten
                </label>
            </div>

        </div>

        <div class="form-actions">
            <a href="<?= e($listPage) ?>" class="button">
                Annuleren
            </a>

            <button type="submit" class="button">
                <?= $isEdit
                    ? '💾 Wijzigingen opslaan'
                    : '➕ Adres toevoegen'
                ?>
            </button>
        </div>

    </form>

</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const street = document.getElementById('street');
    const houseNumber = document.getElementById('house_number');
    const postalCode = document.getElementById('postal_code');
    const city = document.getElementById('city');
    const country = document.getElementById('country');
    const latitude = document.getElementById('latitude');
    const longitude = document.getElementById('longitude');
    const locationButton =
        document.getElementById('use-current-location');
    const mapsButton =
        document.getElementById('open-google-maps');
    const locationStatus =
        document.getElementById('location-status');

    function updateRequiredFields() {
        const hasCoordinates =
            latitude.value.trim() !== ''
            && longitude.value.trim() !== '';

        street.required = !hasCoordinates;
        houseNumber.required = !hasCoordinates;
        postalCode.required = !hasCoordinates;
        city.required = !hasCoordinates;
    }

    latitude.addEventListener('input', updateRequiredFields);
    longitude.addEventListener('input', updateRequiredFields);

    updateRequiredFields();

    locationButton.addEventListener('click', function () {
        if (!navigator.geolocation) {
            locationStatus.textContent =
                'Locatiebepaling wordt niet ondersteund.';
            return;
        }

        locationButton.disabled = true;
        locationStatus.textContent =
            'Locatie wordt opgehaald...';

        navigator.geolocation.getCurrentPosition(
            function (position) {
                latitude.value =
                    position.coords.latitude.toFixed(7);

                longitude.value =
                    position.coords.longitude.toFixed(7);

                locationStatus.textContent =
                    'Huidige locatie is ingevuld.';

                updateRequiredFields();
                locationButton.disabled = false;
            },
            function (error) {
                if (error.code === error.PERMISSION_DENIED) {
                    locationStatus.textContent =
                        'Toegang tot locatie is geweigerd.';
                } else if (
                    error.code === error.POSITION_UNAVAILABLE
                ) {
                    locationStatus.textContent =
                        'De locatie is niet beschikbaar.';
                } else if (error.code === error.TIMEOUT) {
                    locationStatus.textContent =
                        'Het ophalen duurde te lang.';
                } else {
                    locationStatus.textContent =
                        'Locatie kon niet worden opgehaald.';
                }

                locationButton.disabled = false;
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }
        );
    });

    mapsButton.addEventListener('click', function () {
        let destination = '';

        if (
            latitude.value.trim() !== ''
            && longitude.value.trim() !== ''
        ) {
            destination =
                latitude.value.trim()
                + ','
                + longitude.value.trim();
        } else {
            destination = [
                street.value.trim(),
                houseNumber.value.trim(),
                postalCode.value.trim(),
                city.value.trim(),
                country.value.trim()
            ].filter(Boolean).join(' ');
        }

        if (destination === '') {
            locationStatus.textContent =
                'Vul eerst een adres of GPS-coördinaten in.';
            return;
        }

        window.open(
            'https://www.google.com/maps/search/?api=1&query='
            + encodeURIComponent(destination),
            '_blank',
            'noopener'
        );
    });
});
</script>

<?php render_footer(); ?>
