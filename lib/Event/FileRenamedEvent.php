<?php

/**
 * OpenRegister FileRenamedEvent
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
 * Event dispatched for file action: FileRenamedEvent
 */
class FileRenamedEvent extends Event
{

    /**
     * Constructor for FileRenamedEvent
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
        private readonly array $data = []
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the object UUID.
     *
     * @return string The object UUID.
     */
    public function getObjectUuid(): string
    {
        return $this->objectUuid;
    }//end getObjectUuid()

    /**
     * Get the file ID.
     *
     * @return int The file ID.
     */
    public function getFileId(): int
    {
        return $this->fileId;
    }//end getFileId()

    /**
     * Get additional event data.
     *
     * @return array The event data.
     */
    public function getData(): array
    {
        return $this->data;
    }//end getData()
}//end class
