<?php

/**
 * A timer configuration the store refuses, named in the message.
 *
 * Thrown at ARM time and at rule validation: an unknown calendar name (never
 * downgraded to weekdays), an enforcing outcome on a timer whose legal effect
 * is not `wettelijk`, an SLA outside `{value: 1..10000, unit}`, an escalation
 * rule without an SLA, or a preBreach offset that resolves before the anchor.
 * The message always names the refused value and the constraint.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A refused timer value, named in the message.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
 */
class FlowTimerValidationException extends InvalidArgumentException {
}//end class
