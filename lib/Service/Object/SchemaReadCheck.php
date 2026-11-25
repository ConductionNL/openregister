<?php

/**
 * SchemaReadCheck: the Core answer to the Kernel schema read port.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaReadCheckInterface;

/**
 * Answers the schema read port with the permission check's own read verdict.
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */
final class SchemaReadCheck implements SchemaReadCheckInterface {

	/**
	 * Constructor.
	 *
	 * @param PermissionCheckInterface $permissions The permission check.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function __construct(
		private readonly PermissionCheckInterface $permissions,
	) {
	}//end __construct()

	/**
	 * Whether the current caller may read the schema's objects in the register.
	 *
	 * A schema that is not a stored Schema entity is refused: the permission
	 * check only judges stored schemas, and the port fails closed.
	 *
	 * @param SchemaInterface $schema   The schema.
	 * @param string          $register The register id.
	 *
	 * @return bool True when the read is allowed.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function canReadSchema(SchemaInterface $schema, string $register): bool {
		if ($schema instanceof Schema === false) {
			return false;
		}

		return $this->permissions->hasPermission(
			ask: (new PermissionAsk(schema: $schema, action: 'read'))->about(object: null, register: $register)
		) === true;
	}//end canReadSchema()
}//end class
