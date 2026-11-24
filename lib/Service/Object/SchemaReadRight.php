<?php
/**
 * SchemaReadRight: the Kernel schema read-right port over the object permission check.
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaReadRightInterface;

/**
 * Answers {@see SchemaReadRightInterface} with PermissionCheckInterface::hasPermission() with a read PermissionAsk.
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-is-limited-to-object-events-in-one-scope
 */
final class SchemaReadRight implements SchemaReadRightInterface {

	/**
	 * Constructor.
	 *
	 * @param PermissionCheckInterface $permissions The object permission check.
	 */
	public function __construct(
		private readonly PermissionCheckInterface $permissions,
	) {
	}//end __construct()

	/**
	 * Whether the user may read the schema's objects; a schema that is not a stored Schema is refused.
	 *
	 * @param SchemaInterface $schema   The schema.
	 * @param string          $userId   The user.
	 * @param string|null     $register The register id.
	 *
	 * @return bool
	 */
	public function mayRead(SchemaInterface $schema, string $userId, ?string $register): bool {
		if ($schema instanceof Schema === false) {
			return false;
		}

		return $this->permissions->hasPermission(
			ask: (new PermissionAsk(schema: $schema, action: 'read'))
				->askedBy(userId: $userId, objectOwner: null)
				->about(object: null, register: $register)
		);
	}//end mayRead()
}//end class
