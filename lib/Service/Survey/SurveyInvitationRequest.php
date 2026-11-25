<?php

/**
 * What an invitation to answer a survey is about, who it goes to and until when it holds.
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
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survey;

/**
 * One invitation to be sent: the survey, the object it asks about, the respondent and the expiry.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */
final class SurveyInvitationRequest {

	/**
	 * Constructor.
	 *
	 * @param string $register      The survey register, by id, uuid or slug.
	 * @param string $survey        The survey's uuid.
	 * @param string $subjectObject The uuid of the object the survey asks about.
	 * @param string $respondent    The respondent's address.
	 * @param string $expiresAt     Until when the invitation can be answered, as a date this instance can read.
	 * @param string $minter        The user the invitation link is minted for.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $survey,
		public readonly string $subjectObject,
		public readonly string $respondent,
		public readonly string $expiresAt,
		public readonly string $minter,
	) {
	}//end __construct()
}//end class
