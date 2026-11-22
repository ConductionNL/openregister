<?php

/**
 * Which parts differ, and WHO differs (row 11.36).
 *
 * 🔑 THREE VERSIONS, BECAUSE TWO CANNOT ANSWER THE QUESTION (D-1). Comparing
 * the live definition with the incoming one says what differs. It does not say
 * who differs, and that is the whole question: `ImportHandler` already has a
 * two-way comparison (`schemaContentDiffers`) and it cannot tell a municipality
 * that added a field from an app that removed one. With the shipped baseline
 * kept, every part falls into one of four states, three of which have an
 * obvious answer and only the fourth needs a person.
 *
 * 🔴 AN EMPTY DECLARATION IS AN ABSENT ONE. A schema entity holds `[]` for an
 * authorization block, a property list or a required list nobody declared,
 * while a shipped file leaves the key out or writes `null`. Compared as
 * values, the entity default reads as a local change, so a shipped
 * authorization block meets a "conflict" on every unattended upgrade and is
 * held back, leaving the schema open. At a declaration root
 * ({@see self::isDeclarationRoot()}) `[]`, `null` and a missing key are the
 * same part. Deeper down `[]` keeps its meaning: `read: []` is a rule.
 *
 * 🔴 AN ABSENT BASELINE IS NOT AN EMPTY ONE. Before this change shipped, no
 * instance had a baseline, and treating "no baseline" as "the shipped
 * definition was empty" would make every existing property read as a local
 * addition and freeze the next upgrade solid. The caller asks
 * {@see self::hasBaseline()} and falls back to today's behaviour when the
 * answer is no; this class refuses to guess.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
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
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

/**
 * The four states a part of a shipped descriptor can be in.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
class DivergenceComparator {

	/**
	 * Live and incoming both match the baseline.
	 *
	 * @var string
	 */
	public const UNCHANGED = 'unchanged';

	/**
	 * The instance moved this part; the app did not.
	 *
	 * @var string
	 */
	public const LOCAL = 'local';

	/**
	 * The app moved this part; the instance did not.
	 *
	 * @var string
	 */
	public const UPSTREAM = 'upstream';

	/**
	 * Both moved it, and not to the same place.
	 *
	 * @var string
	 */
	public const BOTH = 'both';

	/**
	 * Both moved it to the SAME place, which is nobody disagreeing.
	 *
	 * A fifth name for an honest reason: calling it `both` would report a
	 * conflict that has nothing to resolve, and calling it `unchanged` would
	 * claim the instance still matches a baseline it does not match. It is
	 * treated as needing no decision and no write.
	 *
	 * @var string
	 */
	public const CONVERGED = 'converged';

	/**
	 * What a part holds when it is not there at all.
	 *
	 * A sentinel rather than `null`, because `null` is a value a descriptor can
	 * legitimately carry and "the key is absent" is a different fact from "the
	 * key is there and holds null".
	 *
	 * @var string
	 */
	public const ABSENT = "\0absent\0";

	/**
	 * Constructor.
	 *
	 * @param DescriptorParts $parts The flattener.
	 */
	public function __construct(
		private readonly DescriptorParts $parts,
	) {
	}//end __construct()

	/**
	 * The state of every part, keyed by path.
	 *
	 * @param DescriptorRevisions $descriptors What the app shipped, what the instance runs and what the app now ships.
	 *
	 * @return array<string, string> Path to state.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function states(DescriptorRevisions $descriptors): array {
		$flat = new DescriptorRevisions(
			baseline: $this->parts->flatten(descriptor: $descriptors->baseline),
			live: $this->parts->flatten(descriptor: $descriptors->live),
			incoming: $this->parts->flatten(descriptor: $descriptors->incoming)
		);

		$paths = array_unique(array_merge(array_keys($flat->baseline), array_keys($flat->live), array_keys($flat->incoming)));
		sort($paths);

		$states = [];
		foreach ($paths as $path) {
			$states[$path] = $this->stateOf(part: self::partAt(flat: $flat, path: $path));
		}

		return $states;
	}//end states()

	/**
	 * The parts that differ from the baseline, with what each side holds.
	 *
	 * The report an administrator reads. An untouched instance produces an
	 * empty list, which is the spec's second scenario: every part unchanged
	 * reports nothing rather than reporting everything as fine.
	 *
	 * @param array<string, mixed> $baseline The definition the app shipped.
	 * @param array<string, mixed> $live     The definition the instance runs.
	 *
	 * @return array<int, array{path: string, state: string, shipped: mixed, live: mixed}> The divergences.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function report(array $baseline, array $live): array {
		$b = $this->parts->flatten(descriptor: $baseline);
		$l = $this->parts->flatten(descriptor: $live);

		return $this->localDivergences(shipped: $b, live: $l);
	}//end report()

	/**
	 * The flattened parts that differ, in path order, as report entries.
	 *
	 * @param array<string, mixed> $shipped The flattened baseline.
	 * @param array<string, mixed> $live    The flattened live definition.
	 *
	 * @return array<int, array{path: string, state: string, shipped: mixed, live: mixed}> The divergences.
	 */
	private function localDivergences(array $shipped, array $live): array {
		$divergences = [];
		foreach (self::sortedPaths(shipped: $shipped, live: $live) as $path) {
			$shippedPart = self::partIn(flat: $shipped, path: $path);
			$livePart = self::partIn(flat: $live, path: $path);
			if ($this->identical(a: $shippedPart, b: $livePart) === false) {
				$divergences[] = array_merge(
					['path' => $path, 'state' => self::LOCAL],
					self::sides(shipped: $shippedPart, live: $livePart)
				);
			}
		}

		return $divergences;
	}//end localDivergences()

	/**
	 * Every path either side holds, sorted.
	 *
	 * @param array<string, mixed> $shipped The flattened baseline.
	 * @param array<string, mixed> $live    The flattened live definition.
	 *
	 * @return array<int, string> The paths.
	 */
	private static function sortedPaths(array $shipped, array $live): array {
		$paths = array_unique(array_merge(array_keys($shipped), array_keys($live)));
		sort($paths);

		return $paths;
	}//end sortedPaths()

	/**
	 * What each side holds at one path, null for an absent part, and whether it is present.
	 *
	 * @param mixed $shipped The baseline part, or ABSENT.
	 * @param mixed $live    The live part, or ABSENT.
	 *
	 * @return array{shipped: mixed, live: mixed, shippedPresent: bool, livePresent: bool}
	 */
	private static function sides(mixed $shipped, mixed $live): array {
		return [
			'shipped' => self::presentOrNull(part: $shipped),
			'live' => self::presentOrNull(part: $live),
			'shippedPresent' => ($shipped !== self::ABSENT),
			'livePresent' => ($live !== self::ABSENT),
		];
	}//end sides()

	/**
	 * The part, or null when it is absent.
	 *
	 * @param mixed $part The part, or ABSENT.
	 *
	 * @return mixed
	 */
	private static function presentOrNull(mixed $part): mixed {
		if ($part === self::ABSENT) {
			return null;
		}

		return $part;
	}//end presentOrNull()

	/**
	 * Whether a baseline was ever recorded for this subject.
	 *
	 * @param array<string, mixed>|null $baseline The stored baseline.
	 *
	 * @return bool True when there is one to compare against.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function hasBaseline(?array $baseline): bool {
		return ($baseline !== null && $baseline !== []);
	}//end hasBaseline()

	/**
	 * The state of one part.
	 *
	 * @param PartRevisions $part What the part held when shipped, holds live and holds incoming.
	 *
	 * @return string The state.
	 */
	private function stateOf(PartRevisions $part): string {
		$localMoved = ($this->identical(a: $part->live, b: $part->baseline) === false);
		$upstreamMoved = ($this->identical(a: $part->incoming, b: $part->baseline) === false);

		if ($localMoved === false && $upstreamMoved === false) {
			return self::UNCHANGED;
		}

		if ($localMoved === true && $upstreamMoved === false) {
			return self::LOCAL;
		}

		if ($localMoved === false) {
			// Reached only when something moved, so upstream is the one that
			// did; phpstan reports the second half of the pair as always true.
			return self::UPSTREAM;
		}

		if ($this->identical(a: $part->live, b: $part->incoming) === true) {
			return self::CONVERGED;
		}

		return self::BOTH;
	}//end stateOf()

	/**
	 * What one path holds in each flattened revision, or {@see self::ABSENT}.
	 *
	 * A part holding null, or an empty declaration root, reads as absent.
	 *
	 * @param DescriptorRevisions $flat The flattened revisions.
	 * @param string|int          $path The path.
	 *
	 * @return PartRevisions The part.
	 */
	private static function partAt(DescriptorRevisions $flat, string|int $path): PartRevisions {
		return new PartRevisions(
			baseline: self::partIn(flat: $flat->baseline, path: $path),
			live: self::partIn(flat: $flat->live, path: $path),
			incoming: self::partIn(flat: $flat->incoming, path: $path)
		);
	}//end partAt()

	/**
	 * What one flattened revision holds at a path, or {@see self::ABSENT}.
	 *
	 * Absent: the path is missing, holds null, or is an empty declaration root.
	 *
	 * @param array<string|int, mixed> $flat The flattened revision.
	 * @param string|int               $path The path.
	 *
	 * @return mixed The part, or ABSENT.
	 */
	private static function partIn(array $flat, string|int $path): mixed {
		$part = ($flat[$path] ?? self::ABSENT);
		if ($part === [] && self::isDeclarationRoot(path: (string)$path) === true) {
			return self::ABSENT;
		}

		return $part;
	}//end partIn()

	/**
	 * Whether two flattened revisions hold the same part at a path, absence and emptiness alike.
	 *
	 * @param array<string|int, mixed> $shipped The flattened baseline.
	 * @param array<string|int, mixed> $live    The flattened live definition.
	 * @param string                   $path    The path.
	 *
	 * @return bool True when the part did not move.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function samePartAt(array $shipped, array $live, string $path): bool {
		return $this->identical(a: self::partIn(flat: $shipped, path: $path), b: self::partIn(flat: $live, path: $path));
	}//end samePartAt()

	/**
	 * Whether a path names a whole declaration, for which empty means none.
	 *
	 * A top-level part (`properties`, `required`, `authorization`,
	 * `configuration`), one property's `authorization` block, or one guarded
	 * annotation under `configuration`.
	 *
	 * @param string $path A flattened path.
	 *
	 * @return bool True for a declaration root.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public static function isDeclarationRoot(string $path): bool {
		$segments = explode(DescriptorParts::SEPARATOR, $path);
		$depth = count($segments);
		if ($depth === 1) {
			return true;
		}

		if ($depth === 2) {
			return $segments[0] === GuardedAnnotations::PART;
		}

		return $depth === 3 && $segments[0] === 'properties' && $segments[2] === 'authorization';
	}//end isDeclarationRoot()

	/**
	 * A shipped definition as it is recorded as a baseline: an empty declaration root holds `[]`, never `null`.
	 *
	 * The comparison already reads both as absent; recording one spelling keeps
	 * the stored baseline, the reset preview and the report agreeing with it.
	 *
	 * @param array<string, mixed> $definition The shipped definition.
	 *
	 * @return array<string, mixed> The definition to record.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function recordable(array $definition): array {
		foreach ($definition as $part => $value) {
			if ($value === null) {
				$definition[$part] = [];
			}
		}

		foreach ([GuardedAnnotations::PART, 'properties'] as $container) {
			if (is_array($definition[$container] ?? null) === true) {
				$definition[$container] = self::withEmptyChildren(container: $container, children: $definition[$container]);
			}
		}

		return $definition;
	}//end recordable()

	/**
	 * The children of a top-level part with each null declaration root under them made `[]`.
	 *
	 * @param string       $container `configuration` or `properties`.
	 * @param array<mixed> $children  The part's children.
	 *
	 * @return array<mixed> The children.
	 */
	private static function withEmptyChildren(string $container, array $children): array {
		foreach ($children as $key => $child) {
			if ($container === GuardedAnnotations::PART && $child === null) {
				$children[$key] = [];
			}

			$isProperty = ($container === 'properties' && is_array($child) === true);
			if ($isProperty === true && array_key_exists('authorization', $child) === true && $child['authorization'] === null) {
				$children[$key]['authorization'] = [];
			}
		}

		return $children;
	}//end withEmptyChildren()

	/**
	 * Whether two part values are the same, absence included.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 *
	 * @return bool True when they are the same.
	 */
	private function identical(mixed $a, mixed $b): bool {
		if ($a === self::ABSENT || $b === self::ABSENT) {
			return ($a === $b);
		}

		return $this->parts->same(a: $a, b: $b);
	}//end identical()
}//end class
