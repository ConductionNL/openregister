<?php

/**
 * FavouriteServiceInterface: what callers outside the component use of FavouriteService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Notifications\Db\Watcher;

/**
 * Star and unstar an object for the current user.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FavouriteServiceInterface {

	/**
	 * Star an object: follow it with notifications off, unless already followed.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return Watcher The follow as it now stands.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function star(
		ObjectEntity $object,
		?string $register = null,
		?string $schema = null
	): Watcher;

	/**
	 * Unstar an object: stop following it.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return boolean True when a follow was removed.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function unstar(ObjectEntity $object): bool;
}//end interface
