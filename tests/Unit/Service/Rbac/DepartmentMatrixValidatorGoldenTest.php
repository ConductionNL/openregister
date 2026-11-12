<?php

/**
 * Golden test for DepartmentMatrixValidator::validate() and findingsForRow().
 *
 * Pins, against tests/Unit/Service/Rbac/fixtures/department-matrix-validator-golden.json
 * (written from the code before validate/rowsFindings/validateUserSource/
 * rowFindings/whereFindings were split), the exact findings for every shape a
 * matrix declaration can take: absent, not an object, field missing or
 * unknown, every userSource form, rowsFrom and fileVerbs, missing rows, and
 * rows with bad groups, actions, until and where. A declaration with no
 * findings is the allowed outcome; every finding is a refusal at save. Set
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

use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use OCA\OpenRegister\Service\Rbac\MatrixShape;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator
 */
final class DepartmentMatrixValidatorGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/department-matrix-validator-golden.json';

	private const PROPERTIES = ['department' => ['type' => 'string'], 'status' => ['type' => 'string'], 'org' => ['type' => 'string']];

	/**
	 * Matrix declarations, keyed by label.
	 *
	 * @return array<string, mixed>
	 */
	private function matrices(): array {
		$source = ['groupPrefix' => 'dep-'];
		$row    = ['group' => 'staff', 'actions' => ['read'], 'value' => 'x'];
		$base   = ['field' => 'department', 'userSource' => $source, 'rows' => [$row]];

		$rows = [
			'not-an-object' => 'row',
			'list-row' => [1, 2],
			'no-group' => ['actions' => ['read']],
			'blank-group' => ['group' => '  ', 'actions' => ['read']],
			'no-actions' => ['group' => 'g'],
			'actions-string' => ['group' => 'g', 'actions' => 'read'],
			'empty-actions' => ['group' => 'g', 'actions' => []],
			'unknown-actions' => ['group' => 'g', 'actions' => ['read', ' bogus ', 'approve', 7, ' handle ']],
			'no-group-no-actions' => [],
			'until-ok' => ['group' => 'g', 'actions' => ['read'], 'until' => '2031-01-01'],
			'until-bad' => ['group' => 'g', 'actions' => ['read'], 'until' => 'whenever'],
			'until-number' => ['group' => 'g', 'actions' => ['read'], 'until' => 20310101],
			'until-null' => ['group' => 'g', 'actions' => ['read'], 'until' => null],
			'where-string' => ['group' => 'g', 'actions' => ['read'], 'where' => 'x'],
			'where-list' => ['group' => 'g', 'actions' => ['read'], 'where' => ['a', 'b']],
			'where-empty' => ['group' => 'g', 'actions' => ['read'], 'where' => []],
			'where-ok' => ['group' => 'g', 'actions' => ['read'], 'where' => ['status' => 'open', '_owner' => 'x']],
			'where-repeats-field' => ['group' => 'g', 'actions' => ['read'], 'where' => ['department' => 'x']],
			'where-unknown' => ['group' => 'g', 'actions' => ['read'], 'where' => ['ghost' => 'x', 'status' => ['$in' => []]]],
			'where-bad-values' => ['group' => 'g', 'actions' => ['read'], 'where' => ['status' => [], 'org' => ['$in' => ['a']], '_organisation' => 'o']],
			'where-org-marked' => ['group' => 'g', 'actions' => ['read'], 'where' => ['_organisation' => 'o'], 'crossOrganisation' => true],
			'where-unknown-and-bad' => ['group' => 'g', 'actions' => ['read'], 'where' => ['ghost' => ['$nope' => 1]]],
			'until-and-where-bad' => ['group' => '', 'actions' => [], 'until' => 'x', 'where' => 'y'],
		];

		$out = [
			'absent' => null,
			'no-matrix-key' => ['read' => ['x']],
			'not-an-object' => 'matrix',
			'empty-object' => [],
			'valid' => $base,
			'blank-field' => (['field' => '  '] + $base),
			'unknown-field' => (['field' => 'afdeling'] + $base),
			'no-user-source' => ['field' => 'department', 'rows' => [$row]],
			'user-source-string' => (['userSource' => 'groups'] + $base),
			'user-source-empty' => (['userSource' => []] + $base),
			'user-source-groups' => (['userSource' => ['groups' => true]] + $base),
			'user-source-groups-truthy' => (['userSource' => ['groups' => 1]] + $base),
			'user-source-blank-prefix' => (['userSource' => ['groupPrefix' => ' ']] + $base),
			'user-source-schema' => (['userSource' => ['schema' => 'people', 'property' => 'dept']] + $base),
			'user-source-schema-no-property' => (['userSource' => ['schema' => 'people', 'property' => ' ']] + $base),
			'no-rows' => ['field' => 'department', 'userSource' => $source],
			'rows-empty' => (['rows' => []] + $base),
			'rows-string' => (['rows' => 'x'] + $base),
			'rows-from' => ['field' => 'department', 'userSource' => $source, 'rowsFrom' => 'provider'],
			'rows-from-empty-rows' => ['field' => 'department', 'userSource' => $source, 'rowsFrom' => 'provider', 'rows' => []],
			'rows-from-with-rows' => ['field' => 'department', 'userSource' => $source, 'rowsFrom' => 'provider', 'rows' => [$rows['no-group']]],
			'rows-from-blank' => ['field' => 'department', 'userSource' => $source, 'rowsFrom' => ' '],
			'rows-from-number' => ['field' => 'department', 'userSource' => $source, 'rowsFrom' => 5, 'rows' => [$row]],
			'file-verbs-ok' => (['fileVerbs' => true] + $base),
			'file-verbs-bad' => (['fileVerbs' => 'yes'] + $base),
			'file-verbs-null' => (['fileVerbs' => null] + $base),
			'everything-wrong' => ['field' => '', 'userSource' => 'x', 'rowsFrom' => '', 'fileVerbs' => 1],
			'keyed-rows' => (['rows' => ['first' => $rows['no-group'], 'second' => $row]] + $base),
		];

		foreach ($rows as $label => $badRow) {
			$out['row:'.$label] = (['rows' => [$row, $badRow]] + $base);
		}

		return $out;
	}//end matrices()

	/**
	 * Every output.
	 *
	 * @return array<string, mixed>
	 */
	private function outputs(): array {
		$validator = new DepartmentMatrixValidator(['approve']);
		$out       = [];
		foreach ($this->matrices() as $label => $matrix) {
			$authorization = ['read' => ['x']];
			if ($label !== 'no-matrix-key') {
				$authorization['matrix'] = $matrix;
			}

			if ($label === 'absent') {
				$authorization = null;
			}

			$out['validate:'.$label] = $validator->validate(self::PROPERTIES, $authorization);
		}

		foreach (['row' => 'row', 'array' => ['group' => '', 'actions' => ['nope'], 'where' => ['department' => 1, 'ghost' => []]]] as $label => $row) {
			$out['findingsForRow:'.$label] = $validator->findingsForRow($row, new MatrixShape(self::PROPERTIES, 'department'));
		}

		$out['withDeclaredActions'] = $validator->withDeclaredActions([])->validate(
			self::PROPERTIES,
			['matrix' => ['field' => 'department', 'userSource' => ['groups' => true], 'rows' => [['group' => 'g', 'actions' => ['approve']]]]]
		);

		return $out;
	}//end outputs()

	/**
	 * Every output matches the golden.
	 *
	 * @return void
	 */
	public function testTheFindingsMatchTheGolden(): void {
		$actual = $this->outputs();
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))."\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $key => $value) {
			$this->assertSame($value, $actual[$key], $key);
		}
	}//end testTheFindingsMatchTheGolden()

	/**
	 * The valid declarations pass and every refused one has at least one finding.
	 *
	 * @return void
	 */
	public function testAllowedAndRefusedDeclarations(): void {
		$outputs = $this->outputs();
		$allowed = [
			'validate:absent', 'validate:no-matrix-key', 'validate:valid', 'validate:user-source-groups',
			'validate:user-source-schema', 'validate:rows-from', 'validate:rows-from-empty-rows', 'validate:file-verbs-ok',
			'validate:row:until-ok', 'validate:row:until-null', 'validate:row:where-empty', 'validate:row:where-ok',
			'validate:row:where-org-marked',
		];
		foreach ($outputs as $key => $findings) {
			if (in_array($key, $allowed, true) === true) {
				$this->assertSame([], $findings, $key);
				continue;
			}

			$this->assertNotSame([], $findings, $key);
		}
	}//end testAllowedAndRefusedDeclarations()
}//end class
