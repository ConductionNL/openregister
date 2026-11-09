<?php

/**
 * Golden findings for a matrix row's `where` block, pinned before its refactor.
 *
 * A finding here is a refusal: a row that carries one never reaches the
 * compiler. A row without one is admitted. Each scenario records the complete
 * finding list the validator gave before the per-key check was split, in
 * order, so a check that moves, disappears or starts firing on a row it used
 * to admit fails here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins admitted and refused `where` conditions.
 */
class DepartmentMatrixWhereGoldenTest extends TestCase {

	private const UNKNOWN = 'matrix.where-unknown-field';
	private const REPEATS = 'matrix.where-repeats-field';
	private const BAD     = 'matrix.bad-where-value';

	/**
	 * The `where` blocks and the findings the pre-refactor code gave.
	 *
	 * @return array<string, array<int, mixed>> Scenario name => [where, expected].
	 */
	public static function scenarios(): array {
		$neither = '", which is neither a property of this schema nor _organisation or _owner.';
		$value   = '" must be a value, a non-empty list of values or {$in: [...]}.';

		return [
			'repeats the field' => [
				['department' => 'VTH'],
				[[self::REPEATS, 'Row 0: where repeats the matrix field "department".']],
			],
			'unknown field' => [
				['colour' => 'red'],
				[[self::UNKNOWN, 'Row 0: where names "colour' . $neither]],
			],
			'metadata field reads' => [
				['_organisation' => 'org-1'],
				[],
			],
			'property reads' => [
				['status' => ['$in' => ['open', 'closed']]],
				[],
			],
			'bad value' => [
				['status' => ['$in' => []]],
				[[self::BAD, 'Row 0: the condition on "status' . $value]],
			],
			'field and bad value give one finding' => [
				['department' => ['$in' => []]],
				[[self::REPEATS, 'Row 0: where repeats the matrix field "department".']],
			],
			'unknown and bad value give one finding' => [
				['colour' => ['nested' => ['x']]],
				[[self::UNKNOWN, 'Row 0: where names "colour' . $neither]],
			],
			'several keys in order' => [
				['colour' => 'red', 'status' => 'open', 'department' => 'x', '_owner' => ['$in' => []]],
				[
					[self::UNKNOWN, 'Row 0: where names "colour' . $neither],
					[self::REPEATS, 'Row 0: where repeats the matrix field "department".'],
					[self::BAD, 'Row 0: the condition on "_owner' . $value],
				],
			],
			'integer key' => [
				[5 => 'x'],
				[[self::UNKNOWN, 'Row 0: where names "5' . $neither]],
			],
		];
	}//end scenarios()

	/**
	 * The full finding list matches the pinned one.
	 *
	 * @param array<int|string, mixed>       $where    The row's `where` block.
	 * @param array<int, array<int, string>> $expected [code, message] pairs, in order.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testTheFindingsArePinned(array $where, array $expected): void {
		$properties = ['department' => ['type' => 'string'], 'status' => ['type' => 'string']];
		$row        = ['group' => 'vth', 'actions' => ['read'], 'where' => $where];

		$findings = (new DepartmentMatrixValidator())->findingsForRow($row, $properties, 'department');

		$pairs = [];
		foreach ($findings as $finding) {
			$pairs[] = [$finding['code'], $finding['message']];
		}

		$this->assertSame($expected, $pairs);
	}//end testTheFindingsArePinned()
}//end class
