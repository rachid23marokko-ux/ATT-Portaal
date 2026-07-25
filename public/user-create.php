<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Helpers/bootstrap.php';
require_once dirname(__DIR__) . '/app/Helpers/layout.php';

use App\Services\PermissionService;

PermissionService::require('users.manage');

$pdo = db();

$errors = [];

$name = '';
$email = '';
$roleId = 0;

$currentRole = strtolower((string) ($_SESSION['role'] ?? ''));

if ($currentRole === 'developer') {

    $roles = $pdo->query("
        SELECT id, name
        FROM roles
        ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

} else {

    $stmt = $pdo->prepare("
        SELECT id, name
        FROM roles
        WHERE name <> 'developer'
        ORDER BY id ASC
    ");

    $stmt->execute();

    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $roleId = (int) ($_POST['role_id'] ?? 0);
$organizationId = (int) ($_POST['organization_id'] ?? 0);
$isMultivalent = isset($_POST['is_multivalent']) ? 1 : 0;
    if ($name === '') {
        $errors[] = 'Vul een naam in.';
    }

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Vul een geldig e-mailadres in.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Het wachtwoord moet minimaal 8 tekens bevatten.';
    }

    $roleExists = false;

    foreach ($roles as $role) {
        if ((int) $role['id'] === $roleId) {
            $roleExists = true;
            break;
        }
    }

    if (!$roleExists) {
        $errors[] = 'Kies een geldige rol.';
    }
if ($currentRole !== 'developer' && $roleId === 1) {
    $errors[] = 'Je mag geen Developer aanmaken.';
}

if (!$isMultivalent && !in_array($organizationId, [1, 2], true)) {
    $errors[] = 'Kies een organisatie.';
}
    if (!$errors) {
        $check = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = :email
            LIMIT 1
        ");

        $check->execute([
            'email' => $email,
        ]);

        if ($check->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = 'Dit e-mailadres is al in gebruik.';
        }
    }

    if (!$errors) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (
                role_id,
                name,
                email,
                password_hash,
                is_multivalent,
                can_message_everyone,
                is_active,
                is_online,
                last_seen_at,
                session_version,
                created_at,
                updated_at
            ) VALUES (
                :role_id,
                :name,
                :email,
                :password_hash,
                0,
                0,
                1,
                0,
                NULL,
                1,
                NOW(),
                NOW()
            )
        ");

        $stmt->execute([
            'role_id' => $roleId,
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);
$newUserId = (int) $pdo->lastInsertId();

$organizationIds = [];

if ($roleId === 1 || $roleId === 2 || $isMultivalent === 1) {
    $organizationIds = [1, 2];
} else {
    $organizationIds = [$organizationId];
}

$linkStmt = $pdo->prepare("
    INSERT INTO user_organizations (
        user_id,
        organization_id
    ) VALUES (
        :user_id,
        :organization_id
    )
");

foreach ($organizationIds as $orgId) {
    $linkStmt->execute([
        'user_id' => $newUserId,
        'organization_id' => $orgId,
    ]);
}
        header('Location: /users.php?created=1');
        exit;
    }
}

render_header('Nieuwe gebruiker');
?>

<div class="card">

    <h2>Nieuwe gebruiker</h2>

    <p>Vul hieronder de gegevens van de nieuwe gebruiker in.</p>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <strong>De gebruiker kon niet worden aangemaakt.</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="">

        <div class="form-group">
            <label for="name">Naam</label>

            <input
                type="text"
                id="name"
                name="name"
                value="<?= e($name) ?>"
                required
                autocomplete="name"
            >
        </div>

        <div class="form-group">
            <label for="email">E-mailadres</label>

            <input
                type="email"
                id="email"
                name="email"
                value="<?= e($email) ?>"
                required
                autocomplete="email"
            >
        </div>

        <div class="form-group">
            <label for="password">Tijdelijk wachtwoord</label>

            <input
                type="password"
                id="password"
                name="password"
                required
                minlength="8"
                autocomplete="new-password"
            >

            <small>Gebruik minimaal 8 tekens.</small>
        </div>

        <div class="form-group">
            <label for="role_id">Rol</label>

            <select id="role_id" name="role_id" required>
                <option value="">Kies een rol</option>

                <?php foreach ($roles as $role): ?>
                    <option
                        value="<?= (int) $role['id'] ?>"
                        <?= $roleId === (int) $role['id'] ? 'selected' : '' ?>
                    >
                        <?= e(ucfirst((string) $role['name'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
<div class="form-group">
    <label for="organization_id">Organisatie</label>

    <select id="organization_id" name="organization_id">
        <option value="">Kies organisatie</option>
        <option value="1">BRABO</option>
        <option value="2">NLW</option>
    </select>
</div>

<div class="form-group">
    <label style="display:inline-flex; align-items:center; gap:8px;">
        <input
            type="checkbox"
            name="is_multivalent"
            value="1"
            style="width:auto; margin:0;"
        >
        Multivalente chauffeur (BRABO + NLW)
    </label>
</div>
        <p style="margin-top: 20px;">
            <button class="button button-primary" type="submit">
                Gebruiker aanmaken
            </button>

            <a class="button" href="/users.php">
                Annuleren
            </a>
        </p>

    </form>

</div>

<?php render_footer(); ?>
