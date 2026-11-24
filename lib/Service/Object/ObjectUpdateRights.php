<?php
/**
 * ObjectUpdateRights: whether a user may update one object.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectUpdateRightsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;

/**
 * Loads the object as a lookup and asks the schema-level permission check for `update`.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
 *
 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
 */
class ObjectUpdateRights implements ObjectUpdateRightsInterface {

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects     Loads the object under the current identity's RBAC.
	 * @param SchemaMapperInterface     $schemas     Resolves the object's schema.
	 * @param PermissionCheckInterface  $permissions Answers the schema-level `update` check.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly SchemaMapperInterface $schemas,
		private readonly PermissionCheckInterface $permissions,
	) {
	}//end __construct()

	/**
	 * Whether the user may update the object with this uuid.
	 *
	 * @param string $uuid   The object uuid.
	 * @param string $userId The user the check is for.
	 *
	 * @return bool True when the object loads and the user holds `update` on it.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function mayUpdateObject(string $uuid, string $userId): bool {
		// A lookup for the check, not a person opening the object: no recent read is recorded.
		$object = WriteCause::asLookup(fn () => $this->objects->find(id: $uuid));
		if (($object instanceof ObjectEntity) === false) {
			return false;
		}

		$schema = $this->schemas->find((string)$object->getSchema());

		return $this->permissions->hasPermission(
			ask: (new PermissionAsk(schema: $schema, action: 'update'))
				->askedBy(userId: $userId, objectOwner: $object->getOwner())
				->about(object: $object, register: null)
		);
	}//end mayUpdateObject()
}//end class
