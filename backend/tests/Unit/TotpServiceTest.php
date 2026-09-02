<?php

namespace Tests\Unit;

use App\Services\TotpService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TotpServiceTest extends TestCase
{
    public function test_generated_secret_produces_a_valid_current_code(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);

        $method = new ReflectionMethod($service, 'code');
        $code = $method->invoke($service, $secret, intdiv(time(), 30));

        $this->assertTrue($service->verify($secret, $code));
        $this->assertFalse($service->verify($secret, '000000') && $code !== '000000');
    }
}
