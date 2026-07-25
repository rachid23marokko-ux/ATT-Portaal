<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Helpers/bootstrap.php';

use App\Services\PermissionService;

if (!PermissionService::isManager()) {
    http_response_code(403);
    exit('Geen toegang tot dit onderdeel.');
}

render_header('Instellingen');
?>

<div class="card">
    <div class="card-header">
        <h2>Instellingen</h2>
    </div>

    <div class="card-body">
        <p>
            Hier komen later de algemene instellingen van het ATT Portaal.
        </p>
    </div>
</div>

<?php render_footer(); ?>
