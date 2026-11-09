<?php

/**
 * CommentsEntityListenerBehaviourTest — the registered existence check answers truthfully.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Listener\CommentsEntityListener;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Comments\CommentsEntityEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Listener\CommentsEntityListener
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class CommentsEntityListenerBehaviourTest extends TestCase {

	/**
	 * Capture the existence callback the listener registers.
	 *
	 * @param MagicMapperInterface $mapper The mapper.
	 * @param LoggerInterface      $logger The logger.
	 *
	 * @return callable
	 */
	private function registeredCallback(MagicMapperInterface $mapper, LoggerInterface $logger): callable {
		$captured = null;
		$event = $this->createMock(CommentsEntityEvent::class);
		$event->expects($this->once())
			->method('addEntityCollection')
			->with(
				'openregister',
				$this->callback(
					static function ($cb) use (&$captured): bool {
						$captured = $cb;
						return is_callable($cb);
					}
				)
			);

		(new CommentsEntityListener($mapper, $logger))->handle($event);

		return $captured;
	}

	public function testAnExistingObjectIsReportedAsExisting(): void {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->expects($this->once())->method('find')->with('uuid-1')->willReturn(new ObjectEntity());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('debug');

		$callback = $this->registeredCallback($mapper, $logger);

		$this->assertTrue($callback('uuid-1'));
	}

	public function testAMissingObjectIsReportedAsAbsentAndLogged(): void {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('debug')
			->with('Object UUID not found for comments entity: uuid-2', $this->arrayHasKey('exception'));

		$callback = $this->registeredCallback($mapper, $logger);

		$this->assertFalse($callback('uuid-2'));
	}

	public function testAnUnrelatedEventTouchesNothing(): void {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->expects($this->never())->method('find');
		$event = $this->createMock(Event::class);

		(new CommentsEntityListener($mapper, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}
}
