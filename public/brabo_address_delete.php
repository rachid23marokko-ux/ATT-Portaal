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

if (
    !$permissions->isAdmin()
    && !$permissions->isDeveloper()
) {
    http_response_code(403);
    exit('Geen toestemming.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: brabo_addresses.php');
    exit;
}

verify_csrf();

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: brabo_addresses.php');
    exit;
}

$org = db()->prepare("
    SELECT id
    FROM organizations
    WHERE name = 'Brabo'
    LIMIT 1
");
$org->execute();

$organization = $org->fetch(PDO::FETCH_ASSOC);

if (!$organization) {
    exit('Organisatie Brabo niet gevonden.');
}

$stmt = db()->prepare("
    DELETE FROM addresses
    WHERE id = :id
      AND organization_id = :organization_id
");

$stmt->execute([
    'id' => $id,
    'organization_id' => $organization['id'],
]);

header('Location: brabo_addresses.php');
exit;
