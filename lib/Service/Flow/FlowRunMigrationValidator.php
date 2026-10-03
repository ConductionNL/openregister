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
	 * @param FlowRun               $run           The run.
	 * @param int                   $targetVersion The version asked for.
	 * @param array<string, string> $mapping       Old node id to new node id.
	 *
	 * @return array{ok: bool, marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function validate(FlowRun $run, int $targetVersion, array $mapping = []): array {
		if (in_array((string)$run->getStatus(), self::MIGRATABLE_STATUSES, true) === false) {
			return [
				'ok' => false,
				'marking' => [],
				'unmapped' => [],
				'reason' => 'This run is ' . (string)$run->getStatus()
					. ', so there is nothing left to move. Migrating a finished run would rewrite what already happened.',
			];
		}

		$nodes = $this->nodesOf(flowId: (string)$run->getFlowId(), version: $targetVersion);
		if ($nodes === null) {
			return [
				'ok' => false,
				'marking' => [],
				'unmapped' => [],
				'reason' => 'Version ' . $targetVersion . ' of this flow could not be read, so nothing was migrated.',
			];
		}

		$sourceNodes = $this->nodesOf(flowId: (string)$run->getFlowId(), version: (int)$run->getFlowVersion());

		['marking' => $marking, 'unmapped' => $unmapped] = $this->remapMarking(
			run: $run,
			nodes: $nodes,
			sourceNodes: $sourceNodes,
			mapping: $mapping
		);

		if ($unmapped !== []) {
			$unmappedPronoun = 'them';
			if (count($unmapped) === 1) {
				$unmappedPronoun = 'it';
			}

			return [
				'ok' => false,
				'marking' => [],
				'unmapped' => $unmapped,
				'reason' => 'Version ' . $targetVersion . ' has nowhere for this run to land: '
					. implode(', ', $unmapped) . '. Map ' . $unmappedPronoun
					. ' to a node of the same kind, or leave the run where it is.',
			];
		}

		return ['ok' => true, 'marking' => $marking, 'unmapped' => [], 'reason' => ''];
	}//end validate()

	/**
	 * Where each token would land on the target version, and what would not.
	 *
	 * @param FlowRun               $run         The run.
	 * @param array<string, mixed>  $nodes       The target version's nodes, by id.
	 * @param array<string, mixed>|null $sourceNodes The run's own version's nodes, by id.
	 * @param array<string, string> $mapping     Old node id to new node id.
	 *
	 * @return array{marking: array<string, int>, unmapped: array<int, string>} The remapped marking.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function remapMarking(FlowRun $run, array $nodes, ?array $sourceNodes, array $mapping): array {
		$marking = [];
		$unmapped = [];

		foreach ($this->markingOf(run: $run) as $place => $tokens) {
			[$nodeId, $suffix] = $this->splitPlace(place: (string)$place);
			$targetId = ($mapping[$nodeId] ?? $nodeId);

			if (array_key_exists($targetId, $nodes) === false) {
				$unmapped[] = (string)$place;
				continue;
			}

			// THE KIND HAS TO MATCH TOO. A mapping that points a user task at a
			// gateway would land a token somewhere the engine cannot resume
			// from, and the run would park forever with nothing saying why.
			// An UNKNOWN kind on either side is not a mismatch: a graph that
			// does not declare one has nothing to disagree about.
			$from = $this->kindOf(node: (($sourceNodes ?? [])[$nodeId] ?? []));
			$to = $this->kindOf(node: $nodes[$targetId]);
			if ($from !== '' && $to !== '' && $from !== $to) {
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
	 * The nodes of one version, keyed by id, or null when unreadable.
	 *
	 * @param string $flowId  The flow.
	 * @param int    $version The version.
	 *
	 * @return array<string, array<string, mixed>>|null The nodes.
	 */
	private function nodesOf(string $flowId, int $version): ?array {
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

		$keyed = [];
		foreach ($nodes as $key => $node) {
			if (is_array($node) === false) {
				continue;
			}

			$fallbackId = '';
			if (is_string($key) === true) {
				$fallbackId = $key;
			}

			$id = trim((string)($node['id'] ?? $fallbackId));
			if ($id !== '') {
				$keyed[$id] = $node;
			}
		}

		return $keyed;
	}//end nodesOf()

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
		$places = ($run->getMarking() ?? []);
		if (is_array($places) === false) {
			return [];
		}

		$normalised = [];
		foreach ($places as $key => $value) {
			if (is_int($key) === true) {
				$normalised[(string)$value] = 1;
				continue;
			}

			$normalised[(string)$key] = max(1, (int)$value);
		}

		return $normalised;
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
