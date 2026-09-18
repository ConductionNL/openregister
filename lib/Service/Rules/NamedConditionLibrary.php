<?php

/**
 * One condition, named once, used by twenty rules (row 11.40).
 *
 * The ledger note: "Row 11.20 rules stand alone. Without composition, the same
 * condition is written out in twenty rules and corrected in nineteen of them."
 *
 * 🔑 A NAMED CONDITION IS A RECORD, NOT A MACRO (D-1). Textual inclusion would
 * make the twenty copies invisible rather than absent: the same duplication,
 * now unlistable. A reference is stored as a reference, so the inventory can
 * say which rules use a condition, a correction reaches all of them, and a
 * cycle can be found — a reference graph can be walked, an expanded string
 * cannot.
 *
 * 🔴 A CYCLE IS REFUSED AT SAVE AND BOUNDED AT EVALUATION, BOTH. The save
 * refusal is the one a person reads; the evaluation ceiling is what stops a
 * library edited around the validator (an import, a direct write, a fixture)
 * from turning one save into an infinite descent.
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
 * The named-condition vocabulary: how one is declared, referenced and refused.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
class NamedConditionLibrary {

	/**
	 * The schema annotation the library is declared under.
	 *
	 * 🔴 IT MUST BE IN `Schema::ANNOTATION_VOCABULARY`, or `setConfiguration()`
	 * DROPS it on every save: the library would sit declared in the app's
	 * register JSON, visible in the repo, and never reach the running system,
	 * while every rule referencing it refused. The file records that trap five
	 * times over; this is the sixth.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-conditions';

	/**
	 * The key a node uses to reference a named condition.
	 *
	 * A `$` prefix, like the dynamic variables, so a reference cannot collide
	 * with a JSONLogic or AST operator: neither dialect owns a key starting
	 * with a dollar.
	 *
	 * @var string
	 */
	public const REF = '$condition';

	/**
	 * How deep a chain of named conditions may go.
	 *
	 * Administered, in the sense that it is one number in one place rather
	 * than a belief spread over the code. Five is deeper than any composition
	 * anyone has asked for and shallow enough that a refusal arrives before a
	 * timeout does.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 5;

	/**
	 * The keys whose values are themselves conditions, in both dialects.
	 *
	 * A reference can sit inside any of these, so the walk has to follow them.
	 * Anything else is an operand, and an operand that happens to be an array
	 * is not a condition.
	 *
	 * @var array<int, string>
	 */
	private const BRANCHES = ['and', 'or', 'not', '!', 'if'];

	/**
	 * The declared conditions, keyed by name.
	 *
	 * A declaration without an `expression` is dropped: a name with nothing
	 * behind it is not a condition, and keeping it would let a reference
	 * resolve to nothing and then be judged.
	 *
	 * @param array<string, mixed>|null $annotation The declaration.
	 *
	 * @return array<string, array{expression: mixed, description: string}> The library.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function libraryFrom(?array $annotation): array {
		if ($annotation === null) {
			return [];
		}

		$library = [];
		foreach ($annotation as $name => $declaration) {
			$name = (string)$name;
			if ($name === '' || is_array($declaration) === false) {
				continue;
			}

			if (array_key_exists('expression', $declaration) === false) {
				continue;
			}

			$library[$name] = [
				'expression' => $declaration['expression'],
				'description' => (string)($declaration['description'] ?? ''),
			];
		}

		return $library;
	}//end libraryFrom()

	/**
	 * Every named condition a node references, directly.
	 *
	 * @param mixed $node The condition node.
	 *
	 * @return array<int, string> The names, in order of appearance.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function referencesIn(mixed $node): array {
		if (is_array($node) === false || $node === []) {
			return [];
		}

		if (array_key_exists(self::REF, $node) === true) {
			return [(string)$node[self::REF]];
		}

		$names = [];
		foreach ($node as $key => $value) {
			if (in_array((string)$key, self::BRANCHES, true) === false) {
				continue;
			}

			if (is_array($value) === false) {
				continue;
			}

			// `{"not": {...}}` carries one node; `{"and": [...]}` carries a
			// list of them. Both spellings appear in the corpus.
			$children = ($this->isList(value: $value) === true ? $value : [$value]);
			foreach ($children as $child) {
				foreach ($this->referencesIn(node: $child) as $name) {
					$names[] = $name;
				}
			}
		}

		return $names;
	}//end referencesIn()

	/**
	 * Why a library and the nodes referencing it may not be saved, or null.
	 *
	 * @param array<string, mixed>|null $annotation The declared library.
	 * @param array<string, mixed>      $usingNodes Condition nodes by the name of what carries them.
	 *
	 * @return string|null The reason, naming the name.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function refusalFor(?array $annotation, array $usingNodes = []): ?string {
		$library = $this->libraryFrom(annotation: $annotation);

		foreach ($library as $name => $declaration) {
			foreach ($this->referencesIn(node: $declaration['expression']) as $referenced) {
				if (array_key_exists($referenced, $library) === false) {
					return sprintf('named condition "%s" references "%s", which is not declared', $name, $referenced);
				}
			}
		}

		foreach ($usingNodes as $owner => $node) {
			foreach ($this->referencesIn(node: $node) as $referenced) {
				if (array_key_exists($referenced, $library) === false) {
					return sprintf('"%s" references named condition "%s", which is not declared', (string)$owner, $referenced);
				}
			}
		}

		$cycle = $this->cycleIn(library: $library);
		if ($cycle !== null) {
			return sprintf('named conditions form a cycle: %s', implode(' → ', $cycle));
		}

		$deep = $this->tooDeepIn(library: $library);
		if ($deep !== null) {
			return sprintf(
				'named condition "%s" composes deeper than the administered depth of %d',
				$deep,
				self::MAX_DEPTH
			);
		}

		return null;
	}//end refusalFor()

	/**
	 * Which rules use each named condition (task 1.4).
	 *
	 * Direct references only, and that is deliberate: an administrator asking
	 * "what does correcting this break" wants the rules that name it, and a
	 * transitive list would bury those among conditions that merely compose it.
	 *
	 * @param array<string, mixed> $usingNodes Condition nodes by the name of what carries them.
	 *
	 * @return array<string, array<int, string>> Condition name to the owners using it.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function usage(array $usingNodes): array {
		$usage = [];
		foreach ($usingNodes as $owner => $node) {
			foreach ($this->referencesIn(node: $node) as $name) {
				if (isset($usage[$name]) === false) {
					$usage[$name] = [];
				}

				if (in_array((string)$owner, $usage[$name], true) === false) {
					$usage[$name][] = (string)$owner;
				}
			}
		}

		ksort($usage);

		return $usage;
	}//end usage()

	/**
	 * The first cycle in the library, as the path that closes it.
	 *
	 * @param array<string, array{expression: mixed, description: string}> $library The library.
	 *
	 * @return array<int, string>|null The cycle path, or null.
	 */
	private function cycleIn(array $library): ?array {
		foreach (array_keys($library) as $name) {
			$path = $this->walk(library: $library, name: (string)$name, seen: []);
			if ($path !== null) {
				return $path;
			}
		}

		return null;
	}//end cycleIn()

	/**
	 * Walk one name's references, returning the path that closes a cycle.
	 *
	 * @param array<string, array{expression: mixed, description: string}> $library The library.
	 * @param string                                                       $name    The name being walked.
	 * @param array<int, string>                                           $seen    The path so far.
	 *
	 * @return array<int, string>|null The cycle, or null.
	 */
	private function walk(array $library, string $name, array $seen): ?array {
		if (in_array($name, $seen, true) === true) {
			$seen[] = $name;
			return $seen;
		}

		if (array_key_exists($name, $library) === false) {
			return null;
		}

		$seen[] = $name;
		foreach ($this->referencesIn(node: $library[$name]['expression']) as $referenced) {
			$cycle = $this->walk(library: $library, name: $referenced, seen: $seen);
			if ($cycle !== null) {
				return $cycle;
			}
		}

		return null;
	}//end walk()

	/**
	 * The first name whose composition is deeper than the ceiling.
	 *
	 * Only called after the cycle check, so the descent terminates.
	 *
	 * @param array<string, array{expression: mixed, description: string}> $library The library.
	 *
	 * @return string|null The name, or null.
	 */
	private function tooDeepIn(array $library): ?string {
		foreach (array_keys($library) as $name) {
			if ($this->depthOf(library: $library, name: (string)$name, depth: 0) > self::MAX_DEPTH) {
				return (string)$name;
			}
		}

		return null;
	}//end tooDeepIn()

	/**
	 * How deep one name composes.
	 *
	 * @param array<string, array{expression: mixed, description: string}> $library The library.
	 * @param string                                                       $name    The name.
	 * @param int                                                          $depth   The depth so far.
	 *
	 * @return int The depth.
	 */
	private function depthOf(array $library, string $name, int $depth): int {
		if (array_key_exists($name, $library) === false || $depth > self::MAX_DEPTH) {
			return $depth;
		}

		$deepest = $depth;
		foreach ($this->referencesIn(node: $library[$name]['expression']) as $referenced) {
			$deepest = max($deepest, $this->depthOf(library: $library, name: $referenced, depth: ($depth + 1)));
		}

		return $deepest;
	}//end depthOf()

	/**
	 * Whether an array is a list rather than a map.
	 *
	 * @param array<mixed> $value The array.
	 *
	 * @return bool True when it is a list.
	 */
	private function isList(array $value): bool {
		return array_is_list($value);
	}//end isList()
}//end class
