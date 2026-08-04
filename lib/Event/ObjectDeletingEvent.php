<?php

/**
 * OpenRegister ObjectDeletingEvent
 *
 * This file contains the event class dispatched when an object is being deleted
 * in the OpenRegister application. Supports hook-based rejection via StoppableEventInterface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Event dispatched when an object is being deleted.
 *
 * Implements StoppableEventInterface so hooks can reject deletion.
 */
class ObjectDeletingEvent extends Event implements StoppableEventInterface
{

    /**
     * The object entity being deleted
     *
     * @var ObjectEntity The object entity that is being deleted
     */
    private ObjectEntity $object;

    /**
     * Whether event propagation has been stopped
     *
     * @var boolean
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
     * Constructor for ObjectDeletingEvent
     *
     * @param ObjectEntity $object The object entity that is being deleted
     *
     * @return void
     */
    public function __construct(ObjectEntity $object)
    {
        parent::__construct();
        $this->object = $object;
    }//end __construct()

    /**
     * Get the object entity being deleted
     *
     * @return ObjectEntity The object entity that is being deleted
     *
     * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
     */
    public function getObject(): ObjectEntity
    {
        return $this->object;
    }//end getObject()

    /**
     * Check if propagation has been stopped by a hook
     *
     * @return bool True if propagation is stopped
     *
     * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }//end isPropagationStopped()

    /**
     * Stop event propagation (used by hooks to reject deletion)
     *
     * @return void
     *
     * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
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
     *
     * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
     */
    public function setErrors(array $errors): void
    {
        $this->errors = $errors;
    }//end setErrors()

    /**
     * Get errors from hooks
     *
     * @return array<string, mixed> The error details
     *
     * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
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
     *
     * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
     */
    public function setModifiedData(array $data): void
    {
        $this->modifiedData = $data;
    }//end setModifiedData()

    /**
     * Get modified data from hooks
     *
     * @return array<string, mixed> The modified data
     *
     * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
     */
    public function getModifiedData(): array
    {
        return $this->modifiedData;
    }//end getModifiedData()
}//end class
