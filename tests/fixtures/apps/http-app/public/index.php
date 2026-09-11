<?php

declare(strict_types=1);

// The whole HTTP entry point, fully explicit: autoload → static-file guard →
// boot → dispatch. Nothing hidden, nothing magic.

require dirname(__DIR__, 7) . '/vendor/autoload.php';

// Under `php -S`, serve real files from public/ directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== false && is_file($file)) {
        return false;
    }
}

$app = \Lava\Core\Boot\Kernel::boot(dirname(__DIR__));
$request = \Lava\Core\Http\RequestFactory::fromGlobals();

if ($app instanceof \Lava\Core\Boot\BootFailure) {
    \Lava\Core\Http\Emitter::emit($app->toResponse($request));
    return;
}

\Lava\Core\Http\Emitter::emit($app->handle($request));
