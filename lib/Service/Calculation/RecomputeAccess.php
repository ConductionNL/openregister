<?php

/**
 * Whether a user may update an object, as the recompute asks it per object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use Throwable;

/**
 * The object's update rule decides; a check that throws refuses.
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */
class RecomputeAccess {
	/**
	 * Constructor.
	 *
	 * @param PermissionCheckInterface $permissions The object and schema permission check.
	 */
	public function __construct(
		private readonly PermissionCheckInterface $permissions,
	) {
	}//end __construct()

	/**
	 * The update check of one user over one schema's objects.
	 *
	 * @param Schema      $schema The schema the objects belong to.
	 * @param string|null $userId The user; null for the signed-in caller.
	 *
	 * @return Closure(ObjectEntity): bool
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	public function forUser(Schema $schema, ?string $userId): Closure {
		return function (ObjectEntity $object) use ($schema, $userId): bool {
			try {
				return $this->permissions->hasPermission(
					schema: $schema,
					action: 'update',
					userId: $userId,
					objectOwner: $object->getOwner(),
					_rbac: true,
					object: $object
				);
			} catch (Throwable $e) {
				return false;
			}
		};
	}//end forUser()
}//end class
