<?php

/**
 * Unit tests for the CSV export of an activity feed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\ActivityFeedExport;
use PHPUnit\Framework\TestCase;

/**
 * A row that is no array is skipped; an undated row exports an empty moment.
 *
 * @covers \OCA\OpenRegister\Service\Integration\ActivityFeedExport
 */
class ActivityFeedExportRowsTest extends TestCase {

	/**
	 * Only array rows are exported, in order, under the header.
	 *
	 * @return void
	 */
	public function testANonArrayRowIsSkipped(): void {
		$csv = (new ActivityFeedExport())->toCsv(
			[
				['timestamp' => 0, 'kind' => 'audit', 'actor' => 'alice', 'action' => 'update', 'summary' => 'status', 'url' => ''],
				'garbage',
				['kind' => 'activity', 'actor' => 'bob', 'action' => 'comment'],
			],
			'UTC'
		);

		$lines = array_values(array_filter(explode("\n", $csv), static fn (string $l): bool => $l !== ''));
		$rows  = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', '\\'), $lines);

		$this->assertSame(ActivityFeedExport::COLUMNS, $rows[0]);
		$this->assertCount(3, $rows, 'header and two rows');
		$this->assertSame(['', 'audit', 'alice', 'update', 'status', ''], $rows[1]);
		$this->assertSame('bob', $rows[2][2]);

	}//end testANonArrayRowIsSkipped()
}//end class
