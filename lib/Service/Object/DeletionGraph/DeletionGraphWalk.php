<?php

/**
 * What one walk of the deletion graph has found so far.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\DeletionGraph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\DeletionGraph;

use OCA\OpenRegister\Dto\DeletionAnalysis;

/**
 * The visited objects and the targets and blockers of one deletion graph walk.
 *
 * One instance lives for one canDelete() call; every object the walk reaches adds to it.
 */
final class DeletionGraphWalk {

	/**
	 * The uuids the walk has entered, for cycle detection.
	 *
	 * @var array<int, string|null>
	 */
	private array $visited = [];

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $cascadeTargets = [];

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $nullifyTargets = [];

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $defaultTargets = [];

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $blockers = [];

	/**
	 * Whether the walk has already entered an object.
	 *
	 * @param string|null $uuid The object uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function hasVisited(?string $uuid): bool {
		return in_array($uuid, $this->visited, true);
	}//end hasVisited()

	/**
	 * Record that the walk enters an object.
	 *
	 * @param string|null $uuid The object uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function markVisited(?string $uuid): void {
		$this->visited[] = $uuid;
	}//end markVisited()

	/**
	 * Add an object that is deleted along with the root.
	 *
	 * @param array<string, mixed> $target The cascade target.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function addCascadeTarget(array $target): void {
		$this->cascadeTargets[] = $target;
	}//end addCascadeTarget()

	/**
	 * Add a reference that is cleared.
	 *
	 * @param array<string, mixed> $target The nullify target.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function addNullifyTarget(array $target): void {
		$this->nullifyTargets[] = $target;
	}//end addNullifyTarget()

	/**
	 * Add a reference that is reset to its default.
	 *
	 * @param array<string, mixed> $target The default target.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function addDefaultTarget(array $target): void {
		$this->defaultTargets[] = $target;
	}//end addDefaultTarget()

	/**
	 * Add a reference that blocks the delete.
	 *
	 * @param array<string, mixed> $blocker The blocker.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function addBlocker(array $blocker): void {
		$this->blockers[] = $blocker;
	}//end addBlocker()

	/**
	 * Add several blockers, in order.
	 *
	 * @param array<int, array<string, mixed>> $blockers The blockers.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function addBlockers(array $blockers): void {
		$this->blockers = array_merge($this->blockers, $blockers);
	}//end addBlockers()

	/**
	 * The analysis of the whole walk: deletable when nothing blocks it.
	 *
	 * @return DeletionAnalysis
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function toAnalysis(): DeletionAnalysis {
		return new DeletionAnalysis(
			deletable: empty($this->blockers),
			cascadeTargets: $this->cascadeTargets,
			nullifyTargets: $this->nullifyTargets,
			defaultTargets: $this->defaultTargets,
			blockers: $this->blockers,
			chainPaths: []
		);
	}//end toAnalysis()
}//end class
