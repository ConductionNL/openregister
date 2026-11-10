<?php

/**
 * MagicRowWrite
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One object written to its register+schema magic table: the object, where
 * it goes, whether the write must claim a fresh identifier, and what the
 * write produced.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicRowWrite {

	/**
	 * Insert-only: refuse instead of updating when the identifier is taken.
	 *
	 * @var boolean
	 */
	public bool $failIfExists = false;

	/**
	 * The generated `_id` when the write inserted; null when it updated or
	 * lost an insert race.
	 *
	 * @var integer|null
	 */
	public ?int $insertedId = null;

	/**
	 * The prepared column values, filled by the save.
	 *
	 * @var array<string, mixed>
	 */
	public array $row = [];

	/**
	 * The uuid the row is written under, filled by the save.
	 *
	 * @var string
	 */
	public string $uuid = '';

	/**
	 * Constructor.
	 *
	 * @param array    $objectData The object data to save.
	 * @param Register $register   The register context.
	 * @param Schema   $schema     The schema for validation and table selection.
	 * @param string   $tableName  The table name to save to.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly array $objectData,
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly string $tableName,
	) {
	}//end __construct()
}//end class
