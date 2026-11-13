<?php

/**
 * Unit tests for JurisdictionPatternSets: the registry and the merge after any method.
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

use OCA\OpenRegister\Files\Service\TextExtraction\EntityDetectionQuery;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\JurisdictionPatternSets;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\NlLicensePlates;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\NlPatternSet;
use OCA\OpenRegister\Formats\BsnFormat;
use OCA\OpenRegister\Formats\IdentifierFormats;
use OCA\OpenRegister\Formats\LicensePlateNlFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JurisdictionPatternSets::class)]
#[UsesClass(EntityDetectionQuery::class)]
#[UsesClass(NlPatternSet::class)]
#[UsesClass(NlLicensePlates::class)]
#[UsesClass(LicensePlateNlFormat::class)]
#[UsesClass(BsnFormat::class)]
#[UsesClass(IdentifierFormats::class)]
final class JurisdictionPatternSetsTest extends TestCase {

	private const TEXT = 'Jan de Vries, kenteken 12-GBK-3, BSN 111222333';

	/**
	 * @return array<string, mixed>
	 */
	private static function entity(string $type, string $value, float $confidence=0.9): array {
		$start = (int) strpos(self::TEXT, $value);

		return ['type' => $type, 'value' => $value, 'category' => 'x', 'position_start' => $start, 'position_end' => $start + strlen($value), 'confidence' => $confidence];
	}

	/**
	 * @param array<int, array<string, mixed>> $entities
	 *
	 * @return array<int, string>
	 */
	private static function typesAndValues(array $entities): array {
		return array_map(static fn (array $e): string => $e['type'].':'.$e['value'], $entities);
	}

	public function testPresidioDoesNotHideAPlate(): void {
		$presidio = [self::entity('PERSON', 'Jan de Vries')];

		$merged = (new JurisdictionPatternSets())->merge($presidio, new EntityDetectionQuery(self::TEXT, null, 0.5), ['nl'], false);

		$this->assertSame(['PERSON:Jan de Vries', 'SSN:111222333', 'LICENSE_PLATE:12-GBK-3'], self::typesAndValues($merged));
	}

	public function testTheBackendWinsAnOverlap(): void {
		$backend = [self::entity('SSN', '111222333', 0.99), self::entity('PERSON', '12-GBK-3', 0.7)];

		$merged = (new JurisdictionPatternSets())->merge($backend, new EntityDetectionQuery(self::TEXT, null, 0.5), ['nl'], false);

		$this->assertSame(['SSN:111222333', 'PERSON:12-GBK-3'], self::typesAndValues($merged));
		$this->assertSame(0.99, $merged[0]['confidence'], 'the backend keeps its confidence');
	}

	public function testUnderTheRegexMethodAPhoneInsideAProvedSpanGivesWay(): void {
		$regex = [self::entity('PHONE', '111222333', 0.6), self::entity('PHONE', '12', 0.6), self::entity('EMAIL', 'Vries', 0.9)];

		$merged = (new JurisdictionPatternSets())->merge($regex, new EntityDetectionQuery(self::TEXT, null, 0.5), ['nl'], true);
		$this->assertSame(['EMAIL:Vries', 'SSN:111222333', 'LICENSE_PLATE:12-GBK-3'], self::typesAndValues($merged));

		$notRegex = (new JurisdictionPatternSets())->merge($regex, new EntityDetectionQuery(self::TEXT, null, 0.5), ['nl'], false);
		$this->assertSame(['PHONE:111222333', 'PHONE:12', 'EMAIL:Vries'], self::typesAndValues($notRegex), 'another backend keeps its spans');
	}

	public function testNoEnabledSetYieldsNothingAndAnUnknownCodeIsIgnored(): void {
		$sets = new JurisdictionPatternSets();
		$query = new EntityDetectionQuery(self::TEXT, null, 0.5);

		$this->assertSame([], $sets->merge([], $query, [], true));
		$this->assertSame([], $sets->merge([], $query, ['xx'], true));
	}

	public function testTheTypeFilterAndThresholdApply(): void {
		$sets = new JurisdictionPatternSets();

		$this->assertSame(['SSN:111222333'], self::typesAndValues($sets->merge([], new EntityDetectionQuery(self::TEXT, ['SSN'], 0.5), ['nl'], true)));
		$this->assertSame(['SSN:111222333'], self::typesAndValues($sets->merge([], new EntityDetectionQuery(self::TEXT, null, 0.81), ['nl'], true)), 'a plate at 0.8 is under 0.81');
	}

	public function testTheRegistryNamesItsCodesAndTheUnknownOnes(): void {
		$this->assertSame(['nl'], JurisdictionPatternSets::codes());
		$this->assertSame(['xx', '1'], JurisdictionPatternSets::unknown(['nl', 'xx', 1]));
		$this->assertSame([], JurisdictionPatternSets::unknown(['nl']));
	}
}
