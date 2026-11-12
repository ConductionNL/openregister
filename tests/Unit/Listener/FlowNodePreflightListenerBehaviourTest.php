<?php

/**
 * FlowNodePreflightListenerBehaviourTest — labels, skips and the fail-open path.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\FlowNodePreflightListener;
use OCA\OpenRegister\Service\Flow\FlowNodePreflightInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Listener\FlowNodePreflightListener
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses   \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses   \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses   \OCA\OpenRegister\Event\WriteVetoState
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatingEvent
 */
class FlowNodePreflightListenerBehaviourTest extends TestCase {

	private function entity(array $data, ?string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		if ($uuid !== null) {
			$entity->setUuid($uuid);
		}
		$entity->setObject($data);
		return $entity;
	}

	public function testTheFlowNameLabelsTheRefusal(): void {
		$data = ['name' => 'intake', 'nodes' => [], 'edges' => []];
		$preflight = $this->createMock(FlowNodePreflightInterface::class);
		$preflight->method('looksLikeFlow')->willReturn(true);
		$preflight->expects($this->once())->method('assertRunnable')->with($this->arrayHasKey('nodes'), 'intake')
			->willThrowException(new UnexpectedValueException('cannot run x'));

		$event = new ObjectCreatingEvent($this->entity($data, 'u-1'));
		(new FlowNodePreflightListener($preflight, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['code' => 'flow-node-type-unavailable', 'message' => 'cannot run x'], $event->getErrors());
	}

	public function testAnUnnamedFlowIsLabelledByItsUuid(): void {
		$data = ['nodes' => [], 'edges' => []];
		$preflight = $this->createMock(FlowNodePreflightInterface::class);
		$preflight->method('looksLikeFlow')->willReturn(true);
		$preflight->expects($this->once())->method('assertRunnable')->with($this->arrayHasKey('nodes'), 'u-2');

		$event = new ObjectUpdatingEvent(newObject: $this->entity($data, 'u-2'), oldObject: null);
		(new FlowNodePreflightListener($preflight, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	public function testAnUnnamedFlowWithoutUuidIsLabelledFlow(): void {
		$data = ['nodes' => [], 'edges' => []];
		$preflight = $this->createMock(FlowNodePreflightInterface::class);
		$preflight->method('looksLikeFlow')->willReturn(true);
		$preflight->expects($this->once())->method('assertRunnable')->with($this->arrayHasKey('nodes'), 'flow');

		(new FlowNodePreflightListener($preflight, $this->createMock(LoggerInterface::class)))
			->handle(new ObjectCreatingEvent($this->entity($data, null)));
	}

	public function testANonFlowIsNotAsserted(): void {
		$preflight = $this->createMock(FlowNodePreflightInterface::class);
		$preflight->method('looksLikeFlow')->willReturn(false);
		$preflight->expects($this->never())->method('assertRunnable');

		(new FlowNodePreflightListener($preflight, $this->createMock(LoggerInterface::class)))
			->handle(new ObjectCreatingEvent($this->entity(['title' => 'x'], 'u-4')));
	}

	public function testAnOwnFailureIsLoggedAndTheSaveProceeds(): void {
		$preflight = $this->createMock(FlowNodePreflightInterface::class);
		$preflight->method('looksLikeFlow')->willReturn(true);
		$preflight->method('assertRunnable')->willThrowException(new RuntimeException('registry exploded'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with(
				'[FlowNodePreflightListener] Preflight itself failed, allowing the save: registry exploded',
				$this->callback(static fn (array $ctx): bool => $ctx['exception'] === RuntimeException::class)
			);

		$event = new ObjectCreatingEvent($this->entity(['name' => 'f'], 'u-5'));
		(new FlowNodePreflightListener($preflight, $logger))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	public function testAnUnrelatedEventIsIgnored(): void {
		$preflight = $this->createMock(FlowNodePreflightInterface::class);
		$preflight->expects($this->never())->method('looksLikeFlow');

		$event = new Event();
		(new FlowNodePreflightListener($preflight, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}
}
