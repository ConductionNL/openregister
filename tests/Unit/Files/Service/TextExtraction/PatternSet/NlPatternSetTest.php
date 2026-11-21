<?php

/**
 * Unit tests for the Dutch pattern set: BSNs by the elfproef, licence plates by sidecode, and the guard.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction\PatternSet
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction\PatternSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\NlLicensePlates;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\NlPatternSet;
use OCA\OpenRegister\Formats\BsnFormat;
use OCA\OpenRegister\Formats\IdentifierFormats;
use OCA\OpenRegister\Formats\LicensePlateNlFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NlPatternSet::class)]
#[CoversClass(NlLicensePlates::class)]
#[UsesClass(LicensePlateNlFormat::class)]
#[UsesClass(BsnFormat::class)]
#[UsesClass(IdentifierFormats::class)]
final class NlPatternSetTest extends TestCase {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function plates(string $text): array {
		return array_values(array_filter(
			(new NlPatternSet())->detect($text),
			static fn (array $entity): bool => $entity['type'] === EntityRecognitionHandler::ENTITY_TYPE_LICENSE_PLATE
		));
	}

	public function testEverySidecodeIsRecognisedWithTheSidecodeItMatched(): void {
		$text = 'AB-12-34, 12-34-AB, 12-AB-34, AB-12-DE, AB-DE-12, 12-AB-DE, 12-GBK-3, 1-GBK-23, '
			.'GB-123-K, G-123-BK, GBK-12-D, G-12-BKD, 1-GB-234 en 123-GB-4.';

		$plates = $this->plates($text);

		$this->assertCount(14, $plates);
		$this->assertSame(range(1, 14), array_column($plates, 'sidecode'));
		foreach ($plates as $plate) {
			$this->assertSame(EntityRecognitionHandler::CATEGORY_PERSONAL_DATA, $plate['category']);
			$this->assertSame(NlLicensePlates::HYPHENATED_CONFIDENCE, $plate['confidence']);
			$this->assertSame($plate['value'], substr($text, $plate['position_start'], $plate['position_end'] - $plate['position_start']));
		}
	}

	public function testThePlateInTheScenarioIsFoundAtItsPosition(): void {
		$text = 'Het voertuig met kenteken 12-GBK-3 stond geparkeerd';

		$plates = $this->plates($text);

		$this->assertCount(1, $plates);
		$this->assertSame('12-GBK-3', $plates[0]['value']);
		$this->assertSame(strpos($text, '12-GBK-3'), $plates[0]['position_start']);
	}

	public function testABsnIsFoundOnlyWhenItPassesTheElfproef(): void {
		$entities = (new NlPatternSet())->detect('BSN 111222333 en nummer 123456789');

		$this->assertSame([EntityRecognitionHandler::ENTITY_TYPE_SSN], array_column($entities, 'type'));
		$this->assertSame('111222333', $entities[0]['value']);
	}

	public function testPlateShapedNonPlatesYieldNothing(): void {
		$nonPlates = [
			'zaak ZK-12-AB-34',
			'datum 2024-12-AB',
			'postcode 1234 AB',
			'artikel 12GBK3',
			'versie 1-2-3',
			'datum 12-11-2026',
			'telefoon 06-123-45',
			'product AC-12-34',
			'bestelling 12-GAK-3',
			'code G-12-BKE',
			'referentie AB-12-34X',
			'kenmerk 2026-AB-12',
			'ISBN 978-90-12',
		];

		foreach ($nonPlates as $text) {
			$this->assertSame([], $this->plates($text), $text);
		}

		$this->assertSame([], $this->plates('ZK-AB-12-34'), 'a plate joined to more groups by a hyphen');
	}

	public function testAnUnhyphenatedPlateCountsOnlyWithAContextWordBeforeIt(): void {
		$plates = $this->plates('kenteken 12GBK3');

		$this->assertCount(1, $plates);
		$this->assertSame('12GBK3', $plates[0]['value']);
		$this->assertSame(NlLicensePlates::COMPACT_CONFIDENCE, $plates[0]['confidence']);
		$this->assertSame(7, $plates[0]['sidecode']);

		$this->assertCount(1, $this->plates('Licence plate: 12GBK3'));
		$this->assertSame([], $this->plates('12GBK3 kenteken'), 'the word must come before');
		$this->assertSame([], $this->plates('kenteken'.str_repeat(' ', 31).'12GBK3'), 'more than 30 characters before');
	}

	public function testASpanClaimedByABsnIsNotAlsoAPlate(): void {
		$entities = (new NlPatternSet())->detect('kenteken 111222333');

		$this->assertSame([EntityRecognitionHandler::ENTITY_TYPE_SSN], array_column($entities, 'type'));
	}
}
