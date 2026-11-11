<?php

/**
 * AuditTrailMapper bulk insert + cascade-context unit tests.
 *
 * Covers the delete-path performance additions:
 * - buildEntry() folds referential-integrity cascade context into the
 *   `changed` column at build time (single INSERT, no post-insert UPDATE).
 * - insertAuditTrails() persists many rows with ONE multi-row INSERT, resolves
 *   the assigned ids, and seals every row into the hash chain in id order.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AuditTrailMapper::buildEntry() and ::insertAuditTrails().
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AuditTrailMapperBulkTest extends TestCase {

	private IDBConnection&MockObject $db;

	private ContainerInterface&MockObject $container;

	private AuditHashService&MockObject $hashService;

	private AuditTrailMapper $mapper;

	private ?\OCA\OpenRegister\Service\Audit\TokenContext $tokenContext = null;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->hashService = $this->createMock(AuditHashService::class);

		// The container resolves AuditHashService for chain sealing; every other
		// lazily-resolved collaborator (VerwerkingsactiviteitMapper, SchemaMapper,
		// RegisterMapper) throws — buildEntry treats that as "not configured"
		// and fails open, matching a bare unit environment.
		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === AuditHashService::class) {
					return $this->hashService;
				}

				if ($id === \OCA\OpenRegister\Service\Audit\TokenContext::class && $this->tokenContext !== null) {
					return $this->tokenContext;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		// Identifier quoting goes through the platform (duck-typed, no hard
		// dependency on doctrine/dbal in the unit environment).
		$platform = new class {

			/**
			 * Quote an SQL identifier.
			 *
			 * @param string $identifier The identifier.
			 *
			 * @return string The quoted identifier.
			 */
			public function quoteIdentifier(string $identifier): string {
				return '"' . $identifier . '"';
			}
		};
		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$this->mapper = new AuditTrailMapper(
			$this->db,
			new AuditTrailMapperServices($this->container, $this->createMock(LoggerInterface::class), $this->createMock(IUserSession::class), $request)
		);
	}//end setUp()

	/**
	 * Build a minimal ObjectEntity for audit rows.
	 *
	 * @param string $uuid The object uuid.
	 * @param int $id The object numeric id.
	 *
	 * @return ObjectEntity
	 */
	private function makeObject(string $uuid, int $id = 7): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId($id);
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['name' => 'thing']);
		return $object;
	}//end makeObject()

	// -------------------------------------------------------------------------
	// buildEntry()
	// -------------------------------------------------------------------------

	public function testBuildAuditTrailInsideAnOrganisationContextNamesTheOrganisation(): void {
		$trail = \OCA\OpenRegister\Service\OrganisationContext::run(
			'org-a',
			fn (): AuditTrail => $this->mapper->buildEntry(change: new AuditedChange(old: $this->makeObject('obj-1'), new: null, action: 'delete'))
		);

		$this->assertSame('org-a', ($trail->getResultSummary() ?? [])['organisationContext'] ?? null);
		$this->assertSame('System', $trail->getUser());
	}//end testBuildAuditTrailInsideAnOrganisationContextNamesTheOrganisation()

	public function testBuildAuditTrailKeepsTheTokenBesideTheOrganisationContext(): void {
		$this->tokenContext = new \OCA\OpenRegister\Service\Audit\TokenContext(
			resolver: $this->createMock(\OCA\OpenRegister\Service\Audit\TokenResolver::class)
		);
		$this->tokenContext->claim(
			identity: new \OCA\OpenRegister\Service\Audit\TokenIdentity(mechanism: 'bearer', token: new \OCA\OpenRegister\Service\Audit\NamedIdentifier(id: 'tok-1'), consumer: new \OCA\OpenRegister\Service\Audit\NamedIdentifier(name: 'portal'))
		);

		$trail = \OCA\OpenRegister\Service\OrganisationContext::run(
			'org-a',
			fn (): AuditTrail => $this->mapper->buildEntry(change: new AuditedChange(old: $this->makeObject('obj-1'), new: null, action: 'delete'))
		);

		$this->assertSame('portal', $trail->getConsumer());
		$this->assertSame('tok-1', ($trail->getResultSummary() ?? [])['token']['reference'] ?? null);
		$this->assertSame('org-a', ($trail->getResultSummary() ?? [])['organisationContext'] ?? null);
	}//end testBuildAuditTrailKeepsTheTokenBesideTheOrganisationContext()

	public function testBuildAuditTrailForDeleteProducesEmptyChangeSet(): void {
		$trail = $this->mapper->buildEntry(change: new AuditedChange(old: $this->makeObject('obj-1'), new: null, action: 'delete'));

		$this->assertSame('delete', $trail->getAction());
		$this->assertSame('obj-1', $trail->getObjectUuid());
		$this->assertSame([], $trail->getChanged());
		$this->assertNotNull($trail->getUuid());
		$this->assertGreaterThanOrEqual(14, $trail->getSize());

		// A DELETE audit row is precisely the row or#2265 was losing: 311 of
		// 330 soft-deleted procest cases had no surviving delete row. With no
		// retention policy resolvable the row is now retained INDEFINITELY
		// instead of being stamped `+30 days`.
		$this->assertNull($trail->getExpires());
		$this->assertNotNull($trail->getRetentionPeriod());
	}//end testBuildAuditTrailForDeleteProducesEmptyChangeSet()

	public function testBuildAuditTrailFoldsCascadeContextIntoChangedColumn(): void {
		$trail = $this->mapper->buildEntry(change: new AuditedChange(
			old: $this->makeObject('obj-2'),
			new: null,
			action: 'referential_integrity.root_delete',
			cascadeContext: [
				'triggerObject' => 'root-uuid',
				'triggerSchema' => 'my-schema',
				'action_type' => 'referential_integrity.root_delete',
				'property' => 'children',
			]
		));

		$changed = $trail->getChanged();
		$this->assertSame('referential_integrity', $changed['triggeredBy']);
		$this->assertSame(
			[
				'triggerObject' => 'root-uuid',
				'triggerSchema' => 'my-schema',
				'action_type' => 'referential_integrity.root_delete',
				'property' => 'children',
			],
			$changed['cascadeContext']
		);
	}//end testBuildAuditTrailFoldsCascadeContextIntoChangedColumn()

	public function testBuildAuditTrailCascadeContextDefaultsMissingKeys(): void {
		$trail = $this->mapper->buildEntry(change: new AuditedChange(
			old: $this->makeObject('obj-3'),
			new: null,
			action: 'delete',
			cascadeContext: []
		));

		$changed = $trail->getChanged();
		$this->assertSame(
			[
				'triggerObject' => null,
				'triggerSchema' => null,
				'action_type' => 'referential_integrity.cascade_delete',
				'property' => null,
			],
			$changed['cascadeContext']
		);
	}//end testBuildAuditTrailCascadeContextDefaultsMissingKeys()

	// -------------------------------------------------------------------------
	// insertAuditTrails()
	// -------------------------------------------------------------------------

	public function testInsertAuditTrailsWithEmptyInputDoesNothing(): void {
		$this->db->expects($this->never())->method('executeStatement');

		$this->assertSame([], $this->mapper->insertAuditTrails(entries:[]));
	}//end testInsertAuditTrailsWithEmptyInputDoesNothing()

	public function testInsertAuditTrailsRejectsNonAuditTrailRows(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->mapper->insertAuditTrails(entries:[new \stdClass()]);
	}//end testInsertAuditTrailsRejectsNonAuditTrailRows()

	public function testInsertAuditTrailsRejectsRowsWithoutUuid(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->mapper->insertAuditTrails(entries:[new AuditTrail()]);
	}//end testInsertAuditTrailsRejectsRowsWithoutUuid()

	public function testInsertAuditTrailsIssuesOneMultiRowInsertAndSealsInIdOrder(): void {
		$rowA = $this->mapper->buildEntry(change: new AuditedChange(old: $this->makeObject('obj-a'), new: null, action: 'delete'));
		$rowB = $this->mapper->buildEntry(change: new AuditedChange(old: $this->makeObject('obj-b'), new: null, action: 'delete'));

		$this->mockQueryBuilderTableName();

		$capturedSql = null;
		$capturedParams = null;
		$this->db->expects($this->once())
			->method('executeStatement')
			->willReturnCallback(
				function (string $sql, array $params) use (&$capturedSql, &$capturedParams): int {
					$capturedSql = $sql;
					$capturedParams = $params;
					return 2;
				}
			);

		// The id-resolution SELECT returns the freshly-assigned ids by uuid —
		// deliberately out of order to prove readback maps ids by uuid.
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 12, 'uuid' => $rowB->getUuid()],
			['id' => 11, 'uuid' => $rowA->getUuid()],
			false
		);
		$this->db->expects($this->once())
			->method('executeQuery')
			->with(
				$this->stringContains('SELECT id, uuid FROM *PREFIX*openregister_audit_trails WHERE uuid IN'),
				[$rowA->getUuid(), $rowB->getUuid()]
			)
			->willReturn($result);

		// The write path inserts and stops. Sealing belongs to AuditSealJob:
		// with one sealer, unsealed rows are a contiguous tail rather than
		// holes, so a later row can never chain across a gap and end up sharing
		// a predecessor with it.
		$this->hashService->expects($this->never())->method('sealRows');

		$inserted = $this->mapper->insertAuditTrails(entries: [$rowA, $rowB]);

		// ONE multi-row INSERT: two parenthesised value groups, one statement.
		$this->assertStringStartsWith('INSERT INTO *PREFIX*openregister_audit_trails (', $capturedSql);
		$this->assertSame(1, substr_count($capturedSql, ' VALUES '));
		// Two parenthesised value groups joined once => exactly one '), (' separator.
		$this->assertSame(1, substr_count($capturedSql, '), ('));
		// Params cover both rows' uuids.
		$this->assertContains($rowA->getUuid(), $capturedParams);
		$this->assertContains($rowB->getUuid(), $capturedParams);

		// Ids are still attached by uuid — deferring the seal must not cost the
		// caller the ids it inserted.
		$this->assertSame(11, $inserted[0]->getId());
		$this->assertSame(12, $inserted[1]->getId());
	}//end testInsertAuditTrailsIssuesOneMultiRowInsertAndSealsInIdOrder()

	public function testInsertAuditTrailsSurvivesSealFailure(): void {
		$row = $this->mapper->buildEntry(change: new AuditedChange(old: $this->makeObject('obj-c'), new: null, action: 'delete'));

		$this->mockQueryBuilderTableName();
		$this->db->method('executeStatement')->willReturn(1);

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 21, 'uuid' => $row->getUuid()],
			false
		);
		$this->db->method('executeQuery')->willReturn($result);

		$this->hashService->method('sealRows')->willThrowException(new \RuntimeException('hash backend down'));

		// Fail-soft: the row stays inserted and is returned with its id.
		$inserted = $this->mapper->insertAuditTrails(entries:[$row]);

		$this->assertSame(21, $inserted[0]->getId());
	}//end testInsertAuditTrailsSurvivesSealFailure()

	/**
	 * Stub the query-builder table-name resolution used by the bulk INSERT.
	 *
	 * insertAuditTrailChunk() resolves the physical table name through
	 * IQueryBuilder::getTableName(); the bare IDBConnection mock would
	 * otherwise return null from getQueryBuilder().
	 *
	 * @return void
	 */
	private function mockQueryBuilderTableName(): void {
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('getTableName')->willReturn('*PREFIX*openregister_audit_trails');
		$this->db->method('getQueryBuilder')->willReturn($qb);
	}//end mockQueryBuilderTableName()
	/**
	 * An explicitly-named `update` survives, even with no `old` entity.
	 *
	 * The inference used to fire on `$action === 'update'`, which is both the
	 * default AND a legitimate explicit value — so a caller that KNEW the write
	 * was an update could not say so, and got `create` in the row. That is
	 * exactly the case MagicMapper hits when its own lookup takes the UPDATE
	 * branch on an operation the service labelled a create (#2217), and it is
	 * why a fix passing an explicit action would have looked applied while
	 * changing nothing.
	 *
	 * @return void
	 */
	public function testAnExplicitUpdateSurvivesWithNoOldEntity(): void {
		$trail = $this->mapper->buildEntry(change: new AuditedChange(
			old: null,
			new: $this->makeObject('obj-upd'),
			action: 'update'
		));

		$this->assertSame('update', $trail->getAction());
	}//end testAnExplicitUpdateSurvivesWithNoOldEntity()

	/**
	 * With no action named, a missing `old` still infers a create.
	 *
	 * The guard tests above are only meaningful next to this one: a change that
	 * simply stopped inferring would pass them and break every caller that
	 * relies on the inference — which is most of them.
	 *
	 * @return void
	 */
	public function testAnOmittedActionStillInfersCreate(): void {
		$trail = $this->mapper->buildEntry(change: new AuditedChange(old: null, new: $this->makeObject('obj-new')));

		$this->assertSame('create', $trail->getAction());
	}//end testAnOmittedActionStillInfersCreate()

	/**
	 * With no action named, a missing `new` still infers a delete.
	 *
	 * @return void
	 */
	public function testAnOmittedActionStillInfersDelete(): void {
		$trail = $this->mapper->buildEntry(change: new AuditedChange(old: $this->makeObject('obj-gone'), new: null));

		$this->assertSame('delete', $trail->getAction());
	}//end testAnOmittedActionStillInfersDelete()
}//end class
