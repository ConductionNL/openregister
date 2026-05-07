<?php

/**
 * OpenRegister ReferenceValidationFailedEvent
 *
 * Side-channel event dispatched when a `validateReference: true`
 * property fails to resolve: the referenced UUID was not found in the
 * target schema and the save pipeline rejected the object. This is an
 * extensibility hook — listeners can use it for monitoring, alerting,
 * or telemetry without altering the exception-driven failure flow.
 *
 * Dispatched immediately before `ReferenceValidationException` is
 * thrown, so listeners observe every rejection regardless of whether
 * the controller layer recovers or surfaces the 422 to the client.
 *
 * Closes the reference-existence-validation spec requirement:
 * "Validation events dispatched for notification and extensibility."
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Event dispatched when reference existence validation fails.
 */
class ReferenceValidationFailedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param string      $propertyName     Schema property carrying the
     *                                      broken reference.
     * @param string      $referencedUuid   UUID that did not resolve.
     * @param string      $targetSchemaSlug Slug (or raw `$ref`) of the
     *                                      target schema.
     * @param string|null $targetRegister   Register the lookup was
     *                                      performed in, or null when
     *                                      no register context applied.
     */
    public function __construct(
        private readonly string $propertyName,
        private readonly string $referencedUuid,
        private readonly string $targetSchemaSlug,
        private readonly ?string $targetRegister=null
    ) {
        parent::__construct();

    }//end __construct()

    /**
     * Schema property name that holds the broken reference.
     *
     * @return string Property name as declared on the schema.
     */
    public function getPropertyName(): string
    {
        return $this->propertyName;

    }//end getPropertyName()

    /**
     * UUID that failed to resolve during validation.
     *
     * @return string The unresolved UUID.
     */
    public function getReferencedUuid(): string
    {
        return $this->referencedUuid;

    }//end getReferencedUuid()

    /**
     * Slug (or raw `$ref`) of the schema the reference targets.
     *
     * @return string Target schema slug or raw `$ref`.
     */
    public function getTargetSchemaSlug(): string
    {
        return $this->targetSchemaSlug;

    }//end getTargetSchemaSlug()

    /**
     * Register the reference was searched in.
     *
     * @return string|null Register identifier or null when no register
     *                     context applied to the lookup.
     */
    public function getTargetRegister(): ?string
    {
        return $this->targetRegister;

    }//end getTargetRegister()
}//end class
