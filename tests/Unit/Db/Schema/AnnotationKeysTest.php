<?php

/**
 * Unit tests for the annotation keys Schema owns and the services that name them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\Schema;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Schema\AnnotationVocabulary;
use OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator;
use OCA\OpenRegister\Service\Rbac\AccessDeclarationValidators;
use OCA\OpenRegister\Service\Rbac\ClassificationScale;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCA\OpenRegister\Service\Rbac\SecondFactorReadGate;
use PHPUnit\Framework\TestCase;

/**
 * The hierarchy, classification and confidentiality keys keep their spelling and stay in the vocabulary.
 *
 * @spec exclude schema plumbing, not a capability of its own
 */
final class AnnotationKeysTest extends TestCase {

	public function testTheKeysKeepTheirSpelling(): void {
		self::assertSame('x-openregister-hierarchy', Schema::HIERARCHY_ANNOTATION);
		self::assertSame('x-openregister-classification', Schema::CLASSIFICATION_ANNOTATION);
		self::assertSame('x-openregister-confidentiality', Schema::CONFIDENTIALITY_ANNOTATION);
		self::assertSame('x-openregister-second-factor', Schema::SECOND_FACTOR_ANNOTATION);
	}

	public function testTheServicesNameTheSchemaKeys(): void {
		self::assertSame(Schema::HIERARCHY_ANNOTATION, HierarchyGrantExpander::ANNOTATION);
		self::assertSame(Schema::HIERARCHY_ANNOTATION, AccessDeclarationValidators::HIERARCHY_ANNOTATION);
		self::assertSame(Schema::CLASSIFICATION_ANNOTATION, ClassificationScale::ANNOTATION);
		self::assertSame(Schema::CONFIDENTIALITY_ANNOTATION, ConfidentialityAnnotationValidator::ANNOTATION);
		self::assertSame(Schema::SECOND_FACTOR_ANNOTATION, SecondFactorReadGate::ANNOTATION);
	}

	public function testTheVocabularyKeepsTheKeysAndSchemaReadsTheSameList(): void {
		foreach ([Schema::HIERARCHY_ANNOTATION, Schema::CLASSIFICATION_ANNOTATION, Schema::CONFIDENTIALITY_ANNOTATION, Schema::SECOND_FACTOR_ANNOTATION] as $key) {
			self::assertContains($key, AnnotationVocabulary::KEYS);
			self::assertFalse((new AnnotationVocabulary())->isUnknownAnnotation(key: $key));
		}

		self::assertSame(AnnotationVocabulary::KEYS, Schema::ANNOTATION_VOCABULARY);
	}
}
