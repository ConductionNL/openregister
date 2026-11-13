<?php

/**
 * DataSubjectRequestService over both discovery sources: records found only
 * through a schema-declared subject id field reach the access export and the
 * erasure, an object found by both sources carries both reasons, and a record
 * the handler may not read stays out.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectSearchInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Archival\ArchivalSchemaLookup;
use OCA\OpenRegister\Service\DsarService;
use OCA\OpenRegister\Service\Gdpr\DataSubjectDeadline;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\ErasureRequest;
use OCA\OpenRegister\Service\Gdpr\ProcessingAnnotationReader;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectDeclaredFieldIndex;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectEntityIndex;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectLoader;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectWriter;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectRetentionGate;
use OCA\OpenRegister\Service\Gdpr\SubjectQuery;
use OCA\OpenRegister\Service\Gdpr\SubjectRecords;
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
 * Tests for discovery through schema-declared subject id fields.
 *
 * @covers \OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService
 * @covers \OCA\OpenRegister\Service\Gdpr\Subject\SubjectDeclaredFieldIndex
 * @uses   \OCA\OpenRegister\Service\Gdpr\ProcessingAnnotationReader
 * @uses   \OCA\OpenRegister\Service\Gdpr\SubjectRecords
 * @uses   \OCA\OpenRegister\Service\Gdpr\SubjectQuery
 * @uses   \OCA\OpenRegister\Service\Gdpr\SubjectMatch
 * @uses   \OCA\OpenRegister\Service\Gdpr\ErasureRequest
 * @uses   \OCA\OpenRegister\Service\Gdpr\ErasureRun
 * @uses   \OCA\OpenRegister\Service\Gdpr\DataSubjectDeadline
 * @uses   \OCA\OpenRegister\Service\Gdpr\Subject\SubjectEntityIndex
 * @uses   \OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectLoader
 * @uses   \OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectWriter
 * @uses   \OCA\OpenRegister\Service\Gdpr\Subject\SubjectRetentionGate
 * @uses   \OCA\OpenRegister\Service\Gdpr\Subject\SubjectWriteLabel
 * @uses   \OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard
 * @uses   \OCA\OpenRegister\Service\Archival\ArchivalSchemaLookup
 *
 * @spec openspec/changes/dsar-finds-records-by-declared-subject-id/specs/gdpr-data-subject-rights/spec.md
 */
class DataSubjectRequestServiceDeclaredFieldsTest extends TestCase {

	/**
	 * The learner every test asks about.
	 *
	 * @var string
	 */
	private const LEARNER = '5b1c0000-0000-4000-8000-000000000001';

	/**
	 * Entity index join connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private $db;

	/**
	 * Scoped object loads.
	 *
	 * @var MagicMapper&MockObject
	 */
	private $objectMapper;

	/**
	 * The declared-field search.
	 *
	 * @var MagicObjectSearchInterface&MockObject
	 */
	private $search;

	/**
	 * The audited write path.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Legal holds.
	 *
	 * @var RetentionService&MockObject
	 */
	private $retentionService;

	/**
	 * The scoping each declared-field search ran with.
	 *
	 * @var list<array{0: bool, 1: bool}>
	 */
	private array $searchScopes = [];

	/**
	 * Subject under test.
	 *
	 * @var DataSubjectRequestService
	 */
	private DataSubjectRequestService $service;

	/**
	 * Build the service over both sources.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->search = $this->createMock(MagicObjectSearchInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->retentionService = $this->createMock(RetentionService::class);
		$this->retentionService->method('validateNotImmutable')->willReturn(null);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$enrolment = new Schema();
		$enrolment->setId(7);
		$enrolment->setSlug('inschrijving');
		$enrolment->setConfiguration(['x-openregister-processing' => ['subjectIdFields' => ['learnerId' => 'learnerId']]]);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willReturn([$enrolment]);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('getAllRegisterIdsWithSchema')->willReturn([1]);
		$archivalSchemas = $this->createMock(SchemaMapper::class);
		$archivalSchemas->method('find')->willReturn($enrolment);

		$this->service = new DataSubjectRequestService(
			records: new SubjectRecords(
				index: new SubjectEntityIndex(db: $this->db, logger: $logger),
				loader: new SubjectObjectLoader(objectMapper: $this->objectMapper, logger: $logger),
				writer: new SubjectObjectWriter(
					objectService: $this->objectService,
					dsarService: $this->createMock(DsarService::class),
					userSession: $session,
					logger: $logger
				),
				declaredFields: new SubjectDeclaredFieldIndex(
					schemas: $schemas,
					registers: $registers,
					objects: $this->search,
					reader: new ProcessingAnnotationReader(),
					logger: $logger
				)
			),
			retentionGate: new SubjectRetentionGate(
				retentionService: $this->retentionService,
				archivalGuard: new ArchivalRetentionGuard(new ArchivalSchemaLookup($archivalSchemas, $logger))
			),
			deadline: new DataSubjectDeadline(),
			logger: $logger
		);
	}//end setUp()

	/**
	 * The entity index join answers these rows.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return void
	 */
	private function entityRows(array $rows): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['selectDistinct', 'from', 'innerJoin', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$cursor = $this->createMock(IResult::class);
		$cursor->method('fetchAll')->willReturn($rows);
		$qb->method('executeQuery')->willReturn($cursor);
		$this->db->method('getQueryBuilder')->willReturn($qb);
		$this->db->method('escapeLikeParameter')->willReturnArgument(0);
	}//end entityRows()

	/**
	 * The declared-field search answers these objects; the scoped load answers the readable ones.
	 *
	 * @param list<ObjectEntity> $found    What the field search finds.
	 * @param list<ObjectEntity> $readable What the handler may load, by uuid.
	 *
	 * @return void
	 */
	private function objects(array $found, array $readable): void {
		$this->search->method('searchObjects')->willReturnCallback(
			function (array $query = [], ?string $_activeOrgUuid = null, bool $_rbac = true, bool $_multitenancy = true) use ($found): array {
				$this->searchScopes[] = [$_rbac, $_multitenancy];
				return $found;
			}
		);
		$byUuid = [];
		foreach ($readable as $object) {
			$byUuid[$object->getUuid()] = $object;
		}

		$this->objectMapper->method('find')->willReturnCallback(
			static function ($identifier) use ($byUuid): ObjectEntity {
				if (isset($byUuid[$identifier]) === false) {
					throw new DoesNotExistException('not found or not authorised');
				}

				return $byUuid[$identifier];
			}
		);
	}//end objects()

	/**
	 * An enrolment of the learner.
	 *
	 * @param string $uuid The enrolment.
	 *
	 * @return ObjectEntity
	 */
	private function enrolment(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema('7');
		$object->setObject(['learnerId' => self::LEARNER, 'course' => 'Wiskunde']);
		return $object;
	}//end enrolment()

	/**
	 * Enrolments with no detected entity are in the access export, each with its declared field as the reason.
	 *
	 * @return void
	 */
	public function testEnrolmentsKeyedOnTheLearnerAreInTheAccessExport(): void {
		$this->entityRows([]);
		$enrolments = [$this->enrolment('e1'), $this->enrolment('e2'), $this->enrolment('e3')];
		$this->objects(found: $enrolments, readable: $enrolments);

		$bundle = $this->service->assembleAccessExport(subjectId: self::LEARNER, type: 'learnerId');

		$this->assertSame(3, $bundle['objectCount']);
		foreach ($bundle['objects'] as $envelope) {
			$this->assertSame([['type' => 'learnerId', 'field' => 'learnerId']], $envelope['subjectFields']);
			$this->assertSame([], $envelope['gdprEntities']);
		}

		$this->assertSame([[true, true]], $this->searchScopes);
	}//end testEnrolmentsKeyedOnTheLearnerAreInTheAccessExport()

	/**
	 * An object found by both sources is listed once, with both reasons.
	 *
	 * @return void
	 */
	public function testAnObjectFoundByBothSourcesCarriesBothReasons(): void {
		$this->entityRows(
			[
				['id' => 1, 'type' => 'email', 'value' => self::LEARNER, 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'e1'],
				['id' => 2, 'type' => 'email', 'value' => self::LEARNER, 'category' => 'pii', 'detected_at' => '', 'object_id' => 11, 'object_uuid' => 'other'],
			]
		);
		$e1 = $this->enrolment('e1');
		$other = $this->enrolment('other');
		$this->objects(found: [$e1], readable: [$e1, $other]);

		$results = $this->service->findSubjectData(new SubjectQuery(subjectId: self::LEARNER));

		$this->assertCount(2, $results);
		$this->assertSame('email', $results[0]['gdprEntities'][0]['type']);
		$this->assertSame([['type' => 'learnerId', 'field' => 'learnerId']], $results[0]['subjectFields']);
		$this->assertSame([], $results[1]['subjectFields']);
	}//end testAnObjectFoundByBothSourcesCarriesBothReasons()

	/**
	 * An enrolment the handler may not read is not returned, even when the field search found it.
	 *
	 * @return void
	 */
	public function testARecordTheHandlerMayNotReadStaysOut(): void {
		$this->entityRows([]);
		$mine = $this->enrolment('mine');
		$theirs = $this->enrolment('other-organisation');
		$this->objects(found: [$mine, $theirs], readable: [$mine]);

		$results = $this->service->findSubjectObjects(new SubjectQuery(subjectId: self::LEARNER));

		$this->assertSame(['mine'], array_map(static fn (array $hit): string => $hit['object']->getUuid(), $results));
	}//end testARecordTheHandlerMayNotReadStaysOut()

	/**
	 * A partial id in ilike mode does not find a record through its declared field.
	 *
	 * @return void
	 */
	public function testNoFuzzyMatchOnAnId(): void {
		$this->entityRows([]);
		$near = $this->enrolment('near');
		$near->setObject(['learnerId' => '5b1c-aaaa']);
		$this->objects(found: [$near], readable: [$near]);

		$this->assertSame([], $this->service->findSubjectData(new SubjectQuery(subjectId: '5b1c', mode: 'ilike')));
	}//end testNoFuzzyMatchOnAnId()

	/**
	 * Pseudonymising reaches the enrolments keyed on the id; the held one is reported and left unchanged.
	 *
	 * @return void
	 */
	public function testAnErasureReachesRecordsKeyedOnTheId(): void {
		$this->entityRows([]);
		$free1 = $this->enrolment('free1');
		$free2 = $this->enrolment('free2');
		$held = $this->enrolment('held');
		$this->objects(found: [$free1, $free2, $held], readable: [$free1, $free2, $held]);
		$this->retentionService->method('hasActiveLegalHold')->willReturnCallback(static fn (ObjectEntity $object): bool => $object->getUuid() === 'held');
		$written = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use (&$written) {
				$written[] = $object->getUuid();
				return $object;
			}
		);

		$summary = $this->service->erase(new ErasureRequest(subjectId: self::LEARNER, eraseMode: DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE));

		$this->assertSame(3, $summary['matchedCount']);
		$this->assertSame(['free1', 'free2'], $written);
		$this->assertSame('[erased]', $free1->getObject()['learnerId']);
		$this->assertSame('Wiskunde', $free1->getObject()['course']);
		$this->assertSame('[erased]', $free2->getObject()['learnerId']);
		$this->assertSame('held', $summary['held'][0]['uuid']);
		$this->assertSame(self::LEARNER, $held->getObject()['learnerId']);
	}//end testAnErasureReachesRecordsKeyedOnTheId()
}//end class
