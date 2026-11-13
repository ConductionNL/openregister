<?php

/**
 * Reads the survey records a write needs: the survey, its questions, whether it has answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survey
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survey;

use OCA\OpenRegister\Db\MagicMapper\MagicListRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * The survey records, read from the register the written object lives in.
 *
 * The sibling schemas are found through that register's own schema list, not
 * by a global slug lookup: a schema slug is unique per organisation only, so
 * a bare `find('surveyQuestion')` could resolve another tenant's copy.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */
class SurveyRecords {

	/**
	 * The survey definition schema.
	 *
	 * @var string
	 */
	public const SURVEY = 'survey';

	/**
	 * The question schema.
	 *
	 * @var string
	 */
	public const QUESTION = 'surveyQuestion';

	/**
	 * The answer set schema.
	 *
	 * @var string
	 */
	public const ANSWER_SET = 'surveyAnswerSet';

	/**
	 * The most questions read for one survey.
	 *
	 * @var int
	 */
	public const QUESTION_CAP = 500;

	/**
	 * Constructor.
	 *
	 * @param SchemaCatalogInterface $catalog The registers, schemas and object tables.
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
	) {
	}//end __construct()

	/**
	 * The slug of the schema an object is written under, if it has one.
	 *
	 * @param ObjectEntity $object The object being written.
	 *
	 * @return string|null The schema slug.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
	 */
	public function schemaSlugOf(ObjectEntity $object): ?string {
		$reference = (string)$object->getSchema();
		if ($reference === '') {
			return null;
		}

		return $this->catalog->schemas()->find(id: $reference, _rbac: false, _multitenancy: false)->getSlug();
	}//end schemaSlugOf()

	/**
	 * A survey's data, with its uuid under `id`, or null when it is not there.
	 *
	 * @param ObjectEntity $within     An object in the same register.
	 * @param string       $surveyUuid The survey.
	 *
	 * @return array<string, mixed>|null The survey.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
	 */
	public function survey(ObjectEntity $within, string $surveyUuid): ?array {
		$table = $this->table(within: $within, slug: self::SURVEY);
		if ($table === null || $surveyUuid === '') {
			return null;
		}

		$survey = $this->catalog->tables()->find(
			identifier: $surveyUuid,
			register: $table->register,
			schema: $table->schema,
			includeDeleted: false,
			_rbac: false,
			_multitenancy: false
		);

		return array_merge((array)$survey->getObject(), ['id' => $survey->getUuid()]);
	}//end survey()

	/**
	 * A survey's questions, each with its `slug` (else its uuid) and `id`.
	 *
	 * @param ObjectEntity $within     An object in the same register.
	 * @param string       $surveyUuid The survey.
	 *
	 * @return array<int, array<string, mixed>> The questions.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
	 */
	public function questions(ObjectEntity $within, string $surveyUuid): array {
		$questions = [];
		foreach ($this->rowsOf(within: $within, slug: self::QUESTION, surveyUuid: $surveyUuid, limit: self::QUESTION_CAP) as $row) {
			$uuid = (string)$row->getUuid();
			$slug = (string)$row->getSlug();
			if ($slug === '') {
				$slug = $uuid;
			}

			$questions[] = array_merge((array)$row->getObject(), ['id' => $uuid, 'slug' => $slug]);
		}

		return $questions;
	}//end questions()

	/**
	 * Whether any answer set names this survey.
	 *
	 * @param ObjectEntity $within     An object in the same register.
	 * @param string       $surveyUuid The survey.
	 *
	 * @return bool True when at least one answer set exists.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
	 */
	public function hasAnswers(ObjectEntity $within, string $surveyUuid): bool {
		return $this->rowsOf(within: $within, slug: self::ANSWER_SET, surveyUuid: $surveyUuid, limit: 1) !== [];
	}//end hasAnswers()

	/**
	 * The rows of one sibling schema that name a survey.
	 *
	 * @param ObjectEntity $within     An object in the same register.
	 * @param string       $slug       The sibling schema.
	 * @param string       $surveyUuid The survey.
	 * @param int          $limit      The most rows to read.
	 *
	 * @return array<int, ObjectEntity> The rows.
	 */
	private function rowsOf(ObjectEntity $within, string $slug, string $surveyUuid, int $limit): array {
		$table = $this->table(within: $within, slug: $slug);
		if ($table === null || $surveyUuid === '') {
			return [];
		}

		return $this->catalog->tables()->findAllListed(
			request: new MagicListRequest(
				table: $table,
				query: new ListQuery(limit: $limit, filters: ['object->survey' => $surveyUuid])
			)
		);
	}//end rowsOf()

	/**
	 * The register of an object and the sibling schema with this slug in it.
	 *
	 * @param ObjectEntity $within An object in the register.
	 * @param string       $slug   The schema slug.
	 *
	 * @return RegisterSchema|null The table, or null when the register holds no such schema.
	 */
	private function table(ObjectEntity $within, string $slug): ?RegisterSchema {
		$reference = (string)$within->getRegister();
		if ($reference === '') {
			return null;
		}

		$register = $this->catalog->registers()->find(id: $reference, _rbac: false, _multitenancy: false);
		$schema = $this->schemaIn(register: $register, slug: $slug);
		if ($schema === null) {
			return null;
		}

		return new RegisterSchema(register: $register, schema: $schema);
	}//end table()

	/**
	 * The schema with this slug among a register's schemas.
	 *
	 * @param Register $register The register.
	 * @param string   $slug     The schema slug.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaIn(Register $register, string $slug): ?Schema {
		foreach ($register->getSchemas() as $reference) {
			$schema = $this->catalog->schemas()->find(id: $reference, _rbac: false, _multitenancy: false);
			if ($schema->getSlug() === $slug) {
				return $schema;
			}
		}

		return null;
	}//end schemaIn()
}//end class
