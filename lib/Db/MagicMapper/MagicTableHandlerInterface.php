<?php

/**
 * MagicTableHandlerInterface: what callers outside the Db component use of MagicTableHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
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

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The methods of MagicTableHandler that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to MagicTableHandler.
 */
interface MagicTableHandlerInterface {

	/**
	 * Check if a specialized table exists for register+schema combination
	 *
	 * This method provides fast existence checking with intelligent caching
	 * to avoid repeated database calls. Cache is automatically invalidated
	 * when tables are created, updated, or dropped.
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return bool True if specialized table exists, false if should use generic storage
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function existsTableForRegisterSchema(Register $register, Schema $schema): bool;

	/**
	 * Get table name for a specific register+schema combination
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return string The table name for the register+schema combination
	 */
	public function getTableNameForRegisterSchema(Register $register, Schema $schema): string;
}//end interface
