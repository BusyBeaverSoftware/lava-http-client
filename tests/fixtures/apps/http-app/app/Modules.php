<?php

declare(strict_types=1);

use Lava\Core\Modules\ModuleRef;

return [
    // Enabled unconditionally, and the pack does have a config file — so this
    // fixture exercises both halves of the manifest: `config/http_client.php`
    // is read by LoadPackConfig at boot, and the gate flag `http_client` is
    // defined by the pack from this very line.
    ModuleRef::of(\Lava\HttpClient\HttpClientModule::class, package: 'lavaphp/http-client', feature: 'http_client'),
];
