<?php

/**
 * OpenRegister ListenerDeferralService
 *
 * Actor-forwarding deferral contract for heavy post-save listeners
 * (openregister#408). Captures the acting context (session user + active
 * organisation) ONCE per request, buffers per-job-class entries, and
 * enqueues chunk-level background jobs — one job per chunk of entries, never
 * one job per object of a bulk save. Buffers flush when a chunk fills and at
 * request shutdown (`register_shutdown_function`, precedent:
 * SearchQueryHandler::flushSearchTrails()).
 *
 * Object-event dispatch sites run after persistence with no wrapping DB
 * transaction (verified: SaveObject / SaveObjects / MagicMapper), so an
 * enqueued job always references committed state. Delivery is at-least-once;
 * the jobs are reconciliations against current state (see ActorForwardedJob).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deferral
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

namespace OCA\OpenRegister\Service\Deferral;

use OCA\OpenRegister\Service\CauseFrame;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IUserSession;

/**
 * Buffers deferred listener work and enqueues actor-forwarded chunk jobs.
 *
 * Registered as a shared service, so all listeners in one request append to
 * the same buffers and bulk saves coalesce into chunk-level jobs.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
class ListenerDeferralService implements ListenerDeferralInterface {

	/**
	 * Kill-switch value that restores synchronous listener execution.
	 *
	 * @var string
	 */
	public const MODE_INLINE = 'inline';

	/**
	 * Default deferral mode: heavy listener work runs in background jobs.
	 *
	 * @var string
	 */
	public const MODE_BACKGROUND = 'background';

	/**
	 * The longest job argument Nextcloud 32 and 33 accept, as its own JSON
	 * encoding (a literal in `OC\BackgroundJob\JobList::add()` there). Also the
	 * limit used when the running server does not name its own.
	 *
	 * @var integer
	 */
	public const ARGUMENT_LENGTH_NC32 = 4000;

	/**
	 * The server's own name for the limit, defined from Nextcloud 34 on.
	 *
	 * @var string
	 */
	private const SERVER_LIMIT_CONSTANT = 'OC\\BackgroundJob\\JobList::MAX_ARGUMENT_JSON_LENGTH';

	/**
	 * App-config key of the deferral kill switch.
	 *
	 * @var string
	 */
	private const CONFIG_KEY = 'listenerDeferral';

	/**
	 * App id used for config lookups.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * Entry buffers keyed by job class.
	 *
	 * @var array<string, array{entries: array<int, array<string, mixed>>, dedupe: array<string, bool>, chunkSize: int}>
	 */
	private array $buffers = [];

	/**
	 * Whether the shutdown flush has been registered for this request.
	 *
	 * @var boolean
	 */
	private bool $shutdownHooked = false;

	/**
	 * Whether the acting context has been captured for this request.
	 *
	 * @var boolean
	 */
	private bool $actorCaptured = false;

	/**
	 * Captured acting user id (null = session-less origin).
	 *
	 * @var string|null
	 */
	private ?string $capturedUserId = null;

	/**
	 * Captured active organisation uuid (drift detection only).
	 *
	 * @var string|null
	 */
	private ?string $capturedOrgUuid = null;

	/**
	 * Wire collaborators.
	 *
	 * @param IUserSession                 $userSession   Session used to capture the acting user.
	 * @param OrganisationServiceInterface $organisation  Resolver for the active organisation.
	 * @param DeferralQueueServices        $queue         Job list, kill-switch config and logger.
	 * @param int|null                     $argumentLimit Job argument limit to respect; null reads the running server's.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly OrganisationServiceInterface $organisation,
		private readonly DeferralQueueServices $queue,
		private readonly ?int $argumentLimit = null,
	) {
	}//end __construct()

	/**
	 * The longest job argument the running Nextcloud accepts, as JSON.
	 *
	 * The limit is 4000 characters on Nextcloud 32 and 33 and 32000 from 34
	 * on; `info.xml` supports 32 up to 35, so it is read from the server's own
	 * constant when that exists and is 4000 otherwise (the safe side: a chunk
	 * under 4000 is accepted everywhere).
	 *
	 * @return int The limit in characters.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	public function getArgumentLimit(): int {
		if ($this->argumentLimit !== null) {
			return $this->argumentLimit;
		}

		if (defined(self::SERVER_LIMIT_CONSTANT) === true) {
			$serverLimit = constant(self::SERVER_LIMIT_CONSTANT);
			if (is_int($serverLimit) === true && $serverLimit > 0) {
				return $serverLimit;
			}
		}

		return self::ARGUMENT_LENGTH_NC32;
	}//end getArgumentLimit()

	/**
	 * Whether deferral is enabled on this instance.
	 *
	 * Reads the `openregister/listenerDeferral` app-config value; anything
	 * other than `inline` (including unset) means background deferral. The
	 * kill switch exists so an instance with an unhealthy cron can restore
	 * the pre-deferral synchronous behaviour operationally.
	 *
	 * @return bool True when listener work should be deferred to jobs.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function isDeferralEnabled(): bool {
		try {
			$mode = $this->queue->appConfig->getValueString(self::APP_ID, self::CONFIG_KEY, self::MODE_BACKGROUND);
		} catch (\Throwable $e) {
			return true;
		}

		return $mode !== self::MODE_INLINE;
	}//end isDeferralEnabled()

	/**
	 * Buffer one entry for a job class, flushing full chunks immediately.
	 *
	 * The acting context is captured on the first call of the request (the
	 * session user cannot change mid-request). When `$dedupeKey` is given,
	 * an entry with an already-buffered key is dropped — this is how N bulk
	 * writes to one schema coalesce into a single threshold evaluation.
	 *
	 * Fail-soft: a capture or enqueue failure is logged and never breaks the
	 * save that triggered it (same blast radius as today's listener
	 * exception swallowing).
	 *
	 * @param string $jobClass FQCN of the ActorForwardedJob subclass.
	 * @param array<string, mixed> $entry Entry payload (uuid/register/schema/version + extras).
	 * @param int $chunkSize Maximum entries per enqueued job.
	 * @param string|null $dedupeKey Optional coalescing key within the buffer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function defer(
		string $jobClass,
		array $entry,
		int $chunkSize = self::DEFAULT_CHUNK_SIZE,
		?string $dedupeKey = null,
	): void {
		$options = new DeferralOptions(chunkSize: $chunkSize, dedupeKey: $dedupeKey);
		$this->deferEntry(deferred: new DeferredEntry(jobClass: $jobClass, entry: $entry), options: $options);
	}//end defer()

	/**
	 * Buffer one entry for its job class, flushing full chunks immediately.
	 *
	 * Fail-soft like defer(): a capture or enqueue failure is logged and never
	 * breaks the save that triggered it.
	 *
	 * @param DeferredEntry   $deferred The entry and the job class it is enqueued for.
	 * @param DeferralOptions $options  Chunk size and optional coalescing key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function deferEntry(DeferredEntry $deferred, DeferralOptions $options = new DeferralOptions()): void {
		$jobClass = $deferred->jobClass;
		try {
			$this->captureActor();
			$this->ensureBuffer(jobClass: $jobClass, chunkSize: $options->chunkSize);

			if ($this->isDuplicate(jobClass: $jobClass, dedupeKey: $options->dedupeKey) === true) {
				return;
			}

			$this->buffers[$jobClass]['entries'][] = $deferred->entry;
			// The cause is read NOW, while the frame that caused the write is on
			// the stack: the buffer is flushed at shutdown, after every frame of
			// the request has been popped (REQ-RCN-002).
			$this->buffers[$jobClass]['causes'][] = self::causeNow();

			$this->hookShutdown();
			$this->flushWhenFull(jobClass: $jobClass);
		} catch (\Throwable $e) {
			$this->logBufferFailure(jobClass: $jobClass, error: $e);
		}//end try
	}//end deferEntry()

	/**
	 * Log an entry that could not be buffered; deferring never throws into the listener.
	 *
	 * @param string     $jobClass The job class the entry was for.
	 * @param \Throwable $error    Why buffering failed.
	 *
	 * @return void
	 */
	private function logBufferFailure(string $jobClass, \Throwable $error): void {
		$this->queue->logger->error(
			message: '[ListenerDeferralService] Failed to buffer deferred listener entry',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'jobClass' => $jobClass,
				'error' => $error->getMessage(),
			]
		);
	}//end logBufferFailure()

	/**
	 * Open the buffer of a job class on its first entry; its chunk size is fixed then.
	 *
	 * @param string $jobClass  The job class.
	 * @param int    $chunkSize The requested chunk size (at least 1 is used).
	 *
	 * @return void
	 */
	private function ensureBuffer(string $jobClass, int $chunkSize): void {
		if (isset($this->buffers[$jobClass]) === false) {
			$this->buffers[$jobClass] = [
				'entries' => [],
				'causes' => [],
				'dedupe' => [],
				'chunkSize' => max(1, $chunkSize),
			];
		}
	}//end ensureBuffer()

	/**
	 * Whether the dedupe key was already buffered for this job class; marks it seen otherwise.
	 *
	 * @param string      $jobClass  The job class.
	 * @param string|null $dedupeKey The dedupe key, or null for none.
	 *
	 * @return bool
	 */
	private function isDuplicate(string $jobClass, ?string $dedupeKey): bool {
		if ($dedupeKey === null) {
			return false;
		}

		if (isset($this->buffers[$jobClass]['dedupe'][$dedupeKey]) === true) {
			return true;
		}

		$this->buffers[$jobClass]['dedupe'][$dedupeKey] = true;
		return false;
	}//end isDuplicate()

	/**
	 * Flush the buffer of a job class once it holds a full chunk.
	 *
	 * @param string $jobClass The job class.
	 *
	 * @return void
	 */
	private function flushWhenFull(string $jobClass): void {
		if (count($this->buffers[$jobClass]['entries']) >= $this->buffers[$jobClass]['chunkSize']) {
			$this->flushBuffer(jobClass: $jobClass);
		}
	}//end flushWhenFull()

	/**
	 * Flush every remaining buffer to the job list.
	 *
	 * Called at request shutdown and available to tests / orchestrators that
	 * need deterministic flushing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function flushAll(): void {
		foreach (array_keys($this->buffers) as $jobClass) {
			$this->flushBuffer(jobClass: $jobClass);
		}
	}//end flushAll()

	/**
	 * Enqueue a buffer's entries as chunk jobs and reset it.
	 *
	 * A chunk is cut by size as well as by count: an entry may carry the
	 * object's previous data (an update notification's `oldData`), and 25 of
	 * those passed Nextcloud's job argument limit (4000 characters on 32 and
	 * 33, 32000 from 34). The job list then refused the whole chunk and every
	 * entry in it was dropped with one log line (zuiddrecht live test, 14
	 * October 2026: 11 chunks of 25 in one cron run). Now the entries are
	 * spread over as many jobs as the running server's limit needs. An entry
	 * that alone exceeds it goes without its `oldData`, which keeps the entry
	 * but means the conditioned `updated` rules and the `calculatedChange`
	 * rules, which fail closed without the previous data, are skipped for that
	 * object (the log names it); only an entry that is still too large after
	 * that is dropped, and it is named in the log.
	 *
	 * @param string $jobClass FQCN of the job to enqueue.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	private function flushBuffer(string $jobClass): void {
		$entries = ($this->buffers[$jobClass]['entries'] ?? []);
		if (count($entries) === 0) {
			return;
		}

		$causes = ($this->buffers[$jobClass]['causes'] ?? []);
		$this->buffers[$jobClass]['entries'] = [];
		$this->buffers[$jobClass]['causes'] = [];
		$this->buffers[$jobClass]['dedupe'] = [];

		foreach (self::causeSegments(entries: $entries, causes: $causes) as $segment) {
			foreach ($this->chunksUnderLimit(jobClass: $jobClass, entries: $segment['entries'], cause: $segment['cause']) as $chunk) {
				$this->enqueue(jobClass: $jobClass, entries: $chunk, cause: $segment['cause']);
			}
		}
	}//end flushBuffer()

	/**
	 * The cause of the write being made now, or null when it is a person's.
	 *
	 * @return CauseFrame|null The cause and its run.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md
	 */
	private static function causeNow(): ?CauseFrame {
		$current = WriteCause::current();
		if ($current['cause'] === WriteCause::PERSON) {
			return null;
		}

		return new CauseFrame(cause: $current['cause'], run: $current['run']);
	}//end causeNow()

	/**
	 * The entries split into consecutive runs that share one cause, in order.
	 *
	 * A job carries one cause, so entries deferred under different causes go
	 * out in different jobs. Only consecutive entries are joined, which keeps
	 * the order the entries were deferred in.
	 *
	 * @param array<int, array<string, mixed>> $entries The buffered entries.
	 * @param array<int, CauseFrame|null>      $causes  The cause of each entry, by position.
	 *
	 * @return array<int, array{cause: CauseFrame|null, entries: array<int, array<string, mixed>>}> The segments.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md
	 */
	private static function causeSegments(array $entries, array $causes): array {
		$segments = [];
		$last = -1;
		foreach (array_values($entries) as $position => $entry) {
			$cause = ($causes[$position] ?? null);
			if ($last >= 0 && self::sameCause(first: $segments[$last]['cause'], second: $cause) === true) {
				$segments[$last]['entries'][] = $entry;
				continue;
			}

			$segments[] = ['cause' => $cause, 'entries' => [$entry]];
			$last++;
		}

		return $segments;
	}//end causeSegments()

	/**
	 * Whether two captured causes are the same cause of the same run.
	 *
	 * @param CauseFrame|null $first  One cause.
	 * @param CauseFrame|null $second The other.
	 *
	 * @return bool
	 */
	private static function sameCause(?CauseFrame $first, ?CauseFrame $second): bool {
		if ($first === null || $second === null) {
			return $first === $second;
		}

		return $first->cause === $second->cause && $first->run === $second->run;
	}//end sameCause()

	/**
	 * The entries in consecutive chunks whose job argument stays within the
	 * limit, in their original order.
	 *
	 * @param string                           $jobClass FQCN of the job, for the log.
	 * @param array<int, array<string, mixed>> $entries  The buffered entries.
	 * @param CauseFrame|null                  $cause    The cause the jobs carry, measured in the envelope.
	 *
	 * @return array<int, array<int, array<string, mixed>>> The chunks.
	 */
	private function chunksUnderLimit(string $jobClass, array $entries, ?CauseFrame $cause = null): array {
		$envelope = new DeferredListenerContext(userId: $this->capturedUserId, orgUuid: $this->capturedOrgUuid, entries: [], cause: $cause);
		// Measured the way the job list measures: plain json_encode. Each entry
		// adds its own length plus the comma that joins it to the next.
		$budget = new JobArgumentBudget(
			jobClass: $jobClass,
			room: ($this->getArgumentLimit() - strlen((string)json_encode($envelope->toJobArguments())))
		);

		$packing = new DeferredChunkPacking(budget: $budget);
		foreach ($entries as $entry) {
			$fitted = $this->fitEntry(entry: $entry, budget: $budget);
			if ($fitted !== null) {
				$packing->add(fitted: $fitted);
			}
		}

		return $packing->chunks();
	}//end chunksUnderLimit()

	/**
	 * An entry with its encoded length, reduced if it must be, or null when it
	 * cannot fit a job at all (logged as an error; the entry is not enqueued).
	 *
	 * @param array<string, mixed> $entry  The entry.
	 * @param JobArgumentBudget    $budget The job class (for the log) and the room an entry may take in a job.
	 *
	 * @return array{entry: array<string, mixed>, length: int}|null
	 */
	private function fitEntry(array $entry, JobArgumentBudget $budget): ?array {
		$fitted = $this->withoutOldDataWhenTooLarge(entry: $entry, budget: $budget);
		if ($fitted['length'] <= $budget->room) {
			return $fitted;
		}

		$this->logOversizedEntry(fitted: $fitted, budget: $budget);
		return null;
	}//end fitEntry()

	/**
	 * Log an entry that does not fit a background job's arguments even without its old data.
	 *
	 * @param array{entry: array<string, mixed>, length: int} $fitted The entry as trimmed, with its encoded length.
	 * @param JobArgumentBudget                               $budget The job class and the room left.
	 *
	 * @return void
	 */
	private function logOversizedEntry(array $fitted, JobArgumentBudget $budget): void {
		$this->queue->logger->error(
			message: '[ListenerDeferralService] A deferred listener entry is larger than a background job may carry; it was not enqueued',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'jobClass' => $budget->jobClass,
				'uuid' => (string)($fitted['entry']['uuid'] ?? ''),
				'length' => $fitted['length'],
				'limit' => $this->getArgumentLimit(),
			]
		);
	}//end logOversizedEntry()

	/**
	 * An entry with its encoded length; an oversized entry whose previous data
	 * is the bulk loses `oldData` when that makes it fit (logged as a warning).
	 *
	 * The previous data is the bulk of a large update. Losing it beats losing
	 * the entry, but it is not free: rules that compare old against new fail
	 * closed without it and are skipped.
	 *
	 * @param array<string, mixed> $entry  The entry.
	 * @param JobArgumentBudget    $budget The job class (for the log) and the room an entry may take in a job.
	 *
	 * @return array{entry: array<string, mixed>, length: int}
	 */
	private function withoutOldDataWhenTooLarge(array $entry, JobArgumentBudget $budget): array {
		$length = $this->jobArgumentLength(entry: $entry);
		if ($length <= $budget->room || array_key_exists('oldData', $entry) === false) {
			return ['entry' => $entry, 'length' => $length];
		}

		$reduced = $entry;
		unset($reduced['oldData']);
		$reducedLength = $this->jobArgumentLength(entry: $reduced);
		if ($reducedLength > $budget->room) {
			return ['entry' => $entry, 'length' => $length];
		}

		$this->logOldDataDropped(entry: $entry, budget: $budget);
		return ['entry' => $reduced, 'length' => $reducedLength];
	}//end withoutOldDataWhenTooLarge()

	/**
	 * The length an entry adds to a job's arguments.
	 *
	 * Measured the way the job list measures: plain json_encode, plus the
	 * comma that joins the entry to the next.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return int The length.
	 */
	private function jobArgumentLength(array $entry): int {
		return (strlen((string)json_encode($entry)) + 1);
	}//end jobArgumentLength()

	/**
	 * Warn that an entry was enqueued without its previous data, because with it the job would not fit.
	 *
	 * @param array<string, mixed> $entry  The entry as it was deferred, with its previous data.
	 * @param JobArgumentBudget    $budget The job class and the room left in its arguments.
	 *
	 * @return void
	 */
	private function logOldDataDropped(array $entry, JobArgumentBudget $budget): void {
		$this->queue->logger->warning(
			message: '[ListenerDeferralService] A deferred listener entry was too large for a background job; '
				. 'it was enqueued without its previous data; conditioned updated rules and '
				. 'calculatedChange rules are skipped for this object',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'jobClass' => $budget->jobClass,
				'uuid' => (string)($entry['uuid'] ?? ''),
				'length' => $this->jobArgumentLength(entry: $entry),
				'limit' => $this->getArgumentLimit(),
			]
		);
	}//end logOldDataDropped()

	/**
	 * Enqueue one chunk job.
	 *
	 * @param string                           $jobClass FQCN of the job to enqueue.
	 * @param array<int, array<string, mixed>> $entries  The chunk's entries.
	 * @param CauseFrame|null                  $cause    The cause the job carries, null for a person's.
	 *
	 * @return void
	 */
	private function enqueue(string $jobClass, array $entries, ?CauseFrame $cause = null): void {
		$context = new DeferredListenerContext(
			userId: $this->capturedUserId,
			orgUuid: $this->capturedOrgUuid,
			entries: $entries,
			cause: $cause
		);

		try {
			$this->queue->jobList->add($jobClass, $context->toJobArguments());
		} catch (\Throwable $e) {
			$this->queue->logger->error(
				message: '[ListenerDeferralService] Failed to enqueue deferred listener job',
				context: [...$this->enqueueContext(jobClass: $jobClass, entries: $entries), 'error' => $e->getMessage()]
			);
		}
	}//end enqueue()

	/**
	 * The log context of one enqueue attempt: the job class and how many entries it carried.
	 *
	 * @param string                           $jobClass The background job class.
	 * @param array<int, array<string, mixed>> $entries  The entries of the attempt.
	 *
	 * @return array{file: string, line: int, jobClass: string, entries: int}
	 */
	private function enqueueContext(string $jobClass, array $entries): array {
		return [
			'file' => __FILE__,
			'line' => __LINE__,
			'jobClass' => $jobClass,
			'entries' => count($entries),
		];
	}//end enqueueContext()

	/**
	 * Capture the acting context once per request.
	 *
	 * Organisation resolution is fail-soft: a lookup failure captures null
	 * rather than aborting the save. Null userId is legitimate (occ/cron
	 * writes) — the job then runs without impersonation, matching inline
	 * session-less behaviour.
	 *
	 * @return void
	 */
	private function captureActor(): void {
		if ($this->actorCaptured === true) {
			return;
		}

		$this->actorCaptured = true;
		$this->capturedUserId = $this->userSession->getUser()?->getUID();

		try {
			$this->capturedOrgUuid = $this->organisation->getActiveOrganisation()?->getUuid();
		} catch (\Throwable $e) {
			$this->capturedOrgUuid = null;
		}
	}//end captureActor()

	/**
	 * Register the shutdown flush exactly once per request.
	 *
	 * The callback is a closure calling `flushAll()` rather than the
	 * `[$this, 'flushAll']` array form it replaced. A string method name is
	 * invisible to every static tool: a rename of `flushAll()` leaves the
	 * array callable pointing at nothing and fails only at shutdown, where
	 * nothing observes it — the deferred jobs would simply stop being
	 * enqueued, silently. The closure makes the call site greppable and
	 * rename-safe, and matches ObjectEventProxyListener::traceEnabled().
	 *
	 * @return void
	 */
	private function hookShutdown(): void {
		if ($this->shutdownHooked === true) {
			return;
		}

		$this->shutdownHooked = true;
		register_shutdown_function(
			function (): void {
				$this->flushAll();
			}
		);
	}//end hookShutdown()
}//end class
