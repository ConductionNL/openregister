<?php

/**
 * SchemaReadCheckInterface: whether the caller may read a schema's objects in a register.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
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

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The schema-level read right of the current caller, for callers outside Core.
 *
 * Implemented in Core over the permission check, with the same answer as
 * `hasPermission(schema, 'read', register: ...)`.
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */
interface SchemaReadCheckInterface {

	/**
	 * Whether the current caller may read the schema's objects in the register.
	 *
	 * @param SchemaInterface $schema   The schema.
	 * @param string          $register The register id.
	 *
	 * @return bool True when the read is allowed.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function canReadSchema(SchemaInterface $schema, string $register): bool;
}//end interface
