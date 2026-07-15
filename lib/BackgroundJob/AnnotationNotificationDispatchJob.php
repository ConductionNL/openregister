<?php

/**
 * OpenRegister AnnotationNotificationDispatchJob
 *
 * Actor-forwarded background job carrying the deferred work of
 * AnnotationNotificationListener: evaluates and fires the schema-declared
 * `x-openregister-notifications` (which can perform synchronous outbound
 * HTTP and mail — ADR-009 Rule 5 material) off the write path. Runs under
 * the forwarded actor so the dispatcher's RBAC-scoped relation-deeplink
 * resolution (`ObjectService::find(_rbac: true)`) evaluates as the user who
 * made the change instead of failing closed as Anonymous.
 *
 * Entry payload: `trigger` plus trigger-specific context — `transition`
 * carries {action, from, to}; `updated` carries the pre-update data snapshot
 * (`oldData`), because the old state is unrecoverable once the job runs. New
 * data is NOT snapshotted: the job re-fetches the current object, so
 * conditions always evaluate old-snapshot → current state. Duplicate
 * delivery on at-least-once re-runs is suppressed by the dispatcher's
 * dispatch-log dedup.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Deferred x-openregister-notifications dispatch under the forwarded actor.
 *
 * @spec openspec/changes/actor-forwarded-listener-jobs/tasks.md#task-2.2
 */
class AnnotationNotificationDispatchJob extends ActorForwardedJob
{
    /**
     * Wire the dispatcher on top of the actor plumbing.
     *
     * @param ITimeFactory                     $time         Time factory for the parent job class.
     * @param IUserSession                     $userSession  Session to impersonate on / restore.
     * @param IUserManager                     $userManager  Resolver for the captured user id.
     * @param OrganisationService              $organisation Active-organisation resolver.
     * @param LoggerInterface                  $logger       PSR logger.
     * @param DeferredEntryObjectResolver      $resolver     Stale-safe entry re-fetch.
     * @param AnnotationNotificationDispatcher $dispatcher   Notification dispatcher doing the real work.
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
        private readonly AnnotationNotificationDispatcher $dispatcher
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
     * Replay the inline listener's dispatch calls for every live entry.
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
                $this->dispatchEntry(entry: $entry, object: $object);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[AnnotationNotificationDispatchJob] Dispatch failed for entry',
                    context: [
                        'file'    => __FILE__,
                        'line'    => __LINE__,
                        'uuid'    => ($entry['uuid'] ?? null),
                        'trigger' => ($entry['trigger'] ?? null),
                        'error'   => $e->getMessage(),
                    ]
                );
            }
        }//end foreach
    }//end runDeferred()

    /**
     * Mirror the inline AnnotationNotificationListener dispatch semantics.
     *
     * - `created`    → dispatch('created').
     * - `transition` → dispatch('transition', {action, from, to}).
     * - `updated`    → dispatch('updated', old/new context when a snapshot
     *   exists) followed by dispatch('calculatedChange', same context) —
     *   exactly the pair the inline listener fired.
     *
     * @param array<string, mixed> $entry  The job entry.
     * @param ObjectEntity         $object The re-fetched current object.
     *
     * @return void
     */
    private function dispatchEntry(array $entry, ObjectEntity $object): void
    {
        $trigger = (string) ($entry['trigger'] ?? '');

        if ($trigger === 'created') {
            $this->dispatcher->dispatch(object: $object, trigger: 'created');
            return;
        }

        if ($trigger === 'transition') {
            $this->dispatcher->dispatch(
                object: $object,
                trigger: 'transition',
                context: [
                    'action' => (string) ($entry['action'] ?? ''),
                    'from'   => (string) ($entry['from'] ?? ''),
                    'to'     => (string) ($entry['to'] ?? ''),
                ]
            );
            return;
        }

        if ($trigger === 'updated') {
            $oldData = ($entry['oldData'] ?? null);

            $updatedContext = [];
            if (is_array($oldData) === true) {
                $updatedContext = [
                    '_newData' => ($object->getObject() ?? []),
                    '_oldData' => $oldData,
                ];
            }

            $this->dispatcher->dispatch(object: $object, trigger: 'updated', context: $updatedContext);

            if (is_array($oldData) === true) {
                $this->dispatcher->dispatch(
                    object: $object,
                    trigger: 'calculatedChange',
                    context: [
                        '_newData' => ($object->getObject() ?? []),
                        '_oldData' => $oldData,
                    ]
                );
            }
        }//end if
    }//end dispatchEntry()
}//end class
