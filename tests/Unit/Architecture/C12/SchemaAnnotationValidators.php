<?php

/**
 * C12 cases: the annotation validators the schema save path builds.
 *
 * The Db layer reaches them through SchemaDeclarationValidators, typed against
 * their interfaces. ExpressionDefaultResolver and MdtoElementCatalogue have no
 * interface: the Db layer needs neither type, only what the builder returns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

use OCA\OpenRegister\Service\Aggregation;
use OCA\OpenRegister\Service\Archival;
use OCA\OpenRegister\Service\BulkJob;
use OCA\OpenRegister\Service\Calculation;
use OCA\OpenRegister\Service\Consent;
use OCA\OpenRegister\Service\Decision;
use OCA\OpenRegister\Service\ExternalLink;
use OCA\OpenRegister\Service\Handoff;
use OCA\OpenRegister\Service\Lifecycle;
use OCA\OpenRegister\Service\Registry;
use OCA\OpenRegister\Service\Relation;
use OCA\OpenRegister\Service\Rules;

return [
	'classes' => [
		Aggregation\WidgetAnnotationValidator::class => Aggregation\WidgetAnnotationValidatorInterface::class,
		Archival\ArchivalAnnotationValidator::class => Archival\ArchivalAnnotationValidatorInterface::class,
		Archival\ElementMappingValidator::class => Archival\ElementMappingValidatorInterface::class,
		Archival\MdtoElementCatalogue::class => null,
		BulkJob\ReversibilityAnnotationValidator::class => BulkJob\ReversibilityAnnotationValidatorInterface::class,
		Calculation\CalculationAnnotationValidator::class => Calculation\CalculationAnnotationValidatorInterface::class,
		Calculation\PropertyCalculations::class => Calculation\PropertyCalculationsInterface::class,
		Consent\ConsentAnnotationValidator::class => Consent\ConsentAnnotationValidatorInterface::class,
		Decision\ReviewAnnotationValidator::class => Decision\ReviewAnnotationValidatorInterface::class,
		ExternalLink\ExternalLinkAnnotationValidator::class => ExternalLink\ExternalLinkAnnotationValidatorInterface::class,
		ExternalLink\ExternalLinkResolver::class => ExternalLink\ExternalLinkResolverInterface::class,
		Handoff\HandoffAnnotationValidator::class => Handoff\HandoffAnnotationValidatorInterface::class,
		Handoff\HandoffContractBindingValidator::class => Handoff\HandoffContractBindingValidatorInterface::class,
		Lifecycle\LifecycleAnnotationValidator::class => Lifecycle\LifecycleAnnotationValidatorInterface::class,
		Registry\RegistryAnnotationValidator::class => Registry\RegistryAnnotationValidatorInterface::class,
		Relation\LinkExposure::class => Relation\LinkExposureInterface::class,
		Relation\RelationAnnotationValidator::class => Relation\RelationAnnotationValidatorInterface::class,
		Relation\RelationTypeResolver::class => Relation\RelationTypeResolverInterface::class,
		Rules\DependentValueValidator::class => Rules\DependentValueValidatorInterface::class,
		Rules\ExpressionDefaultResolver::class => null,
	],
	'kept' => [],
];
