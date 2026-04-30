<?php

/**
 * OpenRegister TranslationProjectionListener
 *
 * Subscribes to ObjectCreated/Updated/Deleted/Transitioned events
 * and keeps the `openregister_translations` sidecar in sync with the
 * JSONB property data on the object. Same pattern as the realtime
 * event listener — derived-projection-by-event.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|ObjectTransitionedEvent>
 */
class TranslationProjectionListener implements IEventListener
{

    public function __construct(
        private readonly TranslationProjectionService $projection
    ) {}//end __construct()


    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent) {
            $object = $event->getObject();
            if ($object instanceof ObjectEntity) {
                $this->projection->project($object);
            }
            return;
        }

        if ($event instanceof ObjectUpdatedEvent) {
            $object = $event->getNewObject();
            if ($object instanceof ObjectEntity) {
                $this->projection->project($object);
            }
            return;
        }

        if ($event instanceof ObjectDeletedEvent) {
            $object = $event->getObject();
            if ($object instanceof ObjectEntity) {
                $this->projection->purge($object);
            }
            return;
        }

        if ($event instanceof ObjectTransitionedEvent) {
            $object = $event->getObject();
            if ($object instanceof ObjectEntity) {
                $this->projection->project($object);
            }
        }
    }//end handle()


}//end class
