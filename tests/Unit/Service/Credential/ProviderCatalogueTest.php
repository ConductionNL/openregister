<?php

/**
 * ProviderCatalogueTest — unit tests for the read-only provider catalogue loader.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\ProviderCatalogue
 */
class ProviderCatalogueTest extends TestCase
{
    private ProviderCatalogue $catalogue;

    protected function setUp(): void
    {
        // Repo root = four levels up from tests/Unit/Service/Credential.
        $appRoot    = dirname(__DIR__, 4);
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppPath')->willReturn($appRoot);

        $this->catalogue = new ProviderCatalogue(
            $appManager,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testLoadsGithubEntry(): void
    {
        $github = $this->catalogue->get('github');
        $this->assertIsArray($github);
        $this->assertSame('https://api.github.com', $github['baseUrl']);
        $this->assertSame('Authorization', $github['authScheme']['header']);
        $this->assertStringContainsString('{secret}', $github['authScheme']['template']);
    }

    public function testLoadsGitlabEntry(): void
    {
        $gitlab = $this->catalogue->get('gitlab');
        $this->assertIsArray($gitlab);
        $this->assertSame('https://gitlab.com/api/v4', $gitlab['baseUrl']);
        $this->assertStringContainsString('Bearer', $gitlab['authScheme']['template']);
    }

    public function testUnknownProviderReturnsNull(): void
    {
        $this->assertNull($this->catalogue->get('does-not-exist'));
    }

    public function testAllReturnsBothProviders(): void
    {
        $all = $this->catalogue->all();
        $this->assertArrayHasKey('github', $all);
        $this->assertArrayHasKey('gitlab', $all);
    }

    // -------------------------------------------------------------------------
    // Fleet providers (2026-07-12). The catalogue used to hold only github /
    // gitlab / doffin, and create() rejects an unknown provider — so the fleet's
    // real credentials (Mollie, Stripe, KVK, …) could not be brokered AT ALL, and
    // every app kept custody of its own secrets because it had no other option.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function fleetProviderProvider(): array
    {
        return [
            'mollie'      => ['mollie', 'https://api.mollie.com', 'Authorization'],
            'stripe'      => ['stripe', 'https://api.stripe.com', 'Authorization'],
            'adyen'       => ['adyen', 'https://checkout-live.adyen.com', 'X-API-Key'],
            'adyen-test'  => ['adyen-test', 'https://checkout-test.adyen.com', 'X-API-Key'],
            'ccv'         => ['ccv', 'https://api.psp.ccv.eu', 'Authorization'],
            'ccv-sandbox' => ['ccv-sandbox', 'https://api.psp.sandbox.ccv.eu', 'Authorization'],
            'kvk'         => ['kvk', 'https://api.kvk.nl', 'apikey'],
            'twilio'      => ['twilio', 'https://api.twilio.com', 'Authorization'],
            'messagebird' => ['messagebird', 'https://rest.messagebird.com', 'Authorization'],
            'cmcom'       => ['cmcom', 'https://gw.cmtelecom.com', 'X-CM-PRODUCTTOKEN'],
            'openai'      => ['openai', 'https://api.openai.com', 'Authorization'],
            'fireworks'   => ['fireworks', 'https://api.fireworks.ai', 'Authorization'],
        ];
    }//end fleetProviderProvider()

    /**
     * @dataProvider fleetProviderProvider
     */
    public function testFleetProviderIsBrokerable(string $id, string $baseUrl, string $header): void
    {
        $entry = $this->catalogue->get($id);

        $this->assertIsArray($entry, $id.' must exist, or its credential cannot even be created');
        $this->assertSame($id, $entry['identifier']);
        $this->assertSame($baseUrl, $entry['baseUrl']);
        $this->assertSame($header, $entry['authScheme']['header']);
        $this->assertStringContainsString('{secret}', $entry['authScheme']['template']);
    }//end testFleetProviderIsBrokerable()

    // -------------------------------------------------------------------------
    // Security invariants. The allow-rules ARE the security control — they bound
    // what any credential can ever do, and they cannot be widened at runtime. So
    // lock the shape here: a future entry that grants DELETE, or wildcards the
    // whole host, or forgets the host-lock, fails this test rather than shipping.
    // -------------------------------------------------------------------------

    public function testNoProviderGrantsDelete(): void
    {
        foreach ($this->catalogue->all() as $id => $entry) {
            foreach (($entry['allowRules'] ?? []) as $rule) {
                $this->assertNotSame(
                    'DELETE',
                    strtoupper((string) ($rule['method'] ?? '')),
                    $id.' must not grant DELETE through the broker'
                );
            }
        }
    }//end testNoProviderGrantsDelete()

    public function testNoProviderWildcardsItsWholeApiSurface(): void
    {
        foreach ($this->catalogue->all() as $id => $entry) {
            foreach (($entry['allowRules'] ?? []) as $rule) {
                $path = (string) ($rule['pathPattern'] ?? '');

                $this->assertNotSame('/*', $path, $id.' must not grant its entire API surface');
                $this->assertNotSame('*', $path, $id.' must not grant its entire API surface');
                $this->assertStringStartsWith('/', $path, $id.' rule path must be absolute');
            }
        }
    }//end testNoProviderWildcardsItsWholeApiSurface()

    public function testEveryProviderIsHostLockedOverHttps(): void
    {
        foreach ($this->catalogue->all() as $id => $entry) {
            $baseUrl = (string) ($entry['baseUrl'] ?? '');

            // resolveAndLockUrl() parses the host out of baseUrl and refuses any
            // resolved URL that leaves it. An empty or non-https baseUrl would
            // defeat that lock.
            $this->assertStringStartsWith('https://', $baseUrl, $id.' must be https');
            $this->assertIsString(parse_url($baseUrl, PHP_URL_HOST), $id.' must have a lockable host');
        }
    }//end testEveryProviderIsHostLockedOverHttps()

    public function testEverySecretIsCarriedInASingleHeaderTemplate(): void
    {
        foreach ($this->catalogue->all() as $id => $entry) {
            $scheme = ($entry['authScheme'] ?? []);

            // injectAuth() can only substitute {secret} into ONE header. An entry
            // without the placeholder would silently send an unauthenticated call.
            $this->assertNotEmpty($scheme['header'] ?? '', $id.' must name an auth header');
            $this->assertStringContainsString(
                '{secret}',
                (string) ($scheme['template'] ?? ''),
                $id.' template must carry the {secret} placeholder'
            );
        }
    }//end testEverySecretIsCarriedInASingleHeaderTemplate()
}//end class
