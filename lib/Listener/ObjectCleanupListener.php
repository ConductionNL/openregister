<?php

/**
 * ObjectCleanupListener
 *
 * Listens for ObjectDeletedEvent and cleans up associated notes, tasks,
 * email links, calendar event links, contact links, and deck card links.
 *
 * Post-event listener, so the work is DEFERRED by default (see
 * openspec/changes/object-event-sync-async-split): the cleanup spans a full
 * CalDAV calendar walk and vCard/VEVENT rewrites, none of which the deleting
 * request needs to wait for. The listener itself now only buffers one entry
 * per deleted object onto ListenerDeferralService; the real work runs in
 * ObjectCleanupJob under the forwarded actor.
 *
 * Setting `openregister/listenerDeferral` to `inline` restores the previous
 * synchronous behaviour — the identical ObjectRelationCleanupService call,
 * executed in-request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Listener
 * @package   OCA\OpenRegister\Listener
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\BackgroundJob\ObjectCleanupJob;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * ObjectCleanupListener cleans up all entity relations when an object is deleted.
 *
 * Handles ObjectDeletedEvent by cleaning up:
 * (a) Notes (comments)
 * (b) CalDAV tasks
 * (c) Email links
 * (d) Calendar event links (unlink, not delete)
 * (e) Contact links (unlink vCard properties + delete DB records)
 * (f) Deck card links
 *
 * Failures in one entity type do not block cleanup of other types.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @template-implements IEventListener<ObjectDeletedEvent>
 *
 * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md
 */
class ObjectCleanupListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param ListenerDeferralService      $deferral Buffers the entry and enqueues the chunk job.
     * @param ObjectRelationCleanupService $cleanup  Shared cleanup, used by the inline fallback.
     *
     * @return void
     *
     * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md
     */
    public function __construct(
        private readonly ListenerDeferralService $deferral,
        private readonly ObjectRelationCleanupService $cleanup
    ) {
    }//end __construct()

    /**
     * Handle the ObjectDeletedEvent.
     *
     * Buffers the deleted object's identity for ObjectCleanupJob. The entry
     * carries register and schema alongside the uuid so the job's re-create
     * guard can target one magic table instead of a cross-table UUID scan.
     *
     * @param Event $event The event to handle
     *
     * @return void
     *
     * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectDeletedEvent) === false) {
            return;
        }

        $object     = $event->getObject();
        $objectUuid = (string) $object->getUuid();
        if ($objectUuid === '') {
            return;
        }

        if ($this->deferral->isDeferralEnabled() === false) {
            $this->cleanup->cleanup(objectUuid: $objectUuid);
            return;
        }

        $this->deferral->defer(
            jobClass: ObjectCleanupJob::class,
            entry: [
                'uuid'     => $objectUuid,
                'register' => (string) $object->getRegister(),
                'schema'   => (string) $object->getSchema(),
            ],
            dedupeKey: $objectUuid
        );
    }//end handle()
}//end class
