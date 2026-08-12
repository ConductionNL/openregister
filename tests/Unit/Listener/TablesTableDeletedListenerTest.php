<?php

/**
 * Unit tests for TablesTableDeletedListener.
 *
 * Covers that a Tables `TableDeletedEvent` (represented here by a synthetic event
 * object, since the real Tables event class is absent under the CI runner) causes
 * the bound managed schema to be retired via {@see TablesSchemaSyncService}, and
 * that an event carrying no resolvable table id is a no-op.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\TablesTableDeletedListener;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for TablesTableDeletedListener.
 */
class TablesTableDeletedListenerTest extends TestCase {

	/**
	 * A TableDeletedEvent carrying a table retires that table's schema.
	 *
	 * @return void
	 */
	public function testHandleRetiresSchema(): void {
		$syncService = $this->createMock(TablesSchemaSyncService::class);
		$syncService->expects($this->once())->method('retireByTableId')->with(5)->willReturn(true);

		$listener = new TablesTableDeletedListener($syncService, new NullLogger());
		$listener->handle($this->eventWithTable(tableId: 5));
	}//end testHandleRetiresSchema()

	/**
	 * An event with no resolvable table id is a no-op.
	 *
	 * @return void
	 */
	public function testHandleIgnoresUnresolvableEvent(): void {
		$syncService = $this->createMock(TablesSchemaSyncService::class);
		$syncService->expects($this->never())->method('retireByTableId');

		$listener = new TablesTableDeletedListener($syncService, new NullLogger());
		$listener->handle(new Event());
	}//end testHandleIgnoresUnresolvableEvent()

	/**
	 * Build a synthetic event exposing getTable()->getId() like Tables' event.
	 *
	 * @param int $tableId The deleted table id.
	 *
	 * @return Event The synthetic event.
	 */
	private function eventWithTable(int $tableId): Event {
		$table = new class($tableId) {
			/**
			 * @param int $id The table id.
			 */
			public function __construct(
				private int $id,
			) {
			}

			/**
			 * @return int The table id.
			 */
			public function getId(): int {
				return $this->id;
			}
		};

		return new class($table) extends Event {
			/**
			 * @param object $table The table entity.
			 */
			public function __construct(
				private object $table,
			) {
				parent::__construct();
			}

			/**
			 * @return object The table entity.
			 */
			public function getTable(): object {
				return $this->table;
			}
		};
	}//end eventWithTable()
}//end class
