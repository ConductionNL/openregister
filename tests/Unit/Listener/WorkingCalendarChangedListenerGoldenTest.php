<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Listener\WorkingCalendarChangedListener}.
 *
 * Pins exactly what the listener queues and logs for every branch: a foreign
 * event, a non-calendar object, a calendar without slug, without version, a
 * queued recompute and a queue that throws. Written against the code before
 * its refactor and left unchanged by it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\BackgroundJob\RecomputeTimersForCalendarJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\WorkingCalendarChangedListener;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * @no-named-arguments
 */
class WorkingCalendarChangedListenerGoldenTest extends TestCase {

	/**
	 * Every queued job, as [class, argument].
	 *
	 * @var list<array{0: mixed, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * Build a working-calendar-shaped object.
	 *
	 * @param array<string, mixed> $fields Schema, object and version overrides.
	 *
	 * @return ObjectEntity
	 */
	private static function calendar(array $fields): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($fields['schema'] ?? 'working-calendar');
		$object->setObject($fields['object'] ?? ['slug' => ' nl-2026 ']);
		$object->setVersion($fields['version'] ?? ' 1.2.0 ');

		return $object;
	}//end calendar()

	/**
	 * Run the listener on one event and return the log lines it wrote.
	 *
	 * @param object $event   The event to dispatch.
	 * @param bool   $failAdd Whether the job queue throws.
	 *
	 * @return list<string>
	 */
	private function dispatch(object $event, bool $failAdd = false): array {
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(
			function ($job, $argument) use ($failAdd): void {
				if ($failAdd === true) {
					throw new RuntimeException('queue down');
				}

				$this->queued[] = [$job, $argument];
			}
		);
		$logger = new class extends AbstractLogger {
			/**
			 * @var list<string>
			 */
			public array $lines = [];

			/**
			 * @param mixed             $level   Level.
			 * @param string|Stringable $message Message.
			 * @param array<mixed>      $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level . ': ' . $message;
			}
		};

		(new WorkingCalendarChangedListener($jobs, $logger))->handle($event);

		return $logger->lines;
	}//end dispatch()

	/**
	 * @return void
	 */
	public function testForeignEventDoesNothing(): void {
		$this->assertSame([], $this->dispatch(new ObjectCreatedEvent(self::calendar([]))));
		$this->assertSame([], $this->queued);
	}//end testForeignEventDoesNothing()

	/**
	 * @return void
	 */
	public function testOtherSchemaDoesNothing(): void {
		$this->assertSame([], $this->dispatch(new ObjectUpdatedEvent(self::calendar(['schema' => 'case']))));
		$this->assertSame([], $this->queued);
	}//end testOtherSchemaDoesNothing()

	/**
	 * @return void
	 */
	public function testMissingSlugWarns(): void {
		$lines = $this->dispatch(new ObjectUpdatedEvent(self::calendar(['object' => ['slug' => '  ']])));
		$this->assertSame(
			['warning: [WorkingCalendarChangedListener] a working calendar was saved with no slug; nothing queued'],
			$lines
		);
		$this->assertSame([], $this->queued);
	}//end testMissingSlugWarns()

	/**
	 * @return void
	 */
	public function testNullObjectDataCountsAsMissingSlug(): void {
		$object = self::calendar([]);
		$object->setObject(null);
		$lines = $this->dispatch(new ObjectUpdatedEvent($object));
		$this->assertSame(
			['warning: [WorkingCalendarChangedListener] a working calendar was saved with no slug; nothing queued'],
			$lines
		);
	}//end testNullObjectDataCountsAsMissingSlug()

	/**
	 * @return void
	 */
	public function testMissingVersionWarns(): void {
		$object = self::calendar([]);
		$object->setVersion(null);
		$lines = $this->dispatch(new ObjectUpdatedEvent($object));
		$this->assertSame(
			['warning: [WorkingCalendarChangedListener] calendar "nl-2026" carries no version; nothing queued'],
			$lines
		);
		$this->assertSame([], $this->queued);
	}//end testMissingVersionWarns()

	/**
	 * @return void
	 */
	public function testQueuesOneRecomputeWithTrimmedKey(): void {
		$lines = $this->dispatch(new ObjectUpdatedEvent(self::calendar(['schema' => 'x-working-calendar-y'])));
		$this->assertSame([], $lines);
		$this->assertSame(
			[[RecomputeTimersForCalendarJob::class, ['slug' => 'nl-2026', 'version' => '1.2.0']]],
			$this->queued
		);
	}//end testQueuesOneRecomputeWithTrimmedKey()

	/**
	 * @return void
	 */
	public function testQueueFailureIsLoggedNotThrown(): void {
		$lines = $this->dispatch(new ObjectUpdatedEvent(self::calendar([])), true);
		$this->assertSame(
			['error: [WorkingCalendarChangedListener] could not queue a recompute for "nl-2026": queue down'],
			$lines
		);
	}//end testQueueFailureIsLoggedNotThrown()
}//end class
