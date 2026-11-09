<?php

/**
 * RevertHandlerInterface: the part of RevertHandler that the revert controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The methods of RevertHandler that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface RevertHandlerInterface {

	/**
	 * Revert an object to a previous state
	 *
	 * @param string $register The register identifier
	 * @param string $schema The schema identifier
	 * @param string $id The object ID
	 * @param RevertPoint $point The point to revert to, and whether to overwrite the version
	 *
	 * @return ObjectEntity The reverted object
	 *
	 * @throws DoesNotExistException If object not found
	 * @throws NotAuthorizedException If user not authorized
	 * @throws LockedException If object is locked
	 * @throws ObjectStateWriteException If the object is archived or frozen
	 * @throws ValidationException If the restored data fails the current schema
	 * @throws \Exception If reversion fails
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function revert(string $register, string $schema, string $id, RevertPoint $point): ObjectEntity;
}//end interface
