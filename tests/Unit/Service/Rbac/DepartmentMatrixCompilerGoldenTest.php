<?php

/**
 * Golden test for DepartmentMatrixCompiler compile/merge/valuesFromGroups.
 *
 * Pins, against tests/Unit/Service/Rbac/fixtures/department-matrix-compiler-golden.json
 * (written from the code before compile/gatherByActionGroup/valuesOf/
 * valuesFromGroups/merge were split), the rules a matrix compiles to for a
 * declaration that exercises every row branch: non-array rows, blank groups,
 * expired and live `until`, unreadable and field-naming `where`, empty, `$self`,
 * literal, numeric and duplicate values, `value` beating `values`, unknown,
 * declared, duplicate and `handle` actions, merging per group+where+marker and
 * the cross-organisation marker. A dropped row is the refused outcome. Set
 * UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler as C;
use OCA\OpenRegister\Service\Rbac\MatrixCaller;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler
 */
final class DepartmentMatrixCompilerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/department-matrix-compiler-golden.json';

	/**
	 * Every output of the matrix.
	 *
	 * @return array<string, mixed>
	 */
	private function outputs(): array {
		$c   = new C(null, ['approve']);
		$now = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
		$m = ['field' => ' department ', 'rows' => [
		  'not-a-row',
		  ['group' => '  ', 'value' => 'x', 'actions' => ['read']],
		  ['group' => 'past', 'value' => 'x', 'actions' => ['read'], 'until' => '2029-01-01T00:00:00+00:00'],
		  ['group' => 'future', 'value' => 'x', 'actions' => ['read'], 'until' => '2031-01-01T00:00:00+00:00'],
		  ['group' => 'badwhere', 'value' => 'x', 'actions' => ['read'], 'where' => 'nope'],
		  ['group' => 'fieldwhere', 'value' => 'x', 'actions' => ['read'], 'where' => ['department' => 'y']],
		  ['group' => 'empty', 'values' => ['', '  '], 'actions' => ['read']],
		  ['group' => 'novalue', 'actions' => ['read']],
		  ['group' => 'staff', 'value' => ' b ', 'values' => ['ignored'], 'actions' => ['read', ' update ', 'handle', 'bogus', 'approve', 'read']],
		  ['group' => 'staff', 'values' => ['a', '$self', 'b'], 'actions' => ['read']],
		  ['group' => 'staff', 'values' => ['c'], 'actions' => ['read'], 'where' => ['status' => 'open']],
		  ['group' => 'staff', 'values' => ['d'], 'actions' => ['read'], 'where' => ['status' => 'open']],
		  ['group' => 'cross', 'values' => ['e'], 'actions' => 'read'],
		  ['group' => 'cross', 'values' => ['e'], 'actions' => ['delete', 'readFiles'], 'where' => ['_organisation' => 'org-2'], 'crossOrganisation' => true],
		  ['group' => 'num', 'values' => [5, 5.5], 'actions' => ['share']],
		  ['group' => 'marked', 'values' => ['f'], 'actions' => ['read']],
		  ['group' => 'marked', 'values' => ['g'], 'actions' => ['read'], 'crossOrganisation' => true],
		  ['group' => 'marked', 'values' => ['h'], 'actions' => ['read'], 'crossOrganisation' => false],
		]];
		$out = [
		 'compiled' => $c->compileAt($m, new MatrixCaller(['own-1', 'b'], $now)),
		 'compiledOtherOwn' => $c->compileAt($m, new MatrixCaller(['own-1'], $now)),
		 'compiledNoOwn' => $c->compileAt($m, new MatrixCaller([], $now)),
		 'noField' => $c->compileAt(['rows' => $m['rows']], new MatrixCaller([], $now)),
		 'blankField' => $c->compileAt(['field' => ' ', 'rows' => []], new MatrixCaller([], $now)),
		 'rowsNotArray' => $c->compileAt(['field' => 'd', 'rows' => 'x'], new MatrixCaller([], $now)),
		 'defaultClock' => [
		  $c->compile(['field' => 'd', 'rows' => [['group' => 'g', 'value' => 'x', 'actions' => ['read'], 'until' => '2000-01-01T00:00:00+00:00']]], []),
		  $c->compile(['field' => 'd', 'rows' => [['group' => 'g', 'value' => 'x', 'actions' => ['read'], 'until' => '2999-01-01T00:00:00+00:00']]], []),
		 ],
		 'mergeEmpty' => $c->merge(['read' => ['x'], 'matrix' => []], []),
		 'mergeNull' => $c->merge(null, ['read' => [['group' => 'g']]]),
		 'merge' => $c->merge(['read' => ['a' => 'x'], 'update' => 'scalar', 'matrix' => ['field' => 'd']], ['read' => [['group' => 'g']], 'update' => [['group' => 'h']], 'delete' => [['group' => 'i']]]),
		 'groupsTrue' => $c->valuesFromGroups(['groups' => true], ['b', 'a', 'b', 7]),
		 'groupsTruthy' => $c->valuesFromGroups(['groups' => 1], ['b']),
		 'prefix' => $c->valuesFromGroups(['groupPrefix' => ' dep- '], ['dep-a', 'dep-', 'x-dep-b', 'dep-a', 'dep-c']),
		 'prefixPlusGroups' => $c->valuesFromGroups(['groups' => true, 'groupPrefix' => 'dep-'], ['dep-a', 'z']),
		 'nullSource' => $c->valuesFromGroups(null, ['dep-a']),
		 'resolve' => [$c->resolveAction('read'), $c->resolveAction('handle'), $c->resolveAction('handle', ['handle']), $c->resolveAction('handle', ['x'])],
		];

		return $out;
	}//end outputs()

	/**
	 * Every output matches the golden.
	 *
	 * @return void
	 */
	public function testTheOutputsMatchTheGolden(): void {
		$actual = $this->outputs();
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))."\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $key => $value) {
			// JSON round-trip, so float/int keys and values compare as stored.
			$this->assertSame($value, json_decode((string) json_encode($actual[$key]), true), $key);
		}
	}//end testTheOutputsMatchTheGolden()

	/**
	 * The refused rows really are absent, so a fixture rewrite cannot hide one coming back.
	 *
	 * @return void
	 */
	public function testRefusedRowsCompileToNothing(): void {
		$compiled = $this->outputs()['compiled'];
		$groups   = [];
		foreach ($compiled as $rules) {
			foreach ($rules as $rule) {
				$groups[$rule['group']] = true;
			}
		}

		ksort($groups);
		$this->assertSame(['cross', 'future', 'marked', 'num', 'staff'], array_keys($groups));
		$this->assertArrayNotHasKey('bogus', $compiled);
		$this->assertArrayNotHasKey('handle', $compiled);
		$this->assertSame([], $this->outputs()['noField']);
		$this->assertSame([], $this->outputs()['defaultClock'][0]);
		$this->assertNotSame([], $this->outputs()['defaultClock'][1]);
	}//end testRefusedRowsCompileToNothing()
}//end class
