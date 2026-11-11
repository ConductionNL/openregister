<?php

/**
 * Who changed a legal hold, and when.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The user and the moment a hold is placed or released, as the ledger writes them onto the hold.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class HoldStamp {
	/**
	 * Hold the stamp.
	 *
	 * @param string $userId Who places or releases the hold.
	 * @param string $now    The moment, ISO 8601.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $now,
	) {
	}//end __construct()
}//end class
