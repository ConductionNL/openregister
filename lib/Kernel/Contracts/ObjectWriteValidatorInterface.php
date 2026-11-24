<?php

/**
 * A check outside Processes that may refuse an object write before it is stored.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec exclude Container wiring; the listener carries its own spec.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuses an object create or update another area owns the rules of.
 *
 * The ordered listener list (Processes) registers this contract on the object
 * creating and updating events, beside the other write-time validators. The
 * area that owns the rules implements it and aliases it to its listener, so
 * the check runs at the same place in the order without the list naming a
 * class of a higher area. A refusal sets the event's errors and stops it.
 *
 * @template T of Event
 * @template-extends IEventListener<T>
 */
interface ObjectWriteValidatorInterface extends IEventListener {
}//end interface
