<?php

/**
 * What one hierarchy descent has decided so far.
 *
 * `HierarchyGrantExpander::absorbLevel()` used to receive the seen map, the
 * grant map, the provenance map and the descendant count as four separate
 * by-reference arguments. They are one piece of state, read and written
 * together on every level, so they travel together here. The object carries
 * no rule of its own: every decision stays in the expander.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The mutable state of one descent. Deliberately not readonly: it is the
 * accumulator the levels write into.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class HierarchyDescent {

	/**
	 * How many descendants the descent has taken.
	 *
	 * @var integer
	 */
	public int $added = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string, array{mask: int, root: string}> $seen    Everything already decided.
	 * @param array<string, int>                            $granted The grant map.
	 * @param array<string, string>                         $sources The provenance map.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public array $seen,
		public array $granted,
		public array $sources,
	) {
	}//end __construct()
}//end class
