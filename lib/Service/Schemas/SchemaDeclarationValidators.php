<?php

/**
 * SchemaDeclarationValidators: the validators a schema save builds, behind their interfaces.
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

use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator;
use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidator;
use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Archival\ElementMappingValidator;
use OCA\OpenRegister\Service\Archival\ElementMappingValidatorInterface;
use OCA\OpenRegister\Service\Archival\MdtoElementCatalogue;
use OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidator;
use OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Calculation\PropertyCalculations;
use OCA\OpenRegister\Service\Calculation\PropertyCalculationsInterface;
use OCA\OpenRegister\Service\Consent\ConsentAnnotationValidator;
use OCA\OpenRegister\Service\Consent\ConsentAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidatorInterface;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkAnnotationValidator;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator;
use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidator;
use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidatorInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidator;
use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Relation\LinkExposure;
use OCA\OpenRegister\Service\Relation\LinkExposureInterface;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use OCA\OpenRegister\Service\Relation\RelationTypeResolverInterface;
use OCA\OpenRegister\Service\Rules\DependentValueValidator;
use OCA\OpenRegister\Service\Rules\DependentValueValidatorInterface;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver;

/**
 * Builds the annotation validators the schema save path runs.
 *
 * The save path lives in the Db layer and has no container: each check builds
 * its validator with `new`, a fresh instance per call. This class does exactly
 * that, one method per validator, and hands the instance back typed against
 * the validator's interface, so the Db layer names the interface and never the
 * class that implements it.
 */
final class SchemaDeclarationValidators {

	/**
	 * A fresh calculation annotation validator.
	 *
	 * @return CalculationAnnotationValidatorInterface
	 */
	public static function calculation(): CalculationAnnotationValidatorInterface {
		return new CalculationAnnotationValidator();
	}//end calculation()

	/**
	 * A fresh reader of the calculations declared on properties.
	 *
	 * @return PropertyCalculationsInterface
	 */
	public static function propertyCalculations(): PropertyCalculationsInterface {
		return new PropertyCalculations();
	}//end propertyCalculations()

	/**
	 * A fresh relation annotation validator.
	 *
	 * @return RelationAnnotationValidatorInterface
	 */
	public static function relation(): RelationAnnotationValidatorInterface {
		return new RelationAnnotationValidator();
	}//end relation()

	/**
	 * A fresh reader of what a relation exposes.
	 *
	 * @return LinkExposureInterface
	 */
	public static function linkExposure(): LinkExposureInterface {
		return new LinkExposure();
	}//end linkExposure()

	/**
	 * A fresh relation type resolver.
	 *
	 * @return RelationTypeResolverInterface
	 */
	public static function relationTypes(): RelationTypeResolverInterface {
		return new RelationTypeResolver();
	}//end relationTypes()

	/**
	 * A fresh lifecycle annotation validator.
	 *
	 * @return LifecycleAnnotationValidatorInterface
	 */
	public static function lifecycle(): LifecycleAnnotationValidatorInterface {
		return new LifecycleAnnotationValidator();
	}//end lifecycle()

	/**
	 * A fresh reversibility annotation validator.
	 *
	 * @return ReversibilityAnnotationValidatorInterface
	 */
	public static function reversibility(): ReversibilityAnnotationValidatorInterface {
		return new ReversibilityAnnotationValidator();
	}//end reversibility()

	/**
	 * A fresh dependent-value validator.
	 *
	 * @return DependentValueValidatorInterface
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
	 */
	public static function expressionDefaultRefusals(array $properties): array {
		return ExpressionDefaultResolver::validateDeclarations(properties: $properties);
	}//end expressionDefaultRefusals()

	/**
	 * A fresh widget annotation validator.
	 *
	 * @return WidgetAnnotationValidatorInterface
	 */
	public static function widgets(): WidgetAnnotationValidatorInterface {
		return new WidgetAnnotationValidator();
	}//end widgets()

	/**
	 * A fresh MDTO element-mapping validator over the MDTO element catalogue.
	 *
	 * @return ElementMappingValidatorInterface
	 */
	public static function elementMapping(): ElementMappingValidatorInterface {
		return new ElementMappingValidator(catalogue: new MdtoElementCatalogue());
	}//end elementMapping()

	/**
	 * A fresh archival annotation validator.
	 *
	 * @return ArchivalAnnotationValidatorInterface
	 */
	public static function archival(): ArchivalAnnotationValidatorInterface {
		return new ArchivalAnnotationValidator();
	}//end archival()

	/**
	 * A fresh external-link annotation validator.
	 *
	 * @return ExternalLinkAnnotationValidatorInterface
	 */
	public static function externalLinks(): ExternalLinkAnnotationValidatorInterface {
		return new ExternalLinkAnnotationValidator();
	}//end externalLinks()

	/**
	 * A fresh hand-off annotation validator.
	 *
	 * @return HandoffAnnotationValidatorInterface
	 */
	public static function handoff(): HandoffAnnotationValidatorInterface {
		return new HandoffAnnotationValidator();
	}//end handoff()

	/**
	 * A fresh hand-off contract binding validator.
	 *
	 * @return HandoffContractBindingValidatorInterface
	 */
	public static function handoffContractBinding(): HandoffContractBindingValidatorInterface {
		return new HandoffContractBindingValidator();
	}//end handoffContractBinding()

	/**
	 * A fresh registry annotation validator.
	 *
	 * @return RegistryAnnotationValidatorInterface
	 */
	public static function registry(): RegistryAnnotationValidatorInterface {
		return new RegistryAnnotationValidator();
	}//end registry()

	/**
	 * A fresh review annotation validator.
	 *
	 * @return ReviewAnnotationValidatorInterface
	 */
	public static function review(): ReviewAnnotationValidatorInterface {
		return new ReviewAnnotationValidator();
	}//end review()

	/**
	 * A fresh consent annotation validator.
	 *
	 * @return ConsentAnnotationValidatorInterface
	 */
	public static function consent(): ConsentAnnotationValidatorInterface {
		return new ConsentAnnotationValidator();
	}//end consent()

	/**
	 * A fresh extending-form declaration validator.
	 *
	 * @return ExtendingFormValidatorInterface
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function extendingForm(): ExtendingFormValidatorInterface {
		return new ExtendingFormDeclaration();
	}//end extendingForm()

	/**
	 * A fresh reader of the uniqueness constraints and the identity among them.
	 *
	 * @return UniqueConstraintEvaluatorInterface
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public static function uniqueConstraints(): UniqueConstraintEvaluatorInterface {
		return new UniqueConstraintEvaluator();
	}//end uniqueConstraints()
}//end class
