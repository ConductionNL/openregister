<?php

/**
 * Where the schema save checks get the annotation validators they run.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator;
use OCA\OpenRegister\Service\Archival\ElementMappingValidator;
use OCA\OpenRegister\Service\Archival\ElementMappingValidatorInterface;
use OCA\OpenRegister\Service\Archival\MdtoElementCatalogue;
use OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Consent\ConsentAnnotationValidator;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkAnnotationValidator;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver;
use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator;
use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidator;
use OCA\OpenRegister\Service\Relation\LinkExposure;
use OCA\OpenRegister\Service\Relation\LinkExposureInterface;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;
use OCA\OpenRegister\Service\Rules\DependentValueValidator;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver;

/**
 * Builds the validators the schema save checks in lib/Db/SchemaMapper run.
 *
 * Each method constructs exactly what the check used to construct itself with
 * `new`, a fresh instance with the same arguments, so the checks behave as
 * before while naming only this class and the interfaces it returns. The
 * annotation keys they read are re-exported here for the same reason.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) It is the one place that names every validator, so the checks do not.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One factory method per validator a check runs.
 * @SuppressWarnings(PHPMD.StaticAccess)           ExpressionDefaultResolver::validateDeclarations() is static by design.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemaAnnotationValidators {

	/**
	 * The relation-type vocabulary annotation key.
	 *
	 * @var string
	 */
	public const RELATION_VOCABULARY_ANNOTATION = RelationAnnotationValidator::VOCABULARY_ANNOTATION;

	/**
	 * The external-links annotation key.
	 *
	 * @var string
	 */
	public const EXTERNAL_LINK_ANNOTATION = ExternalLinkResolver::ANNOTATION;

	/**
	 * The MDTO element-mapping annotation key.
	 *
	 * @var string
	 */
	public const ELEMENT_MAPPING_ANNOTATION = ElementMappingValidator::ANNOTATION_KEY;

	/**
	 * The `x-openregister-calculations` validator.
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function calculation(): SchemaAnnotationValidatorInterface {
		return new CalculationAnnotationValidator();
	}//end calculation()

	/**
	 * The relation-annotation validator.
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function relation(): SchemaAnnotationValidatorInterface {
		return new RelationAnnotationValidator();
	}//end relation()

	/**
	 * The lifecycle-annotation validator, with its default collaborators.
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function lifecycle(): SchemaAnnotationValidatorInterface {
		return new LifecycleAnnotationValidator();
	}//end lifecycle()

	/**
	 * The `x-openregister-widgets` validator.
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function widget(): SchemaAnnotationValidatorInterface {
		return new WidgetAnnotationValidator();
	}//end widget()

	/**
	 * The external-links annotation validator.
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function externalLink(): SchemaAnnotationValidatorInterface {
		return new ExternalLinkAnnotationValidator();
	}//end externalLink()

	/**
	 * The handoff-annotation validator.
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function handoff(): SchemaAnnotationValidatorInterface {
		return new HandoffAnnotationValidator();
	}//end handoff()

	/**
	 * The handoff contract-binding validator.
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function handoffContractBinding(): SchemaAnnotationValidatorInterface {
		return new HandoffContractBindingValidator();
	}//end handoffContractBinding()

	/**
	 * The reversibility-annotation validator (reads the schema configuration).
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function reversibility(): SchemaAnnotationValidatorInterface {
		return new ReversibilityAnnotationValidator();
	}//end reversibility()

	/**
	 * The dependent-value validator.
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function dependentValue(): SchemaAnnotationValidatorInterface {
		return new DependentValueValidator();
	}//end dependentValue()

	/**
	 * The consent-annotation validator.
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function consent(): SchemaAnnotationValidatorInterface {
		return new ConsentAnnotationValidator();
	}//end consent()

	/**
	 * The review-annotation validator (reads the schema configuration).
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function review(): SchemaAnnotationValidatorInterface {
		return new ReviewAnnotationValidator();
	}//end review()

	/**
	 * The registry-annotation validator.
	 *
	 * @return SchemaAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function registry(): SchemaAnnotationValidatorInterface {
		return new RegistryAnnotationValidator();
	}//end registry()

	/**
	 * The relation-type `exposes` reader.
	 *
	 * @return LinkExposureInterface A fresh reader.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public static function linkExposure(): LinkExposureInterface {
		return new LinkExposure();
	}//end linkExposure()

	/**
	 * The MDTO element-mapping validator over the MDTO catalogue.
	 *
	 * @return ElementMappingValidatorInterface A fresh validator.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public static function elementMapping(): ElementMappingValidatorInterface {
		return new ElementMappingValidator(catalogue: new MdtoElementCatalogue());
	}//end elementMapping()

	/**
	 * The extends-form declaration vocabulary.
	 *
	 * @return ExtendingFormDeclarationInterface A fresh vocabulary.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function extendingForm(): ExtendingFormDeclarationInterface {
		return new ExtendingFormDeclaration();
	}//end extendingForm()

	/**
	 * The refusals of the expression defaults a properties block declares.
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
}//end class
