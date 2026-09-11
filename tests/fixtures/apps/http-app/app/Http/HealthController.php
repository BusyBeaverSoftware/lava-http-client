<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;

final class HealthController
{
    public function check(): ResponseInterface
    {
        return Responses::json(['ok' => true]);
    }
}
