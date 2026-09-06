<?php

declare(strict_types=1);

/**
 * DataSubjectRequestService Unit Tests
 *
 * Verifies the consumable, RBAC + tenant scoped GDPR data-subject-rights
 * service: cross-register discovery (RBAC-scoped — unauthorised objects
 * dropped), access-export assembly with matched PII attributes, erasure
 * that honours legal hold / immutable status (reported as `held`, never
 * erased), erase-mode selection (whole-object soft-delete vs field-level
 * pseudonymise), dry-run, and DSAR processing-activity attribution on
 * fulfilment writes (the immutable-audit contract).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\DsarService;
use OCA\OpenRegister\Service\Gdpr\DataSubjectDeadline;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RetentionService;
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

/**
 * Test class for DataSubjectRequestService.
 */
class DataSubjectRequestServiceTest extends TestCase {

	/**
	 * DB connection mock.
	 *
	 * @var IDBConnection&MockObject
	 */
	private $db;

	/**
	 * Object mapper mock (RBAC-scoped reads).
	 *
	 * @var MagicMapper&MockObject
	 */
	private $objectMapper;

	/**
	 * Object service mock (audited write path).
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Retention service mock.
	 *
	 * @var RetentionService&MockObject
	 */
	private $retentionService;

	/**
	 * DSAR service mock (only the activity-uuid getter is used).
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
	 * Schema mapper mock backing the real archival guard.
	 *
	 * Schemas are REAL entities, so the archival decision comes from the
	 * production predicate Schema::hasArchivalAnnotation() and a test cannot
	 * agree with itself about what "archival" means.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * Subject under test.
	 *
	 * @var DataSubjectRequestService
	 */
	private DataSubjectRequestService $service;

	/**
	 * Set up mocks and the SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->retentionService = $this->createMock(RetentionService::class);
		$this->dsarService = $this->createMock(DsarService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler1');
		$this->userSession->method('getUser')->willReturn($user);

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		// Default: every schema resolves and is NOT archival, so the existing
		// tests keep exercising the erasure path they were written for.
		$this->schemaMapper->method('find')->willReturnCallback(
			fn ($id) => $this->makeSchema(identifier: (string)$id, archival: false)
		);

		$this->service = new DataSubjectRequestService(
			$this->db,
			$this->objectMapper,
			$this->objectService,
			$this->retentionService,
			$this->dsarService,
			new DataSubjectDeadline(),
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			new ArchivalRetentionGuard(
				$this->schemaMapper,
				$this->createMock(LoggerInterface::class)
			)
		);

	}//end setUp()

	/**
	 * Build a REAL Schema, archival or not.
	 *
	 * The annotation is written as data and read back by the production
	 * predicate. Nothing here restates what "archival" means.
	 *
	 * @param string $identifier Schema slug.
	 * @param bool $archival Whether to declare `x-openregister-archival`.
	 *
	 * @return Schema
	 */
	private function makeSchema(string $identifier, bool $archival): Schema {
		$schema = new Schema();
		$schema->setSlug($identifier);
		$configuration = [];
		if ($archival === true) {
			$configuration['x-openregister-archival'] = ['retention' => ['default' => 'P10Y']];
		}

		$schema->setConfiguration($configuration);

		return $schema;
	}//end makeSchema()

	/**
	 * Wire the GdprEntity ⋈ entity_relations join to return $rows.
	 *
	 * @param list<array<string, mixed>> $rows Rows the index join yields.
	 *
	 * @return void
	 */
	private function wireIndexJoin(array $rows): void {
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
		$qb->method('createNamedParameter')->willReturn(':p');

		$cursor = $this->createMock(IResult::class);
		$cursor->method('fetchAll')->willReturn($rows);

		$qb->method('executeQuery')->willReturn($cursor);

		$this->db->method('getQueryBuilder')->willReturn($qb);
		$this->db->method('escapeLikeParameter')->willReturnArgument(0);

	}//end wireIndexJoin()

	/**
	 * Build an ObjectEntity carrying a payload + uuid.
	 *
	 * @param string $uuid Object uuid.
	 * @param array<string, mixed> $payload Object payload.
	 * @param string $schema Schema identifier the object belongs to.
	 *
	 * @return ObjectEntity
	 */
	private function buildObject(string $uuid, array $payload, string $schema = 'schema'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('reg');
		$object->setSchema($schema);
		$object->setObject($payload);
		return $object;
	}//end buildObject()

	/**
	 * findSubjectData returns objects across two registers, dropping the
	 * one the caller is not authorised to read (RBAC scoping).
	 *
	 * @return void
	 */
	public function testFindSubjectDataIsRbacScopedAcrossRegisters(): void {
		$this->wireIndexJoin(
			[
				['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'uuid-a'],
				['id' => 2, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 20, 'object_uuid' => 'uuid-b'],
			]
		);

		$authorised = $this->buildObject('uuid-a', ['email' => 'jane@example.org']);

		// uuid-a (register 1) is authorised; uuid-b (register 2) is denied
		// (the RBAC-scoped find throws — mirrors a not-authorised load).
		$this->objectMapper->method('find')->willReturnCallback(
			function ($identifier) use ($authorised) {
				if ($identifier === 'uuid-a') {
					return $authorised;
				}

				throw new DoesNotExistException('not authorised');
			}
		);

		$results = $this->service->findSubjectData(subjectId: 'jane@example.org');

		$this->assertCount(1, $results);
		$this->assertSame('uuid-a', $results[0]['object']['@self']['id'] ?? $results[0]['object']['id'] ?? 'uuid-a');
		$this->assertSame('email', $results[0]['gdprEntities'][0]['type']);

	}//end testFindSubjectDataIsRbacScopedAcrossRegisters()

	/**
	 * assembleAccessExport bundles the subject's data + matched PII.
	 *
	 * @return void
	 */
	public function testAssembleAccessExportBuildsBundle(): void {
		$this->wireIndexJoin(
			[
				['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '2026-01-01', 'object_id' => 10, 'object_uuid' => 'uuid-a'],
			]
		);
		$this->objectMapper->method('find')->willReturn($this->buildObject('uuid-a', ['email' => 'jane@example.org']));

		$bundle = $this->service->assembleAccessExport(subjectId: 'jane@example.org');

		$this->assertSame('jane@example.org', $bundle['subject']);
		$this->assertSame(1, $bundle['objectCount']);
		$this->assertCount(1, $bundle['objects']);
		$this->assertSame('email', $bundle['objects'][0]['gdprEntities'][0]['type']);

	}//end testAssembleAccessExportBuildsBundle()

	/**
	 * RETENTION WINS OVER ERASURE, PER RECORD.
	 *
	 * An erasure request reaching a mixed set withholds ONLY the records held
	 * under an archival obligation, and reports each one back with the ground
	 * and the wording the handler passes to the data subject. The non-archival
	 * record in the same request is still erased: one retained row must never
	 * block a lawful erasure of the rest.
	 *
	 * The archival decision is made by the production predicate
	 * Schema::hasArchivalAnnotation(), reading a real Schema built here. Break
	 * that method and this test fails; it has no copy of the rule to fall back on.
	 *
	 * @return void
	 */
	public function testEraseWithholdsOnlyTheArchivalRecordsAndReportsThem(): void {
		$this->wireIndexJoin(
			[
				['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'ordinary'],
				['id' => 2, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 20, 'object_uuid' => 'retained'],
			]
		);

		$ordinary = $this->buildObject('ordinary', ['email' => 'jane@example.org'], 'contact');
		$retained = $this->buildObject('retained', ['email' => 'jane@example.org'], 'besluit');
		$this->objectMapper->method('find')->willReturnCallback(
			static fn ($id) => ($id === 'ordinary') ? $ordinary : $retained
		);

		// `besluit` carries the archival annotation; `contact` does not.
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willReturnCallback(
			fn ($id) => $this->makeSchema(identifier: (string)$id, archival: ((string)$id === 'besluit'))
		);
		$this->rebuildServiceWithSchemaMapper();

		// Nothing stands in the way except the archival obligation.
		$this->retentionService->method('hasActiveLegalHold')->willReturn(false);
		$this->retentionService->method('validateNotImmutable')->willReturn(null);

		// EXACTLY ONE WRITE. The retained record is never handed to the audited
		// save path at all, so it cannot be tombstoned on the way past.
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function ($object) use ($ordinary) {
				$this->assertSame($ordinary, $object);
				return $ordinary;
			}
		);

		$summary = $this->service->erase(
			subjectId: 'jane@example.org',
			eraseMode: DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT
		);

		$this->assertSame(2, $summary['matchedCount']);

		// The lawful half of the request still ran.
		$this->assertCount(1, $summary['erased']);

		// The retained half is REFUSED, RECORDED and REPORTED.
		$this->assertCount(1, $summary['withheld']);
		$this->assertSame(1, $summary['withheldCount']);
		$withheld = $summary['withheld'][0];
		$this->assertSame('retained', $withheld['uuid']);
		$this->assertSame('besluit', $withheld['schema']);
		$this->assertSame(
			ArchivalRetentionGuard::GROUND_ARCHIVAL,
			$withheld['ground']
		);

		// The report carries words a handler can pass on, and a next step.
		$this->assertSame(
			'The law requires us to keep this record, so we did not erase it.',
			$withheld['message']
		);
		$this->assertStringContainsString('art. 17(3)(b)', $withheld['basis']);
		$this->assertStringContainsString('Archiefwet', $withheld['basis']);
		$this->assertNotSame('', trim($withheld['action']));

		// AND THE ANSWER IS NOT "DONE". Telling the data subject the erasure is
		// complete while a record is still there is the failure this bucket exists
		// to prevent.
		$this->assertFalse($summary['complete']);

		// The retained record was left exactly as it was: no erasure stamp on it.
		$this->assertEmpty($retained->getDeleted());

	}//end testEraseWithholdsOnlyTheArchivalRecordsAndReportsThem()

	/**
	 * A record whose schema cannot be resolved is left alone, under its own ground.
	 *
	 * FAILS CLOSED. An unresolvable schema is exactly the case where the
	 * annotation cannot be read, so the record might be retained. It is reported
	 * separately from a real archival hold, because the handler's next step
	 * differs: repair the schema, then run the request again.
	 *
	 * @return void
	 */
	public function testEraseLeavesARecordAloneWhenItsSchemaCannotBeResolved(): void {
		$this->wireIndexJoin(
			[
				['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'orphan'],
			]
		);

		$orphan = $this->buildObject('orphan', ['email' => 'jane@example.org'], 'gone');
		$this->objectMapper->method('find')->willReturn($orphan);

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willThrowException(
			new DoesNotExistException('schema gone')
		);
		$this->rebuildServiceWithSchemaMapper();

		$this->retentionService->method('hasActiveLegalHold')->willReturn(false);
		$this->retentionService->method('validateNotImmutable')->willReturn(null);

		$this->objectService->expects($this->never())->method('saveObject');

		$summary = $this->service->erase(
			subjectId: 'jane@example.org',
			eraseMode: DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT
		);

		$this->assertCount(0, $summary['erased']);
		$this->assertCount(1, $summary['withheld']);
		$this->assertSame(
			ArchivalRetentionGuard::GROUND_UNRESOLVED,
			$summary['withheld'][0]['ground']
		);
		$this->assertFalse($summary['complete']);
		$this->assertEmpty($orphan->getDeleted());

	}//end testEraseLeavesARecordAloneWhenItsSchemaCannotBeResolved()

	/**
	 * Rebuild the SUT after swapping in a differently-wired schema mapper.
	 *
	 * @return void
	 */
	private function rebuildServiceWithSchemaMapper(): void {
		$this->service = new DataSubjectRequestService(
			$this->db,
			$this->objectMapper,
			$this->objectService,
			$this->retentionService,
			$this->dsarService,
			new DataSubjectDeadline(),
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			new ArchivalRetentionGuard(
				$this->schemaMapper,
				$this->createMock(LoggerInterface::class)
			)
		);
	}//end rebuildServiceWithSchemaMapper()

	/**
	 * erase skips an object under legal hold (reported `held`, not erased)
	 * and erases the unheld one.
	 *
	 * A run that held a record back is NOT complete. See the comment on the
	 * `complete` assertion below for why that expectation was inverted.
	 *
	 * @return void
	 */
	public function testEraseRespectsLegalHold(): void {
		$this->wireIndexJoin(
			[
				['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'free'],
				['id' => 2, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 20, 'object_uuid' => 'held'],
			]
		);

		$free = $this->buildObject('free', ['email' => 'jane@example.org']);
		$held = $this->buildObject('held', ['email' => 'jane@example.org']);
		$this->objectMapper->method('find')->willReturnCallback(
			static fn ($id) => ($id === 'free') ? $free : $held
		);

		// `held` is under an active legal hold; `free` is not.
		$this->retentionService->method('hasActiveLegalHold')->willReturnCallback(
			static fn (ObjectEntity $o) => $o->getUuid() === 'held'
		);
		$this->retentionService->method('validateNotImmutable')->willReturn(null);

		// Only the free object is written (erased) via the audited save path.
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function ($object) use ($free) {
				$this->assertSame($free, $object);
				return $free;
			}
		);

		$summary = $this->service->erase(subjectId: 'jane@example.org', eraseMode: DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);

		$this->assertSame(2, $summary['matchedCount']);
		$this->assertCount(1, $summary['erased']);
		$this->assertCount(1, $summary['held']);
		$this->assertSame('held', $summary['held'][0]['uuid']);
		$this->assertSame('legal-hold', $summary['held'][0]['reason']);

		// CHANGED DELIBERATELY. This line used to read
		// `assertTrue($summary['complete'])`, pinning as CORRECT a run that
		// answered "complete" while a record it had refused was still sitting
		// there. That is the same shape 4be2adc found in a controller test
		// asserting `success === true` for a batch that had refused a row: the
		// green came from the summary line, not from the data.
		//
		// An erasure is complete when the data is gone. A held record means it is
		// not, so the request is partial and the handler has something to explain.
		$this->assertFalse($summary['complete']);
		$this->assertSame(1, $summary['heldCount']);

	}//end testEraseRespectsLegalHold()

	/**
	 * Whole-object erase soft-deletes (sets deleted metadata); pseudonymise
	 * mode redacts the subject's matching field values in place.
	 *
	 * @return void
	 */
	public function testEraseModesBehaveDifferently(): void {
		// Whole-object mode → setDeleted is populated.
		$this->wireIndexJoin(
			[['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'obj']]
		);
		$object = $this->buildObject('obj', ['email' => 'jane@example.org', 'name' => 'Jane']);
		$this->objectMapper->method('find')->willReturn($object);
		$this->retentionService->method('hasActiveLegalHold')->willReturn(false);
		$this->retentionService->method('validateNotImmutable')->willReturn(null);
		$this->objectService->method('saveObject')->willReturnArgument(0);

		$this->service->erase(subjectId: 'jane@example.org', eraseMode: DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);
		$this->assertNotNull($object->getDeleted());
		$this->assertSame('gdpr-erasure', $object->getDeleted()['deletedReason']);
		// Whole-object mode leaves the payload intact.
		$this->assertSame('jane@example.org', $object->getObject()['email']);

	}//end testEraseModesBehaveDifferently()

	/**
	 * Pseudonymise mode replaces the subject's matching field values in
	 * place and preserves the object (no soft-delete).
	 *
	 * @return void
	 */
	public function testErasePseudonymiseRedactsMatchingFields(): void {
		$this->wireIndexJoin(
			[['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'obj']]
		);
		$object = $this->buildObject('obj', ['email' => 'jane@example.org', 'note' => 'unrelated']);
		$this->objectMapper->method('find')->willReturn($object);
		$this->retentionService->method('hasActiveLegalHold')->willReturn(false);
		$this->retentionService->method('validateNotImmutable')->willReturn(null);
		$this->objectService->method('saveObject')->willReturnArgument(0);

		$this->service->erase(subjectId: 'jane@example.org', eraseMode: DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE);

		$payload = $object->getObject();
		$this->assertSame('[erased]', $payload['email']);
		$this->assertSame('unrelated', $payload['note']);
		// Pseudonymise preserves the object — it is NOT soft-deleted.
		$this->assertArrayNotHasKey('deletedReason', (array)$object->getDeleted());

	}//end testErasePseudonymiseRedactsMatchingFields()

	/**
	 * A dry-run reports matches but mutates nothing.
	 *
	 * @return void
	 */
	public function testEraseDryRunMutatesNothing(): void {
		$this->wireIndexJoin(
			[['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'obj']]
		);
		$object = $this->buildObject('obj', ['email' => 'jane@example.org']);
		$this->objectMapper->method('find')->willReturn($object);
		$this->retentionService->method('hasActiveLegalHold')->willReturn(false);
		$this->retentionService->method('validateNotImmutable')->willReturn(null);

		// saveObject() must never be called on a dry run.
		$this->objectService->expects($this->never())->method('saveObject');

		$summary = $this->service->erase(subjectId: 'jane@example.org', dryRun: true);

		$this->assertTrue($summary['dryRun']);
		$this->assertCount(1, $summary['erased']);
		// Dry run mutates nothing — no soft-delete metadata written.
		$this->assertArrayNotHasKey('deletedReason', (array)$object->getDeleted());

	}//end testEraseDryRunMutatesNothing()

	/**
	 * Fulfilment writes carry the configured DSAR processing-activity
	 * attribution so the immutable audit trail records them.
	 *
	 * @return void
	 */
	public function testRectifyAttributesDsarProcessingActivity(): void {
		$object = $this->buildObject('obj', ['email' => 'old@example.org']);
		$this->objectMapper->method('find')->willReturn($object);
		$this->retentionService->method('validateNotImmutable')->willReturn(null);
		$this->dsarService->method('getDsarProcessingActivityUuid')->willReturn('activity-uuid');
		$this->objectService->method('saveObject')->willReturnArgument(0);

		$result = $this->service->rectify(objectIdentifier: 'obj', changes: ['email' => 'new@example.org']);

		$this->assertNotNull($result);
		$this->assertSame('activity-uuid', $object->getProcessingActivityId());
		$this->assertSame('new@example.org', $object->getObject()['email']);

	}//end testRectifyAttributesDsarProcessingActivity()

	/**
	 * Rectify is refused on an immutable (archived/destroyed) object.
	 *
	 * @return void
	 */
	public function testRectifyRefusedOnImmutableObject(): void {
		$object = $this->buildObject('obj', ['email' => 'x@example.org']);
		$this->objectMapper->method('find')->willReturn($object);
		$this->retentionService->method('validateNotImmutable')->willReturn('OBJECT_DESTROYED');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertNull($this->service->rectify(objectIdentifier: 'obj', changes: ['email' => 'y@example.org']));

	}//end testRectifyRefusedOnImmutableObject()

	/**
	 * Deadline pass-throughs delegate to DataSubjectDeadline.
	 *
	 * @return void
	 */
	public function testDeadlinePassThroughs(): void {
		$received = new \DateTimeImmutable('2026-01-10T00:00:00+00:00');
		$due = $this->service->computeDueAt($received);
		$this->assertSame('2026-02-10T00:00:00+00:00', $due->format('c'));

		$extended = $this->service->extend($due);
		$this->assertSame('2026-04-10T00:00:00+00:00', $extended->format('c'));

		$this->assertTrue($this->service->isOverdue($due, new \DateTimeImmutable('2026-03-01T00:00:00+00:00')));
		$this->assertFalse($this->service->isOverdue($due, new \DateTimeImmutable('2026-01-15T00:00:00+00:00')));

	}//end testDeadlinePassThroughs()
}//end class
