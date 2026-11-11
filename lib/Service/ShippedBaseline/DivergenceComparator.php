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
			$shippedPart = ($shipped[$path] ?? self::ABSENT);
			$livePart = ($live[$path] ?? self::ABSENT);
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
	 * A part holding null reads as absent, as the `??` lookup always has.
	 *
	 * @param DescriptorRevisions $flat The flattened revisions.
	 * @param string|int          $path The path.
	 *
	 * @return PartRevisions The part.
	 */
	private static function partAt(DescriptorRevisions $flat, string|int $path): PartRevisions {
		return new PartRevisions(
			baseline: ($flat->baseline[$path] ?? self::ABSENT),
			live: ($flat->live[$path] ?? self::ABSENT),
			incoming: ($flat->incoming[$path] ?? self::ABSENT)
		);
	}//end partAt()

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
