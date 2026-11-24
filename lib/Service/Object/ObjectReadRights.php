<?php
/**
 * ObjectReadRights: the schema-level read check for a named user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectReadRightsInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;

/**
 * Asks the permission handler for `read` as the given user, with the object's owner and RBAC on.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-a-watcher-who-lost-access-is-not-told
 */
class ObjectReadRights implements ObjectReadRightsInterface {

	/**
	 * Constructor.
	 *
	 * @param PermissionHandlerInterface $permissions The one RBAC evaluator.
	 */
	public function __construct(
		private readonly PermissionHandlerInterface $permissions,
	) {
	}//end __construct()

	/**
	 * Whether the user may read the object; false for an object or schema Core does not store.
	 *
	 * @param string                $userId The user.
	 * @param ObjectEntityInterface $object The object.
	 * @param SchemaInterface       $schema The object's schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-a-watcher-who-lost-access-is-not-told
	 */
	public function mayRead(string $userId, ObjectEntityInterface $object, SchemaInterface $schema): bool {
		if (($object instanceof ObjectEntity) === false || ($schema instanceof Schema) === false) {
			return false;
		}

		$ask = (new PermissionAsk(schema: $schema, action: 'read'))
			->askedBy(userId: $userId, objectOwner: $object->getOwner())
			->about(object: $object, register: null);

		return $this->permissions->hasPermission(ask: $ask, _rbac: true) === true;
	}//end mayRead()
}//end class
