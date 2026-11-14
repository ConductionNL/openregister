<?php

/**
 * Unit tests for DetectorVersion: every key is present, a missing value reads
 * `unknown`, and two records compare by what they describe.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
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
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use OCA\OpenRegister\Service\Anonymisation\DetectorVersion;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Anonymisation\DetectorVersion
 */
final class DetectorVersionTest extends TestCase {

	public function testEveryKeyIsPresentAndAMissingValueIsUnknown(): void {
		$this->assertSame(
			[
				'engine' => 'presidio',
				'engineVersion' => 'unknown',
				'model' => 'unknown',
				'modelVersion' => 'unknown',
				'patternSetVersion' => 'unknown',
				'datasets' => [],
				'changedAt' => 'unknown',
			],
			(new DetectorVersion(fields: ['engine' => 'presidio', 'model' => '  ', 'modelVersion' => ['1.4'], 'datasets' => 'none']))->jsonSerialize()
		);
	}

	public function testValuesAreTrimmedTextAndDatasetsNeedAName(): void {
		$record = new DetectorVersion(
			fields: [
				'engine' => ' openanonymiser ',
				'engineVersion' => 2,
				'datasets' => [['name' => 'nl-woo-synthetic', 'version' => '1'], ['name' => 'conll', 'version' => ''], ['version' => '3'], 'loose'],
			]
		);

		$this->assertSame('openanonymiser', $record->engine);
		$this->assertSame('2', $record->engineVersion);
		$this->assertSame([['name' => 'nl-woo-synthetic', 'version' => '1'], ['name' => 'conll', 'version' => 'unknown']], $record->datasets);
	}

	public function testRecordsCompareByWhatTheyDescribeNotWhenTheyChanged(): void {
		$record = new DetectorVersion(fields: ['engine' => 'presidio', 'modelVersion' => '1.4', 'changedAt' => '2026-11-01']);
		$later = $record->withChangedAt('2026-11-10T09:00:00+00:00');

		$this->assertSame('2026-11-10T09:00:00+00:00', $later->changedAt);
		$this->assertSame('2026-11-01', $record->changedAt);
		$this->assertTrue($record->sameDetectorAs($later));
		$this->assertFalse($record->sameDetectorAs(new DetectorVersion(fields: ['engine' => 'presidio', 'modelVersion' => '1.5'])));
	}
}
