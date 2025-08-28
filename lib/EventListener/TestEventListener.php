<?php

/**
 * OpenRegister Test Event Listener
 *
 * A simple test listener class for verifying that event listeners work correctly 
 * in the OpenRegister application.
 *
 * @category EventListener
 * @package  OCA\OpenRegister\EventListener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\EventListener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * Test event listener for verifying event listener functionality in OpenRegister
 *
 * This listener handles user login events to test that our event system 
 * is working correctly. It logs when users log in and can be easily 
 * triggered for testing purposes.
 *
 * @template T of Event
 *
 * @implements IEventListener<T>
 */
class TestEventListener implements IEventListener
{

    /**
     * Logger instance for debug logging
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;


    /**
     * Constructor for the test event listener
     *
     * @param LoggerInterface $logger The logger service for logging events
     *
     * @return void
     */
    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;

    }//end __construct()


    /**
     * Handles events related to user login for testing purposes
     *
     * This method processes UserLoggedInEvent events and logs detailed
     * information to verify that the event listener system is working
     * correctly.
     *
     * @param Event $event The event to handle
     *
     * @return void
     *
     * @phpstan-param T $event
     */
    public function handle(Event $event): void
    {
        // Log that we received ANY event first
        $this->logger->critical('🧪 OPENREGISTER: TEST EVENT LISTENER TRIGGERED!', [
            'app' => 'openregister', 
            'eventClass' => get_class($event),
            'timestamp' => date('Y-m-d H:i:s'),
            'microtime' => microtime(true),
            'listenerClass' => self::class
        ]);

        // Log event trigger for immediate visibility
        $this->logger->info('OpenRegister test listener triggered', [
            'eventClass' => get_class($event),
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Handle UserLoggedInEvent specifically
        if ($event instanceof UserLoggedInEvent) {
            $user = $event->getUser();
            
            $this->logger->critical('OpenRegister TestEventListener: User logged in successfully!', [
                'userId' => $user->getUID(),
                'userDisplayName' => $user->getDisplayName(), 
                'userEmail' => $user->getEMailAddress(),
                'timestamp' => date('Y-m-d H:i:s'),
                'eventType' => 'UserLoggedInEvent',
                'app' => 'openregister'
            ]);

            // Log user login for debugging
            $this->logger->info('OpenRegister user login detected', [
                'userId' => $user->getUID(),
                'displayName' => $user->getDisplayName(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Test that we can access Nextcloud services
            try {
                $this->logger->info('OpenRegister TestEventListener: Event listener is working correctly!', [
                    'message' => 'This confirms that event listeners are properly registered and triggered',
                    'userId' => $user->getUID(),
                    'eventClass' => get_class($event),
                    'app' => 'openregister'
                ]);
            } catch (\Exception $e) {
                $this->logger->error('OpenRegister TestEventListener: Error in event processing', [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'app' => 'openregister'
                ]);
            }
        } else {
            // Log other events we might receive
            $this->logger->debug('OpenRegister TestEventListener: Received unhandled event', [
                'eventClass' => get_class($event),
                'timestamp' => date('Y-m-d H:i:s'),
                'app' => 'openregister'
            ]);
        }

    }//end handle()


}//end class
