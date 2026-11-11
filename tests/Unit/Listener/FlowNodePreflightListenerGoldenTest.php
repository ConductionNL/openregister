<?php

/**
 * Golden test for FlowNodePreflightListener::handle().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\FlowNodePreflightListener;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use UnexpectedValueException;

/**
 * Pins every observable effect of handle(): which preflight calls are made with
 * which arguments, the errors set on the event, propagation and the log calls.
 *
 * @covers \OCA\OpenRegister\Listener\FlowNodePreflightListener
 */
class FlowNodePreflightListenerGoldenTest extends TestCase {

	/**
	 * Recorded preflight calls.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * Recorded log calls.
	 *
	 * @var array<int, array>
	 */
	private array $logs = [];

	private function listener(bool $looksLikeFlow, ?\Throwable $failure): FlowNodePreflightListener {
		$preflight = $this->createMock(FlowNodePreflight::class);
		$preflight->method('looksLikeFlow')->willReturnCallback(
			function (array $data) use ($looksLikeFlow): bool {
				$this->calls[] = ['looksLikeFlow', $data];
				return $looksLikeFlow;
			}
		);
		$preflight->method('assertRunnable')->willReturnCallback(
			function (array $flow, string $label) use ($failure): void {
				$this->calls[] = ['assertRunnable', $flow, $label];
				if ($failure !== null) {
					throw $failure;
				}
			}
		);

		$logger = new class($this->logs) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, $message, array $context = []): void {
				unset($context['line'], $context['file']);
				$this->sink[] = [$level, (string) $message, $context];
			}
		};

		return new FlowNodePreflightListener($preflight, $logger);
	}

	private function entity(array $data, ?string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		if ($uuid !== null) {
			$entity->setUuid($uuid);
		}

		$entity->setObject($data);
		return $entity;
	}

	private function record(Event $event): array {
		$errors = null;
		$stopped = $event->isPropagationStopped();
		if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent) {
			$errors = $event->getErrors();
		}

		return ['calls' => $this->calls, 'logs' => $this->logs, 'errors' => $errors, 'stopped' => $stopped];
	}

	public function testUnrelatedEventIsIgnored(): void {
		$event = new Event();
		$this->listener(true, new UnexpectedValueException('x'))->handle($event);
		$this->assertSame(['calls' => [], 'logs' => [], 'errors' => null, 'stopped' => false], $this->record($event));
	}

	public function testEmptyDataIsIgnored(): void {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn([]);
		$event = new ObjectCreatingEvent($entity);
		$this->listener(true, new UnexpectedValueException('x'))->handle($event);
		$this->assertSame(['calls' => [], 'logs' => [], 'errors' => [], 'stopped' => false], $this->record($event));
	}

	public function testNonFlowStopsAfterLooksLikeFlow(): void {
		$event = new ObjectCreatingEvent($this->entity(['a' => 1], 'u-1'));
		$this->listener(false, new UnexpectedValueException('x'))->handle($event);
		$this->assertSame(
			['calls' => [['looksLikeFlow', ['id' => 'u-1', 'a' => 1]]], 'logs' => [], 'errors' => [], 'stopped' => false],
			$this->record($event)
		);
	}

	public function testCreateRefusalUsesNameAsLabel(): void {
		$event = new ObjectCreatingEvent($this->entity(['name' => 'my-flow', 'nodes' => []], 'u-1'));
		$data  = ['id' => 'u-1', 'name' => 'my-flow', 'nodes' => []];
		$this->listener(true, new UnexpectedValueException('cannot run'))->handle($event);
		$this->assertSame(
			[
				'calls' => [['looksLikeFlow', $data], ['assertRunnable', $data, 'my-flow']],
				'logs' => [],
				'errors' => ['code' => 'flow-node-type-unavailable', 'message' => 'cannot run'],
				'stopped' => true,
			],
			$this->record($event)
		);
	}

	public function testUpdateUsesNewObjectAndUuidLabel(): void {
		$data  = ['id' => 'u-new', 'nodes' => [1]];
		$event = new ObjectUpdatingEvent(
			$this->entity(['nodes' => [1]], 'u-new'),
			$this->entity(['name' => 'old', 'nodes' => [2]], 'u-old')
		);
		$this->listener(true, null)->handle($event);
		$this->assertSame(
			[
				'calls' => [['looksLikeFlow', $data], ['assertRunnable', $data, 'u-new']],
				'logs' => [],
				'errors' => [],
				'stopped' => false,
			],
			$this->record($event)
		);
	}

	public function testMissingUuidFallsBackToFlowLabel(): void {
		$event = new ObjectCreatingEvent($this->entity(['nodes' => [1]], null));
		$this->listener(true, null)->handle($event);
		$this->assertSame('flow', $this->record($event)['calls'][1][2]);
	}

	public function testInternalFailureIsLoggedAndSaveProceeds(): void {
		$event = new ObjectCreatingEvent($this->entity(['name' => 'n', 'nodes' => []], 'u-1'));
		$data  = ['id' => 'u-1', 'name' => 'n', 'nodes' => []];
		$this->listener(true, new RuntimeException('boom'))->handle($event);
		$this->assertSame(
			[
				'calls' => [['looksLikeFlow', $data], ['assertRunnable', $data, 'n']],
				'logs' => [
					[
						'warning',
						'[FlowNodePreflightListener] Preflight itself failed, allowing the save: boom',
						['exception' => RuntimeException::class],
					],
				],
				'errors' => [],
				'stopped' => false,
			],
			$this->record($event)
		);
	}
}
