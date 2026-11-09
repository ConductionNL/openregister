<?php

/**
 * Unit tests for the one-time dispatch, the by-id lookup and a failing leaf collection of the leaf registry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Dispatch happens once per request, a lookup by id answers known and unknown,
 * and a leaf whose collection throws costs only itself.
 *
 * @covers \OCA\OpenRegister\Service\Integration\LeafRegistry
 */
class LeafRegistryLookupTest extends TestCase {

	/**
	 * A registry whose dispatcher runs the listener, expecting the given dispatch count.
	 *
	 * @param callable                 $listener     The listener.
	 * @param int                      $dispatches   How often dispatch must happen.
	 * @param IntegrationRegistry|null $integrations The integration registry.
	 * @param LoggerInterface|null     $logger       The logger.
	 *
	 * @return LeafRegistry
	 */
	private function registry(
		callable $listener,
		int $dispatches,
		?IntegrationRegistry $integrations = null,
		?LoggerInterface $logger = null
	): LeafRegistry {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->exactly($dispatches))
			->method('dispatchTyped')
			->willReturnCallback(
				static function (object $event) use ($listener): void {
					$listener($event);
				}
			);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);

		return new LeafRegistry(
			$dispatcher,
			$integrations ?? $this->createMock(IntegrationRegistry::class),
			$apps,
			$logger ?? $this->createMock(LoggerInterface::class)
		);

	}//end registry()

	/**
	 * A render-surface leaf.
	 *
	 * @param string $id The id.
	 *
	 * @return LeafDescriptor
	 */
	private function leaf(string $id): LeafDescriptor {
		return new LeafDescriptor(id: $id, label: 'L', icon: 'Cube', kinds: [LeafDescriptor::KIND_RENDER_SURFACE]);

	}//end leaf()

	/**
	 * A lookup by id finds a collected leaf and answers null for an unknown one, after one dispatch.
	 *
	 * @return void
	 */
	public function testALookupByIdAnswersKnownAndUnknownAfterOneDispatch(): void {
		$registry = $this->registry(
			function (RegisterLeafProvidersEvent $event): void {
				$event->registerLeaf($this->leaf('acme-tab'));
			},
			1
		);

		$this->assertSame('acme-tab', $registry->getDescriptor('acme-tab')?->getId());
		$this->assertNull($registry->getDescriptor('other-tab'));
		$this->assertCount(1, $registry->getDescriptors());

	}//end testALookupByIdAnswersKnownAndUnknownAfterOneDispatch()

	/**
	 * A leaf whose collection throws is logged and the leaves after it are still collected.
	 *
	 * @return void
	 */
	public function testAThrowingCollectionCostsOnlyThatLeaf(): void {
		$provider     = $this->createMock(IntegrationProvider::class);
		$integrations = $this->createMock(IntegrationRegistry::class);
		$integrations->expects($this->once())
			->method('addProvider')
			->with($provider)
			->willThrowException(new RuntimeException('bad provider'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'[LeafRegistry] collecting a leaf failed: {message}',
				$this->callback(static fn (array $context): bool => $context['message'] === 'bad provider')
			);

		$registry = $this->registry(
			function (RegisterLeafProvidersEvent $event) use ($provider): void {
				$event->registerLeaf(
					new LeafDescriptor(id: 'acme-data', label: 'D', icon: 'Cube', kinds: [LeafDescriptor::KIND_DATA_PROVIDER]),
					$provider
				);
				$event->registerLeaf($this->leaf('acme-tab'));
			},
			1,
			$integrations,
			$logger
		);

		$this->assertSame('acme-tab', $registry->getDescriptor('acme-tab')?->getId());

	}//end testAThrowingCollectionCostsOnlyThatLeaf()
}//end class
