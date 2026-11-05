<?php

/**
 * ImportSurveyRegister — materialises the survey data model.
 *
 * A survey is four schemas and a register, and without this step none of them
 * exist on an instance: the descriptor would sit in the source tree while
 * every survey endpoint answered about a register that was never imported.
 * This step imports `lib/Settings/survey_register.json` through
 * `ConfigurationService::importFromApp()` so the `surveys` register and its
 * four schemas land idempotently on install and on `occ upgrade`, matched by
 * slug and gated on the descriptor version — the same convention as
 * {@see ImportFlowRegister}.
 *
 * Bump `components.registers.surveys.version` when a schema changes, or an
 * upgraded instance keeps the old shape: the importer short-circuits when the
 * shipped version is not higher than the installed one, and says nothing
 * about it.
 *
 * Never throws. A failure logs a warning and leaves the instance otherwise
 * healthy.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-001-a-survey-is-its-own-object-with-its-own-questions
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

/**
 * Imports the survey register descriptor idempotently on upgrade/install.
 */
class ImportSurveyRegister extends AbstractRegisterImportStep {
	/**
	 * App-relative path to the register descriptor imported by this step.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/survey_register.json';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * @var string
	 */
	protected const REGISTER_VERSION = '1.0.0';

	/**
	 * Name of the register in the step's output lines.
	 *
	 * @var string
	 */
	protected const LABEL = 'Survey';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'Survey register imported (surveys register + survey, surveyQuestion, surveyInvitation and surveyAnswerSet)';

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-001-a-survey-is-its-own-object-with-its-own-questions
	 */
	public function getName(): string {
		return 'Import OpenRegister survey register (surveys register + its four schemas)';
	}//end getName()
}//end class
