<?php

/**
 * OpenRegister SourceRecordRecomputeJob
 *
 * Actor-forwarded background job carrying the deferred work of
 * `SourceRecordChangeListener`: recompute the golden record of every
 * reverse-FK master referenced by a changed source object.
 *
 * WHY THIS JOB EXISTS (openregister#2420). The listener used to call
 * `ObjectService::saveObject()` SYNCHRONOUSLY inside the handling of every
 * `ObjectCreated`/`Updated`/`Deleted` event — a full object write nested
 * inside another object's write, with no deferral and no coalescing, and
 * twice per update when a source was reassigned between masters. ADR-078
 * requires that work to leave the request. A synchronous fan-out of
 * `saveObject()` inside a request is the same shape that serialised every
 * object write on a live instance on 2026-08-11.
 *
 * Entries are deduped on the MASTER uuid at enqueue time, so N source objects
 * pointing at one master — the ordinary case for a reverse-FK relationship,
 * and the whole point of one — collapse into ONE recompute instead of N.
 *
 * Idempotent: `MasterRecomputeService::recompute()` reads the master fresh and
 * re-persists it, so at-least-once delivery produces the same golden record as
 * exactly-once. A master that no longer resolves is a stale no-op.
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

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\Merge\MasterRecomputeService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Deferred reverse-FK master recompute under the forwarded actor.
 *
 * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
 */
class SourceRecordRecomputeJob extends ActorForwardedJob
{
    /**
     * Wire the recompute collaborator on top of the actor plumbing.
     *
     * @param ITimeFactory           $time         Time factory for the parent job class.
     * @param IUserSession           $userSession  Session to impersonate on / restore.
     * @param IUserManager           $userManager  Resolver for the captured user id.
     * @param OrganisationService    $organisation Active-organisation resolver.
     * @param LoggerInterface        $logger       PSR logger.
     * @param MasterRecomputeService $recompute    Shared golden-record recompute.
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        IUserSession $userSession,
        IUserManager $userManager,
        OrganisationService $organisation,
        LoggerInterface $logger,
        private readonly MasterRecomputeService $recompute
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
     * Recompute every buffered master's golden record.
     *
     * `MasterRecomputeService::recompute()` already swallows and logs its own
     * failures, so one unresolvable master cannot abort the chunk.
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
            $masterUuid = (string) ($entry['masterUuid'] ?? '');
            if ($masterUuid === '') {
                continue;
            }

            $this->recompute->recompute(masterUuid: $masterUuid);
        }//end foreach
    }//end runDeferred()
}//end class
