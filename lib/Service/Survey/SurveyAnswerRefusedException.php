<?php

/**
 * Raised when an invitation cannot be answered, or an answer cannot be accepted.
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

use RuntimeException;

/**
 * A refusal with the HTTP status it answers with and a message the respondent can read.
 *
 * `GONE` is the invitation itself (expired, used, never sent); `INCOMPLETE` is the
 * submission (a required question has no answer). The respondent can do something
 * about the second and nothing about the first, so they are told apart.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */
class SurveyAnswerRefusedException extends RuntimeException {

	/**
	 * The invitation can no longer be answered.
	 */
	public const GONE = 410;

	/**
	 * The submission misses an answer it needs.
	 */
	public const INCOMPLETE = 400;

	/**
	 * Constructor.
	 *
	 * @param string $message The refusal, as the respondent reads it.
	 * @param int    $status  GONE or INCOMPLETE.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
	 */
	public function __construct(string $message, public readonly int $status) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
