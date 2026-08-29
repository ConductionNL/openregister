<?php

/**
 * Answers "which version of this flow backs a run queued right now?".
 *
 * Built with the container rather than injected, matching {@see FlowRunAttribution}
 * and {@see FlowDelegationCheck} on the same method: `FlowRunService` is
 * constructed by hand in four test suites, so inserting constructor parameters
 * there shifts every later slot for them.
 *
 * 🔴 THIS IS THE ONLY PLACE A DISPATCH LEARNS ITS VERSION. All six dispatch
 * paths — manual, object trigger, schedule, MCP, workflow-engine operation and
 * sub-flow call — funnel through `FlowRunService::queue()`, so pinning here
 * covers them by construction rather than by six call sites remembering to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the published version a new run must be pinned to.
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class FlowRunVersionPin {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the version mapper.
	 * @param LoggerInterface    $logger    Diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The published version of a flow, or null when it has none.
	 *
	 * @param string $flowId The flow being dispatched.
	 *
	 * @return FlowVersion|null The published version, or null.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function publishedVersionOf(string $flowId): ?FlowVersion {
		try {
			$mapper = $this->container->get(FlowVersionMapper::class);
		} catch (Throwable $e) {
			// 🔴 NOT a fail-open. The caller refuses the dispatch when this is
			// null, exactly as it does for a flow with no published version.
			// An unresolvable mapper means we cannot establish which graph a
			// run would execute, and "run it against whatever is current" is
			// the behaviour versioning exists to remove.
			$this->logger->error(
				message: '[FlowRunVersionPin] Could not resolve the version mapper for flow "'
					. $flowId . '"; the dispatch is refused rather than run unpinned.',
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowId]
			);

			return null;
		}

		if ($mapper instanceof FlowVersionMapper === false) {
			return null;
		}

		try {
			return $mapper->findPublished(flowUuid: $flowId);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowRunVersionPin] Could not read the published version of flow "'
					. $flowId . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowId]
			);

			return null;
		}

	}//end publishedVersionOf()

	/**
	 * The graph a version names.
	 *
	 * @param FlowVersion|null $version The version.
	 *
	 * @return array<string, mixed>|null The graph, or null when unresolvable.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function graphOf(?FlowVersion $version): ?array {
		if ($version === null) {
			return null;
		}

		try {
			return $this->container->get(FlowDefinitionPin::class)
				->graphFor($version->getDefinitionHash());
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowRunVersionPin] Could not read the definition of version '
					. $version->getVersion() . ' of flow "' . $version->getFlowUuid() . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return null;
		}

	}//end graphOf()

	/**
	 * The published version of a flow, refusing when there is none or it is unsound.
	 *
	 * Both questions in one call because a dispatch has no use for either
	 * answer alone: knowing the version without knowing the graph is sound
	 * lets a run start and stop halfway; checking a graph without knowing
	 * which version it belongs to checks the wrong document.
	 *
	 * @param string $flowId  The flow being dispatched.
	 * @param string $trigger What caused the dispatch, for the log.
	 *
	 * @return FlowVersion The published version, guaranteed sound.
	 *
	 * @throws FlowLifecycleRefused When the flow has no published version.
	 * @throws FlowDeadEnd          When that version has a node a token cannot leave.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function requirePublishedAndSound(string $flowId, string $trigger): FlowVersion {
		$version = $this->publishedVersionOf(flowId: $flowId);

		if ($version === null) {
			$this->logger->warning(
				message: '[FlowRunVersionPin] Refused to queue a run of an unpublished flow',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'flow' => $flowId,
					'trigger' => $trigger,
				]
			);

			throw new FlowLifecycleRefused(
				reason: FlowLifecycleRefused::REASON_NO_PUBLISHED_VERSION,
				flowId: $flowId,
				state: null
			);
		}

		$this->refuseDeadEnd(flowId: $flowId, graph: $this->graphOf(version: $version));

		return $version;

	}//end requirePublishedAndSound()

	/**
	 * Refuse a graph that has a node its token cannot leave.
	 *
	 * 🔑 JUDGES THE GRAPH IT IS GIVEN — the version being pinned — never the
	 * flow's editable head. Judging the head would let a half-authored draft
	 * refuse runs of a sound published version, and let a repaired draft mask
	 * a dead end in the version actually going live.
	 *
	 * The flow's own status is still written, because that is the surface an
	 * author looks at, and a refusal nobody can see is a refusal that reads as
	 * "nothing happened".
	 *
	 * @param string     $flowId The flow uuid.
	 * @param array|null $graph  The graph of the version being pinned, or null.
	 *
	 * @return void
	 *
	 * @throws FlowDeadEnd When a non-terminal node has no outgoing edge.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function refuseDeadEnd(string $flowId, ?array $graph): void {
		if ($graph === null) {
			// Not a dead end, and it must not be reported as one: the advancer
			// fails the run naming the version, which is the accurate error.
			return;
		}

		try {
			$mapper = $this->container->get('OCA\OpenRegister\Db\FlowMapper');
			$preflight = $this->container->get('OCA\OpenRegister\Service\Flow\FlowNodePreflight');
			$flow = $mapper->findByUuid($flowId);
		} catch (Throwable $e) {
			// A flow we cannot load is not a flow we can judge — `findByUuid()`
			// throws rather than returning null. Queueing proceeds and the
			// existing not-found handling downstream reports it; inventing a
			// dead-end refusal here would blame the document for a lookup
			// failure.
			return;
		}

		$deadEnds = $this->deadEndsIn(preflight: $preflight, graph: $graph);

		if ($deadEnds === []) {
			// Accepted. Clear a stale refusal so a repaired flow stops
			// reporting the error it no longer has.
			if ($flow->getStatus() === Flow::STATUS_ERROR) {
				$flow->setStatus(Flow::STATUS_OK);
				$flow->setStatusMessage(null);
				$mapper->update($flow);
			}

			return;
		}

		$refusal = new FlowDeadEnd(nodeIds: $deadEnds);

		$flow->setStatus(Flow::STATUS_ERROR);
		$flow->setStatusMessage($refusal->getMessage());
		$mapper->update($flow);

		$this->logger->warning(
			message: '[FlowRunVersionPin] Refused to queue a flow with a dead end',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'flow' => $flowId,
				'nodes' => $deadEnds,
			]
		);

		throw $refusal;

	}//end refuseDeadEnd()

	/**
	 * The ids of the steps a token cannot leave.
	 *
	 * @param FlowNodePreflight    $preflight The inspector.
	 * @param array<string, mixed> $graph     The graph being judged.
	 *
	 * @return array<int, string> The offending step ids.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function deadEndsIn(FlowNodePreflight $preflight, array $graph): array {
		$findings = $preflight->inspect(
			flow: [
				'nodes' => (array)($graph['nodes'] ?? []),
				'edges' => (array)($graph['edges'] ?? []),
			]
		);

		$deadEnds = [];
		foreach (($findings['warnings'] ?? []) as $warning) {
			if (($warning['reason'] ?? '') === FlowNodePreflight::REASON_DEAD_END) {
				$deadEnds[] = (string)($warning['step'] ?? '');
			}
		}

		return $deadEnds;

	}//end deadEndsIn()
}//end class
