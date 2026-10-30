<?php

/**
 * OpenRegister ReferencedCalculationJob
 *
 * Actor-forwarded background job carrying the deferred work of
 * ReferencedCalculationListener: re-saves the objects whose materialised
 * `@ref` calculations read a changed field of an updated object. The
 * re-saves fan out (up to SYNC_LIMIT referrers per dependency, each firing
 * the post-write listeners again), so they run here and not inside the
 * user's update request (ADR-078).
 *
 * Each entry carries the changed fields read by a dependent (computed
 * inline from the old and new data, which a job cannot see) and the cascade
 * depth, so a refresh queued from one of these re-saves stops at the
 * refresher's MAX_DEPTH. Each entry also names the user whose update queued
 * it, and runs as that user: the job's own actor is whoever
 * ListenerDeferralService captured first in the process that queued it,
 * which for a refresh queued from inside another refresh job need not be
 * the same person. Idempotent: a re-save recomputes from current data.
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
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresher;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Deferred referenced-calculation refresh under the forwarded actor.
 *
 * @spec openspec/changes/rematerialise-on-referenced-change/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
 */
class ReferencedCalculationJob extends ActorForwardedJob {
	/**
	 * The session the entry's own actor is set on.
	 *
	 * @var IUserSession
	 */
	private IUserSession $session;

	/**
	 * Resolves the entry's actor.
	 *
	 * @var IUserManager
	 */
	private IUserManager $users;

	/**
	 * Wire the refresh collaborators on top of the actor plumbing.
	 *
	 * @param ITimeFactory                   $time         Time factory for the parent job class.
	 * @param IUserSession                   $userSession  Session to impersonate on / restore.
	 * @param IUserManager                   $userManager  Resolver for the captured user id.
	 * @param OrganisationService            $organisation Active-organisation resolver.
	 * @param LoggerInterface                $logger       PSR logger.
	 * @param DeferredEntryObjectResolver    $resolver     Stale-safe entry re-fetch.
	 * @param ReferencedCalculationRefresher $refresher    Re-saves the referring objects.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly DeferredEntryObjectResolver $resolver,
		private readonly ReferencedCalculationRefresher $refresher,
	) {
		$this->session = $userSession;
		$this->users   = $userManager;
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Refresh the referrers of every live entry; a failing entry does not abort the chunk.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rematerialise-on-referenced-change/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($context->getEntries() as $entry) {
			$changed = array_values(array_filter(($entry['changed'] ?? []), 'is_string'));
			if ($changed === []) {
				continue;
			}

			$object = $this->resolver->resolve(entry: $entry);
			if ($object === null) {
				continue;
			}

			$actor = $this->actorOf(entry: $entry);
			if ($actor === false) {
				continue;
			}

			$previous = $this->session->getUser();
			try {
				// No actor (an occ or cron origin) runs as no user, never as
				// whoever the job itself was captured for.
				$this->session->setUser($actor);

				$this->refresher->refreshChanged(updated: $object, changed: $changed, depth: (int) ($entry['depth'] ?? 0));
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[ReferencedCalculationJob] Refresh failed for entry',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'uuid' => ($entry['uuid'] ?? null),
						'error' => $e->getMessage(),
					]
				);
			} finally {
				$this->session->setUser($previous);
			}//end try
		}//end foreach
	}//end runDeferred()

	/**
	 * The user whose update queued the entry: null when there was none (run as no user), false when that user is gone.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return IUser|null|false
	 */
	private function actorOf(array $entry): IUser|null|false {
		$uid = ($entry['actor'] ?? null);
		if (is_string($uid) === false || $uid === '') {
			return null;
		}

		$user = $this->users->get($uid);
		if ($user === null) {
			$this->logger->warning(
				message: '[ReferencedCalculationJob] The user of the original update no longer resolves; entry skipped',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => ($entry['uuid'] ?? null), 'actor' => $uid]
			);
			return false;
		}

		return $user;
	}//end actorOf()
}//end class
