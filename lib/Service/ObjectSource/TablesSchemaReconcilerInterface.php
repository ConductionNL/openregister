<?php

/**
 * TablesSchemaReconcilerInterface: what callers outside the Service component use of TablesSchemaSyncService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * Reconciles the managed virtual schemas with the Tables tables that exist.
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */
interface TablesSchemaReconcilerInterface {

	/**
	 * Seed a schema for every new table and retire the ones whose table is gone.
	 *
	 * @param array<int, array<string, mixed>> $tables The table descriptors ({id, title, columns}).
	 *
	 * @return array{seeded: int, retired: int, skipped: int} Reconcile statistics.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function reconcile(array $tables): array;

	/**
	 * Retire the managed schema of one deleted table.
	 *
	 * @param int $tableId The id of the deleted table.
	 *
	 * @return bool True when a schema was retired.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function retireByTableId(int $tableId): bool;
}//end interface
