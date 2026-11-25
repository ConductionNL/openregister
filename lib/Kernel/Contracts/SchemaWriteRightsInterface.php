<?php

/**
 * SchemaWriteRightsInterface: whether the caller may change objects of a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
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

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The platform's `update` verdict on a schema, for a component outside Core to ask.
 */
interface SchemaWriteRightsInterface {

	/**
	 * Whether the signed-in caller may update objects of a schema in a register.
	 *
	 * The answer is the one the object write path gives for `update` on the
	 * schema; an error asking it is thrown to the caller, not turned into a yes.
	 *
	 * @param SchemaInterface $schema   The schema.
	 * @param string          $register The register id the objects are in.
	 *
	 * @return bool True when the caller may update objects of the schema.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
	 */
	public function mayUpdate(SchemaInterface $schema, string $register): bool;
}//end interface
