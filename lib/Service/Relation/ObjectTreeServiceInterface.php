<?php

/**
 * The bounded tree read along a schema's parent property.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The bounded tree read along a schema's parent property.
 *
 * Bound to ObjectTreeService in ContractBindings. Every method is described on ObjectTreeService.
 * The direction constants live here so callers name only the interface.
 */
interface ObjectTreeServiceInterface {

	/**
	 * Read the objects below the root.
	 *
	 * @var string
	 */
	public const DIRECTION_DESCENDANTS = 'descendants';

	/**
	 * Read the objects above the root.
	 *
	 * @var string
	 */
	public const DIRECTION_ANCESTORS = 'ancestors';

	/**
	 * Read both.
	 *
	 * @var string
	 */
	public const DIRECTION_BOTH = 'both';

	/**
	 * The tree around one object.
	 *
	 * The root is always in the answer at level 0: the caller has already been
	 * allowed to read it. Ancestors carry negative levels, nearest first as -1.
	 * A node the caller may not read is left out, and so is everything reached
	 * only through it.
	 *
	 * @param ObjectEntity         $root  The object to walk from, already read under the caller's RBAC.
	 * @param ObjectTreeQuery|null $query The direction, the depth and the fields asked for; null walks down to the declared bound.
	 * @param bool                 $_rbac Whether to apply the caller's read access to every node.
	 *
	 * @return array{root: string, register: int, schema: int, parentProperty: string|null,
	 *     direction: string, depth: int, requestedDepth: int|null, maxDepth: int,
	 *     nodes: array<int, array<string, mixed>>, truncated: bool, truncatedBy: string|null}
	 *     The tree.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function tree(ObjectEntity $root, ?ObjectTreeQuery $query = null, bool $_rbac = true): array;
}//end interface
