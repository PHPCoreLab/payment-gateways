<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Core;

use PHPCoreLab\PaymentGateways\Contracts\PaymentProviderInterface;
use PHPCoreLab\PaymentGateways\Exceptions\ProviderNotFoundException;

final class ProviderRegistry
{
    /** @var array<string, PaymentProviderInterface|callable> */
    private array $providers = [];

    public function register(string $name, PaymentProviderInterface|callable $provider): void
    {
        $this->providers[$name] = $provider;
    }

    public function resolve(string $name): PaymentProviderInterface
    {
        $provider = $this->providers[$name]
            ?? throw new ProviderNotFoundException("Provider '{$name}' is not registered.");

        if (is_callable($provider)) {
            $this->providers[$name] = $provider();
        }

        return $this->providers[$name];
    }

    /** @return string[] */
    public function registered(): array
    {
        return array_keys($this->providers);
    }
}
