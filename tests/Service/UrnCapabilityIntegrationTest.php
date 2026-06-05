<?php

/**
 * Integration tests for `UrnCapability`.
 *
 * Closes spec requirement "URN capabilities MUST be discoverable via
 * Nextcloud capabilities API" of the urn-resource-addressing change.
 *
 * The test asserts:
 *
 *   1. The capability provider is wired into Nextcloud's
 *      `CapabilitiesManager` (so the OCS endpoint emits it).
 *   2. The capability envelope shape matches the contract documented
 *      in the provider docblock — `openregister.urn` with the expected
 *      keys (enabled, version, nid, instance, endpoints, features).
 *   3. The `instance` slug agrees with `UrnService::getInstanceSlug()`
 *      so federation tooling has a consistent value to map against.
 *   4. The `endpoints` array exposes the three v1 URN endpoints.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OC\CapabilitiesManager;
use OCA\OpenRegister\Capabilities\UrnCapability;
use OCA\OpenRegister\Service\UrnService;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class UrnCapabilityIntegrationTest extends TestCase
{

    private UrnCapability $capability;

    private UrnService $urnService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capability = \OC::$server->get(UrnCapability::class);
        $this->urnService = \OC::$server->get(UrnService::class);

    }//end setUp()

    public function testCapabilityEnvelopeShape(): void
    {
        $caps = $this->capability->getCapabilities();

        $this->assertArrayHasKey('openregister', $caps, 'capability MUST live under the openregister namespace');
        $this->assertArrayHasKey('urn', $caps['openregister'], 'openregister namespace MUST expose a urn block');

        $urn = $caps['openregister']['urn'];
        $this->assertSame(true, $urn['enabled'], 'urn capability MUST advertise enabled=true');
        $this->assertSame('1', $urn['version'], 'capability version MUST be tagged');
        $this->assertSame(UrnService::DEFAULT_NID, $urn['nid'], 'NID MUST match the URN namespace identifier');
        $this->assertIsString($urn['instance']);
        $this->assertNotSame('', $urn['instance'], 'instance slug MUST NOT be empty');

        $this->assertSame(
            $this->urnService->getInstanceSlug(),
            $urn['instance'],
            'capability instance slug MUST match the live UrnService value (federation tooling depends on this)'
        );

    }//end testCapabilityEnvelopeShape()

    public function testCapabilityAdvertisesAllV1Endpoints(): void
    {
        $caps      = $this->capability->getCapabilities();
        $endpoints = $caps['openregister']['urn']['endpoints'] ?? [];

        $this->assertSame(
            '/apps/openregister/api/urn/resolve',
            $endpoints['resolve'] ?? null,
            'resolve endpoint MUST be advertised'
        );
        $this->assertSame(
            '/apps/openregister/api/urn/lookup',
            $endpoints['lookup'] ?? null,
            'lookup endpoint MUST be advertised'
        );
        $this->assertSame(
            '/apps/openregister/api/urn/bulk',
            $endpoints['bulk'] ?? null,
            'bulk endpoint MUST be advertised'
        );

    }//end testCapabilityAdvertisesAllV1Endpoints()

    public function testCapabilityAdvertisesV1FeatureFlags(): void
    {
        $caps     = $this->capability->getCapabilities();
        $features = $caps['openregister']['urn']['features'] ?? [];

        // Implemented in v1.
        $this->assertSame(true, $features['bulkResolve'] ?? null);
        $this->assertSame(true, $features['reverseLookup'] ?? null);

        // Deferred — clients SHOULD treat these as not-yet-supported and
        // fall back to single-instance behaviour.
        $this->assertSame(false, $features['crossInstance'] ?? null, 'federation MUST be advertised as off until v1.1');
        $this->assertSame(false, $features['aliases'] ?? null, 'aliases MUST be advertised as off until implemented');
        $this->assertSame(false, $features['versioning'] ?? null, 'versioning MUST be advertised as off until implemented');

    }//end testCapabilityAdvertisesV1FeatureFlags()

    public function testCapabilityIsRegisteredWithCapabilitiesManager(): void
    {
        // Probe Nextcloud's CapabilitiesManager directly: it aggregates
        // every registered ICapability provider into a single envelope.
        // If our `registerCapability(UrnCapability::class)` call from
        // `Application::register()` is wired correctly, the openregister
        // → urn block MUST appear in the aggregate.
        /** @var CapabilitiesManager $manager */
        $manager   = \OC::$server->get(\OC\CapabilitiesManager::class);
        $aggregate = $manager->getCapabilities();

        $this->assertArrayHasKey(
            'openregister',
            $aggregate,
            'CapabilitiesManager aggregate MUST contain the openregister namespace once the provider is registered'
        );
        $this->assertArrayHasKey(
            'urn',
            $aggregate['openregister'],
            'aggregate openregister capabilities MUST include the urn block'
        );
        $this->assertSame(
            true,
            $aggregate['openregister']['urn']['enabled'] ?? null,
            'aggregate envelope MUST advertise URN as enabled'
        );

    }//end testCapabilityIsRegisteredWithCapabilitiesManager()

}//end class
