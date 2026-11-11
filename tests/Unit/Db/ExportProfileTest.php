<?php

/**
 * ExportProfileTest: how a saved export profile reads its stored fields, filters and retention.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ExportProfile;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ExportProfile
 */
class ExportProfileTest extends TestCase {

	public function testFieldsAreEmptyWhenUnsetBlankOrNotAJsonArray(): void {
		$profile = new ExportProfile();
		$this->assertSame([], $profile->getFieldsArray());

		$profile->setFields('');
		$this->assertSame([], $profile->getFieldsArray());

		$profile->setFields('"title"');
		$this->assertSame([], $profile->getFieldsArray());
	}//end testFieldsAreEmptyWhenUnsetBlankOrNotAJsonArray()

	public function testFieldsKeepOnlyStringEntriesReindexed(): void {
		$profile = new ExportProfile();
		$profile->setFields('["title", 3, null, "status", {"x":1}]');

		$this->assertSame(['title', 'status'], $profile->getFieldsArray());
	}//end testFieldsKeepOnlyStringEntriesReindexed()

	public function testFiltersAreEmptyWhenUnsetBlankOrNotAJsonArray(): void {
		$profile = new ExportProfile();
		$this->assertSame([], $profile->getFiltersArray());

		$profile->setFilters('');
		$this->assertSame([], $profile->getFiltersArray());

		$profile->setFilters('{oops');
		$this->assertSame([], $profile->getFiltersArray());
	}//end testFiltersAreEmptyWhenUnsetBlankOrNotAJsonArray()

	public function testFiltersAreDecodedAsStored(): void {
		$profile = new ExportProfile();
		$profile->setFilters('{"status":"open","year":[2025,2026]}');

		$this->assertSame(['status' => 'open', 'year' => [2025, 2026]], $profile->getFiltersArray());
	}//end testFiltersAreDecodedAsStored()

	public function testWholeSetNeedsTheFlagAndNoFilters(): void {
		$profile = new ExportProfile();
		$this->assertFalse($profile->isWholeSet());

		$profile->setWholeSet(true);
		$this->assertTrue($profile->isWholeSet());

		$profile->setFilters('{"status":"open"}');
		$this->assertFalse($profile->isWholeSet());
	}//end testWholeSetNeedsTheFlagAndNoFilters()

	public function testRetentionIsCountedInSecondsOrAbsent(): void {
		$profile = new ExportProfile();
		$this->assertNull($profile->getRetentionSeconds());

		$profile->setRetentionDays(2);
		$this->assertSame(172800, $profile->getRetentionSeconds());
	}//end testRetentionIsCountedInSecondsOrAbsent()

	public function testJsonSerializeFillsDefaultsForAnEmptyProfile(): void {
		$this->assertSame(
			[
				'id' => null,
				'uuid' => null,
				'owner' => null,
				'name' => null,
				'description' => null,
				'registerId' => null,
				'schemaId' => null,
				'fields' => [],
				'valueMode' => 'stored',
				'format' => 'csv',
				'filters' => [],
				'wholeSet' => false,
				'retentionDays' => null,
				'createdAt' => null,
				'updatedAt' => null,
			],
			(new ExportProfile())->jsonSerialize()
		);
	}//end testJsonSerializeFillsDefaultsForAnEmptyProfile()

	public function testJsonSerializeDecodesStoredColumnsAndFormatsTimesAsAtom(): void {
		$utc = new DateTimeZone('UTC');
		$profile = new ExportProfile();
		$profile->setId(4);
		$profile->setUuid('e4');
		$profile->setOwner('ann');
		$profile->setName('Open cases');
		$profile->setDescription('Weekly');
		$profile->setRegisterId(1);
		$profile->setSchemaId(2);
		$profile->setFields('["title"]');
		$profile->setValueMode('rendered');
		$profile->setFormat('json');
		$profile->setFilters('{"status":"open"}');
		$profile->setWholeSet(true);
		$profile->setRetentionDays(30);
		$profile->setCreatedAt(new DateTime('2026-01-02 03:04:05', $utc));
		$profile->setUpdatedAt(new DateTime('2026-02-03 04:05:06', $utc));

		$this->assertSame(
			[
				'id' => 4,
				'uuid' => 'e4',
				'owner' => 'ann',
				'name' => 'Open cases',
				'description' => 'Weekly',
				'registerId' => 1,
				'schemaId' => 2,
				'fields' => ['title'],
				'valueMode' => 'rendered',
				'format' => 'json',
				'filters' => ['status' => 'open'],
				'wholeSet' => true,
				'retentionDays' => 30,
				'createdAt' => '2026-01-02T03:04:05+00:00',
				'updatedAt' => '2026-02-03T04:05:06+00:00',
			],
			$profile->jsonSerialize()
		);
	}//end testJsonSerializeDecodesStoredColumnsAndFormatsTimesAsAtom()
}//end class
