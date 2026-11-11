<?php

/**
 * BulkUpsertCommand
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

use OCA\OpenRegister\Db\RegisterSchema;

/**
 * One bulk upsert into a register+schema magic table.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkUpsertCommand {

	/**
	 * Constructor.
	 *
	 * @param array          $objects             The objects, in standard format.
	 * @param RegisterSchema $table               The register and schema the rows belong to.
	 * @param string         $tableName           The bare (unprefixed) target table name.
	 * @param bool           $needsPreUpdateState Whether anything downstream reads the pre-update
	 *                                            rows (audit changeset or update event); false
	 *                                            narrows the existence read to the uuid.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $needsPreUpdateState reports a FACT about the
	 * caller (whether anything will read the pre-update rows) rather than switching between two
	 * behaviours; it is carried to the one statement that varies on it.
	 */
	public function __construct(
		public readonly array $objects,
		public readonly RegisterSchema $table,
		public readonly string $tableName,
		public readonly bool $needsPreUpdateState = true,
	) {
	}//end __construct()

	/**
	 * The run of one chunk of this upsert.
	 *
	 * @param int $chunkNumber The 1-based chunk number, for logging.
	 *
	 * @return MagicUpsertChunkRun The chunk run.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function chunkRun(int $chunkNumber): MagicUpsertChunkRun {
		$run = new MagicUpsertChunkRun(tableName: $this->tableName, chunkNumber: $chunkNumber);
		$run->needsPreUpdateState = $this->needsPreUpdateState;

		return $run;
	}//end chunkRun()
}//end class
