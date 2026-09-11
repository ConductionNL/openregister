<?php

declare(strict_types=1);

/**
 * RetentionService sweep unit tests.
 *
 * 🔴 THESE COVER A SWEEP THAT FOUND NOTHING AND SAID SO AS THOUGH IT HAD
 * LOOKED. Every retention sweep in this app selected from the legacy blob table
 * `openregister_objects`, which `BlobMigrationJob` drains into the per-schema
 * magic tables every five minutes. Measured on the shared development database:
 * 0 rows in the blob table against 1320 magic tables, all 1320 carrying a
 * `_retention` column. So the destruction list was never created, the
 * pre-destruction warning was never sent, and the transfer list was a literal
 * `return []`.
 *
 * The first two tests are the whole point: a magic-table record is found, and a
 * legacy-table record is still found. The rest hold the eligibility rules and
 * the per-run cap in place.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the retention scan and the two eligibility sweeps built on it.
 */
class RetentionSweepTest extends TestCase {

	/**
	 * The bare magic table for register 1 / schema 2.
	 */
	private const MAGIC_TABLE = 'openregister_table_1_2';

	/**
	 * The legacy blob table.
	 */
	private const LEGACY_TABLE = 'openregister_objects';

	private MagicMapper&MockObject $objectMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private RegisterMapper&MockObject $registerMapper;
	private IDBConnection&MockObject $db;
	private LoggerInterface&MockObject $logger;
	private ObjectRetentionHandler&MockObject $settingsHandler;
	private RetentionService $service;

	/**
	 * Objects the magic-table mapper hands back, keyed by `_id`.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $magicObjects = [];

	/**
	 * Rows each table answers with, keyed by bare table name.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rowsByTable = [];

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->settingsHandler = $this->createMock(ObjectRetentionHandler::class);

		$this->rowsByTable = [self::LEGACY_TABLE => [], self::MAGIC_TABLE => []];
		$this->magicObjects = [];

		$this->db->method('getQueryBuilder')->willReturnCallback(
			fn (): IQueryBuilder => $this->makeQueryBuilder()
		);

		$this->objectMapper->method('find')->willReturnCallback(
			function (string|int $identifier): ObjectEntity {
				$object = ($this->magicObjects[(int)$identifier] ?? null);
				if ($object === null) {
					throw new \RuntimeException('no such object: ' . (string)$identifier);
				}

				return $object;
			}
		);

		$this->service = new RetentionService(
			$this->objectMapper,
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(AuditTrailMapper::class),
			$this->settingsHandler,
			$this->createMock(IAppConfig::class),
			$this->createMock(IUserSession::class),
			$this->logger,
			// The REAL scanner over the SAME mocks these tests program, not a
			// mock of its own: what is under test here is which tables get
			// walked and how, and a mocked scanner would assert only that this
			// service called something.
			new RetentionRowScanner(
				$this->db,
				$this->registerMapper,
				$this->schemaMapper,
				$this->objectMapper,
				$this->logger
			),
			new ArchiveActionDateCalculator($this->objectMapper, $this->logger),
		);
	}//end setUp()

	/**
	 * THE WHOLE POINT: a record in a MAGIC table is found.
	 *
	 * This is the record that was invisible. The sweep read the legacy blob
	 * table only, and on a migrated install that table is empty.
	 */
	public function testADestroyCandidateInAMagicTableIsFound(): void {
		$this->givenARegisterWithAMagicTable();
		$this->givenMagicRow(id: 7, uuid: 'magic-uuid', retention: $this->dueForDestruction());

		$eligible = $this->service->findEligibleForDestruction();

		$this->assertCount(1, $eligible);
		$this->assertSame('magic-uuid', $eligible[0]->getUuid());
	}//end testADestroyCandidateInAMagicTableIsFound()

	/**
	 * The legacy blob table is still read, so an install that never finished
	 * (or never started) the blob migration keeps working.
	 */
	public function testADestroyCandidateInTheLegacyTableIsFound(): void {
		$this->givenLegacyRow(id: 3, uuid: 'legacy-uuid', retention: $this->dueForDestruction());

		$eligible = $this->service->findEligibleForDestruction();

		$this->assertCount(1, $eligible);
		$this->assertSame('legacy-uuid', $eligible[0]->getUuid());
	}//end testADestroyCandidateInTheLegacyTableIsFound()

	/**
	 * Both stores at once, which is what a part-migrated install looks like.
	 */
	public function testBothStoresAreScannedInOneRun(): void {
		$this->givenARegisterWithAMagicTable();
		$this->givenLegacyRow(id: 3, uuid: 'legacy-uuid', retention: $this->dueForDestruction());
		$this->givenMagicRow(id: 7, uuid: 'magic-uuid', retention: $this->dueForDestruction());

		$uuids = array_map(
			static fn (ObjectEntity $object): ?string => $object->getUuid(),
			$this->service->findEligibleForDestruction()
		);

		sort($uuids);
		$this->assertSame(['legacy-uuid', 'magic-uuid'], $uuids);
	}//end testBothStoresAreScannedInOneRun()

	/**
	 * 🔴 THE DUTCH SPELLINGS MUST KEEP MATCHING.
	 *
	 * Stored data carries whatever spelling was current when it was written and
	 * there is no migration. A sweep that compares against `destroy` and
	 * `active` alone finds nothing on an install whose records say `vernietigen`
	 * and `nog_te_archiveren`, and reports that as "no objects eligible", which
	 * is the direction that keeps personal data past its lawful term.
	 */
	public function testTheDutchSpellingsAreFoundForDestruction(): void {
		$this->givenLegacyRow(
			id: 3,
			uuid: 'dutch-uuid',
			retention: [
				'archiefnominatie' => 'vernietigen',
				'archiefstatus' => 'nog_te_archiveren',
				'archiefactiedatum' => '2020-01-01',
			]
		);

		$eligible = $this->service->findEligibleForDestruction();

		$this->assertCount(1, $eligible);
		$this->assertSame('dutch-uuid', $eligible[0]->getUuid());
	}//end testTheDutchSpellingsAreFoundForDestruction()

	/**
	 * 🔴 A RECORD WITH NO RECORDED STATE IS STILL SWEPT, BECAUSE NOTHING HAS
	 * HAPPENED TO IT YET.
	 *
	 * Nothing writes `archiefstatus` until a record is transferred or
	 * destroyed, so the ordinary case carries no state at all. Measured on a
	 * live instance: a dossiq case resolved through OpenRegister answered
	 * `{"appraisal":"retain_permanently","retentionPeriod":"P10Y",`
	 * `"disposalDate":"2036-09-11T07:22:48+00:00","recordState":null,`
	 * `"basis":"record"}`, with `recordState` null for every case measured.
	 *
	 * The old comparison treated an absent state as "not live", so reading the
	 * magic tables would have fixed where the sweep looks and still returned
	 * nothing for the common case.
	 */
	public function testARecordWithNoRecordedStateIsStillSwept(): void {
		$this->givenLegacyRow(
			id: 8,
			uuid: 'stateless-uuid',
			retention: [
				'archiefnominatie' => 'vernietigen',
				'archiefactiedatum' => '2020-01-01',
			]
		);

		$eligible = $this->service->findEligibleForDestruction();

		$this->assertCount(1, $eligible);
		$this->assertSame('stateless-uuid', $eligible[0]->getUuid());
	}//end testARecordWithNoRecordedStateIsStillSwept()

	/**
	 * An explicitly null state is the shape the resolver actually emits, and it
	 * means the same thing as an absent one.
	 */
	public function testANullRecordStateIsStillSwept(): void {
		$this->givenLegacyRow(
			id: 9,
			uuid: 'null-state-uuid',
			retention: [
				'archiefnominatie' => 'vernietigen',
				'archiefstatus' => null,
				'archiefactiedatum' => '2020-01-01',
			]
		);

		$this->assertCount(1, $this->service->findEligibleForDestruction());
	}//end testANullRecordStateIsStillSwept()

	/**
	 * The transfer sweep reads an absent state the same way.
	 */
	public function testARecordWithNoRecordedStateIsSweptForTransfer(): void {
		$this->givenLegacyRow(
			id: 10,
			uuid: 'stateless-keep-uuid',
			retention: [
				'archiefnominatie' => 'blijvend_bewaren',
				'archiefactiedatum' => '2020-01-01',
			]
		);

		$eligible = $this->service->findEligibleForTransfer();

		$this->assertCount(1, $eligible);
		$this->assertSame('stateless-keep-uuid', $eligible[0]->getUuid());
	}//end testARecordWithNoRecordedStateIsSweptForTransfer()

	/**
	 * An absent state is live, but a state that is present and means something
	 * else is still honoured: a semi-static record is not destroyed.
	 */
	public function testASemiStaticRecordIsNotDestroyed(): void {
		$retention = $this->dueForDestruction();
		$retention['archiefstatus'] = 'semi_static';
		$this->givenLegacyRow(id: 11, uuid: 'semi-uuid', retention: $retention);

		$this->assertSame([], $this->service->findEligibleForDestruction());
	}//end testASemiStaticRecordIsNotDestroyed()

	/**
	 * The same trap on the transfer side: `blijvend_bewaren` is the spelling
	 * MDTO and the selectielijst use, and it is not `retain_permanently`.
	 */
	public function testTheDutchSpellingIsFoundForTransfer(): void {
		$this->givenLegacyRow(
			id: 4,
			uuid: 'bewaren-uuid',
			retention: [
				'archiefnominatie' => 'blijvend_bewaren',
				'archiefstatus' => 'semi_statisch',
				'archiefactiedatum' => '2020-01-01',
			]
		);

		$eligible = $this->service->findEligibleForTransfer();

		$this->assertCount(1, $eligible);
		$this->assertSame('bewaren-uuid', $eligible[0]->getUuid());
	}//end testTheDutchSpellingIsFoundForTransfer()

	/**
	 * An active legal hold keeps a record out of the destruction list.
	 */
	public function testALegalHoldExcludes(): void {
		$retention = $this->dueForDestruction();
		$retention['legalHold'] = ['active' => true, 'reason' => 'lopend bezwaar'];
		$this->givenLegacyRow(id: 3, uuid: 'held-uuid', retention: $retention);

		$this->assertSame([], $this->service->findEligibleForDestruction());
	}//end testALegalHoldExcludes()

	/**
	 * A record already transferred or destroyed is not live, so it is not swept.
	 */
	public function testAnImmutableStateExcludesFromDestruction(): void {
		$retention = $this->dueForDestruction();
		$retention['archiefstatus'] = 'overgebracht';
		$this->givenLegacyRow(id: 3, uuid: 'gone-uuid', retention: $retention);

		$this->assertSame([], $this->service->findEligibleForDestruction());
	}//end testAnImmutableStateExcludesFromDestruction()

	/**
	 * A disposal date still in the future excludes.
	 */
	public function testAFutureDisposalDateExcludes(): void {
		$retention = $this->dueForDestruction();
		$retention['archiefactiedatum'] = '2099-01-01';
		$this->givenLegacyRow(id: 3, uuid: 'future-uuid', retention: $retention);

		$this->assertSame([], $this->service->findEligibleForDestruction());
	}//end testAFutureDisposalDateExcludes()

	/**
	 * A record already on a pending destruction list is not re-listed.
	 */
	public function testAnExcludedUuidIsNotReturned(): void {
		$this->givenLegacyRow(id: 3, uuid: 'pending-uuid', retention: $this->dueForDestruction());

		$this->assertSame([], $this->service->findEligibleForDestruction(['pending-uuid']));
	}//end testAnExcludedUuidIsNotReturned()

	/**
	 * Transfer finds a retain-permanently record whose date has passed.
	 */
	public function testTransferFindsADueRetainPermanentlyRecord(): void {
		$this->givenARegisterWithAMagicTable();
		$this->givenMagicRow(
			id: 9,
			uuid: 'keep-uuid',
			retention: [
				'archiefnominatie' => 'retain_permanently',
				'archiefstatus' => 'semi_static',
				'archiefactiedatum' => '2020-01-01',
			]
		);

		$eligible = $this->service->findEligibleForTransfer();

		$this->assertCount(1, $eligible);
		$this->assertSame('keep-uuid', $eligible[0]->getUuid());
	}//end testTransferFindsADueRetainPermanentlyRecord()

	/**
	 * A record already handed to an e-Depot has nothing left to transfer.
	 */
	public function testTransferDoesNotFindAnAlreadyTransferredRecord(): void {
		$this->givenLegacyRow(
			id: 5,
			uuid: 'already-uuid',
			retention: [
				'archiefnominatie' => 'blijvend_bewaren',
				'archiefstatus' => 'overgebracht',
				'archiefactiedatum' => '2020-01-01',
			]
		);

		$this->assertSame([], $this->service->findEligibleForTransfer());
	}//end testTransferDoesNotFindAnAlreadyTransferredRecord()

	/**
	 * An excluded uuid keeps a record off a second transfer list.
	 */
	public function testTransferHonoursTheExcludeList(): void {
		$this->givenLegacyRow(
			id: 6,
			uuid: 'listed-uuid',
			retention: [
				'archiefnominatie' => 'bewaren',
				'archiefstatus' => 'active',
				'archiefactiedatum' => '2020-01-01',
			]
		);

		$this->assertSame([], $this->service->findEligibleForTransfer(['listed-uuid']));
	}//end testTransferHonoursTheExcludeList()

	/**
	 * 🔴 THE CAP TRUNCATES, AND IT SAYS SO AT WARNING LEVEL.
	 *
	 * A first run on a long backlog must not build an unreviewable list or a
	 * notification storm. A sweep that stopped early in silence would be the
	 * same class of defect this whole change fixes.
	 */
	public function testTheCapTruncatesAndLogsAWarning(): void {
		foreach ([1, 2, 3] as $id) {
			$this->givenLegacyRow(
				id: $id,
				uuid: 'row-' . (string)$id,
				retention: $this->dueForDestruction()
			);
		}

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$scan = $this->service->scanObjectsWithRetention(
			accept: static fn (ObjectEntity $object): bool => true,
			maxMatches: 2
		);

		$this->assertCount(2, $scan['objects']);
		$this->assertTrue($scan['truncated']);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('stopped at its cap', $warnings[0]);
		$this->assertStringContainsString('cap is 2', $warnings[0]);
		$this->assertStringContainsString('next run', $warnings[0]);
	}//end testTheCapTruncatesAndLogsAWarning()

	/**
	 * A run that fits under the cap reports no truncation and warns about
	 * nothing. Without this the previous test would pass on a scan that always
	 * claimed to be truncated.
	 */
	public function testAnUntruncatedRunDoesNotWarn(): void {
		$this->givenLegacyRow(id: 1, uuid: 'row-1', retention: $this->dueForDestruction());

		$this->logger->expects($this->never())->method('warning');

		$scan = $this->service->scanObjectsWithRetention(
			accept: static fn (ObjectEntity $object): bool => true,
			maxMatches: 10
		);

		$this->assertCount(1, $scan['objects']);
		$this->assertFalse($scan['truncated']);
	}//end testAnUntruncatedRunDoesNotWarn()

	/**
	 * A (register, schema) pair whose magic table was never materialised is
	 * skipped, not thrown on. A schema with no rows yet is an ordinary state.
	 */
	public function testAPairWithoutAMagicTableIsSkipped(): void {
		$this->givenARegisterWithAMagicTable(tableExists: false);
		$this->givenMagicRow(id: 7, uuid: 'magic-uuid', retention: $this->dueForDestruction());

		$this->assertSame([], $this->service->findEligibleForDestruction());
	}//end testAPairWithoutAMagicTableIsSkipped()

	/**
	 * Retention metadata that names no disposal date is never due.
	 */
	public function testARecordWithoutADisposalDateIsNotDue(): void {
		$this->givenLegacyRow(
			id: 3,
			uuid: 'undated-uuid',
			retention: ['archiefnominatie' => 'vernietigen', 'archiefstatus' => 'active']
		);

		$this->assertSame([], $this->service->findEligibleForDestruction());
	}//end testARecordWithoutADisposalDateIsNotDue()

	/**
	 * 🔴 THE LIST IS BUILT FROM `getName()`, NOT `getTitle()`.
	 *
	 * `ObjectEntity` has no `title` property, so Entity's magic accessor threw
	 * "title is not a valid attribute" here, uncaught, and took the whole
	 * DestructionCheckJob run down with it. That is a second reason no
	 * destruction list was ever produced, downstream of the sweep finding
	 * nothing in the first place.
	 */
	public function testTheDestructionListCarriesTheObjectName(): void {
		$this->settingsHandler->method('getArchivalSettingsOnly')->willReturn(
			['destructionListRegister' => 1, 'destructionListSchema' => 2]
		);

		$object = new ObjectEntity();
		$object->setUuid('list-uuid');
		$object->setName('Zaak 2019/42');
		$object->setRetention($this->dueForDestruction());

		$list = $this->service->createDestructionList([$object]);

		$this->assertNotNull($list);
		$this->assertSame('Zaak 2019/42', $list['objects'][0]['title']);
	}//end testTheDestructionListCarriesTheObjectName()

	/**
	 * An object with no name falls back to its uuid rather than failing.
	 */
	public function testTheDestructionListFallsBackToTheUuid(): void {
		$this->settingsHandler->method('getArchivalSettingsOnly')->willReturn(
			['destructionListRegister' => 1, 'destructionListSchema' => 2]
		);

		$object = new ObjectEntity();
		$object->setUuid('list-uuid');
		$object->setRetention($this->dueForDestruction());

		$list = $this->service->createDestructionList([$object]);

		$this->assertSame('list-uuid', $list['objects'][0]['title']);
	}//end testTheDestructionListFallsBackToTheUuid()

	/**
	 * Retention metadata that is due for destruction today.
	 *
	 * @return array<string, mixed> The retention block.
	 */
	private function dueForDestruction(): array {
		return [
			'archiefnominatie' => 'destroy',
			'archiefstatus' => 'active',
			'archiefactiedatum' => '2020-01-01',
		];
	}//end dueForDestruction()

	/**
	 * Register 1 holds schema 2, whose magic table is (or is not) materialised.
	 *
	 * @param bool $tableExists Whether the magic table has been created.
	 *
	 * @return void
	 */
	private function givenARegisterWithAMagicTable(bool $tableExists = true): void {
		// Real entities, not mocks: `getId()` and `getSchemas()` come from the
		// Entity magic `__call`, which PHPUnit cannot stub.
		$register = new Register();
		$register->setId(1);
		$register->setSchemas([2]);

		$schema = new Schema();
		$schema->setId(2);

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectMapper->method('tableExistsForRegisterSchema')->willReturn($tableExists);
		$this->objectMapper->method('getTableNameForRegisterSchema')->willReturn(self::MAGIC_TABLE);
	}//end givenARegisterWithAMagicTable()

	/**
	 * Put one row in the magic table, and the object it hydrates to.
	 *
	 * @param int                  $id        The row's `_id`.
	 * @param string               $uuid      The object uuid.
	 * @param array<string, mixed> $retention The retention block.
	 *
	 * @return void
	 */
	private function givenMagicRow(int $id, string $uuid, array $retention): void {
		$this->rowsByTable[self::MAGIC_TABLE][] = ['_id' => $id];

		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRetention($retention);
		$this->magicObjects[$id] = $object;
	}//end givenMagicRow()

	/**
	 * Put one row in the legacy blob table.
	 *
	 * The row is hydrated in place by the service, exactly as a real blob row
	 * is, so `retention` is the JSON TEXT the column holds and not an array.
	 *
	 * @param int                  $id        The row's `id`.
	 * @param string               $uuid      The object uuid.
	 * @param array<string, mixed> $retention The retention block.
	 *
	 * @return void
	 */
	private function givenLegacyRow(int $id, string $uuid, array $retention): void {
		$this->rowsByTable[self::LEGACY_TABLE][] = [
			'id' => $id,
			'uuid' => $uuid,
			'retention' => json_encode($retention),
		];
	}//end givenLegacyRow()

	/**
	 * A query builder that answers from {@see self::$rowsByTable}.
	 *
	 * It records the table `from()` names and honours `setFirstResult()` /
	 * `setMaxResults()`, so the paging loop under test is really exercised
	 * rather than short-circuited by a builder that always returns everything.
	 *
	 * @return IQueryBuilder The programmed builder.
	 */
	private function makeQueryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('isNotNull')->willReturn('retention IS NOT NULL');
		$qb->method('expr')->willReturn($expr);

		$state = ['table' => '', 'offset' => 0, 'limit' => 0];

		$qb->method('select')->willReturn($qb);
		$qb->method('where')->willReturn($qb);
		$qb->method('from')->willReturnCallback(
			static function (string $from) use ($qb, &$state): IQueryBuilder {
				$state['table'] = $from;
				return $qb;
			}
		);
		$qb->method('setFirstResult')->willReturnCallback(
			static function (int $offset) use ($qb, &$state): IQueryBuilder {
				$state['offset'] = $offset;
				return $qb;
			}
		);
		$qb->method('setMaxResults')->willReturnCallback(
			static function (?int $limit) use ($qb, &$state): IQueryBuilder {
				$state['limit'] = (int)$limit;
				return $qb;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$state): IResult {
				$rows = array_slice(
					($this->rowsByTable[$state['table']] ?? []),
					$state['offset'],
					$state['limit']
				);

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows);

				return $result;
			}
		);

		return $qb;
	}//end makeQueryBuilder()
}//end class
