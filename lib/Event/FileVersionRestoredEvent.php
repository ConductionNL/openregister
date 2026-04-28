<?php

/**
 * OpenRegister FileVersionRestoredEvent
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Event dispatched for file action: FileVersionRestoredEvent
 */
class FileVersionRestoredEvent extends Event
{
    /**
     * Constructor for FileVersionRestoredEvent
     *
     * @param string $objectUuid The UUID of the parent object.
     * @param int    $fileId     The file ID.
     * @param array  $data       Additional event data.
     *
     * @return void
     */
    public function __construct(
        private readonly string $objectUuid,
        private readonly int $fileId,
        private readonly array $data=[]
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the object UUID.
     *
     * @return string The object UUID.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
     */
    public function getObjectUuid(): string
    {
        return $this->objectUuid;
    }//end getObjectUuid()

    /**
     * Get the file ID.
     *
     * @return int The file ID.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
     */
    public function getFileId(): int
    {
        return $this->fileId;
    }//end getFileId()

    /**
     * Get additional event data.
     *
     * @return array The event data.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
     */
    public function getData(): array
    {
        return $this->data;
    }//end getData()
}//end class
