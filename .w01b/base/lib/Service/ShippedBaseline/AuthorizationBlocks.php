<?php

/**
 * The authorization blocks of a schema definition, settled whole.
 *
 * A schema's `authorization` and each property's `authorization` are access
 * policies, and a policy is only meaningful whole. This class finds the
 * blocks, settles each to ONE divergence state for all of its keys, and
 * reports what the settlement applied, replaced and left in conflict. The
 * per-key folding stays in GuardedDescriptorMerge.
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
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

/**
 * Finds, settles and reports the authorization blocks of a definition.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
class AuthorizationBlocks {

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
	 * The authorization block a flattened path belongs to, or null.
	 *
	 * Two kinds of block: the schema's own `authorization`, and the
	 * `authorization` of one property (`properties.<name>.authorization`).
	 *
	 * @param string $path A flattened path.
	 *
	 * @return string|null The block's root path, or null when the path is in no block.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function rootOf(string $path): ?string {
		$segments = explode(DescriptorParts::SEPARATOR, $path);
		if ($segments[0] === 'authorization') {
			return 'authorization';
		}

		if ($segments[0] === 'properties' && isset($segments[2]) === true && $segments[2] === 'authorization') {
			return 'properties' . DescriptorParts::SEPARATOR . $segments[1] . DescriptorParts::SEPARATOR . 'authorization';
		}

		return null;
	}//end rootOf()

	/**
	 * Every authorization block root any of the given definitions carries.
	 *
	 * @param array<string, mixed> ...$definitions Schema definitions (properties, required, authorization).
	 *
	 * @return array<int, string> The block roots, unique.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function roots(array ...$definitions): array {
		$roots = [];
		foreach ($definitions as $definition) {
			if (array_key_exists('authorization', $definition) === true) {
				$roots['authorization'] = true;
			}

			$properties = ($definition['properties'] ?? []);
			if (is_array($properties) === false) {
				continue;
			}

			foreach ($properties as $name => $property) {
				if (is_array($property) === true && array_key_exists('authorization', $property) === true) {
					$roots['properties' . DescriptorParts::SEPARATOR . $name . DescriptorParts::SEPARATOR . 'authorization'] = true;
				}
			}
		}

		return array_keys($roots);
	}//end roots()

	/**
	 * Settle each authorization block to ONE state for all of its keys.
	 *
	 * @param array<string, string> $states    Path to state, after the missing-property rule.
	 * @param array<int, string>    $decisions Paths an administrator decided to take from upstream.
	 *
	 * @return array<string, array{state: string, decided: bool, localMoved: bool}> Block root to its settlement;
	 *         a block in which nothing moved is left out, so its keys fold as before.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function settle(array $states, array $decisions): array {
		$members = [];
		foreach ($states as $path => $state) {
			$root = $this->rootOf(path: (string)$path);
			if ($root !== null) {
				$members[$root][] = $state;
			}
		}

		$blocks = [];
		foreach ($members as $root => $memberStates) {
			$state = $this->blockState(memberStates: $memberStates);
			if ($state === null) {
				continue;
			}

			$decided = in_array($root, $decisions, true);
			$blocks[$root] = [
				'state'      => $state,
				'decided'    => $decided,
				'localMoved' => ($state !== DivergenceComparator::UPSTREAM),
			];
			if ($decided === true) {
				$blocks[$root]['state'] = DivergenceComparator::UPSTREAM;
			}
		}

		return $blocks;
	}//end settle()

	/**
	 * What the settled blocks applied, replaced over a local change, and left in conflict.
	 *
	 * The conflict of a block is reported once, on its root.
	 *
	 * @param array<string, array{state: string, decided: bool, localMoved: bool}> $blocks  The settlement.
	 * @param array<string, array<string|int, mixed>>                              $sources The flattened definitions.
	 *
	 * @return array{applied: array<int, string>, replacedLocal: array<int, string>, conflicts: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function outcome(array $blocks, array $sources): array {
		$outcome = ['applied' => [], 'replacedLocal' => [], 'conflicts' => []];
		foreach ($blocks as $root => $block) {
			if ($block['decided'] === true) {
				$outcome['applied'][] = $root;
			}

			if ($block['decided'] === true && $block['localMoved'] === true) {
				$outcome['replacedLocal'][] = $root;
			}

			if ($block['state'] === DivergenceComparator::BOTH) {
				$shipped = $this->subtree(parts: $sources['incoming'], root: (string)$root);
				$live    = $this->subtree(parts: $sources['live'], root: (string)$root);
				$outcome['conflicts'][] = [
					'path'           => $root,
					'shipped'        => $shipped,
					'live'           => $live,
					'shippedPresent' => ($shipped !== null),
					'livePresent'    => ($live !== null),
				];
			}
		}

		return $outcome;
	}//end outcome()

	/**
	 * One block's state from the states of its keys: null when nothing moved.
	 *
	 * @param array<int, string> $memberStates The states of the block's keys.
	 *
	 * @return string|null UPSTREAM, LOCAL, BOTH, or null.
	 */
	private function blockState(array $memberStates): ?string {
		$both     = in_array(DivergenceComparator::BOTH, $memberStates, true);
		$local    = ($both === true || in_array(DivergenceComparator::LOCAL, $memberStates, true) === true);
		$upstream = ($both === true || in_array(DivergenceComparator::UPSTREAM, $memberStates, true) === true);

		$states = [
			'00' => null,
			'01' => DivergenceComparator::UPSTREAM,
			'10' => DivergenceComparator::LOCAL,
			'11' => DivergenceComparator::BOTH,
		];

		return $states[(int)$local . (int)$upstream];
	}//end blockState()

	/**
	 * One block, re-assembled from flattened parts.
	 *
	 * @param array<string|int, mixed> $parts The flattened definition.
	 * @param string                   $root  The block root path.
	 *
	 * @return mixed The block, or null when the definition does not carry it.
	 */
	private function subtree(array $parts, string $root): mixed {
		$picked = [];
		foreach ($parts as $path => $value) {
			if ((string)$path === $root || str_starts_with((string)$path, $root . DescriptorParts::SEPARATOR) === true) {
				$picked[$path] = $value;
			}
		}

		if ($picked === []) {
			return null;
		}

		$tree = $this->parts->unflatten(parts: $picked);
		foreach (explode(DescriptorParts::SEPARATOR, $root) as $segment) {
			$tree = ($tree[$segment] ?? null);
		}

		return $tree;
	}//end subtree()
}//end class
