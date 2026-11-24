<?php

/**
 * SchemaWriteRights: the Core answer to SchemaWriteRightsInterface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaWriteRightsInterface;

/**
 * Asks the permission check for `update` on a schema, scoped to a register.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */
final class SchemaWriteRights implements SchemaWriteRightsInterface {

	/**
	 * Constructor.
	 *
	 * @param PermissionCheckInterface $permissions The platform's verdict on who may change what.
	 */
	public function __construct(
		private readonly PermissionCheckInterface $permissions,
	) {
	}//end __construct()

	/**
	 * Whether the signed-in caller may update objects of a schema in a register.
	 *
	 * A schema this platform did not load answers no: there are no rules to read.
	 *
	 * @param SchemaInterface $schema   The schema.
	 * @param string          $register The register id the objects are in.
	 *
	 * @return bool True when the caller may update objects of the schema.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
	 */
	public function mayUpdate(SchemaInterface $schema, string $register): bool {
		if ($schema instanceof Schema === false) {
			return false;
		}

		return $this->permissions->hasPermission(
			ask: (new PermissionAsk(schema: $schema, action: 'update'))->about(object: null, register: $register)
		);
	}//end mayUpdate()
}//end class
