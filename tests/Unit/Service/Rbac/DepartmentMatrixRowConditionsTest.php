<?php

/**
 * Matrix rows with `where` and `until`, and the group-id user source.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pins what the new row keys compile to, and what the validator refuses.
 */
class DepartmentMatrixRowConditionsTest extends TestCase {

	/**
	 * A matrix on `assignedGroup` with the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, mixed>
	 */
	private function matrix(array $rows): array {
		return ['field' => 'assignedGroup', 'userSource' => ['groups' => true], 'rows' => $rows];
	}//end matrix()

	/**
	 * `where` joins the field condition in one match; a list becomes `$in`.
	 *
	 * @return void
	 */
	public function testWhereJoinsTheFieldCondition(): void {
		$compiled = (new DepartmentMatrixCompiler())->compile(
			matrix: $this->matrix(
				[['value' => 'team-a', 'group' => 'g', 'actions' => ['update'], 'where' => ['status' => ['s1', 's2'], 'caseType' => 'ct-1']]]
			),
			ownValues: []
		);

		$this->assertSame(
			['update' => [['group' => 'g', 'match' => ['assignedGroup' => ['$in' => ['team-a']], 'caseType' => 'ct-1', 'status' => ['$in' => ['s1', 's2']]]]]],
			$compiled
		);
	}//end testWhereJoinsTheFieldCondition()

	/**
	 * Rows with the same group and `where` merge; a different `where` stays apart.
	 *
	 * @return void
	 */
	public function testRowsMergeOnlyWithAnEqualWhere(): void {
		$compiled = (new DepartmentMatrixCompiler())->compile(
			matrix: $this->matrix(
				[
					['value' => 'team-a', 'group' => 'g', 'actions' => ['read'], 'where' => ['caseType' => 'ct-1']],
					['value' => 'team-b', 'group' => 'g', 'actions' => ['read'], 'where' => ['caseType' => 'ct-1']],
					['value' => 'team-c', 'group' => 'g', 'actions' => ['read'], 'where' => ['caseType' => 'ct-2']],
				]
			),
			ownValues: []
		);

		$this->assertSame(
			[
				['group' => 'g', 'match' => ['assignedGroup' => ['$in' => ['team-a', 'team-b']], 'caseType' => 'ct-1']],
				['group' => 'g', 'match' => ['assignedGroup' => ['$in' => ['team-c']], 'caseType' => 'ct-2']],
			],
			$compiled['read']
		);
	}//end testRowsMergeOnlyWithAnEqualWhere()

	/**
	 * A `where` that cannot be read drops the row, never just the condition.
	 *
	 * @return void
	 */
	public function testAnUnreadableWhereDropsTheRow(): void {
		$compiler = new DepartmentMatrixCompiler();
		foreach ([['status' => []], ['status' => ['$in' => []]], ['status' => [['nested']]], ['assignedGroup' => 'team-b'], 'status'] as $where) {
			$this->assertSame(
				[],
				$compiler->compile(
					matrix: $this->matrix([['value' => 'team-a', 'group' => 'g', 'actions' => ['update'], 'where' => $where]]),
					ownValues: []
				),
				json_encode($where)
			);
		}
	}//end testAnUnreadableWhereDropsTheRow()

	/**
	 * `until` in the past, or unreadable, compiles to nothing; in the future it compiles.
	 *
	 * @return void
	 */
	public function testUntilEndsARow(): void {
		$compiler = new DepartmentMatrixCompiler();
		$now = new DateTimeImmutable('2026-10-23T12:00:00+00:00');
		$row = static fn (string $until): array => ['value' => 'team-a', 'group' => 'g', 'actions' => ['read'], 'until' => $until];

		$this->assertSame([], $compiler->compile(matrix: $this->matrix([$row('2026-10-22')]), ownValues: [], now: $now));
		$this->assertSame([], $compiler->compile(matrix: $this->matrix([$row('someday')]), ownValues: [], now: $now));
		$this->assertArrayHasKey('read', $compiler->compile(matrix: $this->matrix([$row('2026-10-24')]), ownValues: [], now: $now));
	}//end testUntilEndsARow()

	/**
	 * `groups: true` gives every group unprefixed, joined with a prefix source.
	 *
	 * @return void
	 */
	public function testGroupsSourceIsTheCallersGroups(): void {
		$compiler = new DepartmentMatrixCompiler();

		$this->assertSame(['team-a', 'dept:VTH'], $compiler->valuesFromGroups(source: ['groups' => true], userGroups: ['team-a', 'dept:VTH']));
		$this->assertSame(
			['team-a', 'dept:VTH', 'VTH'],
			$compiler->valuesFromGroups(source: ['groups' => true, 'groupPrefix' => 'dept:'], userGroups: ['team-a', 'dept:VTH'])
		);
	}//end testGroupsSourceIsTheCallersGroups()

	/**
	 * The validator accepts the new keys and names what it refuses.
	 *
	 * @return void
	 */
	public function testTheValidatorReadsWhereAndUntil(): void {
		$validator = new DepartmentMatrixValidator();
		$properties = ['assignedGroup' => [], 'status' => []];
		$codes = fn (array $row): array => array_column(
			$validator->validate(properties: $properties, authorization: ['matrix' => $this->matrix([$row])]),
			'code'
		);
		$base = ['value' => '$self', 'group' => 'g', 'actions' => ['update']];

		$this->assertSame([], $codes($base + ['where' => ['status' => ['s1']], 'until' => '2027-01-01']));
		$this->assertSame(['matrix.where-unknown-field'], $codes($base + ['where' => ['phase' => 's1']]));
		$this->assertSame(['matrix.where-repeats-field'], $codes($base + ['where' => ['assignedGroup' => 'x']]));
		$this->assertSame(['matrix.bad-where-value'], $codes($base + ['where' => ['status' => []]]));
		$this->assertSame(['matrix.bad-where'], $codes($base + ['where' => ['s1']]));
		$this->assertSame(['matrix.bad-until'], $codes($base + ['until' => 'someday']));
	}//end testTheValidatorReadsWhereAndUntil()

	/**
	 * A matrix naming a provider may declare no rows; an empty provider is refused.
	 *
	 * @return void
	 */
	public function testRowsFromStandsInForDeclaredRows(): void {
		$validator = new DepartmentMatrixValidator();
		$codes = static fn (array $matrix): array => array_column(
			$validator->validate(properties: ['assignedGroup' => []], authorization: ['matrix' => $matrix]),
			'code'
		);
		$base = ['field' => 'assignedGroup', 'userSource' => ['groups' => true]];

		$this->assertSame([], $codes($base + ['rowsFrom' => 'app.rights']));
		$this->assertSame(['matrix.no-rows'], $codes($base));
		$this->assertContains('matrix.bad-rows-from', $codes($base + ['rowsFrom' => ' ']));
	}//end testRowsFromStandsInForDeclaredRows()

	/**
	 * A row may grant the file verbs; they compile like any other action.
	 *
	 * @return void
	 */
	public function testFileVerbsCompile(): void {
		$compiled = (new DepartmentMatrixCompiler())->compile(
			matrix: $this->matrix([['value' => 'team-a', 'group' => 'g', 'actions' => ['readFiles', 'addFiles', 'deleteFiles']]]),
			ownValues: []
		);

		$this->assertSame(['readFiles', 'addFiles', 'deleteFiles'], array_keys($compiled));
	}//end testFileVerbsCompile()
}//end class
