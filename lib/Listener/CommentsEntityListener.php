<?php

/**
 * CommentsEntityListener
 *
 * Registers the "openregister" objectType with Nextcloud's Comments system.
 * This allows comments to be stored against OpenRegister object UUIDs.
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

use OCA\OpenRegister\Db\MagicMapper;
use OCP\Comments\CommentsEntityEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * CommentsEntityListener registers "openregister" as a comments entity type.
 *
 * When Nextcloud's Comments system dispatches CommentsEntityEvent, this listener
 * adds "openregister" with a validation closure that checks if the object UUID
 * exists in the MagicMapper.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @template-implements IEventListener<CommentsEntityEvent>
 */
class CommentsEntityListener implements IEventListener
{

    /**
     * Object entity mapper for validating object existence.
     *
     * @var MagicMapper
     */
    private readonly MagicMapper $objectEntityMapper;

    /**
     * Logger for error reporting.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param MagicMapper     $objectEntityMapper Mapper for object validation
     * @param LoggerInterface $logger             Logger for error reporting
     *
     * @return void
     */
    public function __construct(
        MagicMapper $objectEntityMapper,
        LoggerInterface $logger
    ) {
        $this->objectEntityMapper = $objectEntityMapper;
        $this->logger = $logger;
    }//end __construct()

    /**
     * Handle the CommentsEntityEvent.
     *
     * Registers "openregister" as an entity collection with a closure
     * that validates whether the given object UUID exists.
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof CommentsEntityEvent) === false) {
            return;
        }

        $event->addEntityCollection(
                'openregister',
                function (string $objectUuid): bool {
                    try {
                        $this->objectEntityMapper->find($objectUuid);
                        return true;
                    } catch (\Exception $e) {
                        $this->logger->debug(
                            'Object UUID not found for comments entity: '.$objectUuid,
                            ['exception' => $e]
                        );
                        return false;
                    }
                }
                );
    }//end handle()
}//end class
