<?php

declare(strict_types=1);

use Lava\Core\Routing\Router;

// Only the returned closure: boot re-executes this file on every boot, so
// classes live in autoloaded files and nothing is declared here.
//
// The fixture is about wiring, not routing — the route exists so the app is a
// real app with a real router, and it deliberately does NOT call the client.
// An HTTP route that made an outbound request would make the fixture depend on
// a server being up to boot, and the wiring claims under test have nothing to
// do with requests. The live tests reach the pack's client through the
// container instead, which is the same object a handler would be given.
return function (Router $r): void {
    $r->get('/health', 'health')->handler([\App\Http\HealthController::class, 'check']);
};
