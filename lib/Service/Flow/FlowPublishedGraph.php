<?php

/**
 * The graph a flow's published version names.
 *
 * 🔑 THE TRIGGER SET IS DERIVED FROM THE PUBLISHED VERSION AND FROM NOTHING
 * ELSE. Deriving it from the flow's head — which is what happened before
 * versioning — would subscribe a DRAFT's trigger nodes and unsubscribe the
 * published version's, which is both rules backwards. Doing it here, from the
 * published row, is what makes "a draft's trigger nodes match nothing" true by
 * construction rather than by a filter on the read path somebody must remember.
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

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowVersionMapper;
use Psr\Container\ContainerInterface;

/**
 * Resolves a flow's published graph and keeps its trigger rows in step with it.
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class FlowPublishedGraph {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the version mapper and the pin.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The published graph of a flow, or null when it has no published version.
	 *
	 * @param string $flowId The flow.
	 *
	 * @return array<string, mixed>|null The published graph, or null.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function graphOf(string $flowId): ?array {
		$published = $this->container->get(FlowVersionMapper::class)->findPublished(flowUuid: $flowId);
		if ($published === null) {
			return null;
		}

		return $this->container->get(FlowDefinitionPin::class)->graphFor($published->getDefinitionHash());

	}//end graphOf()

	/**
	 * The graph of the version a RUN is pinned to.
	 *
	 * 🔴 THE SINGLE RESOLVER. Four call sites hand a flow document to
	 * `FlowRunService::execute()`, and three of them had resolved it LIVE — so
	 * a pinned run reached the engine and then walked the current graph anyway.
	 * Every one of them now goes through this, because a rule with four
	 * implementations is a rule with four chances to drift.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return array<string, mixed>|null The pinned graph, or null when the run
	 *                                   carries no version or it is unresolvable.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function ofRun(FlowRun $run): ?array {
		$version = $run->getFlowVersion();
		if ($version === null) {
			return null;
		}

		$row = $this->container->get(FlowVersionMapper::class)
			->find(flowUuid: (string)$run->getFlowId(), version: (int)$version);

		if ($row === null) {
			return null;
		}

		return $this->container->get(FlowDefinitionPin::class)->graphFor($row->getDefinitionHash());

	}//end ofRun()

	/**
	 * A live document with the run's pinned graph laid over it.
	 *
	 * 🔑 THE GRAPH, AND ONLY THE GRAPH. `owner` and `organisation` stay as the
	 * LIVE document has them, because authorization must re-resolve on every
	 * pass: a revoked grant has to stop the next hop of a run queued while it
	 * was still valid. Pinning those too would make revocation cosmetic for
	 * exactly the long-running work nobody is watching.
	 *
	 * @param FlowRun              $run  The run.
	 * @param array<string, mixed> $live The live flow document.
	 *
	 * @return array<string, mixed>|null The document to walk, or null when the
	 *                                   run is pinned to a version that is gone.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function overlayOnto(FlowRun $run, array $live): ?array {
		// An unpinned run is the interactive draft test run — the one
		// documented exception to "a draft cannot back a run". It carries its
		// own snapshot and walks the document it was given.
		if ($run->getFlowVersion() === null) {
			return $live;
		}

		$pinned = $this->ofRun(run: $run);
		if ($pinned === null) {
			return null;
		}

		foreach (['nodes', 'edges', 'limits', 'executionMode'] as $key) {
			if (array_key_exists($key, $pinned) === true) {
				$live[$key] = $pinned[$key];
			}
		}

		return $live;

	}//end overlayOnto()

}//end class
