<?php

/**
 * MagicUpsertBatch
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
 * One bulk upsert into a register+schema magic table: the rows to write and whether the caller needs the stored state they replace.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicUpsertBatch {

	/**
	 * Constructor.
	 *
	 * @param array $objects             The object rows to upsert.
	 * @param bool  $needsPreUpdateState Whether to read the pre-update state of updated rows.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly array $objects,
		public readonly bool $needsPreUpdateState=true,
	) {
	}//end __construct()
}//end class
