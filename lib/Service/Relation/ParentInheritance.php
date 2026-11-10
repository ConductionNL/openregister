<?php

/**
 * What a child about to be created takes from its parent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The parent, the child's data as written so far, and the roles the relation type inherits.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class ParentInheritance {
	/**
	 * Describe the inheritance.
	 *
	 * @param ObjectEntity          $parent    The parent object.
	 * @param array<string, mixed>  $childData The child's data as written so far.
	 * @param array<string, string> $inherits  Role to property name.
	 */
	public function __construct(
		public readonly ObjectEntity $parent,
		public readonly array $childData,
		public readonly array $inherits,
	) {
	}//end __construct()
}//end class
