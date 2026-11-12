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
		$library = [];
		foreach (($annotation ?? []) as $name => $declaration) {
			if ((string)$name === '') {
				continue;
			}

			$entry = $this->entryFrom(declaration: $declaration);
			if ($entry !== null) {
				$library[(string)$name] = $entry;
			}
		}

		return $library;
	}//end libraryFrom()

	/**
	 * One library entry from a declaration, or null when it declares nothing.
	 *
	 * @param mixed $declaration The declaration under one name.
	 *
	 * @return array{expression: mixed, description: string}|null The entry.
	 */
	private function entryFrom(mixed $declaration): ?array {
		if (is_array($declaration) === false || array_key_exists('expression', $declaration) === false) {
			return null;
		}

		return [
			'expression' => $declaration['expression'],
			'description' => (string)($declaration['description'] ?? ''),
		];
	}//end entryFrom()

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

		return $this->referencesUnderBranches(node: $node);
	}//end referencesIn()

	/**
	 * The names referenced under every branch key of a node.
	 *
	 * Anything that is not a branch key, or whose value is not an array, is an
	 * operand and is not followed.
	 *
	 * @param array<string|int, mixed> $node The condition node.
	 *
	 * @return array<int, string> The names, in order of appearance.
	 */
	private function referencesUnderBranches(array $node): array {
		$names = [];
		foreach ($node as $key => $value) {
			if (in_array((string)$key, self::BRANCHES, true) === true && is_array($value) === true) {
				$names = array_merge($names, $this->referencesUnder(value: $value));
			}
		}

		return $names;
	}//end referencesUnderBranches()

	/**
	 * The names referenced under ONE branch key.
	 *
	 * `{"not": {...}}` carries one node; `{"and": [...]}` carries a list of
	 * them. Both spellings appear in the corpus.
	 *
	 * @param array<string|int, mixed> $value What the branch key carries.
	 *
	 * @return array<int, string> The names, in order of appearance.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	private function referencesUnder(array $value): array {
		$children = [$value];
		if (array_is_list($value) === true) {
			$children = $value;
		}

		$names = [];
		foreach ($children as $child) {
			foreach ($this->referencesIn(node: $child) as $name) {
				$names[] = $name;
			}
		}

		return $names;
	}//end referencesUnder()

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

		return $this->undeclaredRefusal(library: $library, usingNodes: $usingNodes)
			?? $this->compositionRefusal(library: $library);
	}//end refusalFor()

	/**
	 * Why a reference names an undeclared condition: the library's own first, then the users'.
	 *
	 * @param array<string, array{expression: mixed, description: string}> $library    The library.
	 * @param array<string, mixed>                                         $usingNodes Condition nodes by owner.
	 *
	 * @return string|null The reason, or null.
	 */
	private function undeclaredRefusal(array $library, array $usingNodes): ?string {
		$expressions = array_map(static fn (array $declaration): mixed => $declaration['expression'], $library);
		$miss        = $this->firstUndeclared(nodes: $expressions, library: $library);
		if ($miss !== null) {
			return sprintf('named condition "%s" references "%s", which is not declared', $miss[0], $miss[1]);
		}

		$miss = $this->firstUndeclared(nodes: $usingNodes, library: $library);
		if ($miss !== null) {
			return sprintf('"%s" references named condition "%s", which is not declared', $miss[0], $miss[1]);
		}

		return null;
	}//end undeclaredRefusal()

	/**
	 * The first reference, in any of the nodes, to a name the library does not declare.
	 *
	 * @param array<string|int, mixed>                                     $nodes   Condition nodes by owner.
	 * @param array<string, array{expression: mixed, description: string}> $library The library.
	 *
	 * @return array{0: string, 1: string}|null The owner and the undeclared name, or null.
	 */
	private function firstUndeclared(array $nodes, array $library): ?array {
		foreach ($nodes as $owner => $node) {
			foreach ($this->referencesIn(node: $node) as $referenced) {
				if (array_key_exists($referenced, $library) === false) {
					return [(string)$owner, $referenced];
				}
			}
		}

		return null;
	}//end firstUndeclared()

	/**
	 * Why the library's composition is refused: a cycle first, then the depth ceiling.
	 *
	 * @param array<string, array{expression: mixed, description: string}> $library The library.
	 *
	 * @return string|null The reason, or null.
	 */
	private function compositionRefusal(array $library): ?string {
		$graph = new NamedConditionGraph(
			edges: array_map(fn (array $declaration): array => $this->referencesIn(node: $declaration['expression']), $library)
		);

		$cycle = $graph->firstCycle();
		if ($cycle !== null) {
			return sprintf('named conditions form a cycle: %s', implode(' → ', $cycle));
		}

		$deep = $graph->firstTooDeep();
		if ($deep !== null) {
			return sprintf('named condition "%s" composes deeper than the administered depth of %d', $deep, self::MAX_DEPTH);
		}

		return null;
	}//end compositionRefusal()

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
				$usage[$name] = ($usage[$name] ?? []);
				if (in_array((string)$owner, $usage[$name], true) === false) {
					$usage[$name][] = (string)$owner;
				}
			}
		}

		ksort($usage);
		return $usage;
	}//end usage()
}//end class
