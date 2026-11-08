<?php

/**
 * Characterisation tests for ContactCasesPanel::group(), ActivityFeedMerge::page()
 * and the ActivityProvider row filters: what is counted as unreadable (never
 * broken down by schema), group order and the row cap, per-kind counts, the
 * page cursor, and the combined type / actor / after filter.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Service\Integration\ActivityFeedMerge;
use OCA\OpenRegister\Service\Integration\ContactCasesPanel;
use OCA\OpenRegister\Service\Integration\Providers\ActivityProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins the grouping, merge and filter outcomes before their units are split.
 */
class ActivityFeedAndCasesGoldenTest extends TestCase {

	public function testGroupCountsUnreadableApartAndOrdersGroups(): void {
		$rows = [
			'not-an-array',
			['schema' => 'zaak', 'readable' => false],
			['schema' => '  ', 'readable' => true],
			['readable' => true],
			[
				'schema' => 'b', 'schemaLabel' => ' Beta ', 'objectUuid' => 'u1',
				'title' => ' T ', 'status' => ' s ', 'url' => '/x', 'role' => ' r ',
			],
			['schema' => ' a ', 'objectUuid' => 'u2'],
			['schema' => 'a', 'objectUuid' => 'u3', 'readable' => 1],
		];
		for ($i = 0; $i < 11; $i++) {
			$rows[] = ['schema' => 'c', 'schemaLabel' => 'alpha', 'objectUuid' => 'c' . $i];
		}

		$result = (new ContactCasesPanel())->group(rows: $rows);

		$this->assertSame(3, $result['unreadable']);
		$this->assertSame(17, $result['total']);
		$this->assertSame(['c', 'a', 'b'], array_column($result['groups'], 'schema'));
		$this->assertSame([11, 2, 1], array_column($result['groups'], 'count'));
		$this->assertCount(10, $result['groups'][0]['rows']);
		$this->assertSame('a', $result['groups'][1]['label']);
		$this->assertSame(
			['schema' => 'b', 'label' => 'Beta', 'count' => 1, 'rows' => [['objectUuid' => 'u1', 'title' => 'T', 'status' => 's', 'url' => '/x', 'role' => 'r']]],
			$result['groups'][2]
		);
	}//end testGroupCountsUnreadableApartAndOrdersGroups()

	public function testGroupTiesSortByLabelIgnoringCase(): void {
		$result = (new ContactCasesPanel())->group(
			rows: [
				['schema' => 'x', 'schemaLabel' => 'beta'],
				['schema' => 'y', 'schemaLabel' => 'Alpha'],
			]
		);
		$this->assertSame(['y', 'x'], array_column($result['groups'], 'schema'));
		$this->assertSame(['groups' => [], 'unreadable' => 0, 'total' => 0], (new ContactCasesPanel())->group(rows: []));
	}//end testGroupTiesSortByLabelIgnoringCase()

	public function testPageCountsKindsAndCursor(): void {
		$merge = new ActivityFeedMerge();
		$page  = $merge->page(
			bySource: [
				'audit' => [
					['id' => 'a1', 'timestamp' => 50, 'action' => 'read'],
					['id' => 'a2', 'timestamp' => 40, 'action' => 'update'],
					'junk',
				],
				'note' => [['id' => 'n1', 'created' => 60], ['id' => 'n2', 'date' => 30]],
				'mail' => [['id' => 'm1', 'timestamp' => 45]],
				'other' => [['id' => 'o', 'timestamp' => 99]],
			],
			options: ['pageSize' => 2]
		);

		$this->assertSame(['n1', 'm1'], array_column($page['rows'], 'id'));
		$this->assertSame(45, $page['nextCursor']);
		$this->assertSame(['audit' => 1, 'file' => 0, 'note' => 2, 'mail' => 1, 'activity' => 0], $page['counts']);

		$whole = $merge->page(bySource: ['note' => [['id' => 'n1', 'timestamp' => 1]]], options: ['pageSize' => 1]);
		$this->assertNull($whole['nextCursor']);

		$none = $merge->page(bySource: [], options: ['pageSize' => 0]);
		$this->assertSame(['rows' => [], 'nextCursor' => null, 'counts' => ['audit' => 0, 'file' => 0, 'note' => 0, 'mail' => 0, 'activity' => 0]], $none);

		$filtered = $merge->page(
			bySource: ['audit' => [['id' => 'r', 'timestamp' => 5, 'action' => 'read']], 'file' => [['id' => 'f', 'timestamp' => 6]]],
			options: ['includeReads' => true, 'kinds' => ['audit']]
		);
		$this->assertSame(['r'], array_column($filtered['rows'], 'id'));
		$this->assertSame(['audit' => 1, 'file' => 0, 'note' => 0, 'mail' => 0, 'activity' => 0], $filtered['counts']);
	}//end testPageCountsKindsAndCursor()

	public function testWindowBoundsAreInclusiveExceptTheCursor(): void {
		$sources = ['note' => [['id' => 'a', 'timestamp' => 10], ['id' => 'b', 'timestamp' => 20], ['id' => 'c', 'timestamp' => 30]]];
		$ids     = static fn (array $page): array => array_column($page['rows'], 'id');
		$merge   = new ActivityFeedMerge();

		$this->assertSame(['b', 'a'], $ids($merge->page(bySource: $sources, options: ['before' => 30])));
		$this->assertSame(['c', 'b'], $ids($merge->page(bySource: $sources, options: ['from' => 20])));
		$this->assertSame(['b', 'a'], $ids($merge->page(bySource: $sources, options: ['until' => 20])));
		$this->assertSame(['b'], $ids($merge->page(bySource: $sources, options: ['from' => 20, 'until' => 20, 'before' => 25])));
		$this->assertSame(['c', 'b', 'a'], $ids($merge->page(bySource: $sources, options: ['before' => '15', 'from' => '15', 'until' => 15.0])));
	}//end testWindowBoundsAreInclusiveExceptTheCursor()

	public function testActivityFiltersCombine(): void {
		$method = new ReflectionMethod(ActivityProvider::class, 'applyFilters');
		$provider = (new \ReflectionClass(ActivityProvider::class))->newInstanceWithoutConstructor();
		$rows = [
			['id' => 1, 'type' => 'x', 'affecteduser' => 'bob', 'timestamp' => 10],
			['id' => 2, 'type' => 'x', 'affecteduser' => 'eve', 'timestamp' => 20],
			['id' => 3, 'type' => 'y', 'affecteduser' => 'bob', 'timestamp' => 30],
			['id' => 4, 'type' => 'x', 'affecteduser' => 'bob', 'timestamp' => 40],
			['id' => 5],
		];

		$ids = static fn (array $out): array => array_column($out, 'id');
		$this->assertSame($rows, $method->invoke($provider, $rows, []));
		$this->assertSame($rows, $method->invoke($provider, $rows, ['type' => '', 'actor' => '', 'after' => 0]));
		$this->assertSame([1, 4], $ids($method->invoke($provider, $rows, ['type' => 'x', 'actor' => 'bob'])));
		$this->assertSame([4], $ids($method->invoke($provider, $rows, ['type' => 'x', 'actor' => 'bob', 'after' => '15'])));
		$this->assertSame([3, 4], $ids($method->invoke($provider, $rows, ['after' => 30])));
		$this->assertSame([1, 2, 3, 4, 5], $ids($method->invoke($provider, $rows, ['after' => -5])));
		$this->assertSame([], $method->invoke($provider, $rows, ['actor' => 'nobody']));
	}//end testActivityFiltersCombine()
}//end class
