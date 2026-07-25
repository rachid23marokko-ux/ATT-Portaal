<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Helpers/bootstrap.php';
require_once dirname(__DIR__) . '/app/Helpers/layout.php';

use App\Services\PermissionService;

PermissionService::require('users.manage');

$pdo = db();

$currentUser = $_SESSION['user'] ?? [];
$currentRole = strtolower((string) ($currentUser['role'] ?? ''));

$search = trim((string) ($_GET['q'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));
$organizationFilter = (int) ($_GET['organization'] ?? 0);
$statusFilter = trim((string) ($_GET['status'] ?? ''));

$allowedRoles = ['developer', 'admin', 'chauffeur'];
$allowedStatuses = ['active', 'inactive'];

if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = '';
}

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$organizations = $pdo->query(
    'SELECT id, name
     FROM organizations
     ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$where = [];
$params = [];

if ($currentRole !== 'developer') {
    $where[] = "LOWER(r.name) <> 'developer'";
}

if ($search !== '') {
    $where[] = '(
        u.name LIKE :search_name
        OR u.email LIKE :search_email
        OR r.name LIKE :search_role
        OR EXISTS (
            SELECT 1
            FROM user_organizations search_uo
            INNER JOIN organizations search_o
                ON search_o.id = search_uo.organization_id
            WHERE search_uo.user_id = u.id
              AND search_o.name LIKE :search_organization
        )
    )';

    $searchValue = '%' . $search . '%';

    $params['search_name'] = $searchValue;
    $params['search_email'] = $searchValue;
    $params['search_role'] = $searchValue;
    $params['search_organization'] = $searchValue;
}

if ($roleFilter !== '') {
    $where[] = 'LOWER(r.name) = :role';
    $params['role'] = $roleFilter;
}

if ($organizationFilter > 0) {
    $where[] = 'EXISTS (
        SELECT 1
        FROM user_organizations filter_uo
        WHERE filter_uo.user_id = u.id
          AND filter_uo.organization_id = :organization_id
    )';

    $params['organization_id'] = $organizationFilter;
}

if ($statusFilter === 'active') {
    $where[] = 'u.is_active = 1';
}

if ($statusFilter === 'inactive') {
    $where[] = 'u.is_active = 0';
}

$sql = '
    SELECT
        u.id,
        u.name,
        u.email,
        u.is_active,
        u.is_online,
        u.last_seen_at,
        u.created_at,
        r.name AS role,
        COALESCE(
            (
                SELECT GROUP_CONCAT(
                    organization_list.name
                    ORDER BY organization_list.name
                    SEPARATOR ", "
                )
                FROM user_organizations organization_link
                INNER JOIN organizations organization_list
                    ON organization_list.id = organization_link.organization_id
                WHERE organization_link.user_id = u.id
            ),
            "-"
        ) AS organizations
    FROM users u
    INNER JOIN roles r
        ON r.id = u.role_id
';

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY u.name ASC, u.id ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

function formatUserDate(?string $date): string
{
    if (!$date) {
        return '-';
    }

    try {
        return (new DateTimeImmutable($date))->format('d-m-Y H:i');
    } catch (Throwable) {
        return '-';
    }
}

function userIsRecentlyOnline(array $user): bool
{
    if (!(bool) ($user['is_online'] ?? false)) {
        return false;
    }

    if (empty($user['last_seen_at'])) {
        return false;
    }

    try {
        $lastSeen = new DateTimeImmutable((string) $user['last_seen_at']);
        $onlineLimit = new DateTimeImmutable('-2 minutes');

        return $lastSeen >= $onlineLimit;
    } catch (Throwable) {
        return false;
    }
}

render_header('Chauffeurs en gebruikers');
?>

<style>
.users-page {
    display: grid;
    gap: 20px;
}

.users-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
}

.users-header h2 {
    margin: 0 0 6px;
}

.users-header p,
.result-count,
.user-email {
    color: #64748b;
}

.users-header p {
    margin: 0;
}

.users-filters {
    display: grid;
    grid-template-columns: minmax(220px, 2fr) repeat(3, minmax(150px, 1fr)) auto;
    gap: 12px;
    align-items: end;
}

.users-filters label {
    display: block;
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: 600;
}

.users-filters input,
.users-filters select {
    width: 100%;
    min-height: 42px;
}

.filter-buttons {
    display: flex;
    gap: 8px;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

.users-table {
    width: 100%;
    min-width: 1050px;
    border-collapse: collapse;
}

.users-table th,
.users-table td {
    padding: 13px 12px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    vertical-align: middle;
}

.users-table th {
    color: #64748b;
    font-size: 12px;
    text-transform: uppercase;
    white-space: nowrap;
}

.user-name {
    font-weight: 700;
}

.user-email,
.result-count {
    font-size: 13px;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.badge-success {
    color: #166534;
    background: #dcfce7;
}

.badge-danger {
    color: #991b1b;
    background: #fee2e2;
}

.badge-neutral {
    color: #475569;
    background: #e2e8f0;
}

.badge-role {
    color: #1e40af;
    background: #dbeafe;
}

.online-dot,
.offline-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
}

.online-dot {
    background: #22c55e;
}

.offline-dot {
    background: #94a3b8;
}

.empty-state {
    padding: 32px 16px;
    text-align: center;
    color: #64748b;
}

@media (max-width: 1000px) {
    .users-filters {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 650px) {
    .users-filters {
        grid-template-columns: 1fr;
    }

    .filter-buttons {
        width: 100%;
    }

    .filter-buttons .btn {
        flex: 1;
    }
}
</style>

<div class="users-page">

    <div class="card">
        <div class="users-header">
            <div>
                <h2>Chauffeurs en gebruikers</h2>
                <p>Overzicht van alle gebruikers van het ATT Portaal.</p>
            </div>

            <a class="btn btn-primary" href="/user-create.php">
                Nieuwe gebruiker
            </a>
        </div>
    </div>

    <div class="card">
        <form method="get" action="/users.php" class="users-filters">

            <div>
                <label for="q">Zoeken</label>
                <input
                    type="search"
                    id="q"
                    name="q"
                    value="<?= e($search) ?>"
                    placeholder="Naam, e-mailadres of organisatie"
                >
            </div>

            <div>
                <label for="role">Rol</label>

                <select id="role" name="role">
                    <option value="">Alle rollen</option>

                    <?php if ($currentRole === 'developer'): ?>
                        <option value="developer" <?= $roleFilter === 'developer' ? 'selected' : '' ?>>
                            Developer
                        </option>
                    <?php endif; ?>

                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>
                        Admin
                    </option>

                    <option value="chauffeur" <?= $roleFilter === 'chauffeur' ? 'selected' : '' ?>>
                        Chauffeur
                    </option>
                </select>
            </div>

            <div>
                <label for="organization">Organisatie</label>

                <select id="organization" name="organization">
                    <option value="0">Alle organisaties</option>

                    <?php foreach ($organizations as $organization): ?>
                        <option
                            value="<?= (int) $organization['id'] ?>"
                            <?= $organizationFilter === (int) $organization['id'] ? 'selected' : '' ?>
                        >
                            <?= e($organization['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="status">Status</label>

                <select id="status" name="status">
                    <option value="">Alle statussen</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                        Actief
                    </option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                        Inactief
                    </option>
                </select>
            </div>

            <div class="filter-buttons">
                <button type="submit" class="btn btn-primary">Filteren</button>
                <a href="/users.php" class="btn btn-secondary">Wissen</a>
            </div>

        </form>
    </div>

    <div class="card">
        <div class="result-count">
            <?= count($users) ?> gebruiker<?= count($users) === 1 ? '' : 's' ?> gevonden
        </div>

        <div class="table-responsive">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th>Organisatie</th>
                        <th>Rol</th>
                        <th>Status</th>
                        <th>Online</th>
                        <th>Laatst gezien</th>
                        <th>Aangemaakt</th>
                        <th>Acties</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($users === []): ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            Geen gebruikers gevonden.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $item): ?>
                        <?php
                        $isOnline = userIsRecentlyOnline($item);
                        $targetRole = strtolower((string) $item['role']);

                        $canEdit = $currentRole === 'developer'
                            || (
                                $currentRole === 'admin'
                                && $targetRole !== 'developer'
                            );
                        ?>

                        <tr>
                            <td>
                                <div class="user-name"><?= e($item['name']) ?></div>
                                <div class="user-email"><?= e($item['email']) ?></div>
                            </td>

                            <td><?= e($item['organizations']) ?></td>

                            <td>
                                <span class="badge badge-role">
                                    <?= e(ucfirst((string) $item['role'])) ?>
                                </span>
                            </td>

                            <td>
                                <?php if ((bool) $item['is_active']): ?>
                                    <span class="badge badge-success">Actief</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactief</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($isOnline): ?>
                                    <span class="badge badge-success">
                                        <span class="online-dot"></span>
                                        Online
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-neutral">
                                        <span class="offline-dot"></span>
                                        Offline
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td><?= e(formatUserDate($item['last_seen_at'])) ?></td>
                            <td><?= e(formatUserDate($item['created_at'])) ?></td>

                            <td>
                                <?php if ($canEdit && is_file(__DIR__ . '/user-edit.php')): ?>
                                    <a
                                        class="btn btn-secondary"
                                        href="/user-edit.php?id=<?= (int) $item['id'] ?>"
                                    >
                                        Bewerken
                                    </a>
                                <?php else: ?>
                                    <span class="badge badge-neutral">Geen actie</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php render_footer(); ?>
