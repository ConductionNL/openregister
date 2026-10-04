<?php

declare(strict_types=1);

/**
 * ValidateObject nested `nullable` Unit Tests
 *
 * A top-level property that is not required accepts `null`: the prepare step
 * widens its type with `"null"`. The same property one level down, inside an
 * object or inside an array's `items`, got no such treatment, so a nested
 * property marked `nullable: true` refused `null` and every write carrying it
 * failed.
 *
 * Fixtures are learniq's real fragments, copied from ConductionNL/learniq
 * `lib/Settings/learniq_register.json` at origin/development (ef8d9b80,
 * 2026-10-04):
 * - LearnerProfile.beeldmateriaalConsent: an object whose five boolean
 *   sub-fields are `nullable: true` while consent is undecided. 60 of 468
 *   primary pupils hold nulls there, and no save of those profiles passed.
 * - ReportCard.subjectGrades: array items with `courseId` (`nullable: true`
 *   plus a relation `$ref`), left open by openregister#4248.
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
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for `nullable: true` on nested properties and array items.
 */
class ValidateObjectNestedNullableTest extends TestCase {
	private const CURRICULUM_PLAN = '11111111-1111-4111-8111-111111111111';
	private const COURSE          = '22222222-2222-4222-8222-222222222222';

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
	 * Read a JSON fixture as an associative array.
	 *
	 * @param string $name Fixture file name under tests/Fixtures.
	 *
	 * @return array<string, mixed>
	 */
	private function fixture(string $name): array {
		$json = file_get_contents(__DIR__.'/../../../Fixtures/'.$name);
		$this->assertIsString($json, 'fixture must be readable');

		$decoded = json_decode($json, true);
		$this->assertIsArray($decoded, 'fixture must be valid JSON');

		return $decoded;
	}//end fixture()

	/**
	 * Wrap property fragments in an object schema.
	 *
	 * @param array<string, mixed> $properties Property fragments by name.
	 * @param list<string>         $required   Required property names.
	 *
	 * @return object
	 */
	private function objectSchema(array $properties, array $required = []): object {
		$schema = ['type' => 'object', 'properties' => $properties];
		if ($required !== []) {
			$schema['required'] = $required;
		}

		return json_decode(json_encode($schema));
	}//end objectSchema()

	/**
	 * The LearnerProfile schema object with learniq's beeldmateriaalConsent.
	 *
	 * @return object
	 */
	private function learnerProfileSchema(): object {
		return $this->objectSchema(
			[
				'ncUserId' => ['type' => 'string'],
				'tenant_id' => ['type' => 'string'],
				'beeldmateriaalConsent' => $this->fixture('learniq-learnerprofile-beeldmateriaalconsent.json'),
			],
			['ncUserId', 'tenant_id']
		);
	}//end learnerProfileSchema()

	/**
	 * The ReportCard schema object with learniq's subjectGrades.
	 *
	 * @return object
	 */
	private function reportCardSchema(): object {
		return $this->objectSchema(
			[
				'title' => ['type' => 'string'],
				'subjectGrades' => $this->fixture('learniq-reportcard-subjectgrades.json'),
			]
		);
	}//end reportCardSchema()

	/**
	 * Render a result's error message for an assertion failure.
	 *
	 * @param ValidationResult $result The validation result.
	 *
	 * @return string
	 */
	private function errors(ValidationResult $result): string {
		if ($result->isValid() === true) {
			return '';
		}

		return $this->handler->generateErrorMessage($result);
	}//end errors()

	/**
	 * A learner profile whose consent is still undecided (every purpose null)
	 * validates.
	 *
	 * @return void
	 */
	public function testUndecidedBeeldmateriaalConsentValidates(): void {
		$object = [
			'ncUserId' => 'pupil-1',
			'tenant_id' => 'school-1',
			'beeldmateriaalConsent' => [
				'website' => null,
				'socialMedia' => null,
				'schoolgids' => null,
				'classPhoto' => null,
				'video' => null,
			],
		];

		$result = $this->handler->validateObject($object, $this->schema('learner-profile'), $this->learnerProfileSchema());

		$this->assertTrue($result->isValid(), 'a nested nullable boolean must accept null: '.$this->errors($result));
	}//end testUndecidedBeeldmateriaalConsentValidates()

	/**
	 * A partly decided consent (some booleans, some nulls) validates, and a
	 * wrong type in a nested nullable property is still refused.
	 *
	 * @return void
	 */
	public function testPartlyDecidedConsentValidatesAndWrongTypeIsRefused(): void {
		$base = ['ncUserId' => 'pupil-1', 'tenant_id' => 'school-1'];

		$mixed = $this->handler->validateObject(
			$base + ['beeldmateriaalConsent' => ['website' => true, 'socialMedia' => false, 'video' => null]],
			$this->schema('learner-profile'),
			$this->learnerProfileSchema()
		);
		$this->assertTrue($mixed->isValid(), 'booleans and nulls mixed must validate: '.$this->errors($mixed));

		$wrong = $this->handler->validateObject(
			$base + ['beeldmateriaalConsent' => ['website' => 'yes']],
			$this->schema('learner-profile'),
			$this->learnerProfileSchema()
		);
		$this->assertFalse($wrong->isValid(), 'a string in a nested nullable boolean must still be refused');
	}//end testPartlyDecidedConsentValidatesAndWrongTypeIsRefused()

	/**
	 * A report card subject grade with `courseId: null` (and the other
	 * nullable item fields null) validates.
	 *
	 * @return void
	 */
	public function testSubjectGradeWithNullCourseIdValidates(): void {
		$object = [
			'title' => 'Rapport periode 1',
			'subjectGrades' => [
				[
					'curriculumPlanId' => self::CURRICULUM_PLAN,
					'courseId' => null,
					'periodAverage' => null,
					'passed' => null,
					'teacherComment' => null,
				],
				[
					'curriculumPlanId' => self::CURRICULUM_PLAN,
					'courseId' => self::COURSE,
				],
			],
		];

		$result = $this->handler->validateObject($object, $this->schema('report-card'), $this->reportCardSchema());

		$this->assertTrue($result->isValid(), 'courseId: null inside items must validate: '.$this->errors($result));
	}//end testSubjectGradeWithNullCourseIdValidates()

	/**
	 * The nested `$ref` still validates as before: a non-uuid courseId is
	 * refused, and so is a null curriculumPlanId (required, not nullable).
	 *
	 * @return void
	 */
	public function testNestedRefAndNonNullableStillRefuse(): void {
		$badUuid = $this->handler->validateObject(
			['subjectGrades' => [['curriculumPlanId' => self::CURRICULUM_PLAN, 'courseId' => 'not a uuid at all!']]],
			$this->schema('report-card'),
			$this->reportCardSchema()
		);
		$this->assertFalse($badUuid->isValid(), 'a non-uuid in a nested nullable $ref must still be refused');

		$nullRequired = $this->handler->validateObject(
			['subjectGrades' => [['curriculumPlanId' => null]]],
			$this->schema('report-card'),
			$this->reportCardSchema()
		);
		$this->assertFalse($nullRequired->isValid(), 'null in a nested property that is not nullable must be refused');
	}//end testNestedRefAndNonNullableStillRefuse()

	/**
	 * Nullable at depth two accepts null; a sibling without `nullable` still
	 * refuses it.
	 *
	 * @return void
	 */
	public function testNestedNonNullableNullIsRefused(): void {
		$schemaObject = $this->objectSchema(
			[
				'outer' => [
					'type' => 'object',
					'properties' => [
						'inner' => [
							'type' => 'object',
							'properties' => [
								'strict' => ['type' => 'boolean'],
								'loose' => ['type' => 'boolean', 'nullable' => true],
							],
						],
					],
				],
			]
		);

		$loose = $this->handler->validateObject(
			['outer' => ['inner' => ['loose' => null]]],
			$this->schema('deep'),
			$schemaObject
		);
		$this->assertTrue($loose->isValid(), 'nullable at depth two must accept null: '.$this->errors($loose));

		$strict = $this->handler->validateObject(
			['outer' => ['inner' => ['strict' => null]]],
			$this->schema('deep'),
			$schemaObject
		);
		$this->assertFalse($strict->isValid(), 'a nested property without nullable must still refuse null');
	}//end testNestedNonNullableNullIsRefused()

	/**
	 * Array items marked nullable accept a null entry; items without it do not.
	 *
	 * @return void
	 */
	public function testNullableArrayItemsAcceptNull(): void {
		$schemaObject = $this->objectSchema(
			[
				'scores' => ['type' => 'array', 'items' => ['type' => 'number', 'nullable' => true]],
				'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
			]
		);

		$scores = $this->handler->validateObject(['scores' => [7.5, null]], $this->schema('items'), $schemaObject);
		$this->assertTrue($scores->isValid(), 'nullable items must accept a null entry: '.$this->errors($scores));

		$tags = $this->handler->validateObject(['tags' => ['a', null]], $this->schema('items'), $schemaObject);
		$this->assertFalse($tags->isValid(), 'items without nullable must refuse a null entry');
	}//end testNullableArrayItemsAcceptNull()

	/**
	 * A nested nullable enum accepts null without the enum listing it, and a
	 * value outside the enum is still refused.
	 *
	 * @return void
	 */
	public function testNestedNullableEnumAcceptsNull(): void {
		$schemaObject = $this->objectSchema(
			[
				'consent' => [
					'type' => 'object',
					'properties' => [
						'status' => ['type' => 'string', 'enum' => ['granted', 'refused'], 'nullable' => true],
					],
				],
			]
		);

		$result = $this->handler->validateObject(['consent' => ['status' => null]], $this->schema('enum'), $schemaObject);
		$this->assertTrue($result->isValid(), 'a nested nullable enum must accept null: '.$this->errors($result));

		$bad = $this->handler->validateObject(['consent' => ['status' => 'maybe']], $this->schema('enum'), $schemaObject);
		$this->assertFalse($bad->isValid(), 'a value outside the enum must still be refused');
	}//end testNestedNullableEnumAcceptsNull()

	/**
	 * Top-level behaviour is unchanged: a REQUIRED top-level property marked
	 * nullable still refuses null, exactly as before this change.
	 *
	 * @return void
	 */
	public function testTopLevelRequiredNullableIsUnchanged(): void {
		$schemaObject = $this->objectSchema(
			['name' => ['type' => 'string', 'nullable' => true]],
			['name']
		);

		$result = $this->handler->validateObject(['name' => null], $this->schema('top'), $schemaObject);
		$this->assertFalse($result->isValid(), 'top-level behaviour must not change');
	}//end testTopLevelRequiredNullableIsUnchanged()
}//end class
