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
$userRole = (string) ($currentUser['role'] ?? '');
$isManager = in_array($userRole, ['admin', 'developer'], true);

User::updatePresence($userId, true);

function pe_text(mixed $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function pe_date(mixed $value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        !$date
        || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        throw new InvalidArgumentException('Een van de ingevulde datums is ongeldig.');
    }

    return $date->format('Y-m-d');
}

function pe_value(array $profile, string $field): string
{
    return e((string) ($_POST[$field] ?? $profile[$field] ?? ''));
}

function pe_remove_photo(?string $publicPath): void
{
    if (!$publicPath || !str_starts_with($publicPath, '/uploads/profile-photos/')) {
        return;
    }

    $fullPath = dirname(__DIR__) . '/public' . $publicPath;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function pe_initial(string $name): string
{
    $name = trim($name);
    return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
}

$profileStmt = db()->prepare(
    'SELECT
        u.id,
        u.name,
        u.email,
        u.is_active,
        u.is_multivalent,
        r.name AS role,
        dp.*
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN driver_profiles dp ON dp.user_id = u.id
     WHERE u.id = :user_id
     LIMIT 1'
);
$profileStmt->execute(['user_id' => $userId]);
$profile = $profileStmt->fetch();

if (!$profile) {
    http_response_code(404);
    exit('Profiel niet gevonden.');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Ongeldige beveiligingstoken.');
    }

    $oldPhoto = $profile['profile_photo'] ?? null;
    $newPhoto = null;

    try {
        $profilePhoto = $oldPhoto;

        if (!empty($_POST['remove_profile_photo'])) {
            $profilePhoto = null;
        }

        if (
            isset($_FILES['profile_photo'])
            && ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ) {
            $upload = $_FILES['profile_photo'];

            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('De profielfoto kon niet worden geüpload.');
            }

            if ((int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new RuntimeException('De profielfoto mag maximaal 5 MB zijn.');
            }

            if (!is_uploaded_file((string) $upload['tmp_name'])) {
                throw new RuntimeException('Het geüploade bestand is ongeldig.');
            }

            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']);
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

            if (!isset($allowed[$mime])) {
                throw new RuntimeException('Alleen JPG, PNG en WEBP zijn toegestaan.');
            }

            $directory = dirname(__DIR__) . '/public/uploads/profile-photos';

            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('De uploadmap kon niet worden aangemaakt.');
            }

            $filename = sprintf(
                'profile-%d-%s.%s',
                $userId,
                bin2hex(random_bytes(12)),
                $allowed[$mime]
            );
            $destination = $directory . '/' . $filename;

            if (!move_uploaded_file((string) $upload['tmp_name'], $destination)) {
                throw new RuntimeException('De profielfoto kon niet worden opgeslagen.');
            }

            @chmod($destination, 0644);
            $newPhoto = '/uploads/profile-photos/' . $filename;
            $profilePhoto = $newPhoto;
        }

        $data = [
            'user_id' => $userId,
            'first_name' => pe_text($_POST['first_name'] ?? null),
            'middle_name' => pe_text($_POST['middle_name'] ?? null),
            'last_name' => pe_text($_POST['last_name'] ?? null),
            'address' => pe_text($_POST['address'] ?? null),
            'postal_code' => pe_text($_POST['postal_code'] ?? null),
            'city' => pe_text($_POST['city'] ?? null),
            'country' => pe_text($_POST['country'] ?? null),
            'phone' => pe_text($_POST['phone'] ?? null),
            'mobile' => pe_text($_POST['mobile'] ?? null),
            'emergency_contact_name' => pe_text($_POST['emergency_contact_name'] ?? null),
            'emergency_contact_phone' => pe_text($_POST['emergency_contact_phone'] ?? null),
            'birth_date' => pe_date($_POST['birth_date'] ?? null),
            'profile_photo' => $profilePhoto,
        ];

        if ($isManager) {
            $data = array_merge($data, [
                'employee_number' => pe_text($_POST['employee_number'] ?? null),
                'driver_number' => pe_text($_POST['driver_number'] ?? null),
                'employment_start_date' => pe_date($_POST['employment_start_date'] ?? null),
                'department' => pe_text($_POST['department'] ?? null),
                'driver_card_number' => pe_text($_POST['driver_card_number'] ?? null),
                'driver_card_valid_until' => pe_date($_POST['driver_card_valid_until'] ?? null),
                'driving_license_number' => pe_text($_POST['driving_license_number'] ?? null),
                'driving_license_categories' => pe_text($_POST['driving_license_categories'] ?? null),
                'driving_license_valid_until' => pe_date($_POST['driving_license_valid_until'] ?? null),
                'code_95_valid_until' => pe_date($_POST['code_95_valid_until'] ?? null),
                'medical_exam_valid_until' => pe_date($_POST['medical_exam_valid_until'] ?? null),
                'identity_card_number' => pe_text($_POST['identity_card_number'] ?? null),
                'identity_card_valid_until' => pe_date($_POST['identity_card_valid_until'] ?? null),
                'notes' => pe_text($_POST['notes'] ?? null),
            ]);

            $saveStmt = db()->prepare(
                'INSERT INTO driver_profiles (
                    user_id, first_name, middle_name, last_name, address, postal_code,
                    city, country, phone, mobile, emergency_contact_name,
                    emergency_contact_phone, birth_date, employee_number, driver_number,
                    employment_start_date, department, driver_card_number,
                    driver_card_valid_until, driving_license_number,
                    driving_license_categories, driving_license_valid_until,
                    code_95_valid_until, medical_exam_valid_until, identity_card_number,
                    identity_card_valid_until, profile_photo, notes
                ) VALUES (
                    :user_id, :first_name, :middle_name, :last_name, :address, :postal_code,
                    :city, :country, :phone, :mobile, :emergency_contact_name,
                    :emergency_contact_phone, :birth_date, :employee_number, :driver_number,
                    :employment_start_date, :department, :driver_card_number,
                    :driver_card_valid_until, :driving_license_number,
                    :driving_license_categories, :driving_license_valid_until,
                    :code_95_valid_until, :medical_exam_valid_until, :identity_card_number,
                    :identity_card_valid_until, :profile_photo, :notes
                )
                ON DUPLICATE KEY UPDATE
                    first_name = VALUES(first_name),
                    middle_name = VALUES(middle_name),
                    last_name = VALUES(last_name),
                    address = VALUES(address),
                    postal_code = VALUES(postal_code),
                    city = VALUES(city),
                    country = VALUES(country),
                    phone = VALUES(phone),
                    mobile = VALUES(mobile),
                    emergency_contact_name = VALUES(emergency_contact_name),
                    emergency_contact_phone = VALUES(emergency_contact_phone),
                    birth_date = VALUES(birth_date),
                    employee_number = VALUES(employee_number),
                    driver_number = VALUES(driver_number),
                    employment_start_date = VALUES(employment_start_date),
                    department = VALUES(department),
                    driver_card_number = VALUES(driver_card_number),
                    driver_card_valid_until = VALUES(driver_card_valid_until),
                    driving_license_number = VALUES(driving_license_number),
                    driving_license_categories = VALUES(driving_license_categories),
                    driving_license_valid_until = VALUES(driving_license_valid_until),
                    code_95_valid_until = VALUES(code_95_valid_until),
                    medical_exam_valid_until = VALUES(medical_exam_valid_until),
                    identity_card_number = VALUES(identity_card_number),
                    identity_card_valid_until = VALUES(identity_card_valid_until),
                    profile_photo = VALUES(profile_photo),
                    notes = VALUES(notes)'
            );
        } else {
            $saveStmt = db()->prepare(
                'INSERT INTO driver_profiles (
                    user_id, first_name, middle_name, last_name, address, postal_code,
                    city, country, phone, mobile, emergency_contact_name,
                    emergency_contact_phone, birth_date, profile_photo
                ) VALUES (
                    :user_id, :first_name, :middle_name, :last_name, :address, :postal_code,
                    :city, :country, :phone, :mobile, :emergency_contact_name,
                    :emergency_contact_phone, :birth_date, :profile_photo
                )
                ON DUPLICATE KEY UPDATE
                    first_name = VALUES(first_name),
                    middle_name = VALUES(middle_name),
                    last_name = VALUES(last_name),
                    address = VALUES(address),
                    postal_code = VALUES(postal_code),
                    city = VALUES(city),
                    country = VALUES(country),
                    phone = VALUES(phone),
                    mobile = VALUES(mobile),
                    emergency_contact_name = VALUES(emergency_contact_name),
                    emergency_contact_phone = VALUES(emergency_contact_phone),
                    birth_date = VALUES(birth_date),
                    profile_photo = VALUES(profile_photo)'
            );
        }

        $saveStmt->execute($data);

        if ($profilePhoto !== $oldPhoto && $oldPhoto) {
            pe_remove_photo($oldPhoto);
        }

        if (function_exists('log_activity')) {
            log_activity($userId, 'driver_profile_updated', 'driver_profile', $userId);
        }

        flash('success', 'Het profiel is succesvol opgeslagen.');
        redirect('/profile-edit.php');
    } catch (Throwable $exception) {
        if ($newPhoto) {
            pe_remove_photo($newPhoto);
        }

        error_log(
            'ATT-profiel fout voor gebruiker '
            . $userId
            . ': '
            . $exception->getMessage()
        );

        $errors[] = $exception instanceof InvalidArgumentException
            || $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Het profiel kon niet worden opgeslagen.';
    }
}

$displayName = trim(implode(' ', array_filter([
    $profile['first_name'] ?? null,
    $profile['middle_name'] ?? null,
    $profile['last_name'] ?? null,
])));

if ($displayName === '') {
    $displayName = (string) $profile['name'];
}

render_header('Profiel bewerken');
?>

<section class="pe-header">
    <div class="pe-avatar">
        <?php if (!empty($profile['profile_photo'])): ?>
            <img src="<?= e((string) $profile['profile_photo']) ?>" alt="Profielfoto">
        <?php else: ?>
            <?= e(pe_initial($displayName)) ?>
        <?php endif; ?>
    </div>

    <div class="pe-header-copy">
        <span class="pe-eyebrow">Persoonlijk chauffeurprofiel</span>
        <h2><?= e($displayName) ?></h2>
        <p>
            <?= e(ucfirst((string) $profile['role'])) ?>
            <?php if (!empty($profile['is_multivalent'])): ?> · Multivalent<?php endif; ?>
        </p>
    </div>

    <a class="button secondary" href="/my-profile.php">← Terug naar profiel</a>
</section>

<?php foreach ($errors as $error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php endforeach; ?>

<nav class="pe-tabs" aria-label="Profielonderdelen">
    <a href="#persoonlijk">👤 Persoonlijk</a>
    <a href="#contact">📞 Contact</a>
    <a href="#noodcontact">🆘 Noodcontact</a>
    <a href="#foto">📷 Profielfoto</a>
    <?php if ($isManager): ?>
        <a href="#werk">📋 Werk</a>
        <a href="#documenten">📄 Documenten</a>
        <a href="#opmerkingen">📝 Opmerkingen</a>
    <?php endif; ?>
</nav>

<form method="post" enctype="multipart/form-data" class="pe-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <section class="panel pe-section" id="persoonlijk">
        <div class="pe-title"><span>👤</span><div><span class="pe-eyebrow">Persoonlijk</span><h2>Persoonlijke gegevens</h2></div></div>
        <div class="pe-grid">
            <label>Voornaam<input name="first_name" value="<?= pe_value($profile, 'first_name') ?>" autocomplete="given-name"></label>
            <label>Tussenvoegsel<input name="middle_name" value="<?= pe_value($profile, 'middle_name') ?>"></label>
            <label>Achternaam<input name="last_name" value="<?= pe_value($profile, 'last_name') ?>" autocomplete="family-name"></label>
            <label>Geboortedatum<input type="date" name="birth_date" value="<?= pe_value($profile, 'birth_date') ?>"></label>
        </div>
    </section>

    <section class="panel pe-section" id="contact">
        <div class="pe-title"><span>📞</span><div><span class="pe-eyebrow">Bereikbaarheid</span><h2>Contactgegevens</h2></div></div>
        <div class="pe-grid">
            <label class="pe-wide">Adres<input name="address" value="<?= pe_value($profile, 'address') ?>"></label>
            <label>Postcode<input name="postal_code" value="<?= pe_value($profile, 'postal_code') ?>"></label>
            <label>Woonplaats<input name="city" value="<?= pe_value($profile, 'city') ?>"></label>
            <label>Land<input name="country" value="<?= pe_value($profile, 'country') ?>"></label>
            <label>Telefoon<input type="tel" name="phone" value="<?= pe_value($profile, 'phone') ?>"></label>
            <label>Mobiel<input type="tel" name="mobile" value="<?= pe_value($profile, 'mobile') ?>"></label>
        </div>
    </section>

    <section class="panel pe-section" id="noodcontact">
        <div class="pe-title"><span>🆘</span><div><span class="pe-eyebrow">In geval van nood</span><h2>Noodcontact</h2></div></div>
        <div class="pe-grid">
            <label>Contactpersoon<input name="emergency_contact_name" value="<?= pe_value($profile, 'emergency_contact_name') ?>"></label>
            <label>Telefoonnummer<input type="tel" name="emergency_contact_phone" value="<?= pe_value($profile, 'emergency_contact_phone') ?>"></label>
        </div>
    </section>

    <section class="panel pe-section" id="foto">
        <div class="pe-title"><span>📷</span><div><span class="pe-eyebrow">Profielfoto</span><h2>Foto wijzigen</h2></div></div>
        <div class="pe-photo-box">
            <div class="pe-photo-preview">
                <?php if (!empty($profile['profile_photo'])): ?>
                    <img src="<?= e((string) $profile['profile_photo']) ?>" alt="Huidige profielfoto">
                <?php else: ?>
                    <?= e(pe_initial($displayName)) ?>
                <?php endif; ?>
            </div>
            <div class="pe-photo-controls">
                <label>Nieuwe profielfoto<input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"></label>
                <small>JPG, PNG of WEBP. Maximaal 5 MB.</small>
                <?php if (!empty($profile['profile_photo'])): ?>
                    <label class="pe-check"><input type="checkbox" name="remove_profile_photo" value="1"> Huidige profielfoto verwijderen</label>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($isManager): ?>
        <section class="panel pe-section" id="werk">
            <div class="pe-title"><span>📋</span><div><span class="pe-eyebrow">Werk</span><h2>Werkgegevens</h2></div></div>
            <div class="pe-grid">
                <label>Personeelsnummer<input name="employee_number" value="<?= pe_value($profile, 'employee_number') ?>"></label>
                <label>Chauffeursnummer<input name="driver_number" value="<?= pe_value($profile, 'driver_number') ?>"></label>
                <label>In dienst sinds<input type="date" name="employment_start_date" value="<?= pe_value($profile, 'employment_start_date') ?>"></label>
                <label>Afdeling<input name="department" value="<?= pe_value($profile, 'department') ?>"></label>
            </div>
        </section>

        <section class="panel pe-section" id="documenten">
            <div class="pe-title"><span>📄</span><div><span class="pe-eyebrow">Geldigheid</span><h2>Documenten</h2></div></div>
            <div class="pe-doc-grid">
                <article class="pe-doc"><h3>🪪 Chauffeurspas</h3><label>Nummer<input name="driver_card_number" value="<?= pe_value($profile, 'driver_card_number') ?>"></label><label>Geldig tot<input type="date" name="driver_card_valid_until" value="<?= pe_value($profile, 'driver_card_valid_until') ?>"></label></article>
                <article class="pe-doc"><h3>🚘 Rijbewijs</h3><label>Nummer<input name="driving_license_number" value="<?= pe_value($profile, 'driving_license_number') ?>"></label><label>Categorieën<input name="driving_license_categories" value="<?= pe_value($profile, 'driving_license_categories') ?>" placeholder="B, C, CE"></label><label>Geldig tot<input type="date" name="driving_license_valid_until" value="<?= pe_value($profile, 'driving_license_valid_until') ?>"></label></article>
                <article class="pe-doc"><h3>📘 Code 95</h3><label>Geldig tot<input type="date" name="code_95_valid_until" value="<?= pe_value($profile, 'code_95_valid_until') ?>"></label></article>
                <article class="pe-doc"><h3>🩺 Medische keuring</h3><label>Geldig tot<input type="date" name="medical_exam_valid_until" value="<?= pe_value($profile, 'medical_exam_valid_until') ?>"></label></article>
                <article class="pe-doc"><h3>🆔 Identiteitskaart</h3><label>Nummer<input name="identity_card_number" value="<?= pe_value($profile, 'identity_card_number') ?>"></label><label>Geldig tot<input type="date" name="identity_card_valid_until" value="<?= pe_value($profile, 'identity_card_valid_until') ?>"></label></article>
            </div>
        </section>

        <section class="panel pe-section" id="opmerkingen">
            <div class="pe-title"><span>📝</span><div><span class="pe-eyebrow">Intern</span><h2>Opmerkingen</h2></div></div>
            <label>Interne opmerkingen<textarea name="notes" rows="6"><?= pe_value($profile, 'notes') ?></textarea></label>
        </section>
    <?php endif; ?>

    <div class="pe-actions">
        <a class="button secondary" href="/my-profile.php">Annuleren</a>
        <button type="submit" class="button">💾 Profiel opslaan</button>
    </div>
</form>

<style>
.pe-header{display:flex;align-items:center;gap:20px;padding:22px;margin-bottom:18px;border:1px solid #d9e2ea;border-radius:16px;background:#fff;box-shadow:0 7px 22px rgba(7,52,95,.07)}
.pe-avatar,.pe-photo-preview{display:grid;place-items:center;overflow:hidden;flex:0 0 auto;border-radius:50%;background:#0b4a86;color:#fff;font-weight:800}.pe-avatar{width:82px;height:82px;font-size:32px}.pe-photo-preview{width:120px;height:120px;font-size:42px}.pe-avatar img,.pe-photo-preview img{width:100%;height:100%;object-fit:cover}.pe-header-copy{flex:1;min-width:0}.pe-header-copy h2{margin:5px 0;color:#07345f;font-size:27px}.pe-header-copy p{margin:0;color:#6b7b8c}.pe-eyebrow{display:block;color:#6b7b8c;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.pe-tabs{position:sticky;top:92px;z-index:80;display:flex;gap:7px;padding:10px;margin-bottom:18px;overflow-x:auto;border:1px solid #d9e2ea;border-radius:13px;background:rgba(255,255,255,.96);backdrop-filter:blur(8px)}.pe-tabs a{flex:0 0 auto;padding:9px 12px;border-radius:9px;color:#334155;text-decoration:none;font-weight:700}.pe-tabs a:hover{background:#eef4f9;color:#07345f}.pe-form{display:grid;gap:18px}.pe-section{scroll-margin-top:155px}.pe-title{display:flex;align-items:center;gap:12px;margin-bottom:17px}.pe-title>span{font-size:25px}.pe-title h2{margin:4px 0 0}.pe-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.pe-wide{grid-column:1/-1}.pe-form label{display:grid;gap:7px;color:#334155;font-size:14px;font-weight:700}.pe-form input,.pe-form textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:11px 12px;background:#fff;color:#1f2937;font:inherit}.pe-form input:focus,.pe-form textarea:focus{border-color:#0b4a86;outline:3px solid rgba(11,74,134,.12)}.pe-photo-box{display:flex;align-items:center;gap:24px}.pe-photo-controls{display:grid;gap:11px;flex:1}.pe-photo-controls small{color:#6b7b8c}.pe-check{display:flex!important;align-items:center}.pe-check input{width:auto}.pe-doc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.pe-doc{display:grid;gap:13px;padding:17px;border:1px solid #d9e2ea;border-radius:13px;background:#f8fafc}.pe-doc h3{margin:0;color:#07345f}.pe-actions{position:sticky;bottom:0;z-index:70;display:flex;justify-content:flex-end;gap:10px;padding:13px;border:1px solid #d9e2ea;border-radius:13px 13px 0 0;background:rgba(255,255,255,.96);box-shadow:0 -8px 22px rgba(7,52,95,.08);backdrop-filter:blur(8px)}
@media(max-width:760px){.pe-header{align-items:flex-start;flex-direction:column}.pe-header .button{width:100%;text-align:center}.pe-grid,.pe-doc-grid{grid-template-columns:1fr}.pe-wide{grid-column:auto}.pe-photo-box{align-items:flex-start;flex-direction:column}.pe-actions{justify-content:stretch}.pe-actions .button{flex:1;text-align:center}}
</style>

<?php render_footer(); ?>
