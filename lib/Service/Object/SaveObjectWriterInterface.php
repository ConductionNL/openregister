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
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCA\OpenRegister\Exception\ObjectExistsException;

/**
 * The save role of SaveObjectInterface, for callers that only store one object.
 */
interface SaveObjectWriterInterface {

	/**
	 * Saves an object.
	 *
	 * @param array             $data          The object data to save.
	 * @param ObjectSaveOptions $options       The register and schema, the UUID of the object to update, the
	 *                                         uploaded files, the save policy and the actor.
	 * @param bool              $_rbac         Whether to apply RBAC checks (default: true).
	 * @param bool              $_multitenancy Whether to apply multitenancy filtering (default: true).
	 * @param bool              $persist       Whether to persist the object to database (default: true).
	 *
	 * @return ObjectEntity The saved object entity.
	 *
	 * @throws Exception If there is an error during save.
	 * @throws InvalidArgumentException When the options name no schema.
	 * @throws ObjectExistsException When the policy is insert-only and the identifier is already taken.
	 * @throws DuplicateBlockedException When the schema declares `onCreate: "block"`, the candidate
	 *         strongly matches a stored object, and the caller may not override.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function saveObject(
		array $data,
		ObjectSaveOptions $options,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $persist = true
	): ObjectEntity;
}//end interface
