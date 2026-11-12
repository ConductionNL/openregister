<?php

/**
 * MagicObjectLookup
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
 * One single-object lookup in a register+schema magic table: the identifier
 * the caller sent, the table it is looked up in and the register and schema
 * the found row belongs to.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicObjectLookup {

	/**
	 * The object identifier (ID, UUID, slug, or URI).
	 *
	 * @var string|integer
	 */
	public string|int $identifier = '';

	/**
	 * The magic table name.
	 *
	 * @var string
	 */
	public string $tableName = '';

	/**
	 * Constructor.
	 *
	 * @param Register $register The register the table belongs to.
	 * @param Schema   $schema   The schema the table belongs to.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * Set what is looked up, and where.
	 *
	 * @param string|int $identifier The object identifier.
	 * @param string     $tableName  The magic table name.
	 *
	 * @return self This lookup.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function lookingFor(string|int $identifier, string $tableName): self {
		$this->identifier = $identifier;
		$this->tableName  = $tableName;
		return $this;
	}//end lookingFor()
}//end class
