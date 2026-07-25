<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Helpers/bootstrap.php';

use App\Services\PermissionService;

PermissionService::require('users.manage');

render_header('Online gebruikers');
?>

<div class="card">
    <div class="card-header">
        <h2>Online gebruikers</h2>
    </div>

    <div class="card-body">
        <p>
            Hier komt later een overzicht van gebruikers die momenteel
            online zijn.
        </p>
    </div>
</div>

<?php render_footer(); ?>
