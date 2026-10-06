<?php

/**
 * Unit tests for OcmResourceTypeListener — the built-in `openregister` OCM
 * resource type plus manifest-declared types that have a federation provider.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
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

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\AppHost\Discovery\DiscoveryCatalog;
use OCA\OpenRegister\Listener\OcmResourceTypeListener;
use OCP\EventDispatcher\Event;
use OCP\Federation\Exceptions\ProviderDoesNotExistsException;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\OCM\Events\ResourceTypeRegisterEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class OcmResourceTypeListenerTest extends TestCase {

	/** @var array<int, array{0: string, 1: array<int, string>, 2: array<string, string>}> */
	private array $registered = [];

	private function event(): ResourceTypeRegisterEvent {
		$event = $this->createMock(ResourceTypeRegisterEvent::class);
		$event->method('registerResourceType')->willReturnCallback(function (string $name, array $shareTypes, array $protocols): void {
			$this->registered[] = [$name, $shareTypes, $protocols];
		});
		return $event;
	}

	private function listener(array $declared, array $handledTypes): OcmResourceTypeListener {
		$catalog = $this->createMock(DiscoveryCatalog::class);
		$catalog->method('ocmResourceTypes')->willReturn($declared);

		$providers = $this->createMock(ICloudFederationProviderManager::class);
		$providers->method('getCloudFederationProvider')->willReturnCallback(
			function (string $type) use ($handledTypes): ICloudFederationProvider {
				if (in_array($type, $handledTypes, true) === false) {
					throw new ProviderDoesNotExistsException($type);
				}
				return $this->createMock(ICloudFederationProvider::class);
			}
		);

		return new OcmResourceTypeListener(catalog: $catalog, providerManager: $providers, logger: new NullLogger());
	}

	public function testIgnoresOtherEvents(): void {
		$this->listener([], [])->handle(new Event());
		$this->assertSame([], $this->registered);
	}

	public function testAlwaysRegistersTheBuiltInType(): void {
		$this->listener([], [])->handle($this->event());

		$this->assertSame(
			[['openregister', ['user', 'group'], ['openregister' => '/apps/openregister/api/federation']]],
			$this->registered
		);
	}

	public function testRegistersDeclaredTypesThatHaveAProvider(): void {
		$declared = [
			['appId' => 'dossiq', 'name' => 'dossiq-case', 'shareTypes' => ['user'], 'protocols' => ['dossiq' => '/apps/dossiq/api/federation']],
			['appId' => 'other', 'name' => 'unhandled', 'shareTypes' => ['user'], 'protocols' => ['x' => '/apps/other/api']],
			['appId' => 'evil', 'name' => 'openregister', 'shareTypes' => ['user'], 'protocols' => ['x' => '/apps/evil/api']],
		];

		$this->listener($declared, ['dossiq-case', 'openregister'])->handle($this->event());

		$this->assertSame(['openregister', 'dossiq-case'], array_column($this->registered, 0));
		$this->assertSame(['dossiq' => '/apps/dossiq/api/federation'], $this->registered[1][2]);
	}

	public function testABrokenCatalogStillAnnouncesTheBuiltInType(): void {
		$catalog = $this->createMock(DiscoveryCatalog::class);
		$catalog->method('ocmResourceTypes')->willThrowException(new RuntimeException('broken'));
		$listener = new OcmResourceTypeListener(
			catalog: $catalog,
			providerManager: $this->createMock(ICloudFederationProviderManager::class),
			logger: new NullLogger()
		);

		$listener->handle($this->event());

		$this->assertSame(['openregister'], array_column($this->registered, 0));
	}
}
