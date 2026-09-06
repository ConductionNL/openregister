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
 * @spec openspec/changes/actor-forwarded-listener-jobs/tasks.md#task-1.2
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
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly OrganisationService $organisation,
		private readonly IJobList $jobList,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	 * Enqueue one chunk job for a buffer and reset it.
	 *
	 * @param string $jobClass FQCN of the job to enqueue.
	 *
	 * @return void
	 */
	private function flushBuffer(string $jobClass): void {
		$entries = ($this->buffers[$jobClass]['entries'] ?? []);
		if (count($entries) === 0) {
			return;
		}

		$this->buffers[$jobClass]['entries'] = [];
		$this->buffers[$jobClass]['dedupe'] = [];

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
	}//end flushBuffer()

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
