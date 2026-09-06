<?php

/**
 * A timer operation refused because of the timer's current state.
 *
 * Thrown when an extension is requested on a fired, cancelled or superseded
 * timer or past its fire moment, when the extension bound is reached (the
 * message names the bound), when a suspended timer is suspended again, or
 * when a running timer is resumed. The timer is left exactly as it was.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * A state-refused timer operation, with the state in the message.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
 */
class FlowTimerStateException extends RuntimeException {
}//end class
