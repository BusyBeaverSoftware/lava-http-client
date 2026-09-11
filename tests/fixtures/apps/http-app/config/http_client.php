<?php

declare(strict_types=1);

// The pack's own config file, and it is here for one reason: to make
// `HttpClientModule::pack()`'s `configFiles: ['http_client']` a claim the
// fixture can prove. Delete this file and the wiring test's provenance
// assertion fails with '(unset)' — which is what makes that assertion worth
// having.
//
// Every value is deliberately NOT the default, so a test that reads the
// container's ClientOptions proves the file was read rather than that the
// defaults happen to be right.
return [
    'timeout' => 7,
    'connect_timeout' => 3,
    'retries' => 1,
    'backoff_ms' => 0,
    'user_agent' => 'lava-http-fixture/1.0',
];
