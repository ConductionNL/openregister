<?php

/**
 * The flow lifecycle transitions: publish, create a draft, deprecate.
 *
 * 🔴 EACH TRANSITION IS ONE TRANSACTION, and the reason is an invariant that
 * every dispatch path depends on: a flow has AT MOST ONE published version.
 * Publishing is "mark N+1 published" AND "mark N deprecated" AND "rebuild the
 * trigger set" — three writes describing one event. Committed separately, the
 * window between them is a window in which a flow has two published versions
 * or none, and an object write landing in that window queues a run against
 * whichever the reader happened to see.
 *
 * 🔑 THE TRIGGER SET IS DERIVED FROM THE PUBLISHED VERSION AND FROM NOTHING
 * ELSE. That is what makes "a draft's trigger nodes match nothing" true by
 * construction rather than by a filter somebody has to remember on the read
 * path — the rows simply are not there.
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

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes, drafts and deprecates flow versions.
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class FlowVersionService {
	/**
	 * Constructor.
	 *
	 * @param FlowVersionMapper  $versions The version rows.
	 * @param FlowMapper         $flows    The flows whose head mirrors the versions.
	 * @param FlowDefinitionPin  $pin      Canonicalises and stores a graph by hash.
	 * @param FlowLifecycleGuard $guard    The preconditions on every transition.
	 * @param FlowTriggerIndex   $triggers The derived trigger rows.
	 * @param IDBConnection      $db       Wraps each transition in one transaction.
	 * @param LoggerInterface    $logger   Diagnostics.
	 * @param IUserSession       $session  Names who published a version.
	 */
	public function __construct(
		private readonly FlowVersionMapper $versions,
		private readonly FlowMapper $flows,
		private readonly FlowDefinitionPin $pin,
		private readonly FlowLifecycleGuard $guard,
		private readonly FlowTriggerIndex $triggers,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $session,
	) {

	}//end __construct()

	/**
	 * Every version of a flow, newest first.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return FlowVersion[] The versions.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function versionsOf(string $flowUuid): array {
		return $this->versions->findAllForFlow(flowUuid: $flowUuid);

	}//end versionsOf()

	/**
	 * One version of a flow.
	 *
	 * @param string  $flowUuid The flow.
	 * @param integer $number   The version number.
	 *
	 * @return FlowVersion|null The version, or null.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function versionOf(string $flowUuid, int $number): ?FlowVersion {
		return $this->versions->find(flowUuid: $flowUuid, version: $number);

	}//end versionOf()

	/**
	 * The graph a version names.
	 *
	 * @param FlowVersion $version The version.
	 *
	 * @return array<string, mixed>|null The graph, or null when unresolvable.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function graphOfVersion(FlowVersion $version): ?array {
		return $this->pin->graphFor($version->getDefinitionHash());

	}//end graphOfVersion()

	/**
	 * The graph of a flow's head, as a definition document.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return array<string, mixed> The graph.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function graphOf(Flow $flow): array {
		return [
			'nodes' => ($flow->getNodes() ?? []),
			'edges' => ($flow->getEdges() ?? []),
			'limits' => ($flow->getLimits() ?? []),
			'executionMode' => (string)($flow->getExecutionMode() ?? Flow::MODE_ASYNC),
		];

	}//end graphOf()

	/**
	 * Publish the flow's head, deprecating whatever was published before it.
	 *
	 * @param Flow        $flow        The flow whose head is being published.
	 * @param string|null $publishedBy The acting user.
	 *
	 * @return FlowVersion The now-published version.
	 *
	 * @throws FlowLifecycleRefused When the head is not a draft, or has a dead end.
	 * @throws Throwable            When the transaction could not be committed.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function publish(Flow $flow, ?string $publishedBy = null): FlowVersion {
		$flowId = (string)$flow->getUuid();
		// Resolved here rather than at each caller: an install-time import has
		// no session and passes null, a request has one and should not have to
		// remember to pass it.
		$publishedBy = ($publishedBy ?? $this->session->getUser()?->getUID());
		$graph = $this->graphOf(flow: $flow);

		// Guards BEFORE the transaction opens. A refusal is not a rollback —
		// nothing should have been attempted — and opening a transaction to
		// immediately abort it would leave the refusal looking like a failure
		// in the log rather than a decision.
		$this->guard->refusePublishUnlessDraft(flowId: $flowId, state: $flow->getLifecycleStatus());
		$this->guard->refusePublishOnDeadEnd(flowId: $flowId, graph: $graph);

		$hash = $this->pin->pin(flow: $graph, flowId: $flowId);
		if ($hash === null) {
			throw new FlowLifecycleRefused(
				reason: FlowLifecycleRefused::REASON_IMMUTABLE,
				flowId: $flowId,
				state: $flow->getLifecycleStatus(),
				detail: 'The definition could not be stored, so the version would name a graph that is not there.'
			);
		}

		$this->db->beginTransaction();

		try {
			$previous = $this->versions->findPublished(flowUuid: $flowId);
			if ($previous !== null) {
				$previous->setStatus(FlowVersion::STATUS_DEPRECATED);
				$previous->setDeprecatedAt(new DateTime());
				$this->versions->update($previous);
			}

			$version = $this->headVersionRow(flow: $flow, hash: $hash);
			$version->setStatus(FlowVersion::STATUS_PUBLISHED);
			$version->setPublishedAt(new DateTime());
			$version->setPublishedBy($publishedBy);
			$version->setDefinitionHash($hash);
			$stored = $this->persist(version: $version);

			$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
			$flow->setVersion($stored->getVersion());
			$this->flows->update($flow);

			// Inside the transaction, deliberately. The trigger rows and the
			// published version are one fact: a commit that carried the new
			// version but not its triggers would leave the flow published and
			// subscribed to nothing.
			$this->triggers->reindex(flow: $flow);

			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			$this->logger->error(
				message: '[FlowVersionService] Publish failed for flow "' . $flowId . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowId]
			);
			throw $e;
		}//end try

		return $stored;

	}//end publish()

	/**
	 * Copy the published graph into a new draft at version N+1.
	 *
	 * The published version keeps serving — it stays `published` and keeps its
	 * trigger rows — until the draft is itself published. That is the whole
	 * point of a draft: editing must not take a live process offline.
	 *
	 * @param Flow $flow The flow to draft from.
	 *
	 * @return FlowVersion The new draft version row.
	 *
	 * @throws Throwable When the transaction could not be committed.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function createDraft(Flow $flow): FlowVersion {
		$flowId = (string)$flow->getUuid();

		$this->db->beginTransaction();

		try {
			$next = ($this->versions->highestVersion(flowUuid: $flowId) + 1);

			$draft = new FlowVersion();
			$draft->setFlowUuid($flowId);
			$draft->setVersion($next);
			$draft->setStatus(FlowVersion::STATUS_DRAFT);
			$draft->setDefinitionHash((string)$this->pin->pin(flow: $this->graphOf(flow: $flow), flowId: $flowId));
			$draft->setOwner($flow->getOwner());
			$draft->setOrganisation($flow->getOrganisation());
			$draft->setCreated(new DateTime());
			$stored = $this->versions->insert($draft);

			// The head becomes the draft. The flow's stored graph is already
			// the published graph, so the draft starts as an exact copy — an
			// author who opens it and changes nothing can publish it back to
			// an identical hash, which dedupes to the same definition row.
			$flow->setLifecycleStatus(FlowVersion::STATUS_DRAFT);
			$flow->setVersion($next);
			$this->flows->update($flow);

			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			$this->logger->error(
				message: '[FlowVersionService] Could not create a draft of flow "' . $flowId . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowId]
			);
			throw $e;
		}//end try

		return $stored;

	}//end createDraft()

	/**
	 * Retire the published version, leaving the flow backing no new runs.
	 *
	 * Runs already pinned to it keep running: deprecation is about what may
	 * START, never about what is already in flight.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return FlowVersion The deprecated version.
	 *
	 * @throws FlowLifecycleRefused When there is no published version.
	 * @throws Throwable            When the transaction could not be committed.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function deprecate(Flow $flow): FlowVersion {
		$flowId = (string)$flow->getUuid();
		$published = $this->versions->findPublished(flowUuid: $flowId);

		$this->guard->refuseDeprecateUnlessPublished(
			flowId: $flowId,
			state: $published?->getStatus()
		);

		$this->db->beginTransaction();

		try {
			$published->setStatus(FlowVersion::STATUS_DEPRECATED);
			$published->setDeprecatedAt(new DateTime());
			$stored = $this->versions->update($published);

			$flow->setLifecycleStatus(FlowVersion::STATUS_DEPRECATED);
			$this->flows->update($flow);

			// A deprecated flow subscribes to nothing. Reindexing from a flow
			// whose lifecycle is no longer published clears its rows, which is
			// what "MUST NOT back a new run" means on the trigger path.
			$this->triggers->forget(flowUuid: $flowId);

			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			$this->logger->error(
				message: '[FlowVersionService] Could not deprecate flow "' . $flowId . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowId]
			);
			throw $e;
		}//end try

		return $stored;

	}//end deprecate()

	/**
	 * The version row for the flow's head, creating it when it does not exist.
	 *
	 * A flow saved before versioning, or created without an explicit draft
	 * row, still has a head graph. This makes the row that names it rather
	 * than refusing — the alternative is an upgrade path where publishing an
	 * existing flow fails because of a row nobody ever asked for.
	 *
	 * @param Flow   $flow The flow.
	 * @param string $hash The hash of the head graph.
	 *
	 * @return FlowVersion The head's version row, unsaved changes included.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	private function headVersionRow(Flow $flow, string $hash): FlowVersion {
		$flowId = (string)$flow->getUuid();
		$number = (int)($flow->getVersion() ?? 1);

		$existing = $this->versions->find(flowUuid: $flowId, version: $number);
		if ($existing !== null) {
			return $existing;
		}

		$version = new FlowVersion();
		$version->setFlowUuid($flowId);
		$version->setVersion($number);
		$version->setStatus(FlowVersion::STATUS_DRAFT);
		$version->setDefinitionHash($hash);
		$version->setOwner($flow->getOwner());
		$version->setOrganisation($flow->getOrganisation());
		$version->setCreated(new DateTime());

		return $version;

	}//end headVersionRow()

	/**
	 * Insert or update, depending on whether the row has been stored yet.
	 *
	 * @param FlowVersion $version The version to write.
	 *
	 * @return FlowVersion The stored version.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	private function persist(FlowVersion $version): FlowVersion {
		if ($version->getId() === null) {
			return $this->versions->insert($version);
		}

		return $this->versions->update($version);

	}//end persist()
}//end class
