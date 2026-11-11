<?php

/**
 * One read-time evaluation of a profile's calculations.
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
 * One read-time evaluation of a profile's `x-openregister-calculations`: the user, the evaluation data and the calculation map.
 *
 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-013
 */
final class CalculationRun {
	/**
	 * Hold the values.
	 *
	 * @param string               $userId Nextcloud user id (used in warning messages only).
	 * @param array<string, mixed> $data   Evaluation data including `@self`.
	 * @param array<string, mixed> $calcs  Calculation spec map from the schema.
	 *
	 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-013
	 */
	public function __construct(
		public readonly string $userId,
		public readonly array $data,
		public readonly array $calcs,
	) {
	}//end __construct()
}//end class
