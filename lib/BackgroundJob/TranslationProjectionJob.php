<?php

/**
 * OpenRegister TranslationProjectionJob
 *
 * Actor-forwarded background job carrying the deferred work of
 * TranslationProjectionListener (created/updated/transitioned events): keeps
 * the `openregister_translations` sidecar in sync with the JSONB property
 * data. Runs under the forwarded actor so
 * TranslationProjectionService::project()'s translator attribution records
 * the user who actually made the change, not null/System.
 *
 * Idempotent: project() reconciles a desired set (upsert + prune) against
 * the CURRENT object state, so re-runs and out-of-order chunks converge.
 * Entries whose object is gone or soft-deleted are stale no-ops.
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
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Deferred translation-sidecar projection under the forwarded actor.
 *
 * @spec openspec/changes/actor-forwarded-listener-jobs/tasks.md#task-2.1
 */
class TranslationProjectionJob extends ActorForwardedJob
{
    /**
     * Wire the projection collaborators on top of the actor plumbing.
     *
     * @param ITimeFactory                 $time         Time factory for the parent job class.
     * @param IUserSession                 $userSession  Session to impersonate on / restore.
     * @param IUserManager                 $userManager  Resolver for the captured user id.
     * @param OrganisationService          $organisation Active-organisation resolver.
     * @param LoggerInterface              $logger       PSR logger.
     * @param DeferredEntryObjectResolver  $resolver     Stale-safe entry re-fetch.
     * @param TranslationProjectionService $projection   Projection service doing the real work.
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
        private readonly TranslationProjectionService $projection
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
     * Project every still-live entry's current state into the sidecar.
     *
     * Per-entry failures are logged and do not abort the chunk.
     *
     * @param DeferredListenerContext $context The captured dispatch-time context.
     *
     * @return void
     *
     * @spec openspec/specs/event-driven-architecture/spec.md
     */
    protected function runDeferred(DeferredListenerContext $context): void
    {
        foreach ($context->getEntries() as $entry) {
            $object = $this->resolver->resolve(entry: $entry);
            if ($object === null) {
                continue;
            }

            try {
                $this->projection->project($object);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[TranslationProjectionJob] Projection failed for entry',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'uuid'  => ($entry['uuid'] ?? null),
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }//end foreach
    }//end runDeferred()
}//end class
