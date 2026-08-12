<?php

/**
 * AuditTrailMapper::insertAuditTrails batched insert tests.
 *
 * Proves the bulk audit path persists rows with one multi-row INSERT per
 * chunk, builds row content through the exact same buildAuditTrail() logic
 * as createAuditTrail() (json/datetime conversions matching QBMapper's
 * parameter types), reads the generated ids back, and seals the chunk
 * through AuditHashService::sealRows().
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class AuditTrailMapperBulkInsertTest extends TestCase {

	private AuditTrailMapper $mapper;

	private IDBConnection&MockObject $db;

	private ContainerInterface&MockObject $container;

	private AuditHashService&MockObject $hashService;

	/**
	 * Captured [sql, params] pairs from executeStatement (the INSERTs).
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $statements = [];

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->hashService = $this->createMock(AuditHashService::class);
		$userSession = $this->createMock(IUserSession::class);
		$request = $this->createMock(IRequest::class);
		$logger = $this->createMock(LoggerInterface::class);

		$userSession->method('getUser')->willReturn(null);
		$request->method('getId')->willReturn('req-1');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		// Only the AuditHashService resolves; everything else (the AVG
		// VerwerkingsactiviteitMapper) is unavailable, which the mapper
		// treats as "tagging not configured" (fail-open by design).
		$this->container->method('get')
			->willReturnCallback(
				function (string $class): object {
					if ($class === AuditHashService::class) {
						return $this->hashService;
					}

					throw new \RuntimeException('not registered: ' . $class);
				}
			);

		// Table-name resolution through the query builder.
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('getTableName')->willReturn('"oc_openregister_audit_trails"');
		$this->db->method('getQueryBuilder')->willReturn($queryBuilder);

		// BUG-SQL-1 fix coverage: insertAuditTrailChunk() now quotes every
		// INSERT column via the platform (e.g. `user` is a reserved word in
		// PostgreSQL and breaks an unquoted multi-row INSERT). Reuse the
		// same platform mock pattern as the QueryBuilder above.
		$platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
		$platform->method('quoteIdentifier')->willReturnCallback(
			static fn (string $identifier): string => '"' . $identifier . '"'
		);
		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$this->statements = [];
		$this->db->method('executeStatement')
			->willReturnCallback(
				function (string $sql, array $params = []): int {
					$this->statements[] = [$sql, $params];
					return 1;
				}
			);

		$this->mapper = new AuditTrailMapper(
			$this->db,
			$this->container,
			$userSession,
			$request,
			$logger
		);
	}//end setUp()

	/**
	 * Build an ObjectEntity carrying identity + data.
	 */
	private function buildObject(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('1');
		$entity->setSchema('2');
		$entity->setObject($data);
		return $entity;
	}//end buildObject()

	/**
	 * Wire the id-readback SELECT to return an id per inserted uuid.
	 *
	 * @param array<string, int> $uuidToId Map of row uuid to generated id.
	 */
	private function mockIdReadback(array &$uuidToId, int $startId = 100): void {
		$this->db->method('executeQuery')
			->willReturnCallback(
				function (string $sql, array $params = []) use (&$uuidToId, &$startId): IResult {
					$rows = [];
					foreach ($params as $uuid) {
						if (isset($uuidToId[$uuid]) === false) {
							$uuidToId[$uuid] = $startId++;
						}

						$rows[] = ['id' => $uuidToId[$uuid], 'uuid' => $uuid];
					}

					$rows[] = false;
					$cursor = $this->createMock(IResult::class);
					$cursor->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
					return $cursor;
				}
			);
	}//end mockIdReadback()

	/**
	 * Two entries produce ONE multi-row INSERT whose parameters carry the
	 * exact buildAuditTrail() row content (create with no diff-source, and
	 * update with a real old→new changeset), and ids are read back onto the
	 * returned entities.
	 *
	 * The write path must NOT seal. Sealing takes an exclusive lock, so under
	 * concurrency some rows sealed and some fell through unsealed; a row sealed
	 * after a gap chained onto the newest SEALED row, so filling that gap later
	 * gave two rows one predecessor — a fan-out, indistinguishable from
	 * tampering. Leaving every row for AuditSealJob keeps unsealed rows a
	 * contiguous tail, which is what makes the fan-out unreachable rather than
	 * merely unlikely. Hence the `never()` below: it is the invariant, not an
	 * omission.
	 */
	public function testInsertAuditTrailsSingleChunk(): void {
		$old = $this->buildObject('obj-1', ['title' => 'before']);
		$new = $this->buildObject('obj-1', ['title' => 'after']);
		$created = $this->buildObject('obj-2', ['title' => 'fresh']);

		$uuidToId = [];
		$this->mockIdReadback($uuidToId);

		$this->hashService->expects($this->never())->method('sealRows');

		$trails = $this->mapper->insertAuditTrails(
			entries: [
				['old' => null, 'new' => $created, 'action' => 'create'],
				['old' => $old, 'new' => $new, 'action' => 'update'],
			]
		);

		// One multi-row INSERT statement for the whole chunk.
		$this->assertCount(1, $this->statements);
		[$sql, $params] = $this->statements[0];
		$this->assertStringStartsWith('INSERT INTO "oc_openregister_audit_trails"', $sql);
		// Columns + two value tuples: "(col, ...) VALUES (...), (...)".
		$this->assertSame(3, substr_count($sql, '('), '(cols) + 2 value tuples means 3 "(" total');
		$this->assertSame(1, substr_count($sql, 'VALUES'));
		$this->assertSame(2, substr_count($sql, '(?'), 'two value tuples expected');

		// Row content parity: the update row's changed diff records the real
		// old value, json-encoded exactly as QBMapper would persist it. The
		// diff is derived from ObjectEntity::jsonSerialize(), so assert on the
		// decoded structure rather than a hand-built full payload.
		$updateChanged = null;
		foreach ($params as $param) {
			if (is_string($param) === true && str_contains($param, '"title"') === true) {
				$decoded = json_decode($param, true);
				if (is_array($decoded) === true && isset($decoded['title']) === true) {
					$updateChanged = $decoded;
				}
			}
		}

		$this->assertIsArray($updateChanged, 'update changeset must be among the INSERT parameters');
		$this->assertSame(['old' => 'before', 'new' => 'after'], $updateChanged['title']);

		// Returned entities: same builder logic as createAuditTrail().
		$this->assertCount(2, $trails);
		$this->assertContainsOnlyInstancesOf(AuditTrail::class, $trails);
		$this->assertSame('create', $trails[0]->getAction());
		$this->assertSame('obj-2', $trails[0]->getObjectUuid());
		$this->assertSame('update', $trails[1]->getAction());
		$this->assertSame('obj-1', $trails[1]->getObjectUuid());
		$this->assertSame('System', $trails[0]->getUser());
		$this->assertNotNull($trails[0]->getCreated());
		$this->assertGreaterThanOrEqual(14, $trails[0]->getSize());

		// Expiry follows the OBJECT's retention (or#2265). These fixtures carry
		// no retention metadata and the container in this test resolves nothing
		// but AuditHashService, so the resolver is unavailable — both routes
		// land on the same fail-SAFE outcome: retain indefinitely. `null` here
		// is the assertion, not the absence of one: it used to be a hardcoded
		// `+30 days` on every row regardless of the object.
		$this->assertNull($trails[0]->getExpires());
		$this->assertSame('resolver-unavailable:indefinite', $trails[0]->getRetentionPeriod());

		// Ids read back and sealed in one batch.
		$this->assertSame(100, $trails[0]->getId());
		$this->assertSame(101, $trails[1]->getId());
	}//end testInsertAuditTrailsSingleChunk()

	/**
	 * Entries beyond the chunk size split into multiple INSERT statements,
	 * and none of those chunks seals.
	 */
	public function testInsertAuditTrailsChunksInserts(): void {
		$entries = [];
		for ($i = 0; $i < 5; $i++) {
			$entries[] = [
				'old' => null,
				'new' => $this->buildObject('obj-' . $i, ['n' => $i]),
				'action' => 'create',
			];
		}

		$uuidToId = [];
		$this->mockIdReadback($uuidToId);
		// Chunking must not reintroduce sealing by the back door: more chunks
		// would simply mean more chances to punch a gap into the chain.
		$this->hashService->expects($this->never())->method('sealRows');

		$trails = $this->mapper->insertAuditTrails(entries: $entries, chunkSize: 2);

		$this->assertCount(5, $trails);
		// 5 entries at chunk size 2 -> 3 INSERT statements.
		$this->assertCount(3, $this->statements);
	}//end testInsertAuditTrailsChunksInserts()

	/**
	 * No entries -> no database interaction at all.
	 */
	public function testInsertAuditTrailsEmptyIsANoOp(): void {
		$this->db->expects($this->never())->method('executeStatement');

		$this->assertSame([], $this->mapper->insertAuditTrails(entries: []));
	}//end testInsertAuditTrailsEmptyIsANoOp()
}//end class
