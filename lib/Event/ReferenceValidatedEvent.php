<?php

/**
 * OpenRegister ReferenceValidatedEvent
 *
 * Side-channel event dispatched when a `validateReference: true`
 * property successfully resolves: the referenced UUID was found in the
 * target schema and the save pipeline accepted the reference. This is
 * an extensibility hook — listeners can use it for analytics, cache
 * warming, or downstream notifications without altering the save flow.
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
 * Event dispatched when reference existence validation succeeds.
 */
class ReferenceValidatedEvent extends AbstractReferenceValidationEvent {
}//end class
