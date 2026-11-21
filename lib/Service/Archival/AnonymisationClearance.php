<?php

/**
 * The question whether one record may be anonymised now.
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
 * What a clearance check reads: the record's marker, its legal hold and the
 * salt the run would use.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */
final class AnonymisationClearance {
	/**
	 * Hold what the check reads.
	 *
	 * @param array<string, mixed> $marker          Its anonymisation marker, if any.
	 * @param bool                 $hasLegalHold    Whether a hold is active.
	 * @param string               $holdReason      The hold's reason, for the refusal.
	 * @param string               $saltFingerprint The salt this run would use.
	 */
	public function __construct(
		public readonly array $marker,
		public readonly bool $hasLegalHold,
		public readonly string $holdReason,
		public readonly string $saltFingerprint,
	) {
	}//end __construct()
}//end class
