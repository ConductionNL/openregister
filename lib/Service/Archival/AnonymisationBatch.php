<?php

/**
 * A batch of records to anonymise in one sweep.
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

use Closure;

/**
 * The candidates of one sweep, how to find the stores each must reach, and
 * the instance salt.
 *
 * A candidate is `['uuid' => string, 'payload' => array, 'annotation' =>
 * array, 'marker' => array, 'hasLegalHold' => bool, 'holdReason' => string,
 * 'profileName' => string]`.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */
final class AnonymisationBatch {
	/**
	 * Hold the batch.
	 *
	 * @param array<int, array<string, mixed>> $candidates The records to consider.
	 * @param Closure                          $targetsFor Returns the targets for one candidate and its plan.
	 * @param string                           $salt       The instance salt.
	 */
	public function __construct(
		public readonly array $candidates,
		public readonly Closure $targetsFor,
		public readonly string $salt,
	) {
	}//end __construct()
}//end class
