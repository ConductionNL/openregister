<?php

/**
 * A write declared as the system's, in a process that cannot grant it.
 *
 * Thrown rather than degrading. The degraded alternative runs the identical
 * write as whoever is signed in and returns the same value the elevated call
 * would have, so nothing distinguishes it from success until a permission
 * check fails somewhere unrelated, or the wrong principal is found recorded
 * against a row long afterwards.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * Thrown when a declared system write cannot be elevated.
 */
class SystemContextUnavailableException extends RuntimeException {

	/**
	 * Build the refusal.
	 *
	 * @param string $message Why, naming what was being attempted.
	 */
	public function __construct(string $message) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
