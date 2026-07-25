<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Helpers/bootstrap.php';
require_once dirname(__DIR__) . '/app/Helpers/layout.php';

use App\Services\PermissionService;

PermissionService::require('users.manage');

$pdo = db();

$currentUser = $_SESSION['user'] ?? [];
$currentUserId = (int) ($currentUser['id'] ?? 0);
$currentRole = strtolower((string) ($currentUser['role'] ?? ''));

if (!in_array($currentRole, ['developer', 'admin'], true)) {
    http_response_code(403);
    exit('Geen toegang.');
}

$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$userId || $userId < 1) {
    http_response_code(400);
    exit('Ongeldige gebruiker.');
}

$userStmt = $pdo->prepare(
    'SELECT
        u.id,
        u.name,
        u.email,
        u.role_id,
        u.is_multivalent,
        u.is_active,
        u.session_version,
        r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.id = :id
     LIMIT 1'
);

$userStmt->execute(['id' => $userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    exit('Gebruiker niet gevonden.');
}

$targetRole = strtolower((string) $user['role_name']);

if ($currentRole !== 'developer' && $targetRole === 'developer') {
    http_response_code(403);
    exit('Een Admin mag geen Developer bewerken.');
}

$rolesSql = 'SELECT id, name FROM roles';

if ($currentRole !== 'developer') {
    $rolesSql .= " WHERE LOWER(name) <> 'developer'";
}

$rolesSql .= ' ORDER BY id ASC';

$roles = $pdo->query($rolesSql)->fetchAll(PDO::FETCH_ASSOC);

$organizations = $pdo->query(
    'SELECT id, name
     FROM organizations
     ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$organizationStmt = $pdo->prepare(
    'SELECT organization_id
     FROM user_organizations
     WHERE user_id = :user_id'
);

$organizationStmt->execute(['user_id' => $userId]);

$selectedOrganizationIds = array_map(
    'intval',
    $organizationStmt->fetchAll(PDO::FETCH_COLUMN)
);

$errors = [];
$success = isset($_GET['updated']);

$name = (string) $user['name'];
$email = (string) $user['email'];
$roleId = (int) $user['role_id'];
$isMultivalent = (bool) $user['is_multivalent'];
$isActive = (bool) $user['is_active'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals((string) $_SESSION['csrf_token'], $csrfToken)) {
        $errors[] = 'De beveiligingscontrole is mislukt. Vernieuw de pagina.';
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $isMultivalent = isset($_POST['is_multivalent']);
    $isActive = isset($_POST['is_active']);
    $organizationId = (int) ($_POST['organization_id'] ?? 0);

    if ($name === '') {
        $errors[] = 'Vul een naam in.';
    } elseif (mb_strlen($name) > 120) {
        $errors[] = 'De naam mag maximaal 120 tekens bevatten.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Vul een geldig e-mailadres in.';
    } elseif (mb_strlen($email) > 190) {
        $errors[] = 'Het e-mailadres mag maximaal 190 tekens bevatten.';
    }

    $allowedRoleIds = array_map(
        static fn(array $role): int => (int) $role['id'],
        $roles
    );

    if (!in_array($roleId, $allowedRoleIds, true)) {
        $errors[] = 'Kies een geldige rol.';
    }

    $selectedRoleName = '';

    foreach ($roles as $role) {
        if ((int) $role['id'] === $roleId) {
            $selectedRoleName = strtolower((string) $role['name']);
            break;
        }
    }

    if ($currentRole !== 'developer' && $selectedRoleName === 'developer') {
        $errors[] = 'Een Admin mag geen Developer-rol toekennen.';
    }

    if (
        $selectedRoleName === 'chauffeur'
        && !$isMultivalent
        && !in_array($organizationId, [1, 2], true)
    ) {
        $errors[] = 'Kies BRABO of NLW voor de chauffeur.';
    }

    if ($userId === $currentUserId && !$isActive) {
        $errors[] = 'Je kunt je eigen account niet deactiveren.';
    }

    if ($userId === $currentUserId && $selectedRoleName !== $currentRole) {
        $errors[] = 'Je kunt je eigen rol niet wijzigen.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare(
                'UPDATE users
                 SET
                    name = :name,
                    email = :email,
                    role_id = :role_id,
                    is_multivalent = :is_multivalent,
                    is_active = :is_active,
                    session_version = session_version + 1
                 WHERE id = :id'
            );

            $updateStmt->execute([
                'name' => $name,
                'email' => $email,
                'role_id' => $roleId,
                'is_multivalent' => $isMultivalent ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
                'id' => $userId,
            ]);

            $pdo->prepare(
                'DELETE FROM user_organizations
                 WHERE user_id = :user_id'
            )->execute([
                'user_id' => $userId,
            ]);

            if (in_array($selectedRoleName, ['developer', 'admin'], true) || $isMultivalent) {
                $newOrganizationIds = [1, 2];
            } else {
                $newOrganizationIds = [$organizationId];
            }

            $linkStmt = $pdo->prepare(
                'INSERT INTO user_organizations (
                    user_id,
                    organization_id
                 ) VALUES (
                    :user_id,
                    :organization_id
                 )'
            );

            foreach (array_unique($newOrganizationIds) as $newOrganizationId) {
                $linkStmt->execute([
                    'user_id' => $userId,
                    'organization_id' => $newOrganizationId,
                ]);
            }

            $versionStmt = $pdo->prepare(
                'SELECT session_version
                 FROM users
                 WHERE id = :id'
            );

            $versionStmt->execute(['id' => $userId]);
            $newSessionVersion = (int) $versionStmt->fetchColumn();

            $pdo->commit();

            if ($userId === $currentUserId) {
                $_SESSION['user'] = [
                    'id' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'role' => $selectedRoleName,
                    'session_version' => $newSessionVersion,
                ];
            }

            header('Location: /user-edit.php?id=' . $userId . '&updated=1');
            exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ((string) $exception->getCode() === '23000') {
                $errors[] = 'Dit e-mailadres is al in gebruik.';
            } else {
                $errors[] = 'De gebruiker kon niet worden opgeslagen.';
            }
        }
    }

    if ($selectedRoleName === 'chauffeur' && !$isMultivalent) {
        $selectedOrganizationIds = [$organizationId];
    } elseif ($selectedRoleName !== '') {
        $selectedOrganizationIds = [1, 2];
    }
}

$singleOrganizationId = count($selectedOrganizationIds) === 1
    ? (int) $selectedOrganizationIds[0]
    : 0;

render_header('Gebruiker bewerken');
?>

<style>
.user-edit-page {
    display: grid;
    gap: 20px;
    max-width: 900px;
}

.page-heading {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    flex-wrap: wrap;
}

.page-heading h2 {
    margin: 0 0 6px;
}

.page-heading p {
    margin: 0;
    color: #64748b;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.form-group {
    display: grid;
    gap: 7px;
}

.form-group-full {
    grid-column: 1 / -1;
}

.form-group label,
.section-label {
    font-weight: 700;
    font-size: 14px;
}

.form-group input,
.form-group select {
    width: 100%;
    min-height: 44px;
}

.checkbox-row {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    width: fit-content;
    cursor: pointer;
}

.checkbox-row input {
    width: auto;
    min-height: auto;
    margin: 0;
}

.form-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 6px;
}

.alert {
    padding: 14px 16px;
    border-radius: 10px;
}

.alert-success {
    color: #166534;
    background: #dcfce7;
    border: 1px solid #86efac;
}

.alert-danger {
    color: #991b1b;
    background: #fee2e2;
    border: 1px solid #fecaca;
}

.alert ul {
    margin: 8px 0 0 20px;
}

.help-text {
    color: #64748b;
    font-size: 13px;
}

.user-meta {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.badge {
    display: inline-flex;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    background: #e2e8f0;
    color: #475569;
}

@media (max-width: 700px) {
    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group-full {
        grid-column: auto;
    }
}
</style>

<div class="user-edit-page">

    <div class="card">
        <div class="page-heading">
            <div>
                <h2>Gebruiker bewerken</h2>
                <p>Wijzig de gegevens, rol, organisatie en status.</p>
            </div>

            <div class="user-meta">
                <span class="badge">ID <?= (int) $userId ?></span>
                <span class="badge"><?= e((string) $user['role_name']) ?></span>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            De gebruiker is succesvol bijgewerkt.
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger">
            <strong>De gebruiker kon niet worden opgeslagen.</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="post" action="/user-edit.php?id=<?= (int) $userId ?>">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e((string) $_SESSION['csrf_token']) ?>"
            >

            <div class="form-grid">

                <div class="form-group">
                    <label for="name">Naam</label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?= e($name) ?>"
                        maxlength="120"
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
                        maxlength="190"
                        required
                        autocomplete="email"
                    >
                </div>

                <div class="form-group">
                    <label for="role_id">Rol</label>

                    <select id="role_id" name="role_id" required>
                        <?php foreach ($roles as $role): ?>
                            <option
                                value="<?= (int) $role['id'] ?>"
                                <?= $roleId === (int) $role['id'] ? 'selected' : '' ?>
                            >
                                <?= e((string) $role['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="organization_group">
                    <label for="organization_id">Organisatie</label>

                    <select id="organization_id" name="organization_id">
                        <option value="">Kies organisatie</option>

                        <?php foreach ($organizations as $organization): ?>
                            <option
                                value="<?= (int) $organization['id'] ?>"
                                <?= $singleOrganizationId === (int) $organization['id'] ? 'selected' : '' ?>
                            >
                                <?= e((string) $organization['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <span class="help-text">
                        Voor Admin en Developer worden BRABO en NLW automatisch gekoppeld.
                    </span>
                </div>

                <div class="form-group form-group-full">
                    <label class="checkbox-row">
                        <input
                            type="checkbox"
                            name="is_multivalent"
                            value="1"
                            <?= $isMultivalent ? 'checked' : '' ?>
                        >
                        Multivalente chauffeur (BRABO + NLW)
                    </label>
                </div>

                <div class="form-group form-group-full">
                    <label class="checkbox-row">
                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            <?= $isActive ? 'checked' : '' ?>
                        >
                        Account actief
                    </label>
                </div>

                <div class="form-actions form-group-full">
                    <button type="submit" class="btn btn-primary">
                        Wijzigingen opslaan
                    </button>

                    <a href="/users.php" class="btn btn-secondary">
                        Annuleren
                    </a>
                </div>

            </div>
        </form>
    </div>

</div>

<script>
(() => {
    const roleSelect = document.getElementById('role_id');
    const organizationGroup = document.getElementById('organization_group');
    const multivalentInput = document.querySelector('input[name="is_multivalent"]');

    if (!roleSelect || !organizationGroup || !multivalentInput) {
        return;
    }

    const roleNames = <?= json_encode(
        array_column($roles, 'name', 'id'),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    ) ?>;

    const updateOrganizationFields = () => {
        const roleName = String(roleNames[roleSelect.value] || '').toLowerCase();
        const isChauffeur = roleName === 'chauffeur';

        organizationGroup.style.display = isChauffeur ? '' : 'none';
        multivalentInput.closest('.form-group').style.display = isChauffeur ? '' : 'none';
    };

    roleSelect.addEventListener('change', updateOrganizationFields);
    updateOrganizationFields();
})();
</script>

<?php render_footer(); ?>
