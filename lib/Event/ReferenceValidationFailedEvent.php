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
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

/**
 * Event dispatched when reference existence validation fails.
 */
class ReferenceValidationFailedEvent extends AbstractReferenceValidationEvent {
}//end class
