<?php

/**
 * Asks the object's update right, for a controller that changes something hanging on the object.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Trait
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Trait;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\PermissionAsk;

/**
 * Needs the using class to hold `$objectService` and a PermissionHandlerInterface `$permissions`.
 */
trait ChecksObjectUpdateRight
{

	/**
	 * Whether the caller holds the `update` right editing the object requires.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when they do; false too when the schema cannot be read.
	 */
	private function mayUpdate(ObjectEntity $object): bool {
		$schema = $this->objectService->getCurrentSchemaEntity();
		if ($schema === null) {
			return false;
		}

		return $this->permissions->hasPermission(
			ask: (new PermissionAsk(schema: $schema, action: 'update'))
				->askedBy(userId: null, objectOwner: $object->getOwner())
				->about(object: $object, register: null)
		);
	}//end mayUpdate()
}//end trait
