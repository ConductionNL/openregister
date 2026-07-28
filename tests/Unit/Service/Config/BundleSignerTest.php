<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Service\Config\BundleSigner;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class BundleSignerTest extends TestCase
{
    /**
     * Stateful IAppConfig double: setValueString persists, getValueString reads.
     *
     * @var array<string, string>
     */
    private array $store = [];

    private BundleSigner $signer;

    protected function setUp(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => ($this->store[$key] ?? $default)
        );
        $appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                $this->store[$key] = $value;
                return true;
            }
        );

        $this->signer = new BundleSigner($appConfig);
    }

    public function testSignThenVerifyRoundTrips(): void
    {
        $signed = $this->signer->sign(['type' => 'demo', 'objects' => [['b' => 2, 'a' => 1]]]);

        $this->assertArrayHasKey('provenance', $signed);
        $this->assertSame('ed25519', $signed['provenance']['alg']);

        $verdict = $this->signer->verify($signed);
        $this->assertTrue($verdict['signed']);
        $this->assertTrue($verdict['valid']);
        $this->assertTrue($verdict['trusted']);
    }

    public function testTamperingBreaksTheSignature(): void
    {
        $signed = $this->signer->sign(['type' => 'demo', 'objects' => [['a' => 1]]]);
        $signed['objects'][0]['a'] = 999;

        $verdict = $this->signer->verify($signed);
        $this->assertTrue($verdict['signed']);
        $this->assertFalse($verdict['valid'], 'a tampered bundle must not verify');
    }

    public function testCanonicalisationIsKeyOrderIndependent(): void
    {
        $signed = $this->signer->sign(['type' => 'demo', 'objects' => [['a' => 1, 'z' => 2]]]);
        // Re-order keys of the payload without touching values — signature still valid.
        $reordered = ['objects' => [['z' => 2, 'a' => 1]], 'type' => 'demo', 'provenance' => $signed['provenance']];

        $this->assertTrue($this->signer->verify($reordered)['valid']);
    }

    public function testUnsignedIsTrustedOnlyWhenNotEnforced(): void
    {
        $bundle = ['type' => 'demo'];

        // No trusted-keys list → not enforced → unsigned is trusted.
        $this->assertTrue($this->signer->verify($bundle)['trusted']);

        // Enforce by trusting some (other) key → unsigned is no longer trusted.
        $this->store['federated_config_trusted_keys'] = base64_encode(str_repeat('x', 32));
        $verdict = $this->signer->verify($bundle);
        $this->assertFalse($verdict['signed']);
        $this->assertFalse($verdict['trusted']);
    }

    public function testEnforcementTrustsOnlyListedKeys(): void
    {
        $signed = $this->signer->sign(['type' => 'demo']);

        // Enforce with an unrelated key → valid but untrusted.
        $this->store['federated_config_trusted_keys'] = base64_encode(str_repeat('y', 32));
        $verdict = $this->signer->verify($signed);
        $this->assertTrue($verdict['valid']);
        $this->assertFalse($verdict['trusted']);

        // Add this instance's own key → trusted again.
        $this->store['federated_config_trusted_keys'] .= ','.$this->signer->publicKey();
        $this->assertTrue($this->signer->verify($signed)['trusted']);
    }
}
