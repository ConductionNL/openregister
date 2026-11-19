<?php

/**
 * HandoffDeclarationValidators: the hand-off validators the schema save checks build, behind their interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator;
use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidator;
use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidatorInterface;

/**
 * Builds the hand-off annotation and hand-off contract binding validators.
 *
 * The hand-off declaration checks Integrations contributes to the schema save
 * live in the Db layer and have no container: each builds its validator with
 * `new` through this class, typed against the validator's interface.
 *
 * @spec openspec/specs/semantic-object-handoff/spec.md
 */
final class HandoffDeclarationValidators {

	/**
	 * A fresh hand-off annotation validator.
	 *
	 * @return HandoffAnnotationValidatorInterface
	 *
	 * @spec openspec/specs/semantic-object-handoff/spec.md
	 */
	public static function handoff(): HandoffAnnotationValidatorInterface {
		return new HandoffAnnotationValidator();
	}//end handoff()

	/**
	 * A fresh hand-off contract binding validator.
	 *
	 * @return HandoffContractBindingValidatorInterface
	 *
	 * @spec openspec/specs/semantic-object-handoff/spec.md
	 */
	public static function handoffContractBinding(): HandoffContractBindingValidatorInterface {
		return new HandoffContractBindingValidator();
	}//end handoffContractBinding()
}//end class
