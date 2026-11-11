<?php

/**
 * Characterisation of ProcessingLogMapper's subject match: the single-read columns and the
 * list-read JSON fragment for a subject named by type, by value or by both, as the
 * findForSubject() query sends them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ProcessingLog\ProcessingLogSubject;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Golden subject match of the processing log.
 *
 * @covers \OCA\OpenRegister\Db\ProcessingLogMapper
 */
final class ProcessingLogMapperSubjectMatchGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * The subjects, the condition each gives and the values it binds.
	 *
	 * @return array<string, array{0: ?string, 1: ?string, 2: string, 3: list<string>}>
	 */
	public static function subjects(): array {
		return [
			'type and value' => ['BSN', '111', 'orX(andX(eq(subject_id_type, :p0), eq(subject_id_value, :p1)), like(subject_ids, :p2))', ['BSN', '111', '%{"type":"BSN","value":"111"}%']],
			'type only' => ['BSN', null, 'orX(andX(eq(subject_id_type, :p0)), like(subject_ids, :p1))', ['BSN', '%{"type":"BSN",%']],
			'value only' => [null, '1_1%', 'orX(andX(eq(subject_id_value, :p0)), like(subject_ids, :p1))', ['1_1%', '%"value":"1\_1\%"}%']],
			'nothing' => [null, null, 'orX(andX(), like(subject_ids, :p0))', ['%%']],
		];
	}//end subjects()

	/**
	 * The match fits the parts of the subject that are given.
	 *
	 * @param string|null  $type      The subject id type.
	 * @param string|null  $value     The subject id value.
	 * @param string       $condition The rendered condition.
	 * @param list<string> $bound     The bound values, in order.
	 *
	 * @return void
	 *
	 * @dataProvider subjects
	 */
	public function testSubjectMatch(?string $type, ?string $value, string $condition, array $bound): void {
		$mapper = new ProcessingLogMapper($this->recordingDb());
		$qb = $this->recordingDb()->getQueryBuilder();

		$match = (new ReflectionMethod(ProcessingLogMapper::class, 'subjectMatch'))->invoke($mapper, $qb, new ProcessingLogSubject(type: $type, value: $value));

		$this->assertSame($condition, (string) $match);
		$this->assertSame($bound, $this->boundValues());
		foreach ($this->params as [$param, $kind]) {
			$this->assertSame(IQueryBuilder::PARAM_STR, $kind);
		}
	}//end testSubjectMatch()

	/**
	 * findForSubject() puts the match first, before the read scope.
	 *
	 * @return void
	 */
	public function testFindForSubjectStartsWithTheMatch(): void {
		(new ProcessingLogMapper($this->recordingDb()))->findForSubject(new ProcessingLogSubject(type: 'BSN', value: '111'));

		$this->assertStringStartsWith(
			'select(*) from(openregister_processing_log) where(orX(andX(eq(subject_id_type, :p0), eq(subject_id_value, :p1)), like(subject_ids, :p2)))',
			$this->sql()
		);
	}//end testFindForSubjectStartsWithTheMatch()
}//end class
