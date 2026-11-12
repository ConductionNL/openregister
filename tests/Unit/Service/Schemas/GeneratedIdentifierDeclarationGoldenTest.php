<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Schemas;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case reads one property; a declaration that reads is rendered and
 * parsed at fixed moments. Outcomes are compared with
 * fixtures/generated-identifier-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 */
class GeneratedIdentifierDeclarationGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/generated-identifier-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$k = GeneratedIdentifierDeclaration::ANNOTATION;
		$on = static fn (mixed $annotation, mixed $type = 'string'): array => ['type' => $type, $k => $annotation];
		return [
			'no annotation' => [['type' => 'string']],
			'null annotation' => [$on(null)],
			'scalar annotation' => [$on('Z-{seq}')],
			'list annotation' => [$on([])],
			'integer property' => [$on(['sequence' => 's', 'format' => '{seq}'], 'integer')],
			'untyped property' => [['x' => 1, $k => ['sequence' => 's', 'format' => '{seq}']]],
			'union property' => [$on(['sequence' => 's', 'format' => '{seq}'], ['string', 'null'])],
			'unknown keys' => [$on(['sequence' => 's', 'format' => '{seq}', 'reset' => 'year', 0 => 'x'])],
			'missing sequence' => [$on(['format' => '{seq}'])],
			'blank sequence' => [$on(['sequence' => '  ', 'format' => '{seq}'])],
			'number sequence' => [$on(['sequence' => 5, 'format' => '{seq}'])],
			'missing format' => [$on(['sequence' => 's'])],
			'bad reset' => [$on(['sequence' => 's', 'format' => '{seq}', 'resetOn' => 'month'])],
			'null reset' => [$on(['sequence' => 's', 'format' => '{seq}', 'resetOn' => null])],
			'unknown placeholder' => [$on(['sequence' => 's', 'format' => '{seq}-{day}'])],
			'empty placeholder' => [$on(['sequence' => 's', 'format' => '{}{seq}'])],
			'no seq' => [$on(['sequence' => 's', 'format' => 'Z-{year}'])],
			'year reset without year' => [$on(['sequence' => 's', 'format' => 'Z-{seq}', 'resetOn' => 'year'])],
			'plain seq' => [$on(['sequence' => 'zaak', 'format' => '{seq}'])],
			'padded yearly' => [$on(['sequence' => 'zaak', 'format' => 'Z-{year}-{seq:5}', 'resetOn' => 'year'])],
			'month and padded' => [$on(['sequence' => 'm', 'format' => '{year}/{month}/{seq:3}'])],
			'seq before year' => [$on(['sequence' => 'm', 'format' => '{seq:2}.{year}', 'resetOn' => 'year'])],
			'regex literals' => [$on(['sequence' => 'r', 'format' => 'A.(+)*{seq}?/'])],
			'two seqs' => [$on(['sequence' => 'r', 'format' => '{seq}-{seq:4}'])],
			'seq zero pad' => [$on(['sequence' => 'r', 'format' => 'N{seq:0}'])],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $property
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(array $property): void {
		try {
			$declaration = GeneratedIdentifierDeclaration::fromProperty($property, '/p');
		} catch (PropertyVocabularyException $e) {
			$this->assertGolden(['threw' => get_class($e), 'message' => $e->getMessage(), 'errors' => $e->getErrors()]);
			return;
		}

		if ($declaration === null) {
			$this->assertGolden(['result' => null]);
			return;
		}

		$moments = ['jan' => new DateTimeImmutable('2026-01-05T10:00:00+01:00'), 'dec' => new DateTimeImmutable('2025-12-31T23:59:00+01:00')];
		$rendered = [];
		$periods = [];
		foreach ($moments as $label => $moment) {
			$periods[$label] = $declaration->periodAt($moment);
			foreach ([0, 1, 42, 123456] as $number) {
				$value = $declaration->render($number, $moment);
				$rendered[$label . ' ' . $number] = ['value' => $value, 'parsed' => $declaration->parse($value)];
			}
		}

		$foreign = [];
		foreach (['', 'x', 'Z-2026-1', '7', 'Z-26-00001', '2026/01/1', 'A.(+)*12?/', '1-0002', 'N5', '05.2026'] as $value) {
			$foreign[$value] = $declaration->parse($value);
		}

		$this->assertGolden(
			[
				'sequence' => $declaration->sequence(),
				'format' => $declaration->format(),
				'periods' => $periods,
				'rendered' => $rendered,
				'foreign' => $foreign,
			]
		);
	}//end testMatchesGolden()

	/**
	 * @param array<string, mixed> $actual
	 */
	private function assertGolden(array $actual): void {
		$actual = json_decode(json_encode($actual), true);
		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end assertGolden()
}//end class
