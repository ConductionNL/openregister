<?php

/**
 * RegisterServiceInterface: the part of RegisterService that the registers controller and the MCP register tools use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\Register;

/**
 * The methods of RegisterService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface RegisterServiceInterface {

	/**
	 * Find a register by ID with optional extensions
	 *
	 * Retrieves register entity by ID with optional extended data.
	 * Extensions can include related entities like schemas, objects, etc.
	 *
	 * @param int|string $id The ID of the register to find.
	 * @param array<string> $_extend Optional array of extension names to include.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return Register The found register entity
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If register not found
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple registers found (should not happen)
	 * @throws \OCP\DB\Exception If database error occurs
	 *
	 * @spec exclude Pure pass-through to RegisterMapper::find; no business logic.
	 */
	public function find(int|string $id, array $_extend = [], bool $_multitenancy = true): Register;

	/**
	 * Find all registers with optional filters and extensions
	 *
	 * Retrieves all registers matching optional filters and search conditions.
	 * Supports pagination via limit and offset parameters.
	 * Extensions can include related entities like schemas, objects, etc.
	 *
	 * @param int|null $limit Maximum number of results to return (null = no limit)
	 * @param int|null $offset Number of results to skip for pagination
	 * @param array<string, mixed>|null $filters Filters to apply (e.g., ['organisation_id' => 1])
	 * @param array<string, mixed>|null $searchConditions Search conditions for advanced filtering
	 * @param array<string, mixed>|null $searchParams Search parameters for query building
	 * @param array<string> $_extend Optional extensions to include in results.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return Register[] Array of found register entities
	 *
	 * @psalm-return array<Register>
	 *
	 * @spec exclude Pure pass-through to RegisterMapper::findAll; no business logic.
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = [],
		?array $_extend = [],
		bool $_multitenancy = true,
	): array;

	/**
	 * Create a new register from array data.
	 *
	 * @param array $data The data to create the register from
	 *
	 * @return Register The created register
	 *
	 * @throws Exception If register creation fails
	 *
	 * @spec openspec/specs/file-actions/spec.md#object-and-register-folder-provisioning
	 *   (after mapper create, assigns the active/default organisation for multi-tenancy
	 *   and provisions the register's backing folder)
	 */
	public function createFromArray(array $data): Register;

	/**
	 * Update an existing register from array data.
	 *
	 * @param int $id The ID of the register to update
	 * @param array $data The new data for the register
	 *
	 * @return Register The updated register
	 *
	 * @throws Exception If register update fails
	 *
	 * @spec openspec/specs/file-actions/spec.md#object-and-register-folder-provisioning
	 *   (after mapper update, ensures the register's backing folder exists,
	 *   healing legacy null/string folder properties)
	 */
	public function updateFromArray(int $id, array $data): Register;

	/**
	 * Delete a register, then remove its folder.
	 *
	 * The folder goes only after the row is gone: the mapper refuses a register
	 * that still has objects, and that refusal must leave the folder in place.
	 * A folder that cannot be removed is logged, not raised, because the
	 * register itself is already deleted (openregister#4107).
	 *
	 * @param Register $register The register to delete
	 *
	 * @return Register The deleted register
	 *
	 * @throws Exception If register has attached objects or deletion fails
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function delete(Register $register): Register;
}//end interface
