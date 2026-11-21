<?php

/**
 * Other apps add flow triggers to the event catalog through RegisterFlowTriggersEvent.
 *
 * The builder's palette and `GET /api/flow/event-catalog` read getCatalog(), and
 * a case sentry checks its event against knownTriggerIds(), so a contributed
 * trigger is asserted in both. A contribution may not shadow a built-in id or
 * alias, an empty field is refused, a throwing listener keeps what came before
 * it, and the event is dispatched once however often the catalog is read.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Flow;

use OCA\OpenRegister\Event\RegisterFlowTriggersEvent;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\EventCatalogService
 * @uses   \OCA\OpenRegister\Event\RegisterFlowTriggersEvent
 */
class EventCatalogContributedTriggersTest extends TestCase {

	/**
	 * A dispatcher that runs the given listeners on RegisterFlowTriggersEvent, counting dispatches.
	 *
	 * @param list<callable(RegisterFlowTriggersEvent): void> $listeners The listeners.
	 * @param int                                             $count     Incremented per dispatch.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(array $listeners, int &$count = 0): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($listeners, &$count): void {
				$this->assertInstanceOf(RegisterFlowTriggersEvent::class, $event);
				$count++;
				foreach ($listeners as $listener) {
					$listener($event);
				}
			}
		);

		return $dispatcher;
	}//end dispatcher()

	public function testAContributedTriggerIsListedAfterTheOwnEntriesAndKnown(): void {
		$service = new EventCatalogService(
			$this->dispatcher(
				[
					static function (RegisterFlowTriggersEvent $event): void {
						$event->addTrigger('keepiq.share.granted', 'A share is granted', 'Vault');
					},
				]
			)
		);

		$catalog = $service->getCatalog();
		$own     = (new EventCatalogService())->getCatalog();

		$this->assertSame($own, array_slice($catalog, 0, count($own)));
		$this->assertSame(['id' => 'keepiq.share.granted', 'label' => 'A share is granted', 'group' => 'Vault'], end($catalog));
		$this->assertCount(count($own) + 1, $catalog);
		$this->assertContains('keepiq.share.granted', $service->knownTriggerIds());
		$this->assertContains('created', $service->knownTriggerIds());
		$this->assertSame(['keepiq.share.granted'], $service->aliasesFor('keepiq.share.granted'));
	}//end testAContributedTriggerIsListedAfterTheOwnEntriesAndKnown()

	public function testWithoutADispatcherOnlyTheOwnEntriesAreListed(): void {
		$ids = array_column((new EventCatalogService())->getCatalog(), 'id');

		$this->assertContains('object.created', $ids);
		$this->assertNotContains('keepiq.share.granted', $ids);
	}//end testWithoutADispatcherOnlyTheOwnEntriesAreListed()

	public function testABuiltInIdAnAliasAnEmptyFieldAndARepeatAreRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(5))->method('warning');

		$service = new EventCatalogService(
			$this->dispatcher(
				[
					static function (RegisterFlowTriggersEvent $event): void {
						$event->addTrigger('object.created', 'Shadow', 'Vault');
						$event->addTrigger('created', 'Shadow alias', 'Vault');
						$event->addTrigger('keepiq.link.opened', ' ', 'Vault');
						$event->addTrigger(' keepiq.item.read ', ' An item is read ', ' Vault ');
						$event->addTrigger('keepiq.item.read', 'Again', 'Vault');
						$event->addTrigger('', 'No id', 'Vault');
					},
				]
			),
			$logger
		);

		$catalog = $service->getCatalog();
		$extra   = array_slice($catalog, count((new EventCatalogService())->getCatalog()));

		$this->assertSame([['id' => 'keepiq.item.read', 'label' => 'An item is read', 'group' => 'Vault']], $extra);
		$this->assertSame('An object is created', $catalog[0]['label']);
	}//end testABuiltInIdAnAliasAnEmptyFieldAndARepeatAreRefused()

	public function testAThrowingListenerKeepsWhatCameBeforeItAndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('contribution failed'));

		$service = new EventCatalogService(
			$this->dispatcher(
				[
					static function (RegisterFlowTriggersEvent $event): void {
						$event->addTrigger('keepiq.share.granted', 'A share is granted', 'Vault');
					},
					static function (): void {
						throw new RuntimeException('listener broke');
					},
				]
			),
			$logger
		);

		$this->assertContains('keepiq.share.granted', array_column($service->getCatalog(), 'id'));
	}//end testAThrowingListenerKeepsWhatCameBeforeItAndIsLogged()

	public function testTheEventIsDispatchedOncePerService(): void {
		$count   = 0;
		$service = new EventCatalogService($this->dispatcher([], $count));

		$service->getCatalog();
		$service->knownTriggerIds();
		$service->getCatalog();

		$this->assertSame(1, $count);
	}//end testTheEventIsDispatchedOncePerService()

	public function testAListenerThatReadsTheCatalogDoesNotDispatchAgain(): void {
		$count   = 0;
		$service = null;
		$service = new EventCatalogService(
			$this->dispatcher(
				[
					static function (RegisterFlowTriggersEvent $event) use (&$service): void {
						$event->addTrigger('keepiq.key.rotated', 'A key is rotated', 'Vault');
						$service->knownTriggerIds();
					},
				],
				$count
			)
		);

		$this->assertContains('keepiq.key.rotated', $service->knownTriggerIds());
		$this->assertSame(1, $count);
	}//end testAListenerThatReadsTheCatalogDoesNotDispatchAgain()
}//end class
