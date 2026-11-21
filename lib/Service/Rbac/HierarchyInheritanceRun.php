<?php

/**
 * OpenRegister HierarchyInheritanceRun.
 *
 * One descent of one schema's hierarchy by the grant expander: the resolved
 * declaration, what the descent decided so far, and the level it is on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The state of one hierarchy descent inside {@see HierarchyGrantExpander}.
 *
 * A run object: built by HierarchyGrantExpander::expand() and never handed
 * outside that class. The frontier and the depth are progress fields the walk
 * moves on.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class HierarchyInheritanceRun {

	/**
	 * The objects whose children the current level reads: uuid => its entry.
	 *
	 * @var array<string, array{mask: int, root: string}>
	 */
	public array $frontier = [];

	/**
	 * The level being read, for the log.
	 *
	 * @var integer
	 */
	public int $depth = 0;

	/**
	 * Constructor.
	 *
	 * @param array            $hierarchy One declaration, resolved: table, parentColumn, maxDepth, verbs, schemaId.
	 * @param HierarchyDescent $descent   What the descent decided so far, modified in place.
	 */
	public function __construct(
		public readonly array $hierarchy,
		public readonly HierarchyDescent $descent,
	) {
	}//end __construct()

	/**
	 * Mark one child seen under its root and grant it the narrowed mask unless held directly.
	 *
	 * @param string                         $childUuid The child.
	 * @param array{mask: int, root: string} $entry     The child's frontier entry, mask already narrowed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function admit(string $childUuid, array $entry): void {
		$this->descent->seen[$childUuid] = $entry;

		// 🔴 A DIRECT GRANT IS NEVER OVERWRITTEN. The inherited one is the
		// weaker claim by construction, and the spec keeps the existing
		// most-specific-wins resolution: a person given `update` on the
		// child keeps it even where the root grants only `read`.
		//
		// A mask of 0 is skipped for the mirror reason: the schema narrowed
		// every verb away, and recording a grant of nothing would put the
		// object in the list and refuse every action on it, which reads as
		// a broken object.
		if (array_key_exists($childUuid, $this->descent->granted) === true || $entry['mask'] === 0) {
			return;
		}

		$this->descent->granted[$childUuid] = $entry['mask'];
		$this->descent->sources[$childUuid] = $entry['root'];
	}//end admit()
}//end class
