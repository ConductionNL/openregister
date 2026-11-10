<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Integration\PropertyReferenceTypeValidator}
 * and {@see \OCA\OpenRegister\Service\Integration\PropertySemanticReferenceValidator}.
 *
 * Pins, for every branch of `validate()` and `validateAll()`, whether each
 * validator accepts the input or the exact exception class and message it
 * throws. Written against the code before its refactor and left unchanged
 * by it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\PropertyReferenceTypeValidator;
use OCA\OpenRegister\Service\Integration\PropertySemanticReferenceValidator;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @no-named-arguments
 */
class PropertyReferenceValidatorsGoldenTest extends TestCase {

	/**
	 * Run a callable and describe its outcome.
	 *
	 * @param callable $call The call.
	 *
	 * @return string `ok`, or the exception class and message.
	 */
	private static function outcome(callable $call): string {
		try {
			$call();
			return 'ok';
		} catch (Throwable $e) {
			return get_class($e).': '.$e->getMessage();
		}
	}//end outcome()

	/**
	 * @param array<int, string> $ids The registered ids.
	 *
	 * @return PropertyReferenceTypeValidator
	 */
	private function typeValidator(array $ids): PropertyReferenceTypeValidator {
		$registry = $this->createMock(IntegrationRegistry::class);
		$registry->method('isValidIntegrationId')->willReturnCallback(static fn (string $id): bool => in_array($id, $ids, true));
		$registry->method('listIds')->willReturn($ids);

		return new PropertyReferenceTypeValidator($registry);
	}//end typeValidator()

	/**
	 * @return void
	 */
	public function testReferenceTypeOutcomes(): void {
		$validator = $this->typeValidator(['xwiki', 'contacts', 'agenda']);
		$empty = $this->typeValidator([]);
		$actual = [
			self::outcome(fn () => $validator->validate(['type' => 'string'])),
			self::outcome(fn () => $validator->validate(['referenceType' => null], 'p')),
			self::outcome(fn () => $validator->validate(['referenceType' => 42], 'p')),
			self::outcome(fn () => $validator->validate(['referenceType' => 42])),
			self::outcome(fn () => $validator->validate(['referenceType' => ''], 'p')),
			self::outcome(fn () => $validator->validate(['referenceType' => ''])),
			self::outcome(fn () => $validator->validate(['referenceType' => 'xwiki'], 'p')),
			self::outcome(fn () => $validator->validate(['referenceType' => 'nope'], 'p')),
			self::outcome(fn () => $validator->validate(['referenceType' => 'nope'])),
			self::outcome(fn () => $empty->validate(['referenceType' => 'nope'], 'p')),
			self::outcome(fn () => $validator->validateAll([])),
			self::outcome(fn () => $validator->validateAll(['a' => ['referenceType' => 'xwiki'], 'b' => 'skip', 'c' => ['referenceType' => 'bad'], 'd' => ['referenceType' => 1]])),
			self::outcome(fn () => $validator->validateAll([0 => ['referenceType' => 'bad']])),
			self::outcome(fn () => $validator->validateAll(['a' => ['referenceType' => 'contacts'], 'b' => ['type' => 'x']])),
		];

		$this->assertSame(
			[
				'ok',
				'ok',
				"InvalidArgumentException: Property 'p' referenceType must be a string or null",
				'InvalidArgumentException: referenceType must be a string or null',
				"InvalidArgumentException: Property 'p' referenceType must not be empty",
				'InvalidArgumentException: referenceType must not be empty',
				'ok',
				"InvalidArgumentException: Property 'p' referenceType refers to unregistered integration 'nope'. Registered ids: agenda, contacts, xwiki",
				"InvalidArgumentException: referenceType refers to unregistered integration 'nope'. Registered ids: agenda, contacts, xwiki",
				"InvalidArgumentException: Property 'p' referenceType refers to unregistered integration 'nope'. Registered ids: (none)",
				'ok',
				"InvalidArgumentException: Property 'c' referenceType refers to unregistered integration 'bad'. Registered ids: agenda, contacts, xwiki",
				"InvalidArgumentException: referenceType refers to unregistered integration 'bad'. Registered ids: agenda, contacts, xwiki",
				'ok',
			],
			$actual
		);
	}//end testReferenceTypeOutcomes()

	/**
	 * @return void
	 */
	public function testSemanticReferenceOutcomes(): void {
		$validator = new PropertySemanticReferenceValidator(new JsonLdContextService($this->createMock(IURLGenerator::class)));
		$iri = 'https://schema.org/Person';
		$actual = [
			self::outcome(fn () => $validator->validate(['type' => 'string'])),
			self::outcome(fn () => $validator->validate(['referenceSemanticType' => null, 'referenceSemanticApp' => 5], 'p')),
			self::outcome(fn () => $validator->validate(['referenceSemanticType' => 5], 'p')),
			self::outcome(fn () => $validator->validate(['referenceSemanticType' => ''])),
			self::outcome(fn () => $validator->validate(['referenceSemanticType' => 'Person'], 'p')),
			self::outcome(fn () => $validator->validate(['referenceSemanticType' => '1nvalid:x'])),
			self::outcome(fn () => $validator->validate(['referenceSemanticType' => $iri], 'p')),
			self::outcome(fn () => $validator->validate(['referenceSemanticType' => 'urn:isbn:123', 'referenceSemanticApp' => null])),
			self::outcome(fn () => $validator->validate(['referenceSemanticType' => $iri, 'referenceSemanticApp' => 'contacts'], 'p')),
			self::outcome(fn () => $validator->validate(['referenceSemanticType' => $iri, 'referenceSemanticApp' => 7], 'p')),
			self::outcome(fn () => $validator->validate(['referenceSemanticType' => $iri, 'referenceSemanticApp' => ['x']])),
			self::outcome(fn () => $validator->validateAll(['a' => ['referenceSemanticType' => $iri], 'b' => 3, 'c' => ['referenceSemanticType' => 'bad']])),
			self::outcome(fn () => $validator->validateAll([4 => ['referenceSemanticType' => '']])),
			self::outcome(fn () => $validator->validateAll(['a' => ['referenceSemanticType' => $iri, 'referenceSemanticApp' => 'x']])),
		];

		$this->assertSame(
			[
				'ok',
				'ok',
				"InvalidArgumentException: Property 'p' referenceSemanticType must be a non-empty string",
				'InvalidArgumentException: referenceSemanticType must be a non-empty string',
				"InvalidArgumentException: Property 'p' referenceSemanticType must be an absolute IRI, got 'Person'",
				"InvalidArgumentException: referenceSemanticType must be an absolute IRI, got '1nvalid:x'",
				'ok',
				'ok',
				'ok',
				"InvalidArgumentException: Property 'p' referenceSemanticApp must be a string when present",
				'InvalidArgumentException: referenceSemanticApp must be a string when present',
				"InvalidArgumentException: Property 'c' referenceSemanticType must be an absolute IRI, got 'bad'",
				'InvalidArgumentException: referenceSemanticType must be a non-empty string',
				'ok',
			],
			$actual
		);
	}//end testSemanticReferenceOutcomes()
}//end class
