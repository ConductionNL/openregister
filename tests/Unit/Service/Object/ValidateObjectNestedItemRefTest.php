<?php

declare(strict_types=1);

/**
 * ValidateObject nested-item $ref Unit Tests
 *
 * Regression coverage for openregister#2179 as its comments widened it: a
 * relation `$ref` on a property of an OBJECT inside an array's `items` reached
 * Opis untouched, so every write carrying that array failed with
 * `Unresolved reference: schema:///<Slug>#`. A `$ref` on a top-level string
 * property was already stripped (OpenRegister uses it as a relation marker,
 * never as a JSON Schema reference); the same property one level down inside
 * `items.properties` was not.
 *
 * The fixture is learniq's real `ReportCard.subjectGrades` fragment, copied
 * from ConductionNL/learniq `lib/Settings/learniq_register.json` at
 * origin/development (2026-10-02): `curriculumPlanId` and `courseId` carry a
 * `$ref`, and `sourceGradeEntryIds` is an array of `$ref` items nested inside
 * the item object.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for `$ref` on properties of objects inside array items.
 */
class ValidateObjectNestedItemRefTest extends TestCase {
	private const CURRICULUM_PLAN = '11111111-1111-4111-8111-111111111111';
	private const COURSE          = '22222222-2222-4222-8222-222222222222';
	private const GRADE_ENTRY     = '33333333-3333-4333-8333-333333333333';

	private ValidateObject $handler;

	/**
	 * Build a ValidateObject with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('http://localhost:8080');

		$this->handler = new ValidateObject(
			$this->createMock(IAppConfig::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$urlGenerator,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserManager::class)
		);
	}//end setUp()

	/**
	 * Build a Schema entity with the given slug.
	 *
	 * @param string $slug Schema slug.
	 *
	 * @return Schema
	 */
	private function schema(string $slug): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setTitle('Test Schema');
		return $schema;
	}//end schema()

	/**
	 * The ReportCard schema object with learniq's real subjectGrades fragment.
	 *
	 * @return object
	 */
	private function reportCardSchema(): object {
		$fragment = file_get_contents(__DIR__.'/../../../Fixtures/learniq-reportcard-subjectgrades.json');
		$this->assertIsString($fragment, 'fixture must be readable');

		return json_decode(
			json_encode(
				[
					'type' => 'object',
					'properties' => [
						'title' => ['type' => 'string'],
						'subjectGrades' => json_decode($fragment, true),
					],
				]
			)
		);
	}//end reportCardSchema()

	/**
	 * A composed report card with subject grades validates instead of raising
	 * "Unresolved reference: schema:///CurriculumPlan#".
	 *
	 * @return void
	 */
	public function testReportCardWithSubjectGradesValidates(): void {
		$object = [
			'title' => 'Rapport periode 1',
			'subjectGrades' => [
				[
					'curriculumPlanId' => self::CURRICULUM_PLAN,
					'courseId' => self::COURSE,
					'periodAverage' => 7.4,
					'passed' => true,
					'teacherComment' => 'Goed gewerkt',
					'sourceGradeEntryIds' => [self::GRADE_ENTRY],
				],
			],
		];

		$result = $this->handler->validateObject($object, $this->schema('report-card'), $this->reportCardSchema());

		$this->assertTrue(
			$result->isValid(),
			'a $ref on a property of an object inside items must validate like a top-level $ref'
		);
	}//end testReportCardWithSubjectGradesValidates()

	/**
	 * The nested ref still validates as a uuid string, the way a top-level
	 * `$ref` string property does: a non-uuid value is refused with a normal
	 * validation error, not accepted and not a 500.
	 *
	 * @return void
	 */
	public function testNestedRefStillRefusesANonUuid(): void {
		$object = [
			'subjectGrades' => [
				['curriculumPlanId' => 'not a uuid at all!'],
			],
		];

		$result = $this->handler->validateObject($object, $this->schema('report-card'), $this->reportCardSchema());

		$this->assertFalse($result->isValid(), 'a non-uuid in a nested $ref property must still fail validation');
	}//end testNestedRefStillRefusesANonUuid()

	/**
	 * The item object keeps its own shape: its `required` is still enforced,
	 * so the fix strips the relation marker and nothing else.
	 *
	 * @return void
	 */
	public function testItemRequiredIsStillEnforced(): void {
		$object = [
			'subjectGrades' => [
				['courseId' => self::COURSE],
			],
		];

		$result = $this->handler->validateObject($object, $this->schema('report-card'), $this->reportCardSchema());

		$this->assertFalse($result->isValid(), 'items.required (curriculumPlanId) must still be enforced');
	}//end testItemRequiredIsStillEnforced()

	/**
	 * A self-referencing nested ref (learniq Lesson.releaseConditions[].lessonId
	 * pointing at Lesson, as reported on #2179) validates too.
	 *
	 * @return void
	 */
	public function testSelfReferencingNestedRefValidates(): void {
		$schemaObject = json_decode(
			json_encode(
				[
					'type' => 'object',
					'properties' => [
						'releaseConditions' => [
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'required' => ['kind'],
								'properties' => [
									'kind' => ['type' => 'string'],
									'lessonId' => ['type' => 'string', 'format' => 'uuid', '$ref' => 'Lesson'],
								],
							],
						],
					],
				]
			)
		);

		$object = ['releaseConditions' => [['kind' => 'after-lesson', 'lessonId' => self::COURSE]]];

		$result = $this->handler->validateObject($object, $this->schema('lesson'), $schemaObject);

		$this->assertTrue($result->isValid(), 'a self-referencing $ref inside array items must validate');
	}//end testSelfReferencingNestedRefValidates()

	/**
	 * A `$ref` on a property of a plain nested object (not inside items) is
	 * handled the same way.
	 *
	 * @return void
	 */
	public function testRefInsideNestedObjectInsideItemsValidates(): void {
		$schemaObject = json_decode(
			json_encode(
				[
					'type' => 'object',
					'properties' => [
						'lines' => [
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'properties' => [
									'slot' => [
										'type' => 'object',
										'properties' => [
											'roomId' => ['type' => 'string', 'format' => 'uuid', '$ref' => 'Room'],
										],
									],
								],
							],
						],
					],
				]
			)
		);

		$object = ['lines' => [['slot' => ['roomId' => self::COURSE]]]];

		$result = $this->handler->validateObject($object, $this->schema('hour-plan'), $schemaObject);

		$this->assertTrue($result->isValid(), 'a $ref two object levels below items must validate');
	}//end testRefInsideNestedObjectInsideItemsValidates()

	/**
	 * The original #2179 shape, a `$ref` directly on string items
	 * (hermiq EvalRun.skillResults), validates.
	 *
	 * @return void
	 */
	public function testArrayOfRefStringItemsValidates(): void {
		$schemaObject = json_decode(
			json_encode(
				[
					'type' => 'object',
					'properties' => [
						'skillResults' => [
							'type' => 'array',
							'items' => ['type' => 'string', 'format' => 'uuid', '$ref' => 'Skill'],
						],
					],
				]
			)
		);

		$object = ['skillResults' => [self::COURSE]];

		$result = $this->handler->validateObject($object, $this->schema('evalrun'), $schemaObject);

		$this->assertTrue($result->isValid(), 'array-of-$ref string items must validate');
	}//end testArrayOfRefStringItemsValidates()
}//end class
