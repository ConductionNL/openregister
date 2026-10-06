<?php

/**
 * Unit tests for DiscoveryCapability — the public, manifest-driven
 * discovery block of the OCS capabilities answer.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Capabilities
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/apphost-discovery-manifest/specs/apphost-discovery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Capabilities;

use OCA\OpenRegister\AppHost\Discovery\DiscoveryCatalog;
use OCA\OpenRegister\Capabilities\DiscoveryCapability;
use OCP\Capabilities\IInitialStateExcludedCapability;
use OCP\Capabilities\IPublicCapability;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class DiscoveryCapabilityTest extends TestCase {

	public function testIsPublicAndExcludedFromPageInitialState(): void {
		$capability = new DiscoveryCapability(catalog: $this->createMock(DiscoveryCatalog::class), logger: new NullLogger());

		$this->assertInstanceOf(IPublicCapability::class, $capability);
		$this->assertInstanceOf(IInitialStateExcludedCapability::class, $capability);
	}

	public function testEachAppGetsItsOwnTopLevelKeyPlusAnIndex(): void {
		$catalog = $this->createMock(DiscoveryCatalog::class);
		$catalog->method('publicDocuments')->willReturn([
			'decidiq' => ['contractVersion' => 1, 'standards' => [['id' => 'ori']]],
			'dossiq' => ['contractVersion' => 1, 'standards' => [['id' => 'zgw-zaken']]],
		]);

		$capabilities = (new DiscoveryCapability(catalog: $catalog, logger: new NullLogger()))->getCapabilities();

		$this->assertSame(['decidiq', 'dossiq'], $capabilities['discovery']['apps']);
		$this->assertSame('openregister', $capabilities['discovery']['provider']);
		$this->assertSame(1, $capabilities['discovery']['contractVersion']);
		$this->assertSame('ori', $capabilities['decidiq']['discovery']['standards'][0]['id']);
		$this->assertSame('zgw-zaken', $capabilities['dossiq']['discovery']['standards'][0]['id']);
	}

	public function testNothingDeclaredPublishesNothing(): void {
		$catalog = $this->createMock(DiscoveryCatalog::class);
		$catalog->method('publicDocuments')->willReturn([]);

		$this->assertSame([], (new DiscoveryCapability(catalog: $catalog, logger: new NullLogger()))->getCapabilities());
	}

	public function testAFailingCatalogNeverBreaksTheCapabilitiesEndpoint(): void {
		$catalog = $this->createMock(DiscoveryCatalog::class);
		$catalog->method('publicDocuments')->willThrowException(new RuntimeException('broken manifest'));

		$this->assertSame([], (new DiscoveryCapability(catalog: $catalog, logger: new NullLogger()))->getCapabilities());
	}
}
