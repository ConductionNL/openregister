<?php

/**
 * Sets one empty property of an object to the calling user.
 *
 * The write is the action's whole effect: one property, the uid taken from the
 * session, never from the request. It passes the save without the caller's
 * `update` right, because the action's own grant has already been checked by
 * the runner; the session user stays the actor, so the audit trail and the
 * object's history name the caller. The write is conditional on the property
 * still being empty (EmptyPropertyClaim), so two callers cannot both take it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\EmptyPropertyClaim;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\PropertyAlreadySetException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IUserSession;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;

/**
 * The write of a self-assign action.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) EmptyPropertyClaim is the request-scoped claim the mapper reads, as DraftStatusPolicy is.
 *
 * @spec openspec/specs/declared-actions/spec.md
 */
class ObjectActionSelfAssign {

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects     Writes the property.
	 * @param IUserSession  $userSession The caller.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Write the caller's uid into the action's property.
	 *
	 * @param ObjectEntity           $object     The object, already checked: the right and an empty property.
	 * @param ObjectActionDefinition $definition The self-assign action.
	 *
	 * @return ObjectEntity The saved object.
	 *
	 * @throws RuntimeException When there is no caller or the action is not a self-assign.
	 * @throws PropertyAlreadySetException When another caller filled the property after it was read as empty.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	public function assign(ObjectEntity $object, ObjectActionDefinition $definition): ObjectEntity {
		$user = $this->userSession->getUser();
		$field = $definition->selfAssignField();
		if ($user === null || $field === null) {
			throw new RuntimeException('A self-assign action needs a signed-in caller.');
		}

		$uuid = (string)$object->getUuid();
		EmptyPropertyClaim::hold(uuid: $uuid, property: $field);
		try {
			return $this->objects->patchObject(
				patch: new ObjectPatch(
					objectId: $uuid,
					data: [$field => $user->getUID()],
					target: new ObjectFindTarget(register: $object->getRegister(), schema: $object->getSchema()),
					currentUser: $user
				),
				_rbac: false
			);
		} finally {
			EmptyPropertyClaim::consume(uuid: $uuid);
		}
	}//end assign()
}//end class
