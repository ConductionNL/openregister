<?php

declare(strict_types=1);

/**
 * DataSubjectRequestService golden test.
 *
 * Pins the allowed AND refused outcomes of every data-subject-right path so a
 * behaviour-preserving refactor can be proven to be one: which bucket every
 * erasure candidate lands in (and in which order the guards are asked), what
 * is written and what is never written, the RBAC/tenant flags handed to the
 * object loader and the audited save path, and the summary shape.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\DsarService;
use OCA\OpenRegister\Service\Gdpr\DataSubjectDeadline;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\SubjectRecords;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectEntityIndex;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectLoader;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectWriter;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectRetentionGate;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for DataSubjectRequestService.
 */
class DataSubjectRequestServiceGoldenTest extends TestCase {

	/**
	 * DB connection mock.
	 *
	 * @var IDBConnection&MockObject
	 */
	private $db;

	/**
	 * Object mapper mock.
	 *
	 * @var MagicMapperInterface&MockObject
	 */
	private $objectMapper;

	/**
	 * Object service mock.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private $objectService;

	/**
	 * Retention service mock.
	 *
	 * @var RetentionServiceInterface&MockObject
	 */
	private $retentionService;

	/**
	 * DSAR service mock.
	 *
	 * @var DsarService&MockObject
	 */
	private $dsarService;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * Schema mapper backing the real archival guard.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * Named parameters created on the query builder, in order.
	 *
	 * @var list<mixed>
	 */
	private array $params = [];

	/**
	 * Identifiers and flags handed to the object loader, in order.
	 *
	 * @var list<array{0: mixed, 1: bool, 2: bool}>
	 */
	private array $finds = [];

	/**
	 * Uuids and flags handed to the audited save path, in order.
	 *
	 * @var list<array{0: string|null, 1: bool, 2: bool}>
	 */
	private array $saves = [];

	/**
	 * Uuids of objects whose save throws.
	 *
	 * @var list<string>
	 */
	private array $failingSaves = [];

	/**
	 * Objects the loader resolves, by identifier.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $objects = [];

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->objectMapper = $this->createMock(MagicMapperInterface::class);
		$this->objectService = $this->createMock(ObjectOperationsInterface::class);
		$this->retentionService = $this->createMock(RetentionServiceInterface::class);
		$this->dsarService = $this->createMock(DsarService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->dsarService->method('getDsarProcessingActivityUuid')->willReturn('activity-uuid');

		$this->schemaMapper->method('find')->willReturnCallback(
			function ($id) {
				if ((string)$id === 'gone') {
					throw new DoesNotExistException('gone');
				}

				$schema = new Schema();
				$schema->setSlug((string)$id);
				$configuration = [];
				if ((string)$id === 'archival') {
					$configuration['x-openregister-archival'] = ['retention' => ['default' => 'P10Y']];
				}

				$schema->setConfiguration($configuration);
				return $schema;
			}
		);

		$this->objectMapper->method('find')->willReturnCallback(
			function (...$args) {
				$identifier = $args[0];
				$this->finds[] = [$identifier, $args[4], $args[5]];
				if (isset($this->objects[(string)$identifier]) === false) {
					throw new DoesNotExistException('not authorised');
				}

				return $this->objects[(string)$identifier];
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				$object = $args[0];
				$this->saves[] = [$object->getUuid(), $args[5], $args[6]];
				if (in_array($object->getUuid(), $this->failingSaves, true) === true) {
					throw new RuntimeException('disk full');
				}

				return $object;
			}
		);

		$this->retentionService->method('hasActiveLegalHold')->willReturnCallback(
			static fn (ObjectEntity $o) => str_starts_with((string)$o->getUuid(), 'hold')
		);
		$this->retentionService->method('validateNotImmutable')->willReturnCallback(
			static fn (ObjectEntity $o) => str_starts_with((string)$o->getUuid(), 'frozen') ? 'OBJECT_DESTROYED' : null
		);

	}//end setUp()

	/**
	 * Build the service under test from the shared mocks.
	 *
	 * @return DataSubjectRequestService
	 */
	private function buildService(): DataSubjectRequestService {
		$logger = $this->createMock(LoggerInterface::class);

		return new DataSubjectRequestService(records: new SubjectRecords(index: new SubjectEntityIndex(db: $this->db, logger: $logger), loader: new SubjectObjectLoader(objectMapper: $this->objectMapper, logger: $logger), writer: new SubjectObjectWriter(
				objectService: $this->objectService,
				dsarService: $this->dsarService,
				userSession: $this->userSession,
				logger: $logger
			)), retentionGate: new SubjectRetentionGate(
				retentionService: $this->retentionService,
				archivalGuard: new ArchivalRetentionGuard($this->schemaMapper, $logger)
			), deadline: new DataSubjectDeadline(), logger: $logger);

	}//end buildService()

	/**
	 * Wire the index join to return rows (or throw).
	 *
	 * @param list<array<string, mixed>>|null $rows Rows, or null to make the query throw.
	 *
	 * @return void
	 */
	private function wireIndex(?array $rows): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('iLike')->willReturn('ilike');
		$expr->method('isNotNull')->willReturn('notnull');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('selectDistinct')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) {
				$this->params[] = $value;
				return ':p';
			}
		);

		if ($rows === null) {
			$qb->method('executeQuery')->willThrowException(new RuntimeException('db down'));
		} else {
			$cursor = $this->createMock(IResult::class);
			$cursor->method('fetchAll')->willReturn($rows);
			$qb->method('executeQuery')->willReturn($cursor);
		}

		$this->db->method('getQueryBuilder')->willReturn($qb);
		$this->db->method('escapeLikeParameter')->willReturnCallback(
			static fn (string $v) => str_replace('%', '\\%', $v)
		);

	}//end wireIndex()

	/**
	 * One index row for an object.
	 *
	 * @param string $uuid Object uuid ('' for none).
	 * @param int $id Object id.
	 * @param string $value Matched value.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $uuid, int $id = 10, string $value = 'jane@example.org'): array {
		return [
			'id' => 1,
			'type' => 'email',
			'value' => $value,
			'category' => 'pii',
			'detected_at' => '2026-01-01',
			'object_id' => $id,
			'object_uuid' => $uuid,
		];

	}//end row()

	/**
	 * Register a loadable object.
	 *
	 * @param string $uuid Object uuid.
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, string $schema = 'plain', array $payload = ['email' => 'jane@example.org']): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('reg');
		$object->setSchema($schema);
		$object->setObject($payload);
		$this->objects[$uuid] = $object;
		return $object;

	}//end object()

	/**
	 * An object's payload without the `id` key the entity mirrors in.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(ObjectEntity $object): array {
		$payload = $object->getObject();
		unset($payload['id']);
		return $payload;

	}//end payload()

	/**
	 * Every erasure bucket, in one run, with guard order pinned.
	 *
	 * An object that is BOTH archival and held is withheld (archival asked
	 * first); an unloadable one fails; a held one and an immutable one are
	 * held; a save failure fails; the rest are erased. Only erasable objects
	 * reach the save path, each with RBAC + tenancy on.
	 *
	 * @return void
	 */
	public function testEraseBucketsEveryCandidateInGuardOrder(): void {
		$this->wireIndex(
			[
				$this->row('ok'),
				$this->row('ok', 10, 'Jane.Alias@example.org'),
				$this->row('missing'),
				$this->row('hold-archival'),
				$this->row('hold-1'),
				$this->row('frozen-1'),
				$this->row('broken'),
				$this->row('gone-schema'),
				$this->row('', 0),
			]
		);
		$ok = $this->object('ok', 'plain', ['email' => 'JANE@example.org', 'alias' => 'jane.alias@example.org', 'keep' => 'x', 'nested' => ['e' => 'jane@example.org']]);
		$this->object('hold-archival', 'archival');
		$hold = $this->object('hold-1');
		$this->object('frozen-1');
		$this->object('broken');
		$this->object('gone-schema', 'gone');
		$this->failingSaves = ['broken'];

		$summary = $this->buildService()->erase(subjectId: '  jane@example.org  ', eraseMode: 'nonsense');

		$this->assertSame('jane@example.org', $summary['subject']);
		$this->assertSame(DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE, $summary['eraseMode']);
		$this->assertFalse($summary['dryRun']);
		$this->assertSame(7, $summary['matchedCount']);
		$this->assertSame([['uuid' => 'ok', 'register' => 'reg', 'schema' => 'plain']], $summary['erased']);
		$this->assertSame(
			[
				['object' => ['object_id' => 10, 'object_uuid' => 'missing'], 'error' => 'Object could not be loaded (not found or not authorised)'],
				['object' => ['uuid' => 'broken', 'register' => 'reg', 'schema' => 'plain'], 'error' => 'disk full'],
			],
			$summary['failed']
		);
		$this->assertSame(
			[['uuid' => 'hold-1', 'reason' => 'legal-hold'], ['uuid' => 'frozen-1', 'reason' => 'OBJECT_DESTROYED']],
			$summary['held']
		);
		$this->assertSame(['hold-archival', 'gone-schema'], array_column($summary['withheld'], 'uuid'));
		$this->assertSame(
			[ArchivalRetentionGuard::GROUND_ARCHIVAL, ArchivalRetentionGuard::GROUND_UNRESOLVED],
			array_column($summary['withheld'], 'ground')
		);
		$this->assertFalse($summary['complete']);
		$this->assertSame(2, $summary['failedCount']);
		$this->assertSame(2, $summary['heldCount']);
		$this->assertSame(2, $summary['withheldCount']);

		// Only the erasable objects reached the audited save path, scoped.
		$this->assertSame([['ok', true, true], ['broken', true, true]], $this->saves);
		foreach ($this->finds as $find) {
			$this->assertTrue($find[1]);
			$this->assertTrue($find[2]);
		}

		// Pseudonymised in place: subject + every matched value, case-insensitive, nested.
		$this->assertSame(
			['email' => '[erased]', 'alias' => '[erased]', 'keep' => 'x', 'nested' => ['e' => '[erased]']],
			$this->payload(object: $ok)
		);
		$this->assertSame('activity-uuid', $ok->getProcessingActivityId());
		$this->assertEmpty($ok->getDeleted());
		$this->assertSame(['email' => 'jane@example.org'], $this->payload(object: $hold));
		$this->assertNull($hold->getProcessingActivityId());

		// Exact mode: the escaped subject is the whole pattern.
		$this->assertSame(['jane@example.org'], $this->params);

	}//end testEraseBucketsEveryCandidateInGuardOrder()

	/**
	 * A clean whole-object run is complete and stamps the erasure metadata.
	 *
	 * @return void
	 */
	public function testEraseWholeObjectIsCompleteAndStamped(): void {
		$this->wireIndex([$this->row('a'), $this->row('', 22)]);
		$a = $this->object('a');
		$b = $this->object('22');

		$summary = $this->buildService()->erase(subjectId: 'jane@example.org', type: 'email', eraseMode: DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);

		$this->assertTrue($summary['complete']);
		$this->assertSame('email', $summary['type']);
		$this->assertSame(DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT, $summary['eraseMode']);
		$this->assertSame(0, $summary['failedCount']);
		$this->assertSame(0, $summary['heldCount']);
		$this->assertSame(0, $summary['withheldCount']);
		$this->assertCount(2, $summary['erased']);
		$this->assertSame(['jane@example.org', 'email'], $this->params);
		$this->assertSame([['a', true, true], [22, true, true]], $this->finds);

		foreach ([$a, $b] as $object) {
			$deleted = $object->getDeleted();
			$this->assertSame('handler1', $deleted['deletedBy']);
			$this->assertSame('gdpr-erasure', $deleted['deletedReason']);
			$this->assertSame('jane@example.org', $deleted['subject']);
			$this->assertArrayHasKey('deleted', $deleted);
			$this->assertSame(['email' => 'jane@example.org'], $this->payload(object: $object));
		}

	}//end testEraseWholeObjectIsCompleteAndStamped()

	/**
	 * A dry run reports erasable rows, still applies every guard, and writes nothing.
	 *
	 * @return void
	 */
	public function testEraseDryRunWritesNothing(): void {
		$this->wireIndex([$this->row('a'), $this->row('hold-x'), $this->row('arch')]);
		$a = $this->object('a');
		$this->object('hold-x');
		$this->object('arch', 'archival');

		$summary = $this->buildService()->erase(subjectId: 'jane@example.org', dryRun: true);

		$this->assertSame([['uuid' => 'a', 'register' => 'reg', 'schema' => 'plain']], $summary['erased']);
		$this->assertSame(1, $summary['heldCount']);
		$this->assertSame(1, $summary['withheldCount']);
		$this->assertSame([], $this->saves);
		$this->assertSame(['email' => 'jane@example.org'], $this->payload(object: $a));
		$this->assertNull($a->getProcessingActivityId());

	}//end testEraseDryRunWritesNothing()

	/**
	 * Discovery: blank subject short-circuits, grouping, flag pass-through, ilike, db failure.
	 *
	 * @return void
	 */
	public function testDiscoveryShapes(): void {
		$this->db->expects($this->never())->method('getQueryBuilder');
		$this->assertSame([], $this->buildService()->findSubjectData(subjectId: '   '));
		$this->assertSame([], $this->buildService()->findSubjectObjects(subjectId: ''));

	}//end testDiscoveryShapes()

	/**
	 * findSubjectObjects groups rows per object and passes the scoping flags.
	 *
	 * @return void
	 */
	public function testFindSubjectObjectsGroupsAndScopes(): void {
		$this->wireIndex([$this->row('a'), $this->row('a', 10, 'other@example.org'), $this->row('', 7), $this->row('denied'), $this->row('', 0)]);
		$a = $this->object('a');
		$seven = $this->object('7');

		$hits = $this->buildService()->findSubjectObjects(subjectId: 'ja%ne', type: 'email', mode: 'ilike', rbac: false, multitenancy: false);

		$this->assertSame(['%ja\\%ne%', 'email'], $this->params);
		$this->assertCount(2, $hits);
		$this->assertSame($a, $hits[0]['object']);
		$this->assertSame($seven, $hits[1]['object']);
		$this->assertSame(
			[
				['type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detectedAt' => '2026-01-01'],
				['type' => 'email', 'value' => 'other@example.org', 'category' => 'pii', 'detectedAt' => '2026-01-01'],
			],
			$hits[0]['gdprEntities']
		);
		$this->assertSame([['a', false, false], [7, false, false], ['denied', false, false]], $this->finds);

		$data = $this->buildService()->findSubjectData(subjectId: 'jane');
		$this->assertCount(2, $data);
		$this->assertSame($a->jsonSerialize(), $data[0]['object']);
		$this->assertTrue($this->finds[3][1]);
		$this->assertTrue($this->finds[3][2]);

	}//end testFindSubjectObjectsGroupsAndScopes()

	/**
	 * A failing index query yields no hits and no loads.
	 *
	 * @return void
	 */
	public function testIndexFailureYieldsNothing(): void {
		$this->wireIndex(null);

		$summary = $this->buildService()->erase(subjectId: 'jane@example.org');

		$this->assertSame(0, $summary['matchedCount']);
		$this->assertTrue($summary['complete']);
		$this->assertSame([], $this->finds);

	}//end testIndexFailureYieldsNothing()

	/**
	 * Export envelope shape.
	 *
	 * @return void
	 */
	public function testAssembleAccessExport(): void {
		$this->wireIndex([$this->row('a')]);
		$this->object('a');

		$bundle = $this->buildService()->assembleAccessExport(subjectId: ' jane@example.org ', type: 'email');

		$this->assertSame(['subject', 'type', 'generatedAt', 'objectCount', 'objects'], array_keys($bundle));
		$this->assertSame('jane@example.org', $bundle['subject']);
		$this->assertSame('email', $bundle['type']);
		$this->assertSame(1, $bundle['objectCount']);

	}//end testAssembleAccessExport()

	/**
	 * Rectify: allowed write is attributed and scoped; miss, immutable and save failure refuse.
	 *
	 * @return void
	 */
	public function testRectifyAllowedAndRefused(): void {
		$service = $this->buildService();
		$a = $this->object('a', 'plain', ['email' => 'old', 'name' => 'n']);
		$frozen = $this->object('frozen-2');
		$this->object('broken');
		$this->failingSaves = ['broken'];

		$result = $service->rectify(objectIdentifier: 'a', changes: ['email' => 'new']);
		$this->assertSame($a->jsonSerialize(), $result);
		$this->assertSame(['email' => 'new', 'name' => 'n'], $this->payload(object: $a));
		$this->assertSame('activity-uuid', $a->getProcessingActivityId());

		$this->assertNull($service->rectify(objectIdentifier: 'nope', changes: ['email' => 'x']));
		$this->assertNull($service->rectify(objectIdentifier: 'frozen-2', changes: ['email' => 'x']));
		$this->assertSame(['email' => 'jane@example.org'], $this->payload(object: $frozen));
		$this->assertNull($service->rectify(objectIdentifier: 'broken', changes: ['email' => 'x']));

		$this->assertSame([['a', true, true], ['broken', true, true]], $this->saves);
		$this->assertSame([['a', true, true], ['nope', true, true], ['frozen-2', true, true], ['broken', true, true]], $this->finds);

	}//end testRectifyAllowedAndRefused()

	/**
	 * Restriction / objection markers: shape, and refusal on miss / immutable.
	 *
	 * @return void
	 */
	public function testMarkersAllowedAndRefused(): void {
		$service = $this->buildService();
		$a = $this->object('a');
		$frozen = $this->object('frozen-3');

		$this->assertNotNull($service->setRestriction(objectIdentifier: 'a', restricted: true, reason: 'contested'));
		$this->assertNotNull($service->setObjection(objectIdentifier: 'a', objected: false, reason: 'withdrawn'));
		$payload = $this->payload(object: $a);
		$this->assertSame(['active', 'reason', 'setBy', 'setAt'], array_keys($payload['_gdprRestriction']));
		$this->assertTrue($payload['_gdprRestriction']['active']);
		$this->assertSame('contested', $payload['_gdprRestriction']['reason']);
		$this->assertSame('handler1', $payload['_gdprRestriction']['setBy']);
		$this->assertFalse($payload['_gdprObjection']['active']);
		$this->assertSame('withdrawn', $payload['_gdprObjection']['reason']);
		$this->assertSame('activity-uuid', $a->getProcessingActivityId());

		$this->assertNull($service->setRestriction(objectIdentifier: 'nope', restricted: true, reason: 'r'));
		$this->assertNull($service->setObjection(objectIdentifier: 'frozen-3', objected: true, reason: 'r'));
		$this->assertArrayNotHasKey('_gdprObjection', $this->payload(object: $frozen));
		$this->assertSame([['a', true, true], ['a', true, true]], $this->saves);

	}//end testMarkersAllowedAndRefused()

	/**
	 * pseudonymiseObject scrubs, attributes and persists; returns null on save failure.
	 *
	 * @return void
	 */
	public function testPseudonymiseObject(): void {
		$service = $this->buildService();
		$a = $this->object('a', 'plain', ['email' => 'jane@example.org', 'tel' => '0612']);
		$b = $this->object('broken');
		$this->failingSaves = ['broken'];

		$result = $service->pseudonymiseObject(object: $a, subjectId: 'jane@example.org', matched: [['value' => '0612'], ['value' => '']]);
		$this->assertSame($a->jsonSerialize(), $result);
		$this->assertSame(['email' => '[erased]', 'tel' => '[erased]'], $this->payload(object: $a));
		$this->assertSame('activity-uuid', $a->getProcessingActivityId());

		$this->assertNull($service->pseudonymiseObject(object: $b, subjectId: 'jane@example.org', matched: []));
		$this->assertSame([['a', true, true], ['broken', true, true]], $this->saves);

	}//end testPseudonymiseObject()
}//end class
