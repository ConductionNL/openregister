<?php

/**
 * The reference graph of a named-condition library: its cycles and its depth.
 *
 * Built from the edges `NamedConditionLibrary` reads out of each declared
 * expression, so the walk itself never re-reads a condition node.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * Finds the first cycle and the first too-deep name in a library's references.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
final class NamedConditionGraph {

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, string>> $edges Each declared name to the names it references directly.
	 */
	public function __construct(
		private readonly array $edges,
	) {
	}//end __construct()

	/**
	 * The first cycle in the library, as the path that closes it.
	 *
	 * @return array<int, string>|null The cycle path, or null.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function firstCycle(): ?array {
		return $this->cycleThrough(names: array_keys($this->edges), seen: []);
	}//end firstCycle()

	/**
	 * The first name whose composition is deeper than the ceiling.
	 *
	 * Only called after the cycle check, so the descent terminates.
	 *
	 * @return string|null The name, or null.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function firstTooDeep(): ?string {
		foreach (array_keys($this->edges) as $name) {
			if ($this->depthOf(name: (string)$name, depth: 0) > NamedConditionLibrary::MAX_DEPTH) {
				return (string)$name;
			}
		}

		return null;
	}//end firstTooDeep()

	/**
	 * The first cycle closed by walking any of the names, in order.
	 *
	 * @param array<int, string|int> $names The names to walk.
	 * @param array<int, string>     $seen  The path so far.
	 *
	 * @return array<int, string>|null The cycle, or null.
	 */
	private function cycleThrough(array $names, array $seen): ?array {
		foreach ($names as $name) {
			$cycle = $this->walk(name: (string)$name, seen: $seen);
			if ($cycle !== null) {
				return $cycle;
			}
		}

		return null;
	}//end cycleThrough()

	/**
	 * Walk one name's references, returning the path that closes a cycle.
	 *
	 * @param string             $name The name being walked.
	 * @param array<int, string> $seen The path so far.
	 *
	 * @return array<int, string>|null The cycle, or null.
	 */
	private function walk(string $name, array $seen): ?array {
		if (in_array($name, $seen, true) === true) {
			return [...$seen, $name];
		}

		if (array_key_exists($name, $this->edges) === false) {
			return null;
		}

		return $this->cycleThrough(names: $this->edges[$name], seen: [...$seen, $name]);
	}//end walk()

	/**
	 * How deep one name composes.
	 *
	 * @param string $name  The name.
	 * @param int    $depth The depth so far.
	 *
	 * @return int The depth.
	 */
	private function depthOf(string $name, int $depth): int {
		if (array_key_exists($name, $this->edges) === false || $depth > NamedConditionLibrary::MAX_DEPTH) {
			return $depth;
		}

		$deepest = $depth;
		foreach ($this->edges[$name] as $referenced) {
			$deepest = max($deepest, $this->depthOf(name: $referenced, depth: ($depth + 1)));
		}

		return $deepest;
	}//end depthOf()
}//end class
