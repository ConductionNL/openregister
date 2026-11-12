<?php

/**
 * Unit tests for the order of activity rows that share a moment and a kind.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-activity-leaf-merges-an-objects-feed-from-five-sources
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\ActivityFeedMerge;
use PHPUnit\Framework\TestCase;

/**
 * Rows tied on time and kind are ordered by id, the same way on every request, whatever order they came in.
 *
 * @covers \OCA\OpenRegister\Service\Integration\ActivityFeedMerge
 */
class ActivityFeedMergeTieTest extends TestCase {

	/**
	 * Same moment, same kind: ascending id; same moment, other kind: audit first.
	 *
	 * @return void
	 */
	public function testTiesAreBrokenByKindThenId(): void {
		$merge = new ActivityFeedMerge();
		$rows  = [
			['id' => 'n-2', 'timestamp' => 100],
			['id' => 'n-1', 'timestamp' => 100],
			['id' => 'n-3', 'timestamp' => 200],
		];

		$forward  = $merge->page(['note' => $rows, 'audit' => [['id' => 'a-9', 'timestamp' => 100]]]);
		$backward = $merge->page(['note' => array_reverse($rows), 'audit' => [['id' => 'a-9', 'timestamp' => 100]]]);

		$this->assertSame(['n-3', 'a-9', 'n-1', 'n-2'], array_column($forward['rows'], 'id'));
		$this->assertSame(array_column($forward['rows'], 'id'), array_column($backward['rows'], 'id'));

	}//end testTiesAreBrokenByKindThenId()
}//end class
