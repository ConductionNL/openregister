<?php

/**
 * A cache outside Processes that follows every object write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts\Processes
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

namespace OCA\OpenRegister\Kernel\Contracts\Processes;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Follows the object writes so a cache another area keeps never serves a stale entry.
 *
 * The ordered listener list (Processes) registers this contract on the object
 * created, updated and deleted events, in its cache-eviction slot (ListenerRegistrar::registerCacheEvictionListeners()).
 * The area that does the work implements it and aliases it to its listener in
 * its own registrar, so the listener runs at the same place in the order
 * without the list naming a class of a higher area.
 *
 * @template T of Event
 * @template-extends IEventListener<T>
 */
interface ObjectWriteCacheFollowerInterface extends IEventListener {
}//end interface
