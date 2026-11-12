<?php

/**
 * A payload before and after an anonymisation.
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
 * The two states of one payload a report compares.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */
final class PayloadChange {
	/**
	 * Hold both states.
	 *
	 * @param array<string, mixed> $before The payload as it was.
	 * @param array<string, mixed> $after  The payload as it is now.
	 */
	public function __construct(
		public readonly array $before,
		public readonly array $after,
	) {
	}//end __construct()
}//end class
