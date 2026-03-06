<?php

/**
 * OpenRegister ObjectUpdatingEvent
 *
 * This file contains the event class dispatched when an object is being updated
 * in the OpenRegister application. Supports hook-based rejection via StoppableEventInterface.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event dispatched when an object is being updated.
 *
 * Implements StoppableEventInterface so hooks can reject updates.
 */
class ObjectUpdatingEvent extends Event implements StoppableEventInterface
{

    /**
     * The updated object entity state
     *
     * @var ObjectEntity The object entity after update
     */
    private ObjectEntity $newObject;

    /**
     * The previous object entity state
     *
     * @var ObjectEntity|null The object entity before update (null if not available)
     */
    private ?ObjectEntity $oldObject;

    /**
     * Whether event propagation has been stopped
     *
     * @var bool
     */
    private bool $propagationStopped = false;

    /**
     * Errors from hooks that stopped propagation
     *
     * @var array<string, mixed>
     */
    private array $errors = [];

    /**
     * Modified data from hooks
     *
     * @var array<string, mixed>
     */
    private array $modifiedData = [];

    /**
     * Constructor for ObjectUpdatingEvent
     *
     * @param ObjectEntity      $newObject The object entity after update
     * @param ObjectEntity|null $oldObject The object entity before update (null if not available)
     *
     * @return void
     */
    public function __construct(ObjectEntity $newObject, ?ObjectEntity $oldObject=null)
    {
        parent::__construct();
        $this->newObject = $newObject;
        $this->oldObject = $oldObject;
    }//end __construct()

    /**
     * Get the updated object entity
     *
     * @return ObjectEntity The object entity after update
     */
    public function getNewObject(): ObjectEntity
    {
        return $this->newObject;
    }//end getNewObject()

    /**
     * Get the original object entity
     *
     * @return ObjectEntity|null The object entity before update (null if not available)
     */
    public function getOldObject(): ?ObjectEntity
    {
        return $this->oldObject;
    }//end getOldObject()

    /**
     * Check if propagation has been stopped by a hook
     *
     * @return bool True if propagation is stopped
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }//end isPropagationStopped()

    /**
     * Stop event propagation (used by hooks to reject update)
     *
     * @return void
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }//end stopPropagation()

    /**
     * Set errors from hooks
     *
     * @param array<string, mixed> $errors The error details
     *
     * @return void
     */
    public function setErrors(array $errors): void
    {
        $this->errors = $errors;
    }//end setErrors()

    /**
     * Get errors from hooks
     *
     * @return array<string, mixed> The error details
     */
    public function getErrors(): array
    {
        return $this->errors;
    }//end getErrors()

    /**
     * Set modified data from hooks
     *
     * @param array<string, mixed> $data The modified data
     *
     * @return void
     */
    public function setModifiedData(array $data): void
    {
        $this->modifiedData = $data;
    }//end setModifiedData()

    /**
     * Get modified data from hooks
     *
     * @return array<string, mixed> The modified data
     */
    public function getModifiedData(): array
    {
        return $this->modifiedData;
    }//end getModifiedData()
}//end class
