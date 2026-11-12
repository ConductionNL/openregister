<?php

/**
 * The running result of one guarded descriptor merge.
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
 * Folds one path at a time of a guarded merge into its running result.
 *
 * Built by {@see GuardedDescriptorMerge::merge()} for one merge, from the
 * flattened definitions, the decisions in effect and the settled
 * authorization blocks; it holds the parts written so far, the next baseline
 * and what was applied, preserved and found in conflict. Each state's rule is
 * one method, unchanged from when they lived on the merge itself, because
 * these rules decide what an upgrade overwrites.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class DescriptorFold {

	/**
	 * The merged definition's parts so far.
	 *
	 * @var array<string|int, mixed>
	 */
	public array $mergedParts = [];

	/**
	 * The next baseline's parts so far.
	 *
	 * @var array<string|int, mixed>
	 */
	public array $nextBaseline = [];

	/**
	 * Paths taken from upstream.
	 *
	 * @var array<int, string|int>
	 */
	public array $applied = [];

	/**
	 * Paths whose local change was kept.
	 *
	 * @var array<int, string|int>
	 */
	public array $preserved = [];

	/**
	 * Paths both sides changed, differently.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $conflicts = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string|int, mixed>>                              $sources   The flattened baseline, live and incoming definitions.
	 * @param array<int, string>                                                   $decisions Paths an administrator decided to take from upstream.
	 * @param array<string, array{state: string, decided: bool, localMoved: bool}> $blocks    The settled authorization blocks.
	 * @param AuthorizationBlocks                                                  $authorization Which block a path belongs to.
	 */
	public function __construct(
		private readonly array $sources,
		private readonly array $decisions,
		private readonly array $blocks,
		private readonly AuthorizationBlocks $authorization,
	) {
	}//end __construct()

	/**
	 * Fold one path's divergence state into the running result.
	 *
	 * A key of a settled authorization block takes its block's side; every
	 * other path takes its own state's rule.
	 *
	 * @param string|integer $path  The flattened descriptor path.
	 * @param mixed          $state The divergence state for this path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function fold(string|int $path, mixed $state): void {
		$block = $this->blockOf(path: $path);
		if ($block !== null) {
			$this->foldBlockMember(path: (string)$path, block: $block);
			return;
		}

		$this->foldOwnState(path: $path, state: $state);
	}//end fold()

	/**
	 * The settlement of the authorization block a path belongs to, if it settled.
	 *
	 * @param string|integer $path The flattened descriptor path.
	 *
	 * @return array{state: string, decided: bool, localMoved: bool}|null The settlement, or null.
	 */
	private function blockOf(string|int $path): ?array {
		$root = $this->authorization->rootOf(path: (string)$path);
		if ($root === null || isset($this->blocks[$root]) === false) {
			return null;
		}

		return $this->blocks[$root];
	}//end blockOf()

	/**
	 * Fold a path outside any settled block by its own state.
	 *
	 * @param string|integer $path  The flattened descriptor path.
	 * @param mixed          $state The divergence state for this path.
	 *
	 * @return void
	 */
	private function foldOwnState(string|int $path, mixed $state): void {
		$decided = in_array($path, $this->decisions, true);

		// A decided conflict is taken from upstream, and is the only way a
		// conflicting part moves. The decision is the caller's; recording
		// it is the caller's too, which is why it arrives as a path list
		// and not as a flag on this class.
		if ($state === DivergenceComparator::BOTH && $decided === true) {
			$state = DivergenceComparator::UPSTREAM;
			$this->applied[] = $path;
		}

		// UPSTREAM is the one state whose rule reads whether the path was
		// decided, so it is folded here, where that flag is known.
		if ((string)$state === DivergenceComparator::UPSTREAM) {
			$this->foldUpstream(path: $path, decided: $decided);
			return;
		}

		$this->foldPath(path: $path, state: (string)$state);
	}//end foldOwnState()

	/**
	 * Fold one key of a settled authorization block.
	 *
	 * The conflict itself is reported once, on the block root, by the merge.
	 *
	 * @param string                                                $path  The key.
	 * @param array{state: string, decided: bool, localMoved: bool} $block The block's settlement.
	 *
	 * @return void
	 */
	private function foldBlockMember(string $path, array $block): void {
		if ($block['state'] === DivergenceComparator::UPSTREAM) {
			$this->foldUpstream(path: $path, decided: true);
			if ($block['decided'] === false) {
				$this->applied[] = $path;
			}

			return;
		}

		if ($block['state'] === DivergenceComparator::LOCAL) {
			$this->foldLocal(path: $path);
			return;
		}

		// BOTH: the instance's block stands whole, the baseline stays where it
		// was so the conflict is found again on the next import.
		$this->keepLiveAndBaseline(path: $path);
	}//end foldBlockMember()

	/**
	 * Fold ONE path's divergence state, other than UPSTREAM.
	 *
	 * @param string|integer $path  The flattened descriptor path.
	 * @param string         $state The divergence state for this path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function foldPath(string|int $path, string $state): void {
		switch ($state) {
			case DivergenceComparator::LOCAL:
				$this->foldLocal(path: $path);
			break;
			case DivergenceComparator::BOTH:
				$this->foldConflict(path: $path);
			break;
			default:
				// CONVERGED and UNCHANGED write the same thing: the live value
				// stands, and the baseline follows what the app now ships.
				$this->foldSettled(path: $path);
			break;
		}//end switch
	}//end foldPath()

	/**
	 * Fold a path only the app changed.
	 *
	 * @param string|integer $path    The flattened descriptor path.
	 * @param boolean        $decided Whether an administrator took this path from upstream.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function foldUpstream(string|int $path, bool $decided): void {
		if (array_key_exists($path, $this->sources['incoming']) === true) {
			$this->mergedParts[$path]  = $this->sources['incoming'][$path];
			$this->nextBaseline[$path] = $this->sources['incoming'][$path];
			if ($decided === false) {
				$this->applied[] = $path;
			}

			return;
		}

		// Absent from the incoming and unchanged locally: the app removed it,
		// and nobody locally disagreed. It is dropped from both the merged
		// definition and the new baseline.
		if ($decided === false) {
			$this->applied[] = $path;
		}
	}//end foldUpstream()

	/**
	 * Fold a path only the instance changed.
	 *
	 * @param string|integer $path The flattened descriptor path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function foldLocal(string|int $path): void {
		if (array_key_exists($path, $this->sources['live']) === true) {
			$this->mergedParts[$path] = $this->sources['live'][$path];
		}

		// Recorded as preserved whether the local change ADDED the part or
		// REMOVED it. A part the instance deleted is a local decision like any
		// other, and leaving it out of the list would report the upgrade as
		// having preserved less than it did.
		$this->preserved[] = $path;

		// The baseline keeps what the app shipped, so two upgrades later the
		// report still names the version this part diverged from (D-5).
		if (array_key_exists($path, $this->sources['baseline']) === true) {
			$this->nextBaseline[$path] = $this->sources['baseline'][$path];
		}
	}//end foldLocal()

	/**
	 * Fold a path both sides changed, differently.
	 *
	 * @param string|integer $path The flattened descriptor path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function foldConflict(string|int $path): void {
		$hasIncoming = array_key_exists($path, $this->sources['incoming']);
		$hasLive     = array_key_exists($path, $this->sources['live']);

		$this->keepLiveAndBaseline(path: $path);

		$this->conflicts[] = [
			'path' => $path,
			'shipped' => ($this->sources['incoming'][$path] ?? null),
			'live' => ($this->sources['live'][$path] ?? null),
			'shippedPresent' => $hasIncoming,
			'livePresent' => $hasLive,
		];
	}//end foldConflict()

	/**
	 * Keep the live value, and keep the baseline where it was.
	 *
	 * @param string|integer $path The flattened descriptor path.
	 *
	 * @return void
	 */
	private function keepLiveAndBaseline(string|int $path): void {
		if (array_key_exists($path, $this->sources['live']) === true) {
			$this->mergedParts[$path] = $this->sources['live'][$path];
		}

		if (array_key_exists($path, $this->sources['baseline']) === true) {
			$this->nextBaseline[$path] = $this->sources['baseline'][$path];
		}
	}//end keepLiveAndBaseline()

	/**
	 * Fold a path neither side disputes.
	 *
	 * @param string|integer $path The flattened descriptor path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function foldSettled(string|int $path): void {
		if (array_key_exists($path, $this->sources['live']) === true) {
			$this->mergedParts[$path] = $this->sources['live'][$path];
		}

		if (array_key_exists($path, $this->sources['incoming']) === true) {
			$this->nextBaseline[$path] = $this->sources['incoming'][$path];
		}
	}//end foldSettled()
}//end class
