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
	 * Everything decided so far, direct or inherited.
	 *
	 * @var array<string, array{mask: int, root: string}>
	 */
	public array $seen;

	/**
	 * The grant map: object UUID => core permission bitmask.
	 *
	 * @var array<string, int>
	 */
	public array $granted;

	/**
	 * The provenance map: inherited object UUID => the root it came from.
	 *
	 * @var array<string, string>
	 */
	public array $sources;

	/**
	 * How many descendants the descent has taken.
	 *
	 * @var integer
	 */
	public int $added;

	/**
	 * Constructor.
	 *
	 * @param array<string, array{mask: int, root: string}> $seen    Everything already decided.
	 * @param array<string, int>                            $granted The grant map.
	 * @param array<string, string>                         $sources The provenance map.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(array $seen, array $granted, array $sources) {
		$this->seen    = $seen;
		$this->granted = $granted;
		$this->sources = $sources;
		$this->added   = 0;
	}//end __construct()
}//end class
