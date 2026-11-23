<?php

/**
 * Tombstones of destroyed objects on the deleted list (incremental-sync-for-api-consumers REQ-SYNC-003).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Controller\Support\DestroyedTombstones;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Kernel\Contracts\Value\DestructionQuery;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\DeletedFilter;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\Support\DestroyedTombstones
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\DestructionQuery
 * @uses \OCA\OpenRegister\Db\MagicMapper\DeletedFilter
 */
class DestroyedTombstonesTest extends TestCase {

	private AuditTrailMapperInterface&MockObject $mapper;

	private DeletedObjectAuthorizerInterface&MockObject $authorizer;

	/**
	 * The pages the mapper was asked for.
	 *
	 * @var array<int, DestructionQuery>
	 */
	private array $pages = [];

	protected function setUp(): void {
		$this->mapper = $this->createMock(AuditTrailMapperInterface::class);
		$this->authorizer = $this->createMock(DeletedObjectAuthorizerInterface::class);
		$this->authorizer->method('readableDestructionRecords')->willReturnCallback(static fn (array $records): array => $records);
	}

	/**
	 * A destruction entry with the object's data in its payload.
	 *
	 * @param string $uuid    The object's UUID.
	 * @param string $created The moment.
	 *
	 * @return AuditTrail
	 */
	private static function record(string $uuid, string $created = '2026-10-04 09:12:00'): AuditTrail {
		$record = new AuditTrail();
		$record->setObjectUuid($uuid);
		$record->setRegister(4);
		$record->setSchema(12);
		$record->setUser('alice');
		$record->setCreated(new DateTime($created, new DateTimeZone('UTC')));
		$record->setChanged(['name' => 'Jansen', 'bsn' => '999999999']);

		return $record;
	}

	/**
	 * The listing over a mapper that holds these entries.
	 *
	 * @param array<int, AuditTrail> $records The entries the mapper returns.
	 * @param int                    $count   The count the mapper returns.
	 *
	 * @return DestroyedTombstones
	 */
	private function tombstones(array $records, int $count): DestroyedTombstones {
		$this->mapper->method('findDestructions')->willReturnCallback(
			function (DestructionQuery $query) use ($records): array {
				$this->pages[] = $query;
				return $records;
			}
		);
		$this->mapper->method('countDestructions')->willReturn($count);
		$this->mapper->method('oldestDestruction')->willReturn(new DateTimeImmutable('2026-09-01T10:00:00+02:00'));

		return new DestroyedTombstones($this->mapper, $this->authorizer);
	}

	public function testATombstoneCarriesTheIdentityAndMomentAndNoObjectData(): void {
		$this->assertSame(
			[
				'id' => 'a1',
				'register' => 4,
				'schema' => 12,
				'destroyedAt' => '2026-10-04T09:12:00+00:00',
				'tombstone' => true,
			],
			DestroyedTombstones::tombstone(self::record(uuid: 'a1'))
		);
	}

	public function testOnlyAuditTrailEntriesTheAuthorizerReturnsBecomeTombstones(): void {
		$other = new class implements \OCA\OpenRegister\Kernel\Contracts\Core\AuditRecordInterface {
		};
		$this->authorizer = $this->createMock(DeletedObjectAuthorizerInterface::class);
		$this->authorizer->method('readableDestructionRecords')->willReturnCallback(
			static fn (array $records): array => [...$records, $other]
		);

		$listing = $this->tombstones(records: [self::record(uuid: 'a1')], count: 1)
			->listing(query: new DestructionQuery(since: new DateTimeImmutable('2026-10-02T00:00:00+00:00')), deletedTotal: 0, limit: 20, offset: 0);

		$this->assertSame(['a1'], array_column($listing['results'], 'id'));
	}

	public function testTheListingCarriesTombstonesTotalAndCoverage(): void {
		$listing = $this->tombstones(records: [self::record(uuid: 'a1'), self::record(uuid: 'b2')], count: 2)
			->listing(query: new DestructionQuery(since: new DateTimeImmutable('2026-10-02T00:00:00+00:00')), deletedTotal: 0, limit: 20, offset: 0);

		$this->assertSame(['a1', 'b2'], array_column($listing['results'], 'id'));
		$this->assertSame(2, $listing['total']);
		$this->assertSame('2026-09-01T08:00:00+00:00', $listing['destroyedCoverageFrom']);
	}

	public function testARecordTheCallerMayNotReadIsWithheld(): void {
		$readable = self::record(uuid: 'mine');
		$this->authorizer = $this->createMock(DeletedObjectAuthorizerInterface::class);
		$this->authorizer->expects($this->once())->method('readableDestructionRecords')->willReturn([$readable]);

		$listing = $this->tombstones(records: [$readable, self::record(uuid: 'theirs')], count: 1)
			->listing(query: new DestructionQuery(since: new DateTimeImmutable('2026-10-02T00:00:00+00:00')), deletedTotal: 0, limit: 20, offset: 0);

		$this->assertSame(['mine'], array_column($listing['results'], 'id'));
	}

	public function testTombstonesFillThePageAfterTheBinRows(): void {
		$query = new DestructionQuery(since: new DateTimeImmutable('2026-10-02T00:00:00+00:00'));
		$tombstones = $this->tombstones(records: [], count: 9);

		// 15 bin rows; page 10..19 holds bin rows 10..14 and tombstones 0..4.
		$tombstones->listing(query: $query, deletedTotal: 15, limit: 10, offset: 10);
		// Page 20..29 holds tombstones 5..14.
		$tombstones->listing(query: $query, deletedTotal: 15, limit: 10, offset: 20);
		// Page 0..9 is all bin rows: no tombstone is read.
		$tombstones->listing(query: $query, deletedTotal: 15, limit: 10, offset: 0);
		// No page size: every tombstone.
		$tombstones->listing(query: $query, deletedTotal: 15, limit: 0, offset: 0);

		$this->assertSame(
			[[5, 0], [10, 5], [null, 0]],
			array_map(static fn (DestructionQuery $page): array => [$page->limit, $page->offset], $this->pages)
		);
	}

	public function testTheQueryOfAFilterKeepsItsBoundsAndTheActor(): void {
		$since = new DateTimeImmutable('2026-10-02T00:00:00+00:00');
		$tombstones = new DestroyedTombstones($this->mapper, $this->authorizer);

		$query = $tombstones->queryFor(filter: new DeletedFilter(registerId: 4, schemaId: 12, deletedSince: $since), actor: 'alice');

		$this->assertSame($since, $query->since);
		$this->assertSame(4, $query->registerId);
		$this->assertSame(12, $query->schemaId);
		$this->assertSame('alice', $query->actor);
		$this->assertNull($tombstones->queryFor(filter: new DeletedFilter(), actor: null));
	}
}
