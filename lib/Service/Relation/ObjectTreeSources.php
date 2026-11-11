<?php

/**
 * What the object tree walk reads its structure from.
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
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;

/**
 * The child reader, the hierarchy declaration reader and the instance's node cap.
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */
final class ObjectTreeSources {
	/**
	 * Bundle the sources.
	 *
	 * @param HierarchyDescender     $descender   Reads one level of children from a magic table.
	 * @param HierarchyGrantExpander $hierarchies Reads a schema's hierarchy declaration.
	 * @param RelationGraphService   $graphs      Holds the instance's node cap.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly HierarchyDescender $descender,
		public readonly HierarchyGrantExpander $hierarchies,
		public readonly RelationGraphService $graphs,
	) {
	}//end __construct()
}//end class
