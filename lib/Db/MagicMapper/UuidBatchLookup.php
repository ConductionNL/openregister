<?php

/**
 * UuidBatchLookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * One batched existence probe: the uuids to look for in a register+schema magic table and whether soft-deleted rows count.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class UuidBatchLookup {

	/**
	 * Constructor.
	 *
	 * @param array $uuids          The uuids to look for (array<int, string>).
	 * @param bool  $includeDeleted Whether to include soft-deleted rows.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly array $uuids,
		public readonly bool $includeDeleted=false,
	) {
	}//end __construct()
}//end class
