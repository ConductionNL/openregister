<?php

/**
 * Whether a run can move to another version of its flow, and where it lands.
 *
 * 🔴 THE MARKING IS THE CONTRACT (D-2). A run IS its marking. Validation asks,
 * for every place holding a token, whether the target has a node of the same
 * kind under the same id or under the mapping. Same id and kind needs no
 * mapping; a rename needs one; a removed node fails unless it is mapped to a
 * successor. Nothing else about the graph matters to the run, so nothing else
 * is compared: a target that rewrote every edge but kept the nodes a token sits
 * on is a target this run can move to.
 *
 * 🔑 ONE VALIDATOR SERVES BOTH ANSWERS (D-3). The preview and the apply ask
 * THIS object, so what a UI shows before an administrator commits cannot
 * disagree with what happens when they do. Two validators is how a preview
 * promises a migration the write then refuses, and keeping the question in its
 * own class is what stops a second one growing beside the write path.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;

/**
 * Answers whether a run's marking fits a target version, and where it lands.
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
 */
class FlowRunMigrationValidator {

	/**
	 * The statuses a run can be migrated in.
	 *
	 * 🔴 A FINISHED RUN IS NOT MIGRATED, IT IS REWRITTEN. Moving a completed or
	 * failed run onto another version changes the record of what already
	 * happened, which is the one thing a run log exists to prevent. Only a run
	 * that still has somewhere to go can be moved.
	 *
	 * @var array<int, string>
	 */
	public const MIGRATABLE_STATUSES = ['queued', 'running', 'suspended', 'parked', 'waiting'];

	/**
	 * Constructor.
	 *
	 * @param FlowVersionService $versions The versions of a flow and their graphs.
	 */
	public function __construct(
		private readonly FlowVersionService $versions,
	) {
	}//end __construct()

	/**
	 * Whether this run's marking fits the target, and where it would land.
	 *
	 * @param FlowRun          $run       The run.
	 * @param FlowRunMigration $migration The version asked for and its old-to-new node id mapping.
	 *
	 * @return array{ok: bool, marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function validate(FlowRun $run, FlowRunMigration $migration): array {
		$targetVersion = $migration->targetVersion;
		if (in_array((string)$run->getStatus(), self::MIGRATABLE_STATUSES, true) === false) {
			return $this->statusRefusal(run: $run);
		}

		$nodes = $this->nodesOf(flowId: (string)$run->getFlowId(), version: $targetVersion);
		if ($nodes === null) {
			return $this->refusal(reason: 'Version ' . $targetVersion . ' of this flow could not be read, so nothing was migrated.');
		}

		$sourceNodes = $this->nodesOf(flowId: (string)$run->getFlowId(), version: (int)$run->getFlowVersion());
		$remap = new FlowRunMarkingRemap(nodes: $nodes, sourceNodes: $sourceNodes, mapping: $migration->mapping());

		return $this->outcome(targetVersion: $targetVersion, remapped: $this->remapMarking(run: $run, remap: $remap));
	}//end validate()

	/**
	 * The refusal for a run whose status leaves nothing to move.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return array{ok: bool, marking: array<string, int>, unmapped: array<int, string>, reason: string} The refusal.
	 */
	private function statusRefusal(FlowRun $run): array {
		return $this->refusal(
			reason: 'This run is ' . (string)$run->getStatus()
				. ', so there is nothing left to move. Migrating a finished run would rewrite what already happened.'
		);
	}//end statusRefusal()

	/**
	 * The verdict on a remapped marking: refused while any place is unmapped, the new marking otherwise.
	 *
	 * @param integer                                                      $targetVersion The version being migrated to.
	 * @param array{marking: array<string, int>, unmapped: array<int, string>} $remapped      The remapped marking.
	 *
	 * @return array{ok: bool, marking: array<string, int>, unmapped: array<int, string>, reason: string} The verdict.
	 */
	private function outcome(int $targetVersion, array $remapped): array {
		if ($remapped['unmapped'] !== []) {
			return $this->refusal(
				reason: $this->unmappedReason(targetVersion: $targetVersion, unmapped: $remapped['unmapped']),
				unmapped: $remapped['unmapped']
			);
		}

		return ['ok' => true, 'marking' => $remapped['marking'], 'unmapped' => [], 'reason' => ''];
	}//end outcome()

	/**
	 * A refused verdict.
	 *
	 * @param string             $reason   Why the run cannot move.
	 * @param array<int, string> $unmapped The places with nowhere to land, if that is why.
	 *
	 * @return array{ok: bool, marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function refusal(string $reason, array $unmapped = []): array {
		return [
			'ok' => false,
			'marking' => [],
			'unmapped' => $unmapped,
			'reason' => $reason,
		];
	}//end refusal()

	/**
	 * The reason a run with unmappable places cannot move.
	 *
	 * @param int                $targetVersion The version asked for.
	 * @param array<int, string> $unmapped      The places with nowhere to land, at least one.
	 *
	 * @return string The reason, naming the places.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function unmappedReason(int $targetVersion, array $unmapped): string {
		$unmappedPronoun = 'them';
		if (count($unmapped) === 1) {
			$unmappedPronoun = 'it';
		}

		return 'Version ' . $targetVersion . ' has nowhere for this run to land: '
			. implode(', ', $unmapped) . '. Map ' . $unmappedPronoun
			. ' to a node of the same kind, or leave the run where it is.';
	}//end unmappedReason()

	/**
	 * Where each token would land on the target version, and what would not.
	 *
	 * @param FlowRun             $run   The run.
	 * @param FlowRunMarkingRemap $remap The target's nodes, the run's own nodes and the node mapping.
	 *
	 * @return array{marking: array<string, int>, unmapped: array<int, string>} The remapped marking.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function remapMarking(FlowRun $run, FlowRunMarkingRemap $remap): array {
		$marking = [];
		$unmapped = [];

		foreach ($this->markingOf(run: $run) as $place => $tokens) {
			[$nodeId, $suffix] = $this->splitPlace(place: (string)$place);
			$targetId = ($remap->mapping[$nodeId] ?? $nodeId);

			if (array_key_exists($targetId, $remap->nodes) === false) {
				$unmapped[] = (string)$place;
				continue;
			}

			// THE KIND HAS TO MATCH TOO. A mapping that points a user task at a
			// gateway would land a token somewhere the engine cannot resume
			// from, and the run would park forever with nothing saying why.
			// An UNKNOWN kind on either side is not a mismatch: a graph that
			// does not declare one has nothing to disagree about.
			$source = (($remap->sourceNodes ?? [])[$nodeId] ?? []);
			if ($this->kindsClash(source: $source, target: $remap->nodes[$targetId]) === true) {
				$unmapped[] = (string)$place;
				continue;
			}

			$marking[$targetId . $suffix] = (int)$tokens;
		}

		return [
			'marking' => $marking,
			'unmapped' => $unmapped,
		];
	}//end remapMarking()

	/**
	 * Whether a source node and its target declare different kinds; an unknown kind never clashes.
	 *
	 * @param array<string, mixed> $source The source node, or [] when the run's own graph does not hold it.
	 * @param array<string, mixed> $target The target node.
	 *
	 * @return bool True on a mismatch of two known kinds.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function kindsClash(array $source, array $target): bool {
		$from = $this->kindOf(node: $source);
		$to = $this->kindOf(node: $target);
		return $from !== '' && $to !== '' && $from !== $to;
	}//end kindsClash()

	/**
	 * The nodes of one version, keyed by id, or null when unreadable.
	 *
	 * @param string $flowId  The flow.
	 * @param int    $version The version.
	 *
	 * @return array<string, array<string, mixed>>|null The nodes.
	 */
	private function nodesOf(string $flowId, int $version): ?array {
		$nodes = $this->graphNodesOf(flowId: $flowId, version: $version);
		if ($nodes === null) {
			return null;
		}

		return $this->keyNodes(nodes: $nodes);
	}//end nodesOf()

	/**
	 * The raw `nodes` list of one stored version, or null when it cannot be read.
	 *
	 * @param string $flowId  The flow.
	 * @param int    $version The version number.
	 *
	 * @return array<int|string, mixed>|null The nodes.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function graphNodesOf(string $flowId, int $version): ?array {
		$found = $this->versions->versionOf(flowUuid: $flowId, number: $version);
		if ($found === null) {
			return null;
		}

		$graph = $this->versions->graphOfVersion(version: $found);
		if (is_array($graph) === false) {
			return null;
		}

		$nodes = ($graph['nodes'] ?? []);
		if (is_array($nodes) === false) {
			return null;
		}

		return $nodes;
	}//end graphNodesOf()

	/**
	 * Key a version's nodes by id; a node without one falls back to its string key.
	 *
	 * @param array<int|string, mixed> $nodes The raw nodes.
	 *
	 * @return array<string, array<string, mixed>> The nodes by id; junk and blank ids are skipped.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function keyNodes(array $nodes): array {
		$keyed = [];
		foreach ($nodes as $key => $node) {
			if (is_array($node) === false) {
				continue;
			}

			$id = $this->nodeIdOf(key: $key, node: $node);
			if ($id !== '') {
				$keyed[$id] = $node;
			}
		}

		return $keyed;
	}//end keyNodes()

	/**
	 * A node's trimmed id, or its string key when it declares none.
	 *
	 * @param int|string           $key  The node's key in the list.
	 * @param array<string, mixed> $node The node.
	 *
	 * @return string The id, possibly empty.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function nodeIdOf(int|string $key, array $node): string {
		$fallbackId = '';
		if (is_string($key) === true) {
			$fallbackId = $key;
		}

		return trim((string)($node['id'] ?? $fallbackId));
	}//end nodeIdOf()

	/**
	 * The run's marking as `place => tokens`.
	 *
	 * The same normalisation {@see FlowRunMarkingStore} does, because a
	 * hand-authored run can hold a list of place names instead of a map and a
	 * migration that read only one shape would silently move nothing.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return array<string, int> The marking.
	 */
	private function markingOf(FlowRun $run): array {
		return FlowRunCommit::normaliseMarking(places: ($run->getMarking() ?? []));
	}//end markingOf()

	/**
	 * Split a place into its node id and its join suffix.
	 *
	 * A declared join holds one place per incoming edge, named
	 * `<nodeId>#<edgeId>`. The suffix travels with the token: a join that is
	 * still a join in the target is still waiting on the same edges, and
	 * dropping the suffix would collapse a half-arrived join into one place and
	 * fire it early.
	 *
	 * @param string $place The place.
	 *
	 * @return array{0: string, 1: string} The node id and the suffix.
	 */
	private function splitPlace(string $place): array {
		$joinAt = strpos($place, FlowGraph::PLACE_JOIN);
		if ($joinAt === false) {
			return [$place, ''];
		}

		return [substr($place, 0, $joinAt), substr($place, $joinAt)];
	}//end splitPlace()

	/**
	 * The kind of a node, or '' when it declares none.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return string The kind.
	 */
	private function kindOf(array $node): string {
		return trim((string)($node['type'] ?? ($node['kind'] ?? '')));
	}//end kindOf()

}//end class
