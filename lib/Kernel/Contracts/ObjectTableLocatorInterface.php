<?php

/**
 * ObjectTableLocatorInterface: finds the table that holds a register and schema's objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Names the per register and schema table that holds objects, and says
 * whether it exists. Implemented by Db\MagicMapper\MagicTableHandler and,
 * through MagicMapperInterface, by Db\MagicMapper.
 */
interface ObjectTableLocatorInterface {

	/**
	 * Get table name for a specific register+schema combination
	 *
	 * @param RegisterInterface $register The register context
	 * @param SchemaInterface $schema The schema context
	 *
	 * @return string The table name for the register+schema combination
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getTableNameForRegisterSchema(RegisterInterface $register, SchemaInterface $schema): string;

	/**
	 * Check if a specialized table exists for register+schema combination
	 *
	 * This method provides fast existence checking with intelligent caching
	 * to avoid repeated database calls. Cache is automatically invalidated
	 * when tables are created, updated, or dropped.
	 *
	 * @param RegisterInterface $register The register context
	 * @param SchemaInterface $schema The schema context
	 *
	 * @return bool True if specialized table exists, false if should use generic storage
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function existsTableForRegisterSchema(RegisterInterface $register, SchemaInterface $schema): bool;
}//end interface
