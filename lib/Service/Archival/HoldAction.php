<?php

/**
 * One matter placing or releasing its legal hold.
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
 * The matter, why it acts, and who acts when.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class HoldAction {
	/**
	 * Hold the values.
	 *
	 * @param string|null $ownerKey The matter placing or releasing the hold; null for a manual hold, or to release every hold.
	 * @param string      $reason   Why the hold is placed or released.
	 * @param HoldStamp   $stamp    Who acts, and the moment (ISO 8601).
	 */
	public function __construct(
		public readonly ?string $ownerKey,
		public readonly string $reason,
		public readonly HoldStamp $stamp,
	) {
	}//end __construct()
}//end class
