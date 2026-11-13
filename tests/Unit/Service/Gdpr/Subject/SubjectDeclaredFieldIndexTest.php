<?php

/**
 * SubjectDeclaredFieldIndex tests: only declaring schemas are searched, in
 * every register they live in, exactly, scoped, by type, and a failure finds
 * nothing rather than throwing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Subject
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Subject;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectSearchInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Gdpr\ProcessingAnnotationReader;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectDeclaredFieldIndex;
use OCA\OpenRegister\Service\Gdpr\SubjectQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SubjectDeclaredFieldIndex.
 *
 * @covers \OCA\OpenRegister\Service\Gdpr\Subject\SubjectDeclaredFieldIndex
 * @uses   \OCA\OpenRegister\Service\Gdpr\ProcessingAnnotationReader
 * @uses   \OCA\OpenRegister\Service\Gdpr\SubjectQuery
 */
class SubjectDeclaredFieldIndexTest extends TestCase {

	/**
	 * The subject id every test asks for.
	 *
	 * @var string
	 */
	private const LEARNER = '5b1c0000-0000-4000-8000-000000000001';

	/**
	 * Schema mapper mock.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private $schemas;

	/**
	 * Register mapper mock.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private $registers;

	/**
	 * Object search mock.
	 *
	 * @var MagicObjectSearchInterface&MockObject
	 */
	private $objects;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Every search the index ran: query, rbac, multitenancy.
	 *
	 * @var list<array{0: array, 1: bool, 2: bool}>
	 */
	private array $searches = [];

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->objects = $this->createMock(MagicObjectSearchInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A real schema with this configuration.
	 *
	 * @param int                  $id     The schema id.
	 * @param array<string, mixed> $config The configuration.
	 *
	 * @return Schema
	 */
	private function schema(int $id, array $config): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setConfiguration($config);
		return $schema;
	}//end schema()

	/**
	 * A real object with this uuid and payload.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $payload): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($payload);
		return $object;
	}//end object()

	/**
	 * Answer searches from a map of "register/schema/field" to objects.
	 *
	 * @param array<string, list<ObjectEntity>> $answers The objects per search.
	 *
	 * @return void
	 */
	private function answer(array $answers): void {
		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query = [], ?string $_activeOrgUuid = null, bool $_rbac = true, bool $_multitenancy = true) use ($answers) {
				$this->searches[] = [$query, $_rbac, $_multitenancy];
				$fields = array_diff(array_keys($query), ['@self', '_limit']);
				$key = $query['@self']['register'] . '/' . $query['@self']['schema'] . '/' . reset($fields);
				return ($answers[$key] ?? []);
			}
		);
	}//end answer()

	/**
	 * The index under test.
	 *
	 * @return SubjectDeclaredFieldIndex
	 */
	private function index(): SubjectDeclaredFieldIndex {
		return new SubjectDeclaredFieldIndex(
			schemas: $this->schemas,
			registers: $this->registers,
			objects: $this->objects,
			reader: new ProcessingAnnotationReader(),
			logger: $this->logger,
		);
	}//end index()

	/**
	 * Only a declaring schema is searched, in each of its registers, exactly and scoped.
	 *
	 * @return void
	 */
	public function testADeclaringSchemaIsSearchedInEachRegisterScoped(): void {
		$this->schemas->method('findAll')->willReturn(
			[
				$this->schema(id: 7, config: ['x-openregister-processing' => ['subjectIdFields' => ['learnerId' => 'learnerId']]]),
				$this->schema(id: 8, config: ['x-openregister-processing' => ['logReads' => true]]),
				$this->schema(id: 9, config: []),
			]
		);
		$this->registers->method('getAllRegisterIdsWithSchema')->with(7)->willReturn([1, '2']);
		$this->answer(
			[
				'1/7/learnerId' => [$this->object('enrol-1', ['learnerId' => self::LEARNER])],
				'2/7/learnerId' => [$this->object('enrol-2', ['learnerId' => self::LEARNER])],
			]
		);

		$found = $this->index()->discover(query: new SubjectQuery(subjectId: ' ' . self::LEARNER . ' '));

		$this->assertSame(
			[
				'uuid:enrol-1' => ['object_id' => 0, 'object_uuid' => 'enrol-1', 'subjectFields' => [['type' => 'learnerId', 'field' => 'learnerId']]],
				'uuid:enrol-2' => ['object_id' => 0, 'object_uuid' => 'enrol-2', 'subjectFields' => [['type' => 'learnerId', 'field' => 'learnerId']]],
			],
			$found
		);
		$this->assertSame(
			[
				[['@self' => ['register' => 1, 'schema' => 7], 'learnerId' => self::LEARNER, '_limit' => 1000], true, true],
				[['@self' => ['register' => 2, 'schema' => 7], 'learnerId' => self::LEARNER, '_limit' => 1000], true, true],
			],
			$this->searches
		);
	}//end testADeclaringSchemaIsSearchedInEachRegisterScoped()

	/**
	 * The scoping the caller asks for is passed to the search.
	 *
	 * @return void
	 */
	public function testTheCallersScopingIsPassedOn(): void {
		$this->schemas->method('findAll')->willReturn([$this->schema(id: 7, config: ['x-openregister-processing' => ['subjectIdFields' => ['learnerId' => 'learnerId']]])]);
		$this->registers->method('getAllRegisterIdsWithSchema')->willReturn([1]);
		$this->answer([]);

		$this->index()->discover(query: new SubjectQuery(subjectId: self::LEARNER), rbac: false, multitenancy: false);

		$this->assertFalse($this->searches[0][1]);
		$this->assertFalse($this->searches[0][2]);
	}//end testTheCallersScopingIsPassedOn()

	/**
	 * A looser database match never reaches the result: the value must be the id, or a list holding it.
	 *
	 * @return void
	 */
	public function testOnlyAnExactValueOrAListHoldingItMatches(): void {
		$this->schemas->method('findAll')->willReturn([$this->schema(id: 7, config: ['x-openregister-processing' => ['subjectIdFields' => ['learnerId' => 'learners']]])]);
		$this->registers->method('getAllRegisterIdsWithSchema')->willReturn([1]);
		$this->answer(
			[
				'1/7/learners' => [
					$this->object('prefix', ['learners' => '5b1c']),
					$this->object('longer', ['learners' => self::LEARNER . '-aaaa']),
					$this->object('upper', ['learners' => strtoupper(self::LEARNER)]),
					$this->object('list', ['learners' => ['someone-else', self::LEARNER]]),
					$this->object('exact', ['learners' => self::LEARNER]),
					$this->object('absent', []),
				],
			]
		);

		$found = $this->index()->discover(query: new SubjectQuery(subjectId: self::LEARNER, mode: 'ilike'));

		$this->assertSame(['uuid:list', 'uuid:exact'], array_keys($found));
	}//end testOnlyAnExactValueOrAListHoldingItMatches()

	/**
	 * A partial id finds nothing through a declared field, even in ilike mode.
	 *
	 * @return void
	 */
	public function testAPartialIdFindsNothing(): void {
		$this->schemas->method('findAll')->willReturn([$this->schema(id: 7, config: ['x-openregister-processing' => ['subjectIdFields' => ['learnerId' => 'learnerId']]])]);
		$this->registers->method('getAllRegisterIdsWithSchema')->willReturn([1]);
		$this->answer(['1/7/learnerId' => [$this->object('enrol-1', ['learnerId' => '5b1c-aaaa'])]]);

		$this->assertSame([], $this->index()->discover(query: new SubjectQuery(subjectId: '5b1c', mode: 'ilike')));
		$this->assertSame('5b1c', $this->searches[0][0]['learnerId']);
	}//end testAPartialIdFindsNothing()

	/**
	 * With a type only that type's fields are searched; one object found through two fields records both.
	 *
	 * @return void
	 */
	public function testTheTypeFilterAndTwoFieldsOnOneObject(): void {
		$this->schemas->method('findAll')->willReturn(
			[$this->schema(id: 7, config: ['x-openregister-processing' => ['subjectIdFields' => ['learnerId' => 'learnerId', 'mentorId' => 'mentorId']]])]
		);
		$this->registers->method('getAllRegisterIdsWithSchema')->willReturn([1]);
		$both = $this->object('both', ['learnerId' => self::LEARNER, 'mentorId' => self::LEARNER]);
		$both->setId(42);
		$this->answer(['1/7/learnerId' => [$both], '1/7/mentorId' => [$both]]);

		$all = $this->index()->discover(query: new SubjectQuery(subjectId: self::LEARNER));
		$this->assertSame(42, $all['uuid:both']['object_id']);
		$this->assertSame(
			[['type' => 'learnerId', 'field' => 'learnerId'], ['type' => 'mentorId', 'field' => 'mentorId']],
			$all['uuid:both']['subjectFields']
		);

		$this->searches = [];
		$typed = $this->index()->discover(query: new SubjectQuery(subjectId: self::LEARNER, type: 'mentorId'));
		$this->assertSame([['type' => 'mentorId', 'field' => 'mentorId']], $typed['uuid:both']['subjectFields']);
		$this->assertCount(1, $this->searches);
	}//end testTheTypeFilterAndTwoFieldsOnOneObject()

	/**
	 * An object without a uuid is grouped by id; one with neither is dropped.
	 *
	 * @return void
	 */
	public function testObjectsAreGroupedByUuidElseId(): void {
		$this->schemas->method('findAll')->willReturn([$this->schema(id: 7, config: ['x-openregister-processing' => ['subjectIdFields' => ['learnerId' => 'learnerId']]])]);
		$this->registers->method('getAllRegisterIdsWithSchema')->willReturn([1]);
		$byId = new ObjectEntity();
		$byId->setId(5);
		$byId->setObject(['learnerId' => self::LEARNER]);
		$nameless = new ObjectEntity();
		$nameless->setObject(['learnerId' => self::LEARNER]);
		$this->answer(['1/7/learnerId' => [$byId, $nameless, 'not an object']]);

		$this->assertSame(['id:5'], array_keys($this->index()->discover(query: new SubjectQuery(subjectId: self::LEARNER))));
	}//end testObjectsAreGroupedByUuidElseId()

	/**
	 * An empty subject searches nothing.
	 *
	 * @return void
	 */
	public function testAnEmptySubjectSearchesNothing(): void {
		$this->schemas->expects($this->never())->method('findAll');

		$this->assertSame([], $this->index()->discover(query: new SubjectQuery(subjectId: '  ')));
	}//end testAnEmptySubjectSearchesNothing()

	/**
	 * Failing reads are logged without the subject and find nothing; other schemas still answer.
	 *
	 * @return void
	 */
	public function testFailuresFindNothingAndLogNoSubject(): void {
		$this->schemas->method('findAll')->willReturn(
			[
				$this->schema(id: 7, config: ['x-openregister-processing' => ['subjectIdFields' => ['learnerId' => 'learnerId']]]),
				$this->schema(id: 8, config: ['x-openregister-processing' => ['subjectIdFields' => ['learnerId' => 'learnerId']]]),
				$this->schema(id: 9, config: ['x-openregister-processing' => ['subjectIdFields' => ['learnerId' => 'learnerId']]]),
			]
		);
		$this->registers->method('getAllRegisterIdsWithSchema')->willReturnCallback(
			static function (int $schemaId): array {
				if ($schemaId === 8) {
					throw new RuntimeException('register table gone');
				}

				return [1];
			}
		);
		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query) {
				if ($query['@self']['schema'] === 7) {
					throw new RuntimeException('column learnerId missing for ' . self::LEARNER);
				}

				return [$this->object('ok', ['learnerId' => self::LEARNER])];
			}
		);
		$logged = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		$found = $this->index()->discover(query: new SubjectQuery(subjectId: self::LEARNER));

		$this->assertSame(['uuid:ok'], array_keys($found));
		$this->assertSame(
			['[DSR] Declared subject id field search failed', '[DSR] Registers of a schema could not be read'],
			array_column($logged, 0)
		);
		$this->assertStringNotContainsString(self::LEARNER, json_encode($logged));
	}//end testFailuresFindNothingAndLogNoSubject()

	/**
	 * Schemas that cannot be read find nothing; a count answer is not a list of objects.
	 *
	 * @return void
	 */
	public function testUnreadableSchemasAndCountAnswersFindNothing(): void {
		$this->schemas->method('findAll')->willThrowException(new RuntimeException('no schemas'));
		$this->logger->expects($this->once())->method('warning')->with('[DSR] Schemas with subject id fields could not be read');
		$this->assertSame([], $this->index()->discover(query: new SubjectQuery(subjectId: self::LEARNER)));

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willReturn([$this->schema(id: 7, config: ['x-openregister-processing' => ['subjectIdFields' => ['learnerId' => 'learnerId']]])]);
		$this->schemas = $schemas;
		$this->registers->method('getAllRegisterIdsWithSchema')->willReturn([1]);
		$this->objects->method('searchObjects')->willReturn(3);
		$this->assertSame([], $this->index()->discover(query: new SubjectQuery(subjectId: self::LEARNER)));
	}//end testUnreadableSchemasAndCountAnswersFindNothing()
}//end class
