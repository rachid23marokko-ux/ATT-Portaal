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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

verify_csrf();

$addressId = (int)($_POST['address_id'] ?? 0);
$returnTo = trim((string)($_POST['return_to'] ?? 'dashboard.php'));

if ($addressId <= 0) {
    header("Location: {$returnTo}");
    exit;
}

$stmt = db()->prepare("
    SELECT organization_id
    FROM addresses
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    'id' => $addressId,
]);

$address = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$address) {
    header("Location: {$returnTo}");
    exit;
}

$organizationId = (int)$address['organization_id'];

if (
    !$permissions->isAdmin()
    && !$permissions->isDeveloper()
    && !$permissions->isMultivalent()
) {
    if (
        $permissions->canAccessBrabo() && !$permissions->canAccessNlw()
    ) {
        $org = db()->prepare("
            SELECT id
            FROM organizations
            WHERE name='Brabo'
            LIMIT 1
        ");
        $org->execute();

        if ($organizationId !== (int)$org->fetchColumn()) {
            http_response_code(403);
            exit('Geen toegang.');
        }
    }

    if (
        $permissions->canAccessNlw() && !$permissions->canAccessBrabo()
    ) {
        $org = db()->prepare("
            SELECT id
            FROM organizations
            WHERE name='NLW'
            LIMIT 1
        ");
        $org->execute();

        if ($organizationId !== (int)$org->fetchColumn()) {
            http_response_code(403);
            exit('Geen toegang.');
        }
    }
}

$check = db()->prepare("
    SELECT id
    FROM favorites
    WHERE user_id = :user_id
      AND address_id = :address_id
");

$check->execute([
    'user_id' => $user['id'],
    'address_id' => $addressId,
]);

if ($check->fetch()) {

    $delete = db()->prepare("
        DELETE FROM favorites
        WHERE user_id = :user_id
          AND address_id = :address_id
    ");

    $delete->execute([
        'user_id' => $user['id'],
        'address_id' => $addressId,
    ]);

} else {

    $insert = db()->prepare("
        INSERT INTO favorites
        (user_id,address_id)
        VALUES
        (:user_id,:address_id)
    ");

    $insert->execute([
        'user_id' => $user['id'],
        'address_id' => $addressId,
    ]);
}

header("Location: {$returnTo}");
exit;
