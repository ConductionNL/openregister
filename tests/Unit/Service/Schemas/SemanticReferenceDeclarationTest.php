<?php

/**
 * A property that references whichever installed schema implements a semantic type.
 *
 * A `referenceSemanticType` that is not an absolute IRI is never resolved, and a
 * field that never resolves looks exactly like a provider app that is not
 * installed. So it is refused at schema save, through the property validator
 * every save path runs (cross-app-semantic-references task 2.1).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use OCA\OpenRegister\Service\Schemas\SemanticReferenceDeclaration;
use OCA\OpenRegister\Service\Schemas\SemanticReferenceException;
use PHPUnit\Framework\TestCase;

/**
 * `referenceSemanticType` and `referenceSemanticApp`.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\SemanticReferenceDeclaration
 * @uses \OCA\OpenRegister\Service\Schemas\PropertyVocabularyException
 * @uses \OCA\OpenRegister\Service\JsonLd\JsonLdContextService
 * @uses \OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler
 */
class SemanticReferenceDeclarationTest extends TestCase {

	/**
	 * A well-formed reference, with or without an app hint, and a property without one, pass.
	 *
	 * @return void
	 */
	public function testAnAbsoluteIriPasses(): void {
		SemanticReferenceDeclaration::assert(
			property: ['type' => 'string', 'referenceSemanticType' => 'https://schema.org/Organization', 'referenceSemanticApp' => 'shillinq'],
			path: '/vendor'
		);
		SemanticReferenceDeclaration::assert(property: ['type' => 'string', 'referenceSemanticType' => 'urn:example:vendor'], path: '/vendor');
		SemanticReferenceDeclaration::assert(property: ['type' => 'string'], path: '/name');

		$this->assertTrue(JsonLdContextService::isAbsoluteIriValue(value: 'https://schema.org/Organization'));
	}//end testAnAbsoluteIriPasses()

	/**
	 * A bare term is refused with a 422-family error naming the key, the path and the value.
	 *
	 * @return void
	 */
	public function testABareTermIsRefused(): void {
		try {
			SemanticReferenceDeclaration::assert(property: ['type' => 'string', 'referenceSemanticType' => 'Organization'], path: '/vendor');
			$this->fail('A bare term must be refused.');
		} catch (SemanticReferenceException $e) {
			$this->assertInstanceOf(PropertyVocabularyException::class, $e);
			$this->assertStringContainsString("'referenceSemanticType' at '/vendor' is 'Organization', which is not an absolute IRI", $e->getMessage());
			$this->assertSame('invalid_semantic_reference', $e->getErrors()[0]['code']);
			$this->assertSame('referenceSemanticType', $e->getErrors()[0]['key']);
			$this->assertSame('/vendor', $e->getErrors()[0]['path']);
		}
	}//end testABareTermIsRefused()

	/**
	 * An empty or non-string type, and a non-string app hint, are refused.
	 *
	 * @return void
	 */
	public function testMalformedValuesAreRefused(): void {
		foreach ([['referenceSemanticType' => ''], ['referenceSemanticType' => 42], ['referenceSemanticType' => 'https://schema.org/Organization', 'referenceSemanticApp' => ['shillinq']]] as $declaration) {
			try {
				SemanticReferenceDeclaration::assert(property: ['type' => 'string'] + $declaration, path: '/vendor');
				$this->fail('Must be refused: ' . json_encode($declaration));
			} catch (SemanticReferenceException $e) {
				$this->assertStringContainsString("at '/vendor'", $e->getMessage());
			}
		}
	}//end testMalformedValuesAreRefused()

	/**
	 * The schema write path refuses the malformed reference: the property validator runs the declaration.
	 *
	 * @return void
	 */
	public function testTheSchemaWriteIsRejected(): void {
		$handler = new PropertyValidatorHandler();
		$this->assertTrue($handler->validateProperty(property: ['type' => 'string', 'referenceSemanticType' => 'https://schema.org/Organization'], path: '/vendor'));

		$this->expectException(SemanticReferenceException::class);
		$handler->validateProperty(property: ['type' => 'string', 'referenceSemanticType' => 'Organization'], path: '/vendor');
	}//end testTheSchemaWriteIsRejected()
}//end class
