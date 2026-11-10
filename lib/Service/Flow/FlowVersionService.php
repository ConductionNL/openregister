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
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapperInterface;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes, drafts and deprecates flow versions.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A version transition is the
 * point where a flow's separate concerns have to meet: the version rows, the
 * flow whose head mirrors them, the definition store the graph is pinned in,
 * the lifecycle guard, the trigger index that must move in the same
 * transaction, the numbering, the connection that makes it one transaction,
 * and the log. Every one of those is named because publishing genuinely
 * coordinates all of them, and splitting the class would move the coupling
 * into a caller rather than remove it — with the transaction boundary, which
 * is the thing that makes a publish safe, spread across two objects.
 *
 * ⚠️ THIS WAS ALREADY OVER THE THRESHOLD, at 13 against a limit of "under
 * 13", and `development`'s phpmd job was already failing because of it. The
 * semantic-version work took it to 16; moving the comparison and the numbering
 * into FlowSemanticVersion brought it back to 15, which is a real reduction
 * and still over. The suppression states the reason rather than raising the
 * limit for every class in the app.
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */
class FlowVersionService implements FlowVersionServiceInterface {

	/**
	 * The version rows.
	 *
	 * @var FlowVersionMapperInterface
	 */
	private readonly FlowVersionMapperInterface $versions;

	/**
	 * The flows whose head mirrors the versions.
	 *
	 * @var FlowMapperInterface
	 */
	private readonly FlowMapperInterface $flows;

	/**
	 * Canonicalises and stores a graph by hash.
	 *
	 * @var FlowDefinitionPin
	 */
	private readonly FlowDefinitionPin $pin;

	/**
	 * The preconditions on every transition.
	 *
	 * @var FlowLifecycleGuard
	 */
	private readonly FlowLifecycleGuard $guard;

	/**
	 * The derived trigger rows.
	 *
	 * @var FlowTriggerIndex
	 */
	private readonly FlowTriggerIndex $triggers;

	/**
	 * What to call the version that results.
	 *
	 * @var FlowSemanticVersion
	 */
	private readonly FlowSemanticVersion $semver;

	/**
	 * Wraps each transition in one transaction.
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * Diagnostics.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param FlowVersionRows   $rows   The version rows, the flows, the transaction connection and the log.
	 * @param FlowVersionGraphs $graphs The definition pin, the lifecycle guard, the trigger index and the numbering.
	 */
	public function __construct(FlowVersionRows $rows, FlowVersionGraphs $graphs) {
		$this->versions = $rows->versions;
		$this->flows    = $rows->flows;
		$this->db       = $rows->db;
		$this->logger   = $rows->logger;
		$this->pin      = $graphs->pin;
		$this->guard    = $graphs->guard;
		$this->triggers = $graphs->triggers;
		$this->semver   = $graphs->semver;

	}//end __construct()

	/**
	 * Every version of a flow, newest first.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return FlowVersion[] The versions.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
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
	 * @spec openspec/specs/flow-definition-versioning/spec.md
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
	 * @spec openspec/specs/flow-definition-versioning/spec.md
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
	 * @spec openspec/specs/flow-definition-versioning/spec.md
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
	 * Publish the flow's head, deprecating whatever was published before it, in one transaction.
	 *
	 * @param Flow               $flow    The flow whose head is being published.
	 * @param FlowPublishOptions $options Who publishes (null: no acting user), and the bump asked for: `major` to
	 *                                    raise the derived verdict, `minor` to assert there was no removal, which is
	 *                                    REFUSED when the diff found one; null lets the diff decide.
	 *
	 * @throws FlowLifecycleRefused When the head is not a draft, or has a dead end.
	 * @throws Throwable            When the transaction could not be committed.
	 *
	 * @return FlowVersion The now-published version.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function publish(Flow $flow, FlowPublishOptions $options=new FlowPublishOptions()): FlowVersion {
		$run = new FlowPublishRun(flow: $flow, options: $options);
		$run->graph = $this->graphOf(flow: $flow);
		$run->hash = $this->pinPublishable(flow: $flow, graph: $run->graph);

		$this->db->beginTransaction();

		try {
			$stored = $this->publishRun(run: $run);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->rollBackPublish(flow: $flow, failure: $e);
			throw $e;
		}

		return $stored;
	}//end publish()

	/**
	 * Inside the transaction: deprecate the old published version and store the head as published.
	 *
	 * @param FlowPublishRun $run The publish.
	 *
	 * @return FlowVersion The stored, published version.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function publishRun(FlowPublishRun $run): FlowVersion {
		$previous = $this->deprecatePublished(flowId: (string)$run->flow->getUuid());

		// THE SEMANTIC VERSION IS DERIVED HERE, against the graph that was
		// PUBLISHED — not against the previous ordinal. They are usually
		// the same and are not always: a deprecated version, or a draft
		// opened and abandoned, leaves a gap, and what a consumer is
		// running is the published one.
		//
		// `$previous` has already been marked deprecated above; its graph
		// is still what was live a moment ago, which is exactly the
		// comparison an author means by "what does this change".
		$run->semver = $this->semver->forPublish(
			publishedGraph: $this->publishedGraphOf(version: $previous),
			candidateGraph: $run->graph,
			previousSemver: $previous?->getSemver(),
			requested: $run->options->bump
		);

		$stored = $this->persist(version: $this->publishedHead(run: $run));
		$this->mirrorPublished(flow: $run->flow, stored: $stored);

		return $stored;
	}//end publishRun()

	/**
	 * The head version row, stamped as published by this run.
	 *
	 * @param FlowPublishRun $run The publish.
	 *
	 * @return FlowVersion The stamped, not yet persisted, row.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function publishedHead(FlowPublishRun $run): FlowVersion {
		$version = $this->headVersionRow(flow: $run->flow, hash: $run->hash);
		$version->setStatus(FlowVersion::STATUS_PUBLISHED);
		$version->setPublishedAt(new DateTime());
		$version->setPublishedBy($run->options->publishedBy);
		$version->setDefinitionHash($run->hash);
		$version->setSemver($run->semver);
		$version->setSemverSource(FlowSemanticVersion::SOURCE_DERIVED);

		return $version;
	}//end publishedHead()

	/**
	 * Roll a failed publish back and say why.
	 *
	 * @param Flow      $flow    The flow whose publish failed.
	 * @param Throwable $failure What went wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function rollBackPublish(Flow $flow, Throwable $failure): void {
		$flowId = (string)$flow->getUuid();
		$this->db->rollBack();
		$this->logger->error(
			message: '[FlowVersionService] Publish failed for flow "' . $flowId . '": ' . $failure->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowId]
		);
	}//end rollBackPublish()

	/**
	 * Publish an app's changed flow declaration as the next version.
	 *
	 * An app that ships a flow in `x-openregister-flows` gets version 1
	 * published on first import. A later app upgrade that CHANGES the
	 * declaration rewrote the flow's head and stopped there, while runs walk
	 * the PUBLISHED version. So the fix an upgrade shipped never ran until
	 * someone opened a draft and published it by hand. Measured on the
	 * Rotterdam stack: version 1 had 32 nodes, the head 147, and every run
	 * still walked the 32.
	 *
	 * Publishes only when the shipped declaration still owns the flow:
	 * - a version is published, and nobody published it (`publishedBy` null
	 *   is how an import publishes; a person's publish names them), so an
	 *   administrator's own version is never replaced by an upgrade;
	 * - the head is not an open draft, so an edit in progress is not
	 *   published under its author;
	 * - the head's graph differs from the published one.
	 *
	 * Otherwise nothing happens and the head waits for a person, exactly as
	 * before. `enabled` and `owner` are untouched: publishing answers which
	 * graph runs, adoption answers whether it runs.
	 *
	 * @param Flow $flow The flow whose head now holds the shipped declaration.
	 *
	 * @return FlowVersion|null The newly published version, or null when nothing was published.
	 *
	 * @throws FlowLifecycleRefused When the shipped graph cannot be published (a dead end).
	 * @throws Throwable            When a transaction could not be committed.
	 *
	 * @spec openspec/changes/shipped-flow-update-is-published/specs/flow-definition-versioning/spec.md#requirement-a-changed-shipped-flow-is-published-as-the-next-version
	 */
	public function publishShippedUpdate(Flow $flow): ?FlowVersion {
		$flowId = (string)$flow->getUuid();
		$published = $this->versions->findPublished(flowUuid: $flowId);
		if ($published === null) {
			return null;
		}

		if ((string)($published->getPublishedBy() ?? '') !== '') {
			$this->logger->info(
				message: '[FlowVersionService] Flow "' . $flowId . '" has a version published by '
					. $published->getPublishedBy() . '; the shipped update stays on the head until a person publishes it.'
			);
			return null;
		}

		if ($flow->getLifecycleStatus() !== FlowVersion::STATUS_PUBLISHED) {
			$this->logger->info(
				message: '[FlowVersionService] Flow "' . $flowId . '" has an open draft; the shipped update is not published over it.'
			);
			return null;
		}

		$canonical = $this->pin->canonicalise(flow: $this->graphOf(flow: $flow));
		if ($canonical === null || $canonical['hash'] === $published->getDefinitionHash()) {
			return null;
		}

		$this->createDraft(flow: $flow);

		return $this->publish(flow: $flow);

	}//end publishShippedUpdate()

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
	 * @spec openspec/specs/flow-definition-versioning/spec.md
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
	 * @spec openspec/specs/flow-definition-versioning/spec.md
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
	 * Refuse a head that may not be published, and pin its graph.
	 *
	 * Guards BEFORE the transaction opens. A refusal is not a rollback —
	 * nothing should have been attempted — and opening a transaction to
	 * immediately abort it would leave the refusal looking like a failure
	 * in the log rather than a decision.
	 *
	 * @param Flow                 $flow  The flow whose head is being published.
	 * @param array<string, mixed> $graph The head's graph.
	 *
	 * @return string The hash the graph is pinned under.
	 *
	 * @throws FlowLifecycleRefused When the head is not a draft, has a dead end, or could not be stored.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function pinPublishable(Flow $flow, array $graph): string {
		$flowId = (string)$flow->getUuid();
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

		return $hash;

	}//end pinPublishable()

	/**
	 * Deprecate the flow's published version, if it has one.
	 *
	 * @param string $flowId The flow.
	 *
	 * @return FlowVersion|null The version that was published, now deprecated; null when none was.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function deprecatePublished(string $flowId): ?FlowVersion {
		$previous = $this->versions->findPublished(flowUuid: $flowId);
		if ($previous !== null) {
			$previous->setStatus(FlowVersion::STATUS_DEPRECATED);
			$previous->setDeprecatedAt(new DateTime());
			$this->versions->update($previous);
		}

		return $previous;

	}//end deprecatePublished()

	/**
	 * Mirror a just-published version onto the flow's head and reindex its triggers.
	 *
	 * The reindex runs inside the transaction, deliberately. The trigger rows
	 * and the published version are one fact: a commit that carried the new
	 * version but not its triggers would leave the flow published and
	 * subscribed to nothing.
	 *
	 * @param Flow        $flow   The flow.
	 * @param FlowVersion $stored The published version, as stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function mirrorPublished(Flow $flow, FlowVersion $stored): void {
		$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
		$flow->setVersion($stored->getVersion());
		$flow->setSemver($stored->getSemver());
		$flow->setSemverSource(FlowSemanticVersion::SOURCE_DERIVED);
		$this->flows->update($flow);
		$this->triggers->reindex(flow: $flow);

	}//end mirrorPublished()

	/**
	 * What publishing this flow's head would be called, without publishing it.
	 *
	 * 🔑 THE PREFLIGHT AND THE PUBLISH READ THE SAME PAIR OF GRAPHS. Both go
	 * through `publishedGraphOf()` and `FlowSemanticVersion`, so a preview
	 * that says "major, this removes step X" cannot be followed by a publish
	 * that quietly disagrees — which would be worse than showing nothing,
	 * because the author would have believed it.
	 *
	 * It answers for a flow with nothing published yet too: `first` is true,
	 * `next` is the first version, and no removal is claimed.
	 *
	 * @param Flow $flow The flow whose head would be published.
	 *
	 * @return array{
	 *     verdict: string,
	 *     next: string,
	 *     removed: string,
	 *     removedNodes: array<int, string>,
	 *     removedEdges: array<int, string>,
	 *     removedKeys: array<int, string>,
	 *     first: bool,
	 *     current: string|null
	 * } What the publish would be called, and what it takes away.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-the-author-is-told-what-it-will-be-before-publishing
	 */
	public function previewPublish(Flow $flow): array {
		$previous = $this->versions->findPublished(flowUuid: (string)$flow->getUuid());

		$preview = $this->semver->previewOf(
			revision: new FlowGraphRevision(
				published: $this->publishedGraphOf(version: $previous),
				candidate: $this->graphOf(flow: $flow),
				previousSemver: $previous?->getSemver()
			)
		);

		$preview['current'] = $previous?->getSemver();

		return $preview;

	}//end previewPublish()

	/**
	 * The stored graph of a published version, or null when it cannot be read.
	 *
	 * 🔴 A FAILURE TO READ THE PREVIOUS GRAPH IS NOT A FAILURE TO PUBLISH. The
	 * definition row may have been pruned, and refusing would let a LABELLING
	 * feature block a release. A null graph is treated as no previous version
	 * at all, which yields the first version rather than a confident major
	 * nobody can check.
	 *
	 * @param FlowVersion|null $version The version to read.
	 *
	 * @return array<string, mixed>|null The graph, or null.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-a-semantic-version-is-derived-at-publish-from-the-graph
	 */
	private function publishedGraphOf(?FlowVersion $version): ?array {
		if ($version === null) {
			return null;
		}

		try {
			$graph = $this->pin->graphFor($version->getDefinitionHash());
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowVersionService] Could not read the previously published graph; '
					. 'the semantic version starts again: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return null;
		}

		if (is_array($graph) === false) {
			return null;
		}

		return $graph;

	}//end publishedGraphOf()

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
	 * @spec openspec/specs/flow-definition-versioning/spec.md
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
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function persist(FlowVersion $version): FlowVersion {
		if ($version->getId() === null) {
			return $this->versions->insert($version);
		}

		return $this->versions->update($version);

	}//end persist()
}//end class
