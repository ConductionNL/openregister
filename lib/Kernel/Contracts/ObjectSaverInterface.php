<?php

/**
 * ObjectSaverInterface: save one object.
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

use OCP\IUser;

/**
 * Creates or updates one object in a register and schema. Implemented by SaveObject.
 */
interface ObjectSaverInterface {

	/**
	 * Saves an object.
	 *
	 * @param RegisterInterface|int|string|null $register The register containing the object.
	 * @param SchemaInterface|int|string $schema The schema to validate against.
	 * @param array $data The object data to save.
	 * @param string|null $uuid The UUID of the object to update (if updating).
	 * @param int|null $folderId The folder ID to set on the object (optional).
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering (default: true).
	 * @param bool $persist Whether to persist the object to database (default: true).
	 * @param bool $silent Whether to skip audit trail creation and events (default: false).
	 * @param bool $_validation Whether to validate the object (default: true).
	 * @param array|null $uploadedFiles Uploaded files array (optional).
	 * @param IUser|null $currentUser Explicit acting user for `@self.folder` access checks; falls back to the session user when null.
	 * @param bool $failIfExists Insert-only: throw ObjectExistsException rather than update when taken (default: false).
	 * @param bool $_unowned Stamp the system identity even when a session exists.
	 * @param bool $_dedupOverride Save through a blocking duplicate match, when the caller is entitled to.
	 * @param string|null $_owner An explicit owner for a new object, such as a draft's scope value (decision 195).
	 *
	 * @return ObjectRowInterface The saved object entity.
	 *
	 * @throws \Exception If there is an error during save.
	 * @throws \OCA\OpenRegister\Exception\ObjectExistsException When $failIfExists is true and the identifier is already taken.
	 * @throws \OCA\OpenRegister\Exception\DuplicateBlockedException When the schema declares `onCreate: "block"`, the candidate
	 *         strongly matches a stored object, and the caller may not override.
	 */
	public function saveObject(
		RegisterInterface|int|string|null $register,
		SchemaInterface|int|string $schema,
		array $data,
		?string $uuid = null,
		?int $folderId = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $persist = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
		bool $failIfExists = false,
		bool $_unowned = false,
		bool $_dedupOverride = false,
		?string $_owner = null,
	): ObjectRowInterface;
}//end interface
