<?php

/**
 * OpenRegister AnnotationNotificationListener
 *
 * Subscribes to ObjectCreatedEvent / ObjectUpdatedEvent /
 * ObjectTransitionedEvent and asks the dispatcher to fire any matching
 * notifications declared on the schema.
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

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener that fires schema-declared notifications on object events.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectTransitionedEvent>
 */
class AnnotationNotificationListener implements IEventListener
{
    /**
     * Wire the notification dispatcher.
     *
     * @param AnnotationNotificationDispatcher $dispatcher Dispatcher used to fire notifications.
     *
     * @return void
     */
    public function __construct(
        private readonly AnnotationNotificationDispatcher $dispatcher
    ) {
    }//end __construct()

    /**
     * Dispatch any matching annotation notifications for the inbound event.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectTransitionedEvent) {
            $this->dispatcher->dispatch(
                object: $event->getObject(),
                trigger: 'transition',
                context: [
                    'action' => $event->getAction(),
                    'from'   => $event->getFrom(),
                    'to'     => $event->getTo(),
                ]
            );
            return;
        }

        if ($event instanceof ObjectCreatedEvent) {
            $object = $this->extractObject(event: $event);
            if ($object !== null) {
                $this->dispatcher->dispatch(object: $object, trigger: 'created');
            }

            return;
        }

        if ($event instanceof ObjectUpdatedEvent) {
            $newObject = $event->getNewObject();
            $oldObject = $event->getOldObject();

            $this->dispatcher->dispatch(object: $newObject, trigger: 'updated');

            // Also evaluate calculatedChange rules when both old and new
            // objects are available. Pass the previous and new calculated
            // field values so the dispatcher can detect boundary crossings
            // without re-reading versioned history.
            if ($oldObject !== null) {
                $this->dispatcher->dispatch(
                    object: $newObject,
                    trigger: 'calculatedChange',
                    context: [
                        '_newData' => $newObject->getObject() ?? [],
                        '_oldData' => $oldObject->getObject() ?? [],
                    ]
                );
            }//end if
        }//end if
    }//end handle()

    /**
     * Different Object*Event classes expose the entity under different
     * accessors. Normalise to one.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null Object instance, or null when none could be derived.
     */
    private function extractObject(Event $event): ?\OCA\OpenRegister\Db\ObjectEntity
    {
        if (method_exists($event, 'getObject') === true) {
            $obj = $event->getObject();
            if ($obj instanceof \OCA\OpenRegister\Db\ObjectEntity) {
                return $obj;
            }
        }

        if (method_exists($event, 'getNewObject') === true) {
            $obj = $event->getNewObject();
            if ($obj instanceof \OCA\OpenRegister\Db\ObjectEntity) {
                return $obj;
            }
        }

        return null;
    }//end extractObject()
}//end class
