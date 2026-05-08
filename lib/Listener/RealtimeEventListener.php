<?php

/**
 * OpenRegister RealtimeEventListener
 *
 * Subscribes to ObjectCreated/Updated/Deleted/Transitioned events and
 * records each one as a CloudEvent in the realtime event log.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\RealtimeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener that records realtime events for object lifecycle changes.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|ObjectTransitionedEvent>
 */
class RealtimeEventListener implements IEventListener
{
    /**
     * Wire the realtime service used to record events.
     *
     * @param RealtimeService $realtimeService Service that persists realtime events.
     *
     * @return void
     */
    public function __construct(
        private readonly RealtimeService $realtimeService
    ) {
    }//end __construct()

    /**
     * Dispatch the inbound event onto the realtime event log.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent) {
            $object = $event->getObject();
            if ($object instanceof ObjectEntity) {
                $this->realtimeService->record(RealtimeService::TYPE_OBJECT_CREATED, $object);
            }

            return;
        }

        if ($event instanceof ObjectUpdatedEvent) {
            $object = $event->getNewObject();
            if ($object instanceof ObjectEntity) {
                $this->realtimeService->record(RealtimeService::TYPE_OBJECT_UPDATED, $object);
            }

            return;
        }

        if ($event instanceof ObjectDeletedEvent) {
            $object = $event->getObject();
            if ($object instanceof ObjectEntity) {
                $this->realtimeService->record(RealtimeService::TYPE_OBJECT_DELETED, $object);
            }

            return;
        }

        if ($event instanceof ObjectTransitionedEvent) {
            $object = $event->getObject();
            if ($object instanceof ObjectEntity) {
                $this->realtimeService->record(
                    RealtimeService::TYPE_OBJECT_TRANSITIONED,
                    $object,
                    ['action' => $event->getAction(), 'from' => $event->getFrom(), 'to' => $event->getTo()]
                );
            }
        }
    }//end handle()
}//end class
