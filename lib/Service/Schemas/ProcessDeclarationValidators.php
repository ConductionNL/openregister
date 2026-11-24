<?php

/**
 * ProcessDeclarationValidators: the process validators the schema save checks build, behind their interfaces.
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

use OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidator;
use OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Calculation\PropertyCalculations;
use OCA\OpenRegister\Service\Calculation\PropertyCalculationsInterface;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Rules\DependentValueValidator;
use OCA\OpenRegister\Service\Rules\DependentValueValidatorInterface;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver;

/**
 * Builds the lifecycle, calculation, dependent-value, reversibility and review validators.
 *
 * The process declaration checks Processes contributes to the schema save live
 * in the Db layer and have no container: each builds its validator with `new`
 * through this class, typed against the validator's interface.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class ProcessDeclarationValidators {

	/**
	 * A fresh calculation annotation validator.
	 *
	 * @return CalculationAnnotationValidatorInterface
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function calculation(): CalculationAnnotationValidatorInterface {
		return new CalculationAnnotationValidator();
	}//end calculation()

	/**
	 * A fresh reader of the calculations declared on properties.
	 *
	 * @return PropertyCalculationsInterface
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function propertyCalculations(): PropertyCalculationsInterface {
		return new PropertyCalculations();
	}//end propertyCalculations()

	/**
	 * A fresh lifecycle annotation validator.
	 *
	 * @return LifecycleAnnotationValidatorInterface
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function lifecycle(): LifecycleAnnotationValidatorInterface {
		return new LifecycleAnnotationValidator();
	}//end lifecycle()

	/**
	 * A fresh reversibility annotation validator.
	 *
	 * @return ReversibilityAnnotationValidatorInterface
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function reversibility(): ReversibilityAnnotationValidatorInterface {
		return new ReversibilityAnnotationValidator();
	}//end reversibility()

	/**
	 * A fresh dependent-value validator.
	 *
	 * @return DependentValueValidatorInterface
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function dependentValues(): DependentValueValidatorInterface {
		return new DependentValueValidator();
	}//end dependentValues()

	/**
	 * The refusals of the default expressions declared on properties.
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 *
	 * @return array<int, array{code: string, message: string}> The errors, empty when sound.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function expressionDefaultRefusals(array $properties): array {
		return ExpressionDefaultResolver::validateDeclarations(properties: $properties);
	}//end expressionDefaultRefusals()

	/**
	 * A fresh review annotation validator.
	 *
	 * @return ReviewAnnotationValidatorInterface
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function review(): ReviewAnnotationValidatorInterface {
		return new ReviewAnnotationValidator();
	}//end review()
}//end class
