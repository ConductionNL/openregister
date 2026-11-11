<?php

/**
 * OpenRegister AbstractPreWriteObjectEvent
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

namespace OCA\OpenRegister\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * An event dispatched before one object is written, which a listener can veto.
 */
abstract class AbstractPreWriteObjectEvent extends AbstractObjectEntityEvent implements StoppableEventInterface {
	use WriteVetoState;
}//end class
