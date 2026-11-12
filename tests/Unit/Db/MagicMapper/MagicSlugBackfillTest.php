<?php

/**
 * MagicSlugBackfillTest: one page of the slug backfill as the planner reads it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicSlugBackfill;
use OCA\OpenRegister\Db\MagicMapper\MagicSlugTable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSlugBackfill
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSlugTable
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class MagicSlugBackfillTest extends TestCase {
	use RecordsQueries;

	/**
	 * Warnings logged.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Rows given to the converter.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $converted = [];

	/**
	 * A backfill whose converter refuses the uuid `bad` and keeps `title` as the object.
	 *
	 * @return MagicSlugBackfill
	 */
	private function backfill(): MagicSlugBackfill {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->warnings[] = [(string) $message, $context];
			}
		);
		$convert = function (array $row): ?ObjectEntity {
			$this->converted[] = $row;
			if ($row['_uuid'] === 'bad') {
				return null;
			}

			$entity = new ObjectEntity();
			if (array_key_exists('title', $row) === true) {
				$entity->setObject(['title' => $row['title']]);
			}

			return $entity;
		};

		return new MagicSlugBackfill(new MagicSlugTable($this->recordingConnection(), 'openregister_table_1_2'), $convert, $logger);
	}//end backfill()

	public function testAnEmptyPageKeepsTheCursorWhereItWas(): void {
		$this->rowSets = [[]];

		$this->assertSame(['rows' => [], 'fetched' => 0, 'lastId' => 12], $this->backfill()->page(12, 50));
		$this->assertContains('where(gt(t._id, 12))', $this->queries[0]);
		$this->assertContains('setMaxResults(50)', $this->queries[0]);
	}//end testAnEmptyPageKeepsTheCursorWhereItWas()

	public function testRowsBecomePlannerEntriesWithEmptySlugsAsNull(): void {
		$this->rowSets = [[
			['_id' => '4', '_uuid' => 'u4', '_slug' => null, '_created' => '2026-01-02 00:00:00', 'title' => 'Vier'],
			['_id' => 9, '_uuid' => 'u9', '_slug' => 'set', 'title' => 'Negen'],
			['_id' => 11, '_uuid' => 'u11', '_slug' => '', '_created' => null],
		]];

		$page = $this->backfill()->page(3, 50);

		$this->assertSame(3, $page['fetched']);
		$this->assertSame(11, $page['lastId']);
		$objects = array_column($page['rows'], 'object');
		$this->assertSame(
			[
				['id' => 4, 'uuid' => 'u4', 'slug' => null, 'created' => '2026-01-02 00:00:00'],
				['id' => 9, 'uuid' => 'u9', 'slug' => 'set', 'created' => null],
				['id' => 11, 'uuid' => 'u11', 'slug' => null, 'created' => null],
			],
			array_map(static fn (array $row): array => array_diff_key($row, ['object' => true]), $page['rows'])
		);
		$this->assertSame(['Vier', 'Negen', null], array_map(static fn (array $object) => ($object['title'] ?? null), $objects));
		$this->assertSame([], $this->warnings);
	}//end testRowsBecomePlannerEntriesWithEmptySlugsAsNull()

	public function testAnUnconvertibleRowIsCountedButLeftOutAndLogged(): void {
		$this->rowSets = [[
			['_id' => 7, '_uuid' => 'bad', '_slug' => ''],
			['_id' => 8, '_uuid' => 'u8', '_slug' => 'ok', '_created' => '2026-01-01'],
		]];

		$page = $this->backfill()->page(0, 10);

		$this->assertSame(2, $page['fetched']);
		$this->assertSame(8, $page['lastId']);
		$this->assertSame(['u8'], array_column($page['rows'], 'uuid'));
		$this->assertCount(2, $this->converted);
		$this->assertSame(
			[[
				'[ObjectSlug] Backfill skipped object bad in table openregister_table_1_2: the row could not be converted into an object',
				['app' => 'openregister', 'path' => 'backfill'],
			]],
			$this->warnings
		);
	}//end testAnUnconvertibleRowIsCountedButLeftOutAndLogged()

	public function testTheLastRowSetsTheCursorEvenWhenItIsSkipped(): void {
		$this->rowSets = [[
			['_id' => 5, '_uuid' => 'u5', '_slug' => null],
			['_id' => 6, '_uuid' => 'bad', '_slug' => null],
		]];

		$page = $this->backfill()->page(0, 2);

		$this->assertSame(6, $page['lastId']);
		$this->assertSame(['u5'], array_column($page['rows'], 'uuid'));
	}//end testTheLastRowSetsTheCursorEvenWhenItIsSkipped()
}//end class
