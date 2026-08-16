<?php

/**
 * OpenRegister ObjectCleanupJob
 *
 * Actor-forwarded background job carrying the deferred work of
 * ObjectCleanupListener: removing the notes, CalDAV tasks, email links,
 * calendar-event links, contact links and deck-card links that belonged to a
 * deleted object.
 *
 * Why this listener is deferrable even though its object is gone: every one
 * of the six cleanups is keyed by the object UUID alone, so nothing needs to
 * be re-fetched. The heavy part is real — TaskService walks the whole
 * calendar and CalendarEventService rewrites vCards/VEVENTs — and all six
 * services resolve the ACTING user, which is exactly what ActorForwardedJob
 * re-establishes.
 *
 * Re-create guard: delivery is at-least-once and a UUID can legitimately come
 * back (an import replaying the same identifiers, an undo). Cleaning up a
 * LIVE object's notes and tasks would be data loss, so each entry is skipped
 * when its (uuid, register, schema) resolves to an existing, non-soft-deleted
 * object. Resolving to null is the expected case for a genuine delete.
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

use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Deferred deleted-object relation cleanup under the forwarded actor.
 *
 * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md
 */
class ObjectCleanupJob extends ActorForwardedJob {
	/**
	 * Wire the cleanup collaborators on top of the actor plumbing.
	 *
	 * @param ITimeFactory $time Time factory for the parent job class.
	 * @param IUserSession $userSession Session to impersonate on / restore.
	 * @param IUserManager $userManager Resolver for the captured user id.
	 * @param OrganisationService $organisation Active-organisation resolver.
	 * @param LoggerInterface $logger PSR logger.
	 * @param DeferredEntryObjectResolver $resolver Re-create guard lookup.
	 * @param ObjectRelationCleanupService $cleanup Shared cleanup implementation.
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
		private readonly ObjectRelationCleanupService $cleanup,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Clean up every entry whose object is genuinely gone.
	 *
	 * Per-entry failures are logged and do not abort the chunk.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($context->getEntries() as $entry) {
			$uuid = ($entry['uuid'] ?? null);
			if (is_string($uuid) === false || $uuid === '') {
				continue;
			}

			// Re-create guard: a live object under this UUID means the id came
			// back between the delete and this job. Its relations belong to
			// the NEW object; wiping them would be data loss.
			if ($this->resolver->resolve(entry: $entry) !== null) {
				$this->logger->info(
					message: '[ObjectCleanupJob] UUID resolves to a live object again — skipping deferred cleanup',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'uuid' => $uuid,
					]
				);
				continue;
			}

			try {
				$this->cleanup->cleanup(objectUuid: $uuid);
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[ObjectCleanupJob] Cleanup failed for entry',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'uuid' => $uuid,
						'error' => $e->getMessage(),
					]
				);
			}
		}//end foreach
	}//end runDeferred()
}//end class
