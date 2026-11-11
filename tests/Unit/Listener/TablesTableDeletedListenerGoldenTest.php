<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Listener\TablesTableDeletedListener}.
 *
 * Pins which table id the listener resolves from every event shape it
 * tolerates (nested getter, flat getter, throwing or non-scalar getters,
 * neither) and what it logs for a retired, an unbound and a failing retire.
 * Written against the code before its refactor and left unchanged by it.
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

use OCA\OpenRegister\Listener\TablesTableDeletedListener;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * @no-named-arguments
 */
class TablesTableDeletedListenerGoldenTest extends TestCase {

	/**
	 * The table ids the listener asked to retire.
	 *
	 * @var list<int>
	 */
	private array $retired = [];

	/**
	 * An event whose getters answer from closures.
	 *
	 * @param callable|null $table   Answers getTable(), or null to make it throw.
	 * @param callable|null $tableId Answers getTableId(), or null to make it throw.
	 *
	 * @return Event
	 */
	private static function event(?callable $table, ?callable $tableId): Event {
		return new class($table, $tableId) extends Event {
			/**
			 * @param callable|null $table   Answers getTable().
			 * @param callable|null $tableId Answers getTableId().
			 */
			public function __construct(
				private $table,
				private $tableId,
			) {
				parent::__construct();
			}

			/**
			 * @return mixed
			 */
			public function getTable(): mixed {
				if ($this->table === null) {
					throw new \Error('no getTable');
				}

				return ($this->table)();
			}

			/**
			 * @return mixed
			 */
			public function getTableId(): mixed {
				if ($this->tableId === null) {
					throw new \Error('no getTableId');
				}

				return ($this->tableId)();
			}
		};
	}//end event()

	/**
	 * A table object whose getId() answers the given value.
	 *
	 * @param mixed $id The id.
	 *
	 * @return object
	 */
	private static function table(mixed $id): object {
		return new class($id) {
			/**
			 * @param mixed $id The id.
			 */
			public function __construct(private mixed $id) {
			}

			/**
			 * @return mixed
			 */
			public function getId(): mixed {
				return $this->id;
			}
		};
	}//end table()

	/**
	 * Dispatch one event and return the log lines written.
	 *
	 * @param object $event   The event.
	 * @param mixed  $outcome True/false for retire's answer, or a Throwable to throw.
	 *
	 * @return list<string>
	 */
	private function dispatch(object $event, mixed $outcome = true): array {
		$sync = $this->createMock(TablesSchemaSyncService::class);
		$sync->method('retireByTableId')->willReturnCallback(
			function (int $tableId) use ($outcome): bool {
				$this->retired[] = $tableId;
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				return $outcome;
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

		(new TablesTableDeletedListener($sync, $logger))->handle($event);

		return $logger->lines;
	}//end dispatch()

	/**
	 * @return void
	 */
	public function testNestedTableIdWinsAndIsCast(): void {
		$lines = $this->dispatch(self::event(fn () => self::table('17'), fn () => 99));
		$this->assertSame([17], $this->retired);
		$this->assertSame(['info: [ObjectSource:tables] retired virtual schema for deleted table 17'], $lines);
	}//end testNestedTableIdWinsAndIsCast()

	/**
	 * @return void
	 */
	public function testThrowingNestedGetterFallsBackToFlat(): void {
		$this->dispatch(self::event(null, fn () => 5));
		$this->assertSame([5], $this->retired);
	}//end testThrowingNestedGetterFallsBackToFlat()

	/**
	 * @return void
	 */
	public function testNonObjectTableFallsBackToFlat(): void {
		$this->dispatch(self::event(fn () => 'not-a-table', fn () => '6'));
		$this->assertSame([6], $this->retired);
	}//end testNonObjectTableFallsBackToFlat()

	/**
	 * @return void
	 */
	public function testNonScalarNestedIdFallsBackToFlat(): void {
		$this->dispatch(self::event(fn () => self::table([1]), fn () => 7.9));
		$this->assertSame([7], $this->retired);
	}//end testNonScalarNestedIdFallsBackToFlat()

	/**
	 * @return void
	 */
	public function testBooleanIdIsScalarAndCast(): void {
		$this->dispatch(self::event(fn () => self::table(true), null));
		$this->assertSame([1], $this->retired);
	}//end testBooleanIdIsScalarAndCast()

	/**
	 * @return void
	 */
	public function testNonScalarFlatIdIsIgnored(): void {
		$lines = $this->dispatch(self::event(null, fn () => null));
		$this->assertSame([], $this->retired);
		$this->assertSame([], $lines);
	}//end testNonScalarFlatIdIsIgnored()

	/**
	 * @return void
	 */
	public function testNeitherGetterIsIgnored(): void {
		$lines = $this->dispatch(self::event(null, null));
		$this->assertSame([], $this->retired);
		$this->assertSame([], $lines);
	}//end testNeitherGetterIsIgnored()

	/**
	 * @return void
	 */
	public function testPlainEventIsIgnored(): void {
		$lines = $this->dispatch(new Event());
		$this->assertSame([], $this->retired);
		$this->assertSame([], $lines);
	}//end testPlainEventIsIgnored()

	/**
	 * @return void
	 */
	public function testUnboundTableLogsNothing(): void {
		$lines = $this->dispatch(self::event(fn () => self::table(3), null), false);
		$this->assertSame([3], $this->retired);
		$this->assertSame([], $lines);
	}//end testUnboundTableLogsNothing()

	/**
	 * @return void
	 */
	public function testRetireFailureIsLoggedNotThrown(): void {
		$lines = $this->dispatch(self::event(fn () => self::table(4), null), new RuntimeException('db gone'));
		$this->assertSame(['warning: [ObjectSource:tables] could not retire schema for table 4: db gone'], $lines);
	}//end testRetireFailureIsLoggedNotThrown()
}//end class
