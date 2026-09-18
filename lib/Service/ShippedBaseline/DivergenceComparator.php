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
	 * @param array<string, mixed> $baseline The definition the app shipped.
	 * @param array<string, mixed> $live     The definition the instance runs.
	 * @param array<string, mixed> $incoming The definition the app now ships.
	 *
	 * @return array<string, string> Path to state.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function states(array $baseline, array $live, array $incoming): array {
		$b = $this->parts->flatten(descriptor: $baseline);
		$l = $this->parts->flatten(descriptor: $live);
		$i = $this->parts->flatten(descriptor: $incoming);

		$paths = array_unique(array_merge(array_keys($b), array_keys($l), array_keys($i)));
		sort($paths);

		$states = [];
		foreach ($paths as $path) {
			$states[$path] = $this->stateOf(
				baseline: ($b[$path] ?? self::ABSENT),
				live: ($l[$path] ?? self::ABSENT),
				incoming: ($i[$path] ?? self::ABSENT)
			);
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

		$paths = array_unique(array_merge(array_keys($b), array_keys($l)));
		sort($paths);

		$divergences = [];
		foreach ($paths as $path) {
			$shipped = ($b[$path] ?? self::ABSENT);
			$current = ($l[$path] ?? self::ABSENT);
			if ($this->identical(a: $shipped, b: $current) === true) {
				continue;
			}

			$shippedValue = null;
			if ($shipped !== self::ABSENT) {
				$shippedValue = $shipped;
			}

			$liveValue = null;
			if ($current !== self::ABSENT) {
				$liveValue = $current;
			}

			$divergences[] = [
				'path' => $path,
				'state' => self::LOCAL,
				'shipped' => $shippedValue,
				'live' => $liveValue,
				'shippedPresent' => ($shipped !== self::ABSENT),
				'livePresent' => ($current !== self::ABSENT),
			];
		}

		return $divergences;
	}//end report()

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
	 * @param mixed $baseline What the app shipped.
	 * @param mixed $live     What the instance runs.
	 * @param mixed $incoming What the app now ships.
	 *
	 * @return string The state.
	 */
	private function stateOf(mixed $baseline, mixed $live, mixed $incoming): string {
		$localMoved = ($this->identical(a: $live, b: $baseline) === false);
		$upstreamMoved = ($this->identical(a: $incoming, b: $baseline) === false);

		if ($localMoved === false && $upstreamMoved === false) {
			return self::UNCHANGED;
		}

		if ($localMoved === true && $upstreamMoved === false) {
			return self::LOCAL;
		}

		if ($localMoved === false && $upstreamMoved === true) {
			return self::UPSTREAM;
		}

		if ($this->identical(a: $live, b: $incoming) === true) {
			return self::CONVERGED;
		}

		return self::BOTH;
	}//end stateOf()

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
