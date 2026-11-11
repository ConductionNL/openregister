<?php

/**
 * OpenRegister object derivation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A derive: the object a new one comes out of, and the register and schema
 * the new one is saved into.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class ObjectDerivation {
	/**
	 * Name the source of a derive and where the derived object goes.
	 *
	 * @param ObjectEntity $source   The object it comes out of, already read under the caller's RBAC.
	 * @param string       $register The register slug or id.
	 * @param string       $schema   The schema slug or id.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $source,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
