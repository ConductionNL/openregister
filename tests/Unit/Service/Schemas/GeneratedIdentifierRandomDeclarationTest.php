<?php

/**
 * GeneratedIdentifierDeclaration: a random kind with a length and an alphabet.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-sequence-may-be-random-rather-than-counted-req-gid-004
 */

declare(strict_types=1);

namespace Unit\Service\Schemas;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- the annotation fixtures read as plain arrays.

use DateTimeImmutable;
use LogicException;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierException;
use OCA\OpenRegister\Service\Schemas\RandomSequenceCode;
use PHPUnit\Framework\TestCase;

/**
 * REQ-GID-004: a sequence declared random renders `{seq}` as a code.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration
 * @uses   \OCA\OpenRegister\Service\Schemas\RandomSequenceCode
 * @uses   \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierException
 */
final class GeneratedIdentifierRandomDeclarationTest extends TestCase {

	/**
	 * A declaration with this annotation on a string property.
	 *
	 * @param array<string, mixed> $annotation The annotation.
	 *
	 * @return GeneratedIdentifierDeclaration
	 */
	private function declare(array $annotation): GeneratedIdentifierDeclaration {
		$declaration = GeneratedIdentifierDeclaration::fromProperty(
			['type' => 'string', 'x-openregister-generated' => $annotation],
			'identifier'
		);
		$this->assertNotNull(actual: $declaration);
		return $declaration;
	}//end declare()

	/**
	 * A random sequence renders `{seq}` as the code of the number, around the rest of the format.
	 *
	 * @return void
	 */
	public function testARandomSequenceRendersTheCode(): void {
		$declaration = $this->declare(['sequence' => 'case', 'format' => 'Z-{seq}', 'kind' => 'random', 'length' => 10]);
		$moment = new DateTimeImmutable('2026-11-13');
		$expected = (new RandomSequenceCode(alphabet: GeneratedIdentifierDeclaration::DEFAULT_ALPHABET, length: 10, key: 'key'))->encode(number: 7);

		$this->assertTrue(condition: $declaration->isRandom());
		$this->assertSame(expected: 'Z-'.$expected, actual: $declaration->render(sequenceValue: 7, moment: $moment, randomKey: 'key'));
		$next = $declaration->render(sequenceValue: 8, moment: $moment, randomKey: 'key');
		$this->assertMatchesRegularExpression(pattern: '/^Z-[0-9A-HJ-NP-Z]{10}$/', string: $next);
	}//end testARandomSequenceRendersTheCode()

	/**
	 * Three numbers give three different codes, none following from the others.
	 *
	 * @return void
	 */
	public function testThreeCreatesGetThreeUnrelatedCodes(): void {
		$declaration = $this->declare(['sequence' => 'case', 'format' => '{seq}', 'kind' => 'random', 'length' => 10, 'alphabet' => '0123456789']);
		$moment = new DateTimeImmutable('2026-11-13');
		$codes = array_map(static fn (int $n): string => $declaration->render(sequenceValue: $n, moment: $moment, randomKey: 'key'), [1, 2, 3]);

		$this->assertCount(expectedCount: 3, haystack: array_unique($codes));
		$this->assertNotSame(expected: ['0000000001', '0000000002', '0000000003'], actual: $codes);
	}//end testThreeCreatesGetThreeUnrelatedCodes()

	/**
	 * A random sequence is never rendered without its key.
	 *
	 * @return void
	 */
	public function testARandomSequenceNeedsTheKey(): void {
		$declaration = $this->declare(['sequence' => 'case', 'format' => '{seq}', 'kind' => 'random', 'length' => 6]);

		$this->expectException(exception: LogicException::class);
		$declaration->render(sequenceValue: 1, moment: new DateTimeImmutable());
	}//end testARandomSequenceNeedsTheKey()

	/**
	 * A random value does not read back, so an import advances nothing.
	 *
	 * @return void
	 */
	public function testARandomValueDoesNotParse(): void {
		$declaration = $this->declare(['sequence' => 'case', 'format' => 'Z-{seq}', 'kind' => 'random', 'length' => 4, 'alphabet' => '0123456789']);

		$this->assertNull(actual: $declaration->parse(value: 'Z-0042'));
	}//end testARandomValueDoesNotParse()

	/**
	 * A counter stays a counter: the default kind renders the padded number and parses back.
	 *
	 * @return void
	 */
	public function testTheCounterKindIsUnchanged(): void {
		$declaration = $this->declare(['sequence' => 'case', 'format' => 'Z-{seq:4}', 'kind' => 'counter']);

		$this->assertFalse(condition: $declaration->isRandom());
		$this->assertSame(expected: 'Z-0042', actual: $declaration->render(sequenceValue: 42, moment: new DateTimeImmutable()));
		$this->assertSame(expected: ['sequence' => 42, 'period' => ''], actual: $declaration->parse(value: 'Z-0042'));
	}//end testTheCounterKindIsUnchanged()

	/**
	 * Annotations a random kind refuses.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function refused(): array {
		return [
			'unknown kind'     => [['sequence' => 'case', 'format' => '{seq}', 'kind' => 'shuffled', 'length' => 6]],
			'no length'        => [['sequence' => 'case', 'format' => '{seq}', 'kind' => 'random']],
			'text length'      => [['sequence' => 'case', 'format' => '{seq}', 'kind' => 'random', 'length' => '6']],
			'zero length'      => [['sequence' => 'case', 'format' => '{seq}', 'kind' => 'random', 'length' => 0]],
			'one-char alphabet' => [['sequence' => 'case', 'format' => '{seq}', 'kind' => 'random', 'length' => 6, 'alphabet' => 'A']],
			'repeated alphabet' => [['sequence' => 'case', 'format' => '{seq}', 'kind' => 'random', 'length' => 6, 'alphabet' => 'ABA']],
			'space too large'  => [['sequence' => 'case', 'format' => '{seq}', 'kind' => 'random', 'length' => 40]],
		];
	}//end refused()

	/**
	 * @param array<string, mixed> $annotation The annotation.
	 *
	 * @return void
	 * @dataProvider refused
	 */
	public function testAnUnusableRandomKindIsRefused(array $annotation): void {
		$this->expectException(exception: GeneratedIdentifierException::class);
		GeneratedIdentifierDeclaration::fromProperty(['type' => 'string', 'x-openregister-generated' => $annotation], 'identifier');
	}//end testAnUnusableRandomKindIsRefused()
}//end class
