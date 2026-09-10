<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_php_floor_is_honored(): void
    {
        self::assertGreaterThanOrEqual(80300, PHP_VERSION_ID, 'lava/http-client requires PHP 8.3+');
    }
}