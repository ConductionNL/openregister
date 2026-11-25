<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace Unit\Service\Sharing;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use OCA\OpenRegister\Service\Sharing\SharePrincipalRepair;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Stale derived share lists are recomputed; current ones are left alone.
 *
 * @covers \OCA\OpenRegister\Service\Sharing\SharePrincipalRepair
 * @uses   \OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
final class SharePrincipalRepairTest extends TestCase {

	/**
	 * A stored object.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private static function stored(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}

	/**
	 * Run the repair over objects, served in pages, recording writes.
	 *
	 * @param array<int, mixed>                  $objects The stored objects.
	 * @param array<string, array<string,mixed>> $written Receives uuid => written data.
	 * @param array<int, array{0:int,1:int}>     $pages   Receives each page request.
	 * @param array<int, string>                 $failOn  Uuids whose write throws.
	 *
	 * @return array{checked: int, repaired: int, failed: array<int, string>}
	 */
	private static function repairAll(array $objects, array &$written=[], array &$pages=[], array $failOn=[]): array {
		return (new SharePrincipalRepair(new SharePrincipalDeriver()))->repair(
			page: static function (int $offset, int $limit) use ($objects, &$pages): array {
				$pages[] = [$offset, $limit];
				return array_slice($objects, $offset, $limit);
			},
			write: static function (ObjectEntity $object, array $data) use (&$written, $failOn): void {
				if (in_array($object->getUuid(), $failOn, true) === true) {
					throw new RuntimeException('refused');
				}

				$written[$object->getUuid()] = $data;
			}
		);
	}

	public function testAStaleListThatStillGrantsARevokedUserIsRecomputed(): void {
		$written = [];
		$report = self::repairAll(
			[
				self::stored('c1', [
					'name' => 'api key',
					'sharedWith' => [['type' => 'group', 'id' => 'finance', 'permission' => 'use']],
					'sharedUsers' => ['mallory'],
					'sharedGroups' => [],
				]),
			],
			$written
		);

		$this->assertSame(['checked' => 1, 'repaired' => 1, 'failed' => []], $report);
		$this->assertSame([], $written['c1']['sharedUsers']);
		$this->assertSame(['finance'], $written['c1']['sharedGroups']);
		$this->assertSame('api key', $written['c1']['name']);
	}

	public function testAnObjectWrittenBeforeTheListsExistedGetsThem(): void {
		$written = [];
		self::repairAll([self::stored('c1', ['sharedWith' => [['type' => 'user', 'id' => 'alice']]])], $written);

		$this->assertSame(['alice'], $written['c1']['sharedUsers']);
		$this->assertSame([], $written['c1']['sharedGroups']);
	}

	public function testCurrentListsAndNonObjectsAreLeftAlone(): void {
		$written = [];
		$report = self::repairAll(
			[
				self::stored('c1', ['sharedWith' => [['type' => 'user', 'id' => 'alice']], 'sharedUsers' => ['alice'], 'sharedGroups' => []]),
				self::stored('c2', ['sharedWith' => [], 'sharedUsers' => [], 'sharedGroups' => []]),
				['not' => 'an entity'],
			],
			$written
		);

		$this->assertSame(['checked' => 2, 'repaired' => 0, 'failed' => []], $report);
		$this->assertSame([], $written);
	}

	public function testEveryPageIsReadUntilAShortOne(): void {
		$objects = [];
		for ($i = 0; $i < SharePrincipalRepair::PAGE_SIZE + 3; $i++) {
			$objects[] = self::stored('c' . $i, ['sharedUsers' => ['stale']]);
		}

		$written = [];
		$pages = [];
		$report = self::repairAll($objects, $written, $pages);

		$this->assertSame([[0, SharePrincipalRepair::PAGE_SIZE], [SharePrincipalRepair::PAGE_SIZE, SharePrincipalRepair::PAGE_SIZE]], $pages);
		$this->assertSame(SharePrincipalRepair::PAGE_SIZE + 3, $report['checked']);
		$this->assertSame(SharePrincipalRepair::PAGE_SIZE + 3, $report['repaired']);
	}

	public function testAFailedWriteIsNamedAndTheRestContinue(): void {
		$written = [];
		$pages = [];
		$report = self::repairAll(
			[self::stored('c1', ['sharedUsers' => ['x']]), self::stored('c2', ['sharedGroups' => ['y']])],
			$written,
			$pages,
			['c1']
		);

		$this->assertSame(['checked' => 2, 'repaired' => 1, 'failed' => ['c1']], $report);
		$this->assertSame(['c2'], array_keys($written));
	}

	public function testStalenessComparesBothLists(): void {
		$repair = new SharePrincipalRepair(new SharePrincipalDeriver());

		$this->assertTrue($repair->isStale(['sharedUsers' => [], 'sharedGroups' => ['g']]));
		$this->assertTrue($repair->isStale(['sharedUsers' => ['u'], 'sharedGroups' => []]));
		$this->assertTrue($repair->isStale([]));
		$this->assertFalse($repair->isStale(['sharedWith' => [['type' => 'group', 'id' => 'g']], 'sharedUsers' => [], 'sharedGroups' => ['g']]));
	}
}
