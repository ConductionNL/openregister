<?php

/**
 * GraphQL Subscription Event Listener
 *
 * Listens for object CRUD events and pushes them to the
 * GraphQL subscription buffer for SSE delivery.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\GraphQL\SubscriptionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listens for object events and pushes to GraphQL subscription buffer.
 *
 * @implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent>
 */
class GraphQLSubscriptionListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param SubscriptionService $subscriptionService Subscription service
     * @param LoggerInterface     $logger              Logger
     */
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an event.
     *
     * @param Event $event The event
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        try {
            if ($event instanceof ObjectCreatedEvent) {
                $this->subscriptionService->pushEvent('create', $event->getObject());
            } else if ($event instanceof ObjectUpdatedEvent) {
                $this->subscriptionService->pushEvent('update', $event->getObject());
            } else if ($event instanceof ObjectDeletedEvent) {
                $this->subscriptionService->pushEvent('delete', $event->getObject());
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'GraphQL subscription event push failed: '.$e->getMessage()
            );
        }

    }//end handle()
}//end class
