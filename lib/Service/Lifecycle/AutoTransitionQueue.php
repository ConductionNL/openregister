<?php

/**
 * OpenRegister AutoTransitionQueue
 *
 * Decides whether an automatic move runs in the request or off it, and queues
 * the ones that run off it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\BackgroundJob\AutoTransitionJob;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use Psr\Log\LoggerInterface;

/**
 * The off-request half of applying an automatic move.
 *
 * Split from {@see AutoTransitionRunner}, which decides WHICH move an object is
 * eligible for and applies the ones that run inline. Everything about the job
 * list lives here: the job class, the entry shape, the dedupe key and the chunk
 * size. The runner therefore needs to know none of it.
 */
class AutoTransitionQueue {

	/**
	 * Entries per queued job.
	 *
	 * `JobList::add()` refuses a JSON argument longer than 4000 characters. An
	 * entry here carries a uuid, two references, an action, a version, a
	 * timestamp and the pass lineage, which is a few hundred bytes; ten of them
	 * with the context envelope stay well inside the cap.
	 *
	 * @var integer
	 */
	public const CHUNK_SIZE = 10;

	/**
	 * Constructor.
	 *
	 * @param ListenerDeferralService $deferral Queues an async move under the triggering identity.
	 * @param LoggerInterface $logger Records that a move was queued rather than applied.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly ListenerDeferralService $deferral,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this move runs in the request rather than in a job.
	 *
	 * @param AutoTransitionCandidate $candidate The decided move.
	 * @param boolean $queueOnly True for a move decided outside any write boundary.
	 *
	 * @return boolean True when the move is applied here and now.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function appliesInline(AutoTransitionCandidate $candidate, bool $queueOnly): bool {
		if ($queueOnly === true) {
			// There is no point after this write and before a response at which
			// a sync move could run, so the declared mode does not apply.
			return false;
		}

		if ($candidate->isSync() === true) {
			return true;
		}

		// The listener-deferral kill switch restores synchronous behaviour for
		// deferred work generally; an async move decided inside a boundary
		// follows it, exactly as the existing deferred listeners do.
		return $this->deferral->isDeferralEnabled() === false;
	}//end appliesInline()

	/**
	 * Queue the decided move as an actor-forwarded job entry.
	 *
	 * Deduped on uuid PLUS version, not uuid alone: the deferral buffer keeps
	 * the FIRST entry per key, so deduping on the uuid would keep a stale
	 * decision and drop the current one.
	 *
	 * @param AutoTransitionDecision $decision The decided move.
	 * @param array<string, mixed> $lineage The pass lineage to carry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function queue(AutoTransitionDecision $decision, array $lineage): void {
		$this->deferral->defer(
			jobClass: AutoTransitionJob::class,
			entry: [
				'uuid' => $decision->uuid,
				'register' => $decision->register,
				'schema' => $decision->schema,
				'action' => $decision->candidate->action,
				'to' => $decision->candidate->to,
				'version' => $decision->version,
				'updated' => $decision->updated,
				'moves' => (int)($lineage['moves'] ?? 0),
				'visited' => array_values(array_keys(($lineage['visited'] ?? []))),
			],
			chunkSize: self::CHUNK_SIZE,
			dedupeKey: $decision->uuid . '|' . ((string)$decision->version)
		);

		$this->logger->debug(
			'[AutoTransitionQueue] Automatic transition queued for off-request application.',
			[
				'schema' => $decision->schemaSlug,
				'uuid' => $decision->uuid,
				'action' => $decision->candidate->action,
				'mode' => $decision->candidate->mode,
			]
		);
	}//end queue()

	/**
	 * Flush every queued move to the job list now.
	 *
	 * Called by the pass's own end-of-request flush. The deferral service
	 * registers its shutdown handler on its first `defer()` of the request,
	 * which may already have run by the time the pass flushes, so the pass
	 * asks for the flush rather than trusting handler ordering.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function flushQueued(): void {
		$this->deferral->flushAll();
	}//end flushQueued()
}//end class
