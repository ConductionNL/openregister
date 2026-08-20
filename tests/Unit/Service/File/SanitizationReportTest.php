<?php

/**
 * SanitizationReportTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\File\SanitizationReport}
 * covering the ordered jsonSerialize() key set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File;

use OCA\OpenRegister\Service\File\SanitizationReport;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see SanitizationReport}.
 */
class SanitizationReportTest extends TestCase {

	/**
	 * jsonSerialize() returns all keys in the documented order with values.
	 *
	 * @return void
	 */
	public function testJsonSerializeKeyOrderAndValues(): void {
		$report = new SanitizationReport(
			commentsRemoved: 3,
			trackedChangesAccepted: 2,
			trackedChangesDropped: 1,
			revisionAttributesStripped: 7,
			hyperlinksFlattened: 4,
			metadataFieldsScrubbed: 5,
			customXmlPartsDropped: 1,
			fieldCodesStripped: 2,
			sentinelApplied: 'DocuDesk Anonymisation'
		);

		$json = $report->jsonSerialize();

		$expectedKeys = [
			'commentsRemoved',
			'trackedChangesAccepted',
			'trackedChangesDropped',
			'revisionAttributesStripped',
			'hyperlinksFlattened',
			'metadataFieldsScrubbed',
			'customXmlPartsDropped',
			'fieldCodesStripped',
			'sentinelApplied',
		];

		$this->assertSame($expectedKeys, array_keys($json));
		$this->assertSame(3, $json['commentsRemoved']);
		$this->assertSame(2, $json['trackedChangesAccepted']);
		$this->assertSame(1, $json['trackedChangesDropped']);
		$this->assertSame(7, $json['revisionAttributesStripped']);
		$this->assertSame(4, $json['hyperlinksFlattened']);
		$this->assertSame(5, $json['metadataFieldsScrubbed']);
		$this->assertSame(1, $json['customXmlPartsDropped']);
		$this->assertSame(2, $json['fieldCodesStripped']);
		$this->assertSame('DocuDesk Anonymisation', $json['sentinelApplied']);
	}//end testJsonSerializeKeyOrderAndValues()

	/**
	 * Defaults are all-zero with an empty sentinel.
	 *
	 * @return void
	 */
	public function testDefaults(): void {
		$report = new SanitizationReport();
		$json = $report->jsonSerialize();

		foreach (['commentsRemoved', 'trackedChangesAccepted', 'trackedChangesDropped', 'revisionAttributesStripped', 'hyperlinksFlattened', 'metadataFieldsScrubbed', 'customXmlPartsDropped', 'fieldCodesStripped'] as $key) {
			$this->assertSame(0, $json[$key]);
		}

		$this->assertSame('', $json['sentinelApplied']);
	}//end testDefaults()
}//end class
