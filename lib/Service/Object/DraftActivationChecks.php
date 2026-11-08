<?php

/**
 * OpenRegister draft activation checks: what judges a draft before it leaves draft
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\Form\FormPayloadFindings;
use OCP\IL10N;

/**
 * Draft activation checks: the validator, the findings it is reported in, and the words of a refusal.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */
final class DraftActivationChecks {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectValidatorInterface $validator The schema's full validator.
	 * @param FormPayloadFindings      $findings  Turns a refusal into per-property findings.
	 * @param IL10N                    $l10n      Translations.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function __construct(
		public readonly ObjectValidatorInterface $validator,
		public readonly FormPayloadFindings $findings,
		public readonly IL10N $l10n,
	) {
	}//end __construct()
}//end class
