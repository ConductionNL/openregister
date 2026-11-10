<?php

/**
 * RegisterSchema: a register and a schema that belong together.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The resolved register and schema of one magic table (ADR-011 rule 1).
 *
 * Replaces the `Register $register, Schema $schema` pair. The table name is
 * not derived here: MagicTableHandler::getTableNameForRegisterSchema() owns
 * that rule and also fills the mapper's table-name cache, so it stays a
 * mapper call (MagicTableLocatorInterface::getTableNameForRegisterSchema()).
 *
 * @spec exclude ADR-011 shared parameter object, not a capability of its own
 */
final class RegisterSchema {

	/**
	 * Constructor
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema of the register.
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * The database id of the register.
	 *
	 * @return int The id.
	 *
	 * @spec exclude ADR-011 shared parameter object, not a capability of its own
	 */
	public function registerId(): int {
		return $this->register->getId();
	}//end registerId()

	/**
	 * The database id of the schema.
	 *
	 * @return int The id.
	 *
	 * @spec exclude ADR-011 shared parameter object, not a capability of its own
	 */
	public function schemaId(): int {
		return $this->schema->getId();
	}//end schemaId()
}//end class
