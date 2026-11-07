<?php

/**
 * Golden test for HandoffQueueDrainListener::handle().
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\HandoffQueueDrainListener;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCP\App\Events\AppEnableEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Pins the drain calls and every log line for each trigger and summary shape.
 *
 * @covers \OCA\OpenRegister\Listener\HandoffQueueDrainListener
 */
class HandoffQueueDrainListenerGoldenTest extends TestCase {

	/**
	 * The recorded trace.
	 *
	 * @var array<int, array>
	 */
	private array $trace = [];

	private function act(Event $event, mixed $summary): array {
		$service = $this->createMock(HandoffService::class);
		$service->method('drainParked')->willReturnCallback(
			function (...$args) use ($summary): array {
				$this->trace[] = ['drainParked', $args];
				if ($summary instanceof RuntimeException) {
					throw $summary;
				}

				return $summary;
			}
		);

		$logger = new class($this->trace) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->sink[] = ['log', $level, (string) $message, $context];
			}
		};

		(new HandoffQueueDrainListener($service, $logger))->handle($event);
		return $this->trace;
	}

	public function testUnrelatedEventDoesNothing(): void {
		$this->assertSame([], $this->act(new Event(), ['drained' => 1, 'failed' => 1]));
	}

	public function testNothingDrainedIsQuiet(): void {
		$this->assertSame(
			[['drainParked', [null]]],
			$this->act(new AppEnableEvent('x'), ['drained' => 0, 'failed' => 0, 'parked' => 4])
		);
	}

	public function testDrainedIsLoggedForSchemaCreated(): void {
		$summary = ['drained' => 2, 'failed' => 0];
		$this->assertSame(
			[
				['drainParked', [null]],
				[
					'log',
					'info',
					'[HandoffQueueDrainListener] Drained parked handoffs after ' . SchemaCreatedEvent::class,
					['summary' => $summary],
				],
			],
			$this->act(new SchemaCreatedEvent(new Schema()), $summary)
		);
	}

	public function testFailedOnlyIsLoggedForSchemaUpdated(): void {
		$summary = ['drained' => 0, 'failed' => 1];
		$this->assertSame(
			[
				['drainParked', [null]],
				[
					'log',
					'info',
					'[HandoffQueueDrainListener] Drained parked handoffs after ' . SchemaUpdatedEvent::class,
					['summary' => $summary],
				],
			],
			$this->act(new SchemaUpdatedEvent(new Schema(), new Schema()), $summary)
		);
	}

	public function testDrainFailureIsSwallowedAndLogged(): void {
		$this->assertSame(
			[
				['drainParked', [null]],
				[
					'log',
					'warning',
					'[HandoffQueueDrainListener] Drain sweep failed: down',
					['event' => AppEnableEvent::class],
				],
			],
			$this->act(new AppEnableEvent('x'), new RuntimeException('down'))
		);
	}
}
