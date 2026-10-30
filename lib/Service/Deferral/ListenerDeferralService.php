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

use OCA\OpenRegister\Service\OrganisationService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Buffers deferred listener work and enqueues actor-forwarded chunk jobs.
 *
 * Registered as a shared service, so all listeners in one request append to
 * the same buffers and bulk saves coalesce into chunk-level jobs.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
class ListenerDeferralService {

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
	 * Default number of entries per enqueued job.
	 *
	 * @var integer
	 */
	public const DEFAULT_CHUNK_SIZE = 100;

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
	 * @param IUserSession $userSession Session used to capture the acting user.
	 * @param OrganisationService $organisation Resolver for the active organisation.
	 * @param IJobList $jobList Job list the chunk jobs are enqueued on.
	 * @param IAppConfig $appConfig App config holding the kill switch.
	 * @param LoggerInterface $logger PSR logger.
	 * @param int|null $argumentLimit Job argument limit to respect; null reads the running server's.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly OrganisationService $organisation,
		private readonly IJobList $jobList,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
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
			$mode = $this->appConfig->getValueString(self::APP_ID, self::CONFIG_KEY, self::MODE_BACKGROUND);
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
		try {
			$this->captureActor();

			if (isset($this->buffers[$jobClass]) === false) {
				$this->buffers[$jobClass] = [
					'entries' => [],
					'dedupe' => [],
					'chunkSize' => max(1, $chunkSize),
				];
			}

			if ($dedupeKey !== null) {
				if (isset($this->buffers[$jobClass]['dedupe'][$dedupeKey]) === true) {
					return;
				}

				$this->buffers[$jobClass]['dedupe'][$dedupeKey] = true;
			}

			$this->buffers[$jobClass]['entries'][] = $entry;

			$this->hookShutdown();

			if (count($this->buffers[$jobClass]['entries']) >= $this->buffers[$jobClass]['chunkSize']) {
				$this->flushBuffer(jobClass: $jobClass);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ListenerDeferralService] Failed to buffer deferred listener entry',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'jobClass' => $jobClass,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end defer()

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

		$this->buffers[$jobClass]['entries'] = [];
		$this->buffers[$jobClass]['dedupe'] = [];

		foreach ($this->chunksUnderLimit(jobClass: $jobClass, entries: $entries) as $chunk) {
			$this->enqueue(jobClass: $jobClass, entries: $chunk);
		}
	}//end flushBuffer()

	/**
	 * The entries in consecutive chunks whose job argument stays within the
	 * limit, in their original order.
	 *
	 * @param string                           $jobClass FQCN of the job, for the log.
	 * @param array<int, array<string, mixed>> $entries  The buffered entries.
	 *
	 * @return array<int, array<int, array<string, mixed>>> The chunks.
	 */
	private function chunksUnderLimit(string $jobClass, array $entries): array {
		$envelope = new DeferredListenerContext(userId: $this->capturedUserId, orgUuid: $this->capturedOrgUuid, entries: []);
		// Measured the way the job list measures: plain json_encode. Each entry
		// adds its own length plus the comma that joins it to the next.
		$budget = ($this->getArgumentLimit() - strlen((string)json_encode($envelope->toJobArguments())));

		$chunks  = [];
		$current = [];
		$used    = 0;
		foreach ($entries as $entry) {
			$length = (strlen((string)json_encode($entry)) + 1);
			if ($length > $budget && array_key_exists('oldData', $entry) === true) {
				// The previous data is the bulk of a large update. Losing it beats
				// losing the entry, but it is not free: rules that compare old
				// against new fail closed without it and are skipped.
				$reduced = $entry;
				unset($reduced['oldData']);
				$reducedLength = (strlen((string)json_encode($reduced)) + 1);
				if ($reducedLength <= $budget) {
					$this->logger->warning(
						message: '[ListenerDeferralService] A deferred listener entry was too large for a background job; '
							. 'it was enqueued without its previous data; conditioned updated rules and '
							. 'calculatedChange rules are skipped for this object',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'jobClass' => $jobClass,
							'uuid' => (string)($entry['uuid'] ?? ''),
							'length' => $length,
							'limit' => $this->getArgumentLimit(),
						]
					);
					$entry  = $reduced;
					$length = $reducedLength;
				}
			}

			if ($length > $budget) {
				$this->logger->error(
					message: '[ListenerDeferralService] A deferred listener entry is larger than a background job may carry; it was not enqueued',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'jobClass' => $jobClass,
						'uuid' => (string)($entry['uuid'] ?? ''),
						'length' => $length,
						'limit' => $this->getArgumentLimit(),
					]
				);
				continue;
			}

			if ($current !== [] && ($used + $length) > $budget) {
				$chunks[] = $current;
				$current  = [];
				$used     = 0;
			}

			$current[] = $entry;
			$used     += $length;
		}//end foreach

		if ($current !== []) {
			$chunks[] = $current;
		}

		return $chunks;
	}//end chunksUnderLimit()

	/**
	 * Enqueue one chunk job.
	 *
	 * @param string                           $jobClass FQCN of the job to enqueue.
	 * @param array<int, array<string, mixed>> $entries  The chunk's entries.
	 *
	 * @return void
	 */
	private function enqueue(string $jobClass, array $entries): void {
		$context = new DeferredListenerContext(
			userId: $this->capturedUserId,
			orgUuid: $this->capturedOrgUuid,
			entries: $entries
		);

		try {
			$this->jobList->add($jobClass, $context->toJobArguments());
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ListenerDeferralService] Failed to enqueue deferred listener job',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'jobClass' => $jobClass,
					'entries' => count($entries),
					'error' => $e->getMessage(),
				]
			);
		}
	}//end enqueue()

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
