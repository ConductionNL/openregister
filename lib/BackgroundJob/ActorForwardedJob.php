<?php

/**
 * OpenRegister ActorForwardedJob
 *
 * Abstract QueuedJob that re-establishes the acting user captured by
 * ListenerDeferralService before running deferred listener work, and ALWAYS
 * restores the previous session user in a `finally` block so a cron worker
 * can never leak one job's identity into the next job — including when the
 * deferred work throws (precedent: ScheduledReportService::runOne()).
 *
 * Identity rules:
 * - captured user resolves      → impersonate for the duration of execute();
 * - captured user unresolvable  → log and skip (never run under a wrong or
 *   system identity);
 * - no captured user (occ/cron) → run without impersonation, identical to
 *   the inline session-less behaviour.
 *
 * Organisation context re-derives from the restored user's persistent
 * configuration (the same source the request session cache was primed
 * from). It is deliberately NOT force-restored to the captured value:
 * re-establish identity, never re-establish stale authority. A drift is
 * logged for observability only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
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

namespace OCA\OpenRegister\BackgroundJob;

use Generator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolverInterface;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Base class for deferred listener jobs that must run as the original actor.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
abstract class ActorForwardedJob extends QueuedJob {
	/**
	 * Wire the identity plumbing shared by all actor-forwarded jobs.
	 *
	 * @param ITimeFactory $time Time factory for the parent job class.
	 * @param IUserSession $userSession Session to impersonate on / restore.
	 * @param IUserManager $userManager Resolver for the captured user id.
	 * @param OrganisationServiceInterface $organisation Active-organisation resolver (drift detection).
	 * @param LoggerInterface $logger PSR logger (shared with subclasses).
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly OrganisationServiceInterface $organisation,
		protected readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * The entries of a chunk whose object still resolves, each with that live object.
	 *
	 * Resolved one at a time as the caller iterates, so each entry is
	 * re-fetched right before it is handled. Entries whose object is gone or
	 * soft-deleted are stale and skipped.
	 *
	 * @param DeferredListenerContext              $context  The captured dispatch-time context.
	 * @param DeferredEntryObjectResolverInterface $resolver Stale-safe entry re-fetch.
	 *
	 * @return Generator<array<string, mixed>, ObjectEntity> Entry => live object.
	 */
	protected function liveEntries(DeferredListenerContext $context, DeferredEntryObjectResolverInterface $resolver): Generator {
		foreach ($context->getEntries() as $entry) {
			$object = $resolver->resolve(entry: $entry);
			if ($object !== null) {
				yield $entry => $object;
			}
		}
	}//end liveEntries()

	/**
	 * Re-establish the captured actor, run the deferred work, restore.
	 *
	 * @param array<string, mixed> $argument Serialized DeferredListenerContext.
	 *
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") — fromJobArguments is the canonical named constructor of the context VO.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	protected function run($argument): void {
		$context = DeferredListenerContext::fromJobArguments($argument);
		if (count($context->getEntries()) === 0) {
			$this->logger->info(
				message: '[ActorForwardedJob] Job carried no entries — nothing to do',
				context: ['file' => __FILE__, 'line' => __LINE__, 'job' => static::class]
			);
			return;
		}

		$this->runAsCapturedActor(context: $context);
	}//end run()

	/**
	 * Run the deferred work as the captured actor, skipping it when that actor is gone.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 */
	private function runAsCapturedActor(DeferredListenerContext $context): void {
		$userId = $context->getUserId();
		$user   = null;
		if ($userId !== null) {
			$user = $this->capturedActor(userId: $userId);
			if ($user === null) {
				return;
			}
		}

		$this->runUnderActor(user: $user, context: $context);
	}//end runAsCapturedActor()

	/**
	 * The captured actor; null, logged, when they no longer exist.
	 *
	 * The captured actor no longer existing means running anyway would
	 * misattribute the work to whatever identity the worker has, so the job
	 * skips — matching "owner gone" semantics used by scheduled reports.
	 *
	 * @param string $userId The captured user id.
	 *
	 * @return IUser|null
	 */
	private function capturedActor(string $userId): ?IUser {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			$this->logger->warning(
				message: '[ActorForwardedJob] Captured user no longer resolves — skipping deferred work',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'job' => static::class,
					'userId' => $userId,
				]
			);
		}

		return $user;
	}//end capturedActor()

	/**
	 * Run the deferred work with the session set to the actor, then restore it.
	 *
	 * @param IUser|null              $user    The actor; null for a session-less origin.
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 */
	private function runUnderActor(?IUser $user, DeferredListenerContext $context): void {
		$previousUser = $this->userSession->getUser();
		if ($user !== null) {
			$this->userSession->setUser($user);
		}

		try {
			$this->logOrganisationDrift(context: $context);
			$this->runDeferred(context: $context);
		} finally {
			// ALWAYS restore — also on exceptions — so the cron process
			// never carries this job's identity into the next job.
			$this->userSession->setUser($previousUser);
		}
	}//end runUnderActor()

	/**
	 * The deferred listener work, executed under the re-established actor.
	 *
	 * Implementations MUST be idempotent (at-least-once delivery) and MUST
	 * treat entries whose object is gone or soft-deleted as stale no-ops.
	 * (Named runDeferred because QueuedJob::execute() is a final OCP method.)
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 */
	abstract protected function runDeferred(DeferredListenerContext $context): void;

	/**
	 * Log when the user's active organisation changed since capture.
	 *
	 * The job proceeds under the user's CURRENT authority — captured
	 * organisation context is observability metadata, never a privilege
	 * source.
	 *
	 * @param DeferredListenerContext $context The captured context.
	 *
	 * @return void
	 */
	private function logOrganisationDrift(DeferredListenerContext $context): void {
		$captured = $context->getOrganisationUuid();
		if ($captured === null || $context->getUserId() === null) {
			return;
		}

		try {
			$current = $this->organisation->getActiveOrganisation()?->getUuid();
		} catch (\Throwable $e) {
			return;
		}

		if ($current !== $captured) {
			$this->logDrift(captured: $captured, current: $current);
		}
	}//end logOrganisationDrift()

	/**
	 * Log that the active organisation is not the one captured.
	 *
	 * @param string      $captured The organisation captured at dispatch.
	 * @param string|null $current  The user's active organisation now.
	 *
	 * @return void
	 */
	private function logDrift(string $captured, ?string $current): void {
		$this->logger->info(
			message: '[ActorForwardedJob] Active organisation drifted since capture — running under current authority',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'job' => static::class,
				'captured' => $captured,
				'current' => $current,
			]
		);
	}//end logDrift()
}//end class
