<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Search\Service\Aggregation\CrossSchemaTarget},
 * and the reproduction of decidesk Meeting's discarded aggregations through
 * {@see \OCA\OpenRegister\Search\Service\Aggregation\AggregationAnnotationValidator}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/aggregation-dialect-repair/specs/aggregation-api/spec.md#requirement-the-named-annotation-aggregation-surface-must-validate-filtergroupby-fields-against-the-schema-that-owns-them
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation;

use OCA\OpenRegister\Search\Service\Aggregation\AggregationAnnotationValidator;
use OCA\OpenRegister\Search\Service\Aggregation\CrossSchemaTarget;
use PHPUnit\Framework\TestCase;

/**
 * A `schema` key naming another schema is read as `from`; one naming the
 * declaring schema, or checked without its names, is left alone.
 *
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\CrossSchemaTarget
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\AggregationAnnotationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationSpecEntry
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\CrossSchemaAggregationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationJoinAnnotationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationMetricsAnnotationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\GroupByMember
 */
class CrossSchemaTargetTest extends TestCase {

	/**
	 * decidesk's Meeting, as it ships.
	 *
	 * @return array<string, mixed>
	 */
	private static function meeting(): array {
		return json_decode((string) file_get_contents(__DIR__ . '/fixtures/decidesk-meeting-aggregations.json'), true, 512, JSON_THROW_ON_ERROR);
	}//end meeting()

	/**
	 * The codes of the validator's errors for this schema.
	 *
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return array<int, string>
	 */
	private static function codes(array $schema): array {
		return array_column((new AggregationAnnotationValidator())->validate($schema), 'code');
	}//end codes()

	/**
	 * Task 1.1, the reproduction: without its own names the validator reads
	 * Meeting's specs as intra-schema and rejects the filter fields that live
	 * on Participant and ActionItem, which is what discarded the annotation.
	 *
	 * @return void
	 */
	public function testMeetingsAggregationsWereRejectedForFieldsOfTheRelatedSchemas(): void {
		$schema = self::meeting();
		unset($schema['slug'], $schema['title']);

		$errors = (new AggregationAnnotationValidator())->validate($schema);

		$this->assertSame(['aggregation-filter-field-unknown'], array_values(array_unique(array_column($errors, 'code'))));
		$messages = implode("\n", array_column($errors, 'message'));
		$this->assertStringContainsString('"presentParticipantCount" filter references unknown field "attendanceStatus"', $messages);
		$this->assertStringContainsString('"actionItemCount" filter references unknown field "meeting"', $messages);
		$this->assertStringContainsString('"completedActionItemCount" filter references unknown field "taskStatus"', $messages);
	}//end testMeetingsAggregationsWereRejectedForFieldsOfTheRelatedSchemas()

	/**
	 * Saved with its own names, Meeting's specs name other schemas, so they
	 * validate as cross-schema counts and nothing is discarded.
	 *
	 * @return void
	 */
	public function testMeetingsOwnAggregationsValidate(): void {
		$this->assertSame([], self::codes(self::meeting()));
	}//end testMeetingsOwnAggregationsValidate()

	/**
	 * A typo on an intra-schema spec is still caught.
	 *
	 * @return void
	 */
	public function testAGenuinelyUnknownFieldIsStillRejected(): void {
		$schema = self::meeting();
		$schema['x-openregister-aggregations'] = ['publicMeetings' => ['metric' => 'count', 'filter' => ['isPubilc' => true]]];

		$this->assertSame(['aggregation-filter-field-unknown'], self::codes($schema));
	}//end testAGenuinelyUnknownFieldIsStillRejected()

	/**
	 * A `schema` key naming the declaring schema, in any case, keeps the
	 * intra-schema checks: its fields are Meeting's, and a typo is caught.
	 *
	 * @return void
	 */
	public function testASchemaKeyNamingItselfKeepsTheIntraSchemaChecks(): void {
		$schema = self::meeting();
		$schema['x-openregister-aggregations'] = [
			'publicMeetings' => ['metric' => 'count', 'schema' => 'MEETING', 'filter' => ['isPublic' => true]],
			'typo' => ['metric' => 'count', 'schema' => 'meeting', 'groupBy' => ['meetingTipe']],
		];

		$this->assertSame(['aggregation-groupby-field-unknown'], self::codes($schema));
	}//end testASchemaKeyNamingItselfKeepsTheIntraSchemaChecks()

	/**
	 * An explicit `from` is unchanged: it wins over a `schema` key and its
	 * fields are not checked against the declaring schema.
	 *
	 * @return void
	 */
	public function testAnExplicitFromIsUnaffected(): void {
		$target = new CrossSchemaTarget();
		$spec = ['metric' => 'count', 'from' => 'ActionItem', 'schema' => 'Participant', 'filter' => ['meeting' => '@self.id']];

		$this->assertSame($spec, $target->resolve(spec: $spec, ownNames: ['meeting', 'Meeting']));

		$schema = self::meeting();
		$schema['x-openregister-aggregations'] = ['items' => $spec];
		$this->assertSame([], self::codes($schema));
	}//end testAnExplicitFromIsUnaffected()

	/**
	 * A `schema` key naming another schema becomes `from`; the rest of the
	 * spec is kept as declared.
	 *
	 * @return void
	 */
	public function testASchemaKeyNamingAnotherSchemaBecomesFrom(): void {
		$spec = ['metric' => 'count', 'schema' => ' Participant ', 'filter' => ['governanceBody' => '@self.governanceBody']];

		$this->assertSame($spec + ['from' => 'Participant'], (new CrossSchemaTarget())->resolve(spec: $spec, ownNames: ['meeting', 'Meeting']));
	}//end testASchemaKeyNamingAnotherSchemaBecomesFrom()

	/**
	 * Without the declaring schema's names, an empty or non-string `schema`,
	 * or no `schema` at all, the spec comes back as declared.
	 *
	 * @return void
	 */
	public function testWhatCannotBeToldApartIsLeftAlone(): void {
		$target = new CrossSchemaTarget();
		$spec = ['metric' => 'count', 'schema' => 'Participant'];

		$this->assertSame($spec, $target->resolve(spec: $spec, ownNames: []));
		$this->assertSame($spec, $target->resolve(spec: $spec, ownNames: [null, '  ', ['x']]));
		$this->assertSame(['metric' => 'count', 'schema' => ''], $target->resolve(spec: ['metric' => 'count', 'schema' => ''], ownNames: ['meeting']));
		$this->assertSame(['metric' => 'count', 'schema' => 7], $target->resolve(spec: ['metric' => 'count', 'schema' => 7], ownNames: ['meeting']));
		$this->assertSame(['metric' => 'count'], $target->resolve(spec: ['metric' => 'count'], ownNames: ['meeting']));
	}//end testWhatCannotBeToldApartIsLeftAlone()
}//end class
