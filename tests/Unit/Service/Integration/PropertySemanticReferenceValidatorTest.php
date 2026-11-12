<?php

/**
 * A property's referenceSemanticType must be a non-empty absolute IRI and its
 * referenceSemanticApp, when present, a string; the refusal names the
 * property it is about.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use InvalidArgumentException;
use OCA\OpenRegister\Service\Integration\PropertySemanticReferenceValidator;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * PropertySemanticReferenceValidator.
 *
 * @covers \OCA\OpenRegister\Service\Integration\PropertySemanticReferenceValidator
 * @uses \OCA\OpenRegister\Service\JsonLd\JsonLdContextService
 */
class PropertySemanticReferenceValidatorTest extends TestCase {

	private PropertySemanticReferenceValidator $validator;

	protected function setUp(): void {
		$this->validator = new PropertySemanticReferenceValidator(new JsonLdContextService($this->createMock(IURLGenerator::class)));
	}//end setUp()

	private function refusal(array $property, ?string $name = null): string {
		try {
			$this->validator->validate($property, $name);
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
		}

		$this->fail('Expected the property to be refused');
	}//end refusal()

	public function testAPropertyWithoutASemanticTypeIsNotChecked(): void {
		$this->validator->validate(['type' => 'string', 'referenceSemanticApp' => 42]);
		$this->addToAssertionCount(1);
	}//end testAPropertyWithoutASemanticTypeIsNotChecked()

	public function testAnAbsoluteIriWithAStringAppHintIsAccepted(): void {
		$this->validator->validate(['referenceSemanticType' => 'https://schema.org/Person', 'referenceSemanticApp' => 'contacts'], 'owner');
		$this->validator->validate(['referenceSemanticType' => 'urn:nl:bsn']);
		$this->addToAssertionCount(2);
	}//end testAnAbsoluteIriWithAStringAppHintIsAccepted()

	public function testAnEmptyOrNonStringTypeIsRefused(): void {
		$this->assertSame('referenceSemanticType must be a non-empty string', $this->refusal(['referenceSemanticType' => '']));
		$this->assertSame('referenceSemanticType must be a non-empty string', $this->refusal(['referenceSemanticType' => ['x']]));
	}//end testAnEmptyOrNonStringTypeIsRefused()

	public function testARelativeTypeIsRefusedQuotingTheValue(): void {
		$this->assertSame(
			"Property 'owner' referenceSemanticType must be an absolute IRI, got 'Person'",
			$this->refusal(['referenceSemanticType' => 'Person'], 'owner')
		);
	}//end testARelativeTypeIsRefusedQuotingTheValue()

	public function testANonStringAppHintIsRefused(): void {
		$this->assertSame(
			"Property 'owner' referenceSemanticApp must be a string when present",
			$this->refusal(['referenceSemanticType' => 'https://schema.org/Person', 'referenceSemanticApp' => 7], 'owner')
		);
	}//end testANonStringAppHintIsRefused()

	public function testValidateAllNamesStringKeyedPropertiesAndSkipsNonArrays(): void {
		try {
			$this->validator->validateAll(['title' => 'not-a-definition', 'owner' => ['referenceSemanticType' => 'Person']]);
			$this->fail('Expected the owner property to be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame("Property 'owner' referenceSemanticType must be an absolute IRI, got 'Person'", $e->getMessage());
		}
	}//end testValidateAllNamesStringKeyedPropertiesAndSkipsNonArrays()

	public function testValidateAllLeavesAListedPropertyUnnamed(): void {
		try {
			$this->validator->validateAll([['referenceSemanticType' => 'Person']]);
			$this->fail('Expected the property to be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame("referenceSemanticType must be an absolute IRI, got 'Person'", $e->getMessage());
		}
	}//end testValidateAllLeavesAListedPropertyUnnamed()
}//end class
