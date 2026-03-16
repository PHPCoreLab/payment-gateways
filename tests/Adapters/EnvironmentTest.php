<?php

declare(strict_types=1);

namespace PHPCoreLab\PaymentGateways\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use PHPCoreLab\PaymentGateways\Adapters\Razorpay\RazorpayAdapter;
use PHPCoreLab\PaymentGateways\Adapters\PhonePe\PhonePeAdapter;
use PHPCoreLab\PaymentGateways\Adapters\PayU\PayUAdapter;
use PHPCoreLab\PaymentGateways\Adapters\Paytm\PaytmAdapter;
use PHPCoreLab\PaymentGateways\Adapters\Juspay\JuspayAdapter;
use PHPCoreLab\PaymentGateways\Enums\Environment;

final class EnvironmentTest extends TestCase
{
    // ── Razorpay ──────────────────────────────────────────────────────────────

    public function testRazorpayPicksSandboxKeys(): void
    {
        $adapter = new RazorpayAdapter([
            'sandbox_key_id'     => 'rzp_test_KEY',
            'sandbox_key_secret' => 'rzp_test_SECRET',
            'live_key_id'        => 'rzp_live_KEY',
            'live_key_secret'    => 'rzp_live_SECRET',
        ], Environment::Sandbox);

        $this->assertSame('razorpay', $adapter->getName());
    }

    public function testRazorpayPicksLiveKeys(): void
    {
        $adapter = new RazorpayAdapter([
            'sandbox_key_id'     => 'rzp_test_KEY',
            'sandbox_key_secret' => 'rzp_test_SECRET',
            'live_key_id'        => 'rzp_live_KEY',
            'live_key_secret'    => 'rzp_live_SECRET',
        ], Environment::Live);

        $this->assertSame('razorpay', $adapter->getName());
    }

    public function testRazorpayThrowsWhenLiveKeyMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('live_key_id');

        new RazorpayAdapter([
            'sandbox_key_id'     => 'rzp_test_KEY',
            'sandbox_key_secret' => 'rzp_test_SECRET',
        ], Environment::Live);
    }

    // ── PhonePe ───────────────────────────────────────────────────────────────

    public function testPhonePePicksSandboxConfig(): void
    {
        $adapter = new PhonePeAdapter([
            'sandbox_merchant_id' => 'SANDBOX_MID',
            'sandbox_salt_key'    => 'sandbox-salt',
            'live_merchant_id'    => 'LIVE_MID',
            'live_salt_key'       => 'live-salt',
        ], Environment::Sandbox);

        $this->assertSame('phonepe', $adapter->getName());
    }

    public function testPhonePeThrowsWhenLiveMerchantIdMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('live_merchant_id');

        new PhonePeAdapter([
            'sandbox_merchant_id' => 'SANDBOX_MID',
            'sandbox_salt_key'    => 'sandbox-salt',
        ], Environment::Live);
    }

    // ── PayU ──────────────────────────────────────────────────────────────────

    public function testPayUPicksSandboxConfig(): void
    {
        $adapter = new PayUAdapter([
            'sandbox_merchant_key'  => 'sandbox_key',
            'sandbox_merchant_salt' => 'sandbox_salt',
            'live_merchant_key'     => 'live_key',
            'live_merchant_salt'    => 'live_salt',
        ], Environment::Sandbox);

        $this->assertSame('payu', $adapter->getName());
    }

    public function testPayUPicksLiveConfig(): void
    {
        $adapter = new PayUAdapter([
            'sandbox_merchant_key'  => 'sandbox_key',
            'sandbox_merchant_salt' => 'sandbox_salt',
            'live_merchant_key'     => 'live_key',
            'live_merchant_salt'    => 'live_salt',
        ], Environment::Live);

        $this->assertSame('payu', $adapter->getName());
    }

    public function testPayUThrowsWhenLiveKeyMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('live_merchant_key');

        new PayUAdapter([
            'sandbox_merchant_key'  => 'sandbox_key',
            'sandbox_merchant_salt' => 'sandbox_salt',
        ], Environment::Live);
    }

    // ── Paytm ─────────────────────────────────────────────────────────────────

    public function testPaytmPicksSandboxConfig(): void
    {
        $adapter = new PaytmAdapter([
            'sandbox_mid'          => 'SANDBOX_MID',
            'sandbox_merchant_key' => 'sandbox_key',
            'live_mid'             => 'LIVE_MID',
            'live_merchant_key'    => 'live_key',
        ], Environment::Sandbox);

        $this->assertSame('paytm', $adapter->getName());
    }

    public function testPaytmThrowsWhenLiveMidMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('live_mid');

        new PaytmAdapter([
            'sandbox_mid'          => 'SANDBOX_MID',
            'sandbox_merchant_key' => 'sandbox_key',
        ], Environment::Live);
    }

    // ── Juspay ────────────────────────────────────────────────────────────────

    public function testJuspayPicksSandboxConfig(): void
    {
        $adapter = new JuspayAdapter([
            'sandbox_api_key'     => 'sandbox_api',
            'sandbox_merchant_id' => 'sandbox_mid',
            'live_api_key'        => 'live_api',
            'live_merchant_id'    => 'live_mid',
        ], Environment::Sandbox);

        $this->assertSame('juspay', $adapter->getName());
    }

    public function testJuspayPicksLiveConfig(): void
    {
        $adapter = new JuspayAdapter([
            'sandbox_api_key'     => 'sandbox_api',
            'sandbox_merchant_id' => 'sandbox_mid',
            'live_api_key'        => 'live_api',
            'live_merchant_id'    => 'live_mid',
        ], Environment::Live);

        $this->assertSame('juspay', $adapter->getName());
    }

    public function testJuspayThrowsWhenLiveApiKeyMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('live_api_key');

        new JuspayAdapter([
            'sandbox_api_key'     => 'sandbox_api',
            'sandbox_merchant_id' => 'sandbox_mid',
        ], Environment::Live);
    }
}
