<?php

/**
 * TablesTableDeletedListener — retires the managed virtual schema of a deleted
 * Nextcloud Tables table.
 *
 * Nextcloud Tables dispatches `OCA\Tables\Event\TableDeletedEvent` when a table
 * is removed. This listener reads the deleted table's id from the event (via
 * guarded magic getters, so it never assumes a concrete Tables type) and asks
 * {@see TablesSchemaSyncService::retireByTableId()} to remove/retire the bound
 * schema immediately, rather than waiting for the next `occ openregister:tables:
 * sync`. Registration is guarded by `class_exists` in Application so boot never
 * fatals on an instance without Tables installed; the handler itself is a no-op
 * for any event that carries no resolvable table id.
 *
 * Scope note (design D8): the in-scope schema-lifecycle event is
 * `TableDeletedEvent`; ownership transfer and row-level events are handled by the
 * next sync, not here.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Retires the bound virtual schema when a Tables table is deleted.
 *
 * @template-implements IEventListener<Event>
 */
class TablesTableDeletedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param TablesSchemaSyncService $syncService Schema retirement logic.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TablesSchemaSyncService $syncService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a Tables `TableDeletedEvent` by retiring the bound schema.
	 *
	 * @param Event $event The Tables table-deleted event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function handle(Event $event): void {
		$tableId = $this->tableIdFromEvent(event: $event);
		if ($tableId === null) {
			return;
		}

		try {
			if ($this->syncService->retireByTableId(tableId: $tableId) === true) {
				$this->logger->info('[ObjectSource:tables] retired virtual schema for deleted table ' . $tableId);
			}
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:tables] could not retire schema for table ' . $tableId . ': ' . $e->getMessage());
		}
	}//end handle()

	/**
	 * Extract the deleted table's id from the event, tolerant of its shape.
	 *
	 * Tries `getTable()->getId()` then a direct `getTableId()`, each guarded so an
	 * unexpected event shape yields null rather than a fatal.
	 *
	 * @param object $event The event.
	 *
	 * @return int|null The table id, or null when unresolvable.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function tableIdFromEvent(object $event): ?int {
		try {
			$table = $event->getTable();
			if (is_object($table) === true) {
				$id = $table->getId();
				if (is_scalar($id) === true) {
					return (int)$id;
				}
			}
		} catch (Throwable $e) {
			// Fall through to the flat getter.
		}

		try {
			$id = $event->getTableId();
			if (is_scalar($id) === true) {
				return (int)$id;
			}
		} catch (Throwable $e) {
			return null;
		}

		return null;
	}//end tableIdFromEvent()
}//end class
