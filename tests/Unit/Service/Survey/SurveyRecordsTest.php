<?php

/**
 * Reading the survey records a write needs, from the register the object lives in.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survey
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Survey;

use OCA\OpenRegister\Db\MagicMapper\MagicListRequest;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Register\RegisterSchemaIds;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Survey\SurveyRecords;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SurveyRecords against a stubbed catalog: register 7 holds schemas 10 to 13.
 */
#[CoversClass(SurveyRecords::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Register::class)]
#[UsesClass(RegisterSchemaIds::class)]
#[UsesClass(RegisterSchema::class)]
#[UsesClass(Schema::class)]
#[UsesClass(MagicListRequest::class)]
#[UsesClass(ListQuery::class)]
class SurveyRecordsTest extends TestCase {

	/**
	 * The object tables.
	 *
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface&MockObject $tables;

	/**
	 * The schemas by id.
	 *
	 * @var array<int, Schema>
	 */
	private array $schemas = [];

	/**
	 * The reader under test.
	 *
	 * @var SurveyRecords
	 */
	private SurveyRecords $records;

	/**
	 * Wire a catalog whose register 7 holds the four survey schemas.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		foreach (['survey' => 10, 'surveyQuestion' => 11, 'surveyInvitation' => 12, 'surveyAnswerSet' => 13] as $slug => $id) {
			$schema = new Schema();
			$schema->setId($id);
			$schema->setSlug($slug);
			$this->schemas[$id] = $schema;
		}

		$register = new Register();
		$register->setId(7);
		$register->setSlug('surveys');
		$register->setSchemas([10, 11, 12, 13]);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->with('7')->willReturn($register);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(fn (string|int $id): Schema => $this->schemas[(int)$id]);

		$this->tables = $this->createMock(MagicMapperInterface::class);

		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($registers);
		$catalog->method('schemas')->willReturn($schemas);
		$catalog->method('tables')->willReturn($this->tables);

		$this->records = new SurveyRecords(catalog: $catalog);
	}//end setUp()

	/**
	 * An object in register 7 under the given schema.
	 *
	 * @param string               $schema The schema id.
	 * @param string               $uuid   The uuid.
	 * @param array<string, mixed> $data   The data.
	 * @param string|null          $slug   The object slug.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(string $schema, string $uuid, array $data = [], ?string $slug = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister('7');
		$object->setSchema($schema);
		$object->setUuid($uuid);
		$object->setSlug($slug);
		$object->setObject($data);

		return $object;
	}//end object()

	/**
	 * The schema slug of an object is read from its schema; none without a schema.
	 *
	 * @return void
	 */
	public function testSchemaSlugOf(): void {
		$this->assertSame('surveyAnswerSet', $this->records->schemaSlugOf(object: self::object(schema: '13', uuid: 'a')));

		$bare = new ObjectEntity();
		$this->assertNull($this->records->schemaSlugOf(object: $bare));
	}//end testSchemaSlugOf()

	/**
	 * The survey is found in the survey schema of the same register, with its uuid as id.
	 *
	 * @return void
	 */
	public function testSurveyIsReadFromTheSameRegister(): void {
		$this->tables->expects($this->once())->method('find')
			->with(
				's-1',
				$this->callback(fn (Register $register): bool => $register->getId() === 7),
				$this->callback(fn (Schema $schema): bool => $schema->getSlug() === 'survey'),
				false,
				false,
				false
			)
			->willReturn(self::object(schema: '10', uuid: 's-1', data: ['title' => 'KTO', 'version' => 2]));

		$survey = $this->records->survey(within: self::object(schema: '13', uuid: 'a'), surveyUuid: 's-1');

		$this->assertSame(['id' => 's-1', 'title' => 'KTO', 'version' => 2], $survey);
	}//end testSurveyIsReadFromTheSameRegister()

	/**
	 * No survey uuid, or no survey schema in the register, reads nothing.
	 *
	 * @return void
	 */
	public function testNoSurveyWithoutAUuidOrASurveySchema(): void {
		$this->tables->expects($this->never())->method('find');

		$this->assertNull($this->records->survey(within: self::object(schema: '13', uuid: 'a'), surveyUuid: ''));

		$elsewhere = new ObjectEntity();
		$elsewhere->setSchema('13');
		$this->assertNull($this->records->survey(within: $elsewhere, surveyUuid: 's-1'));
	}//end testNoSurveyWithoutAUuidOrASurveySchema()

	/**
	 * Questions are listed by their survey, each keyed by slug, else by uuid.
	 *
	 * @return void
	 */
	public function testQuestionsCarryTheirSlugElseTheirUuid(): void {
		$this->tables->expects($this->once())->method('findAllListed')
			->with($this->callback(
				fn (MagicListRequest $request): bool => $request->table->schema->getSlug() === 'surveyQuestion'
					&& $request->query->filters === ['object->survey' => 's-1']
					&& $request->query->limit === SurveyRecords::QUESTION_CAP
			))
			->willReturn([
				self::object(schema: '11', uuid: 'q-1', data: ['text' => 'Score?', 'required' => true], slug: 'score'),
				self::object(schema: '11', uuid: 'q-2', data: ['text' => 'Remarks?']),
			]);

		$questions = $this->records->questions(within: self::object(schema: '13', uuid: 'a'), surveyUuid: 's-1');

		$this->assertSame(
			[
				['id' => 'q-1', 'text' => 'Score?', 'required' => true, 'slug' => 'score'],
				['id' => 'q-2', 'text' => 'Remarks?', 'slug' => 'q-2'],
			],
			$questions
		);
	}//end testQuestionsCarryTheirSlugElseTheirUuid()

	/**
	 * Whether a survey has answers reads at most one answer set.
	 *
	 * @return void
	 */
	public function testHasAnswersReadsOneAnswerSet(): void {
		$this->tables->expects($this->exactly(2))->method('findAllListed')
			->with($this->callback(
				fn (MagicListRequest $request): bool => $request->table->schema->getSlug() === 'surveyAnswerSet'
					&& $request->query->limit === 1
					&& $request->query->filters === ['object->survey' => 's-1']
			))
			->willReturnOnConsecutiveCalls([self::object(schema: '13', uuid: 'as-1')], []);

		$within = self::object(schema: '10', uuid: 's-1');
		$this->assertTrue($this->records->hasAnswers(within: $within, surveyUuid: 's-1'));
		$this->assertFalse($this->records->hasAnswers(within: $within, surveyUuid: 's-1'));
	}//end testHasAnswersReadsOneAnswerSet()
}//end class
