<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPCoreLab\PaymentGateways\Core\GatewayConfig;
use PHPCoreLab\PaymentGateways\Enums\Environment;

final class GatewayConfigTest extends TestCase
{
    public function testDefaultsToSandbox(): void
    {
        $config = GatewayConfig::fromArray([
            'active_provider' => 'razorpay',
            'providers'       => [],
        ]);

        $this->assertSame(Environment::Sandbox, $config->getEnvironment());
        $this->assertTrue($config->isSandbox());
        $this->assertFalse($config->isLive());
    }

    public function testParsesLiveEnvironment(): void
    {
        $config = GatewayConfig::fromArray([
            'active_provider' => 'razorpay',
            'environment'     => 'live',
            'providers'       => [],
        ]);

        $this->assertSame(Environment::Live, $config->getEnvironment());
        $this->assertTrue($config->isLive());
        $this->assertFalse($config->isSandbox());
    }

    public function testParsesSandboxEnvironment(): void
    {
        $config = GatewayConfig::fromArray([
            'active_provider' => 'payu',
            'environment'     => 'sandbox',
            'providers'       => [],
        ]);

        $this->assertSame(Environment::Sandbox, $config->getEnvironment());
    }

    public function testCanSwitchEnvironmentAtRuntime(): void
    {
        $config = GatewayConfig::fromArray([
            'active_provider' => 'razorpay',
            'environment'     => 'sandbox',
            'providers'       => [],
        ]);

        $this->assertTrue($config->isSandbox());
        $config->setEnvironment(Environment::Live);
        $this->assertTrue($config->isLive());
    }

    public function testCanSwitchActiveProvider(): void
    {
        $config = GatewayConfig::fromArray([
            'active_provider' => 'razorpay',
            'providers'       => [],
        ]);

        $this->assertSame('razorpay', $config->getActiveProvider());
        $config->setActiveProvider('payu');
        $this->assertSame('payu', $config->getActiveProvider());
    }

    public function testInvalidEnvironmentThrows(): void
    {
        $this->expectException(\ValueError::class);

        GatewayConfig::fromArray([
            'active_provider' => 'razorpay',
            'environment'     => 'production',
            'providers'       => [],
        ]);
    }

    public function testGetProviderConfigReturnsEmptyArrayForMissingProvider(): void
    {
        $config = GatewayConfig::fromArray([
            'active_provider' => 'razorpay',
            'providers'       => [],
        ]);

        $this->assertSame([], $config->getProviderConfig('nonexistent'));
    }
}
