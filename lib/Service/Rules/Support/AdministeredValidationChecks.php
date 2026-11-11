<?php

/**
 * OpenRegister AdministeredValidationChecks
 *
 * What an administered validation is evaluated with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules\Support;

use OCA\OpenRegister\Service\Rules\AdministeredValidations;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use OCP\IL10N;

/**
 * The declared checks, the named-condition vocabulary and the caller's language, bundled for the enforcer.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
final class AdministeredValidationChecks {

	/**
	 * Bundle the services.
	 *
	 * @param AdministeredValidations $validations The declared checks.
	 * @param NamedConditionLibrary $conditions The named-condition vocabulary.
	 * @param IL10N $l10n The caller's language.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly AdministeredValidations $validations,
		public readonly NamedConditionLibrary $conditions,
		public readonly IL10N $l10n,
	) {
	}//end __construct()
}//end class
