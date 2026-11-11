<?php

/**
 * SaveObjectWriterInterface: the single-object save of SaveObject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCP\IUser;

/**
 * The save role of SaveObjectInterface, for callers that only store one object.
 */
interface SaveObjectWriterInterface {

	/**
	 * Saves an object.
	 *
	 * @param Register|int|string|null $register The register containing the object.
	 * @param Schema|int|string $schema The schema to validate against.
	 * @param array $data The object data to save.
	 * @param string|null $uuid The UUID of the object to update (if updating).
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
	 * @return ObjectEntity The saved object entity.
	 *
	 * @throws Exception If there is an error during save.
	 * @throws ObjectExistsException When $failIfExists is true and the identifier is already taken.
	 * @throws DuplicateBlockedException When the schema declares `onCreate: "block"`, the candidate
	 *         strongly matches a stored object, and the caller may not override.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function saveObject(
		Register|int|string|null $register, Schema|int|string $schema, array $data, ?string $uuid = null,
		bool $_rbac = true, bool $_multitenancy = true, bool $persist = true, bool $silent = false, bool $_validation = true,
		?array $uploadedFiles = null, ?IUser $currentUser = null, bool $failIfExists = false, bool $_unowned = false,
		bool $_dedupOverride = false, ?string $_owner = null
	): ObjectEntity;
}//end interface
