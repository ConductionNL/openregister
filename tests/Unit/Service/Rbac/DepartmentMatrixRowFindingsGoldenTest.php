<?php

/**
 * Golden test for the per-row findings of DepartmentMatrixValidator.
 *
 * Pins every row finding, code and message, and their order, for refused rows,
 * and none for an allowed row, so the row check can be split without changing
 * which matrices are stored or what an administrator is told.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use PHPUnit\Framework\TestCase;

/**
 * Row findings, pinned whole.
 */
class DepartmentMatrixRowFindingsGoldenTest extends TestCase {

	/**
	 * The findings for a matrix with these rows.
	 *
	 * @param array<int|string, mixed> $rows The rows.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private static function findings(array $rows): array {
		return (new DepartmentMatrixValidator())->validate(
			properties: ['department' => ['type' => 'string']],
			authorization: [
				DepartmentMatrixCompiler::KEY => [
					'field' => 'department',
					'userSource' => ['groupPrefix' => 'dept:'],
					'rows' => $rows,
				],
			]
		);
	}//end findings()

	public function testEveryRowFindingIsPinned(): void {
		$this->assertSame(
			[
				['code' => 'matrix.bad-row', 'message' => 'Row 0 is not an object.'],
				['code' => 'matrix.no-group', 'message' => 'Row 1 names no role group.'],
				['code' => 'matrix.no-actions', 'message' => 'Row 1 grants no action.'],
				['code' => 'matrix.no-actions', 'message' => 'Row x grants no action.'],
				['code' => 'matrix.no-group', 'message' => 'Row 2 names no role group.'],
				['code' => 'matrix.unknown-action', 'message' => 'Row 2 names the action "handel", which is not one this engine resolves.'],
			],
			self::findings(
				[
					0 => 'not a row',
					1 => ['value' => 'VTH', 'group' => ' ', 'actions' => []],
					'x' => ['value' => 'VTH', 'group' => 'g', 'actions' => 'read'],
					2 => ['value' => 'VTH', 'actions' => ['read', ' handel ']],
				]
			)
		);
		$this->assertSame([], self::findings([['value' => 'VTH', 'group' => 'g', 'actions' => ['read']]]));
	}//end testEveryRowFindingIsPinned()
}//end class
