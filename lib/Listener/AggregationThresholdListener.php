<?php

/**
 * AggregationThresholdListener
 *
 * Listens for object lifecycle events and evaluates 'threshold' notification
 * rules declared on the schema — dispatching when an aggregation value crosses
 * the declared threshold.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Evaluates threshold-trigger notification rules after object mutations.
 *
 * @psalm-suppress UnusedClass
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent>
 */
class AggregationThresholdListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param AnnotationNotificationDispatcher $dispatcher Notification dispatcher.
     * @param LoggerInterface                  $logger     Logger.
     */
    public function __construct(
        private readonly AnnotationNotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle an object lifecycle event.
     *
     * @param Event $event Domain event.
     *
     * @return void
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-5
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent) === false
            && ($event instanceof ObjectUpdatedEvent) === false
        ) {
            return;
        }

        try {
            $this->dispatcher->dispatchThreshold(event: $event);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: 'AggregationThresholdListener: dispatch failed',
                context: ['error' => $e->getMessage()]
            );
        }
    }//end handle()
}//end class
