<?php

/**
 * OpenRegister get object sources: where a virtual schema's objects come from, and where a missing source is reported
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use Psr\Log\LoggerInterface;

/**
 * Get object sources: where a virtual schema's objects come from, and where a missing source is reported.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class GetObjectSources {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectSourceRegistry          $objectSourceRegistry Registry of object-source providers (virtual schemas).
	 * @param LoggerInterface               $logger               Logger for object-source delegation warnings.
	 * @param PermissionCheckInterface|null $permissions          Answers whether the caller may read a sourced object;
	 *                                                            null leaves the read check to the caller.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md#requirement-read-access-parity-and-no-enumeration-oracle
	 */
	public function __construct(
		public readonly ObjectSourceRegistry $objectSourceRegistry,
		public readonly LoggerInterface $logger,
		public readonly ?PermissionCheckInterface $permissions=null,
	) {
	}//end __construct()

	/**
	 * Whether the signed-in caller is refused the read of an object a source answered.
	 *
	 * Asked like the read of a native object: the schema's read rule, with the
	 * object and its owner. Without a permission check wired nothing is refused
	 * here, and the caller's own check applies as before.
	 *
	 * @param Schema       $schema The schema the object belongs to.
	 * @param ObjectEntity $object The object the source answered.
	 *
	 * @return bool True when the caller may not read the object.
	 *
	 * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md#requirement-read-access-parity-and-no-enumeration-oracle
	 */
	public function refusesRead(Schema $schema, ObjectEntity $object): bool {
		if ($this->permissions === null) {
			return false;
		}

		$ask = (new PermissionAsk(schema: $schema, action: 'read'))
			->askedBy(userId: null, objectOwner: $object->getOwner())
			->about(object: $object, register: null);

		return $this->permissions->hasPermission(ask: $ask, _rbac: true) === false;
	}//end refusesRead()
}//end class
