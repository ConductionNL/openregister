<?php

/**
 * What one evaluation of a notification rule saw of an object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use DateTime;

/**
 * The fingerprint an evaluation saw, when, and whether it dispatched.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class DedupeSighting {
	/**
	 * Constructor.
	 *
	 * @param string   $fingerprint SHA-1 of the watched-field values.
	 * @param DateTime $now         The evaluation moment (UTC).
	 * @param bool     $dispatched  True when this evaluation recorded a dispatch.
	 */
	public function __construct(
		public readonly string $fingerprint,
		public readonly DateTime $now,
		public readonly bool $dispatched,
	) {
	}//end __construct()
}//end class
