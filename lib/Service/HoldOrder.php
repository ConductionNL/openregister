<?php

/**
 * An order to place or release a legal hold: why, and for which matter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * An order to place or release a legal hold (ADR-011 Rule 2).
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class HoldOrder {
	/**
	 * Build the order.
	 *
	 * @param string      $reason   Why the hold is placed or released.
	 * @param string|null $ownerKey The matter placing or releasing its own hold; null for a manual hold, or to release every hold.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly string $reason,
		public readonly ?string $ownerKey = null,
	) {
	}//end __construct()
}//end class
