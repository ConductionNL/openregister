<?php

/**
 * Somebody following an invitation link, at a moment.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survey
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survey;

use DateTimeInterface;

/**
 * A visit to an invitation link (ADR-011 Rule 2 operation object).
 */
final class InvitationVisit {

	/**
	 * Build the visit.
	 *
	 * @param array<string, mixed>   $invitation The invitation as stored.
	 * @param array<string, mixed>   $survey     Its survey.
	 * @param DateTimeInterface|null $now        The moment, for a frozen clock; null is now.
	 */
	public function __construct(
		public readonly array $invitation,
		public readonly array $survey,
		public readonly ?DateTimeInterface $now = null
	) {
	}//end __construct()
}//end class
