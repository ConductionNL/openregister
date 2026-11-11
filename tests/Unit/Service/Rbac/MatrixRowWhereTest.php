<?php

/**
 * Tests for MatrixRowWhere.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\MatrixRowWhere;
use PHPUnit\Framework\TestCase;

/**
 * A matrix row's `where` reads as conditions, or not at all.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\MatrixRowWhere
 */
class MatrixRowWhereTest extends TestCase {

	/**
	 * Scalars and lists read as conditions, sorted by key; a `$in` object is unwrapped.
	 *
	 * @return void
	 */
	public function testReadableWhereBecomesSortedConditions(): void {
		$this->assertSame(
			['kind' => ['$in' => ['a', 'b']], 'level' => 3, 'status' => ['$in' => ['open']]],
			(new MatrixRowWhere())->conditionsOf(
				row: ['where' => ['status' => ['$in' => ['open']], ' level ' => 3, 'kind' => ['a', 'b']]],
				field: 'department'
			)
		);
		$this->assertSame([], (new MatrixRowWhere())->conditionsOf(row: [], field: 'department'));
	}//end testReadableWhereBecomesSortedConditions()

	/**
	 * Any unreadable part makes the whole row unreadable.
	 *
	 * @return void
	 */
	public function testUnreadableWhereIsRefusedWhole(): void {
		$where = new MatrixRowWhere();
		$cases = [
			'non-array where'      => ['where' => 'status=open'],
			'blank key'            => ['where' => [' ' => 'x']],
			'the matrix field'     => ['where' => ['department' => 'x']],
			'empty list'           => ['where' => ['status' => []]],
			'nested non-scalar'    => ['where' => ['status' => [['open']]]],
			'other operator'       => ['where' => ['status' => ['$gt' => 3]]],
			'$in of non-list'      => ['where' => ['status' => ['$in' => ['k' => 'v']]]],
			'$in not an array'     => ['where' => ['status' => ['$in' => 'open']]],
			'object value'         => ['where' => ['status' => new \stdClass()]],
			'organisation crossed' => ['where' => ['owner' => '$organisation']],
		];

		foreach ($cases as $label => $row) {
			$this->assertNull($where->conditionsOf(row: $row, field: 'department'), $label);
		}
	}//end testUnreadableWhereIsRefusedWhole()

	/**
	 * Binding to the caller's organisation is a crossing unless it is the organisation
	 * column itself or the row says it is meant.
	 *
	 * @return void
	 */
	public function testOrganisationCrossingNeedsIntent(): void {
		$where = new MatrixRowWhere();

		$this->assertTrue($where->declaresCrossing(row: []) === false && $where->bindsToOrganisation(key: 'owner', value: '$organisation'));
		$this->assertFalse($where->bindsToOrganisation(key: '_organisation', value: '$organisation'));
		$this->assertTrue($where->declaresCrossing(row: ['crossOrganisation' => true]));
		$this->assertFalse($where->declaresCrossing(row: ['crossOrganisation' => 'true']));
		$this->assertFalse($where->bindsToOrganisation(key: 'owner', value: 'acme'));
		$this->assertSame(
			['owner' => '$organisation'],
			$where->conditionsOf(row: ['crossOrganisation' => true, 'where' => ['owner' => '$organisation']], field: 'department')
		);
	}//end testOrganisationCrossingNeedsIntent()

	/**
	 * Each crossing is reported with the row name and key; intended ones are not.
	 *
	 * @return void
	 */
	public function testCrossingFindingsNameTheKey(): void {
		$where    = new MatrixRowWhere();
		$findings = $where->crossingFindings(row: ['where' => ['owner' => '$organisation', 'status' => 'open']], name: 'row 2');

		$this->assertSame(['matrix.where-crosses-organisations'], array_column($findings, 'code'));
		$this->assertStringStartsWith('row 2: where binds "owner" to the caller\'s organisation', $findings[0]['message']);
		$this->assertSame([], $where->crossingFindings(row: ['crossOrganisation' => true, 'where' => ['owner' => '$organisation']], name: 'r'));
		$this->assertSame([], $where->crossingFindings(row: [], name: 'r'));
	}//end testCrossingFindingsNameTheKey()
}//end class
