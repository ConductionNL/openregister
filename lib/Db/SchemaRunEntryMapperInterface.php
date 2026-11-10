<?php

/**
 * SchemaRunEntryMapperInterface: the part of SchemaRunEntryMapper that the schema migration controller uses.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The methods of SchemaRunEntryMapper that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SchemaRunEntryMapperInterface {

	/**
	 * Find entries for a run, optionally filtered by outcome.
	 *
	 * @param int $runId The run id.
	 * @param OutcomeFilter|null $filter Optional outcome and page.
	 *
	 * @return SchemaRunEntry[] The entries.
	 */
	public function findByRun(int $runId, ?OutcomeFilter $filter = null): array;

	/**
	 * Create an entry from an array of values.
	 *
	 * @param array<string, mixed> $data The entry values.
	 *
	 * @return SchemaRunEntry The persisted entry.
	 */
	public function createFromArray(array $data): SchemaRunEntry;
}//end interface
