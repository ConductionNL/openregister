<?php

/**
 * Unit tests for the events raised when a reference is accepted or refused on save.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Event\ReferenceValidatedEvent;
use OCA\OpenRegister\Event\ReferenceValidationFailedEvent;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceCheck;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which event each outcome raises, what it carries, and that a failing dispatch never fails the save.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents
 */
class ReferenceEventsTest extends TestCase {

	/**
	 * A reference check on property `owner`.
	 *
	 * @return ReferenceCheck
	 */
	private function check(): ReferenceCheck {
		$check = new ReferenceCheck();
		$check->propertyName     = 'owner';
		$check->uuid             = 'uuid-9';
		$check->targetSchemaSlug = 'person';
		$check->register         = 'crm';

		return $check;

	}//end check()

	/**
	 * An accepted reference raises the validated event with the check's facts.
	 *
	 * @return void
	 */
	public function testAnAcceptedReferenceRaisesTheValidatedEvent(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())
			->method('dispatchTyped')
			->with(
				$this->callback(
					static fn (object $e): bool => get_class($e) === ReferenceValidatedEvent::class
						&& $e->getPropertyName() === 'owner'
						&& $e->getReferencedUuid() === 'uuid-9'
						&& $e->getTargetSchemaSlug() === 'person'
						&& $e->getTargetRegister() === 'crm'
				)
			);

		(new ReferenceEvents($dispatcher, $this->createMock(LoggerInterface::class)))->accepted($this->check());

	}//end testAnAcceptedReferenceRaisesTheValidatedEvent()

	/**
	 * A refused reference raises the failed event.
	 *
	 * @return void
	 */
	public function testARefusedReferenceRaisesTheFailedEvent(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())
			->method('dispatchTyped')
			->with($this->isInstanceOf(ReferenceValidationFailedEvent::class));

		(new ReferenceEvents($dispatcher, $this->createMock(LoggerInterface::class)))->refused($this->check());

	}//end testARefusedReferenceRaisesTheFailedEvent()

	/**
	 * A failing dispatch is logged with the event's short name and swallowed.
	 *
	 * @return void
	 */
	public function testAFailingDispatchIsLoggedNotThrown(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new Exception('listener broke'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'[SaveObject] ReferenceValidationFailedEvent dispatch failed',
				$this->callback(
					static fn (array $c): bool => $c['property'] === 'owner' && $c['uuid'] === 'uuid-9' && $c['error'] === 'listener broke'
				)
			);

		(new ReferenceEvents($dispatcher, $logger))->refused($this->check());

	}//end testAFailingDispatchIsLoggedNotThrown()

	/**
	 * Without a dispatcher nothing is raised and nothing is logged.
	 *
	 * @return void
	 */
	public function testWithoutADispatcherNothingHappens(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new ReferenceEvents(null, $logger))->accepted($this->check());

	}//end testWithoutADispatcherNothingHappens()
}//end class
