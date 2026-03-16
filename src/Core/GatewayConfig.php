<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Core;

use PHPCoreLab\PaymentGateways\Enums\Environment;

final class GatewayConfig
{
    public function __construct(
        private string      $activeProvider,
        private Environment $environment = Environment::Sandbox,
        private readonly array $providers = [],
    ) {}

    public function getActiveProvider(): string   { return $this->activeProvider; }
    public function getEnvironment(): Environment { return $this->environment; }
    public function isSandbox(): bool             { return $this->environment->isSandbox(); }
    public function isLive(): bool                { return $this->environment->isLive(); }

    public function setActiveProvider(string $name): void  { $this->activeProvider = $name; }
    public function setEnvironment(Environment $env): void { $this->environment = $env; }

    /** @return array<mixed> */
    public function getProviderConfig(string $name): array { return $this->providers[$name] ?? []; }

    public static function fromArray(array $config): self
    {
        return new self(
            activeProvider: $config['active_provider'],
            environment:    Environment::from($config['environment'] ?? 'sandbox'),
            providers:      $config['providers'] ?? [],
        );
    }
}
