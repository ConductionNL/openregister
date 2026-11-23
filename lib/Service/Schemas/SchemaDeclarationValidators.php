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

use OCA\OpenRegister\Service\Consent\ConsentAnnotationValidator;
use OCA\OpenRegister\Service\Consent\ConsentAnnotationValidatorInterface;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkAnnotationValidator;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidator;
use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Relation\LinkExposure;
use OCA\OpenRegister\Service\Relation\LinkExposureInterface;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use OCA\OpenRegister\Service\Relation\RelationTypeResolverInterface;

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
	 * A fresh external-link annotation validator.
	 *
	 * @return ExternalLinkAnnotationValidatorInterface
	 */
	public static function externalLinks(): ExternalLinkAnnotationValidatorInterface {
		return new ExternalLinkAnnotationValidator();
	}//end externalLinks()

	/**
	 * A fresh registry annotation validator.
	 *
	 * @return RegistryAnnotationValidatorInterface
	 */
	public static function registry(): RegistryAnnotationValidatorInterface {
		return new RegistryAnnotationValidator();
	}//end registry()

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
