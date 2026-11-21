<?php

/**
 * Unit tests for JoinedColumnProjection: one joined value stamped onto each parent row.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\JoinedColumnProjection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The projection looks each parent's key up among the joined rows and writes
 * the joined field into its column, null when there is no match.
 */
#[CoversClass(JoinedColumnProjection::class)]
class JoinedColumnProjectionTest extends TestCase {

	/**
	 * The projection of an organisation's name onto each case by its `org` key.
	 *
	 * @return JoinedColumnProjection
	 */
	private function projection(): JoinedColumnProjection {
		return new JoinedColumnProjection(parentKey: 'org', joinedKey: 'uuid', sourceField: 'name', column: 'orgName');
	}//end projection()

	/**
	 * Each parent row gets the joined value of its key, keeping its own fields and keys.
	 *
	 * @return void
	 */
	public function testEachRowGetsTheValueOfItsJoinedRow(): void {
		$rows = [
			'a' => ['id' => 1, 'org' => 'o-1'],
			'b' => ['id' => 2, 'org' => 'o-2'],
			'c' => ['id' => 3, 'org' => 'o-1'],
		];
		$joined = [
			['uuid' => 'o-1', 'name' => 'Gemeente A'],
			['uuid' => 'o-2', 'name' => 'Gemeente B'],
		];

		$this->assertSame(
			expected: [
				'a' => ['id' => 1, 'org' => 'o-1', 'orgName' => 'Gemeente A'],
				'b' => ['id' => 2, 'org' => 'o-2', 'orgName' => 'Gemeente B'],
				'c' => ['id' => 3, 'org' => 'o-1', 'orgName' => 'Gemeente A'],
			],
			actual: $this->projection()->stamp(rows: $rows, joinedRows: $joined)
		);
	}//end testEachRowGetsTheValueOfItsJoinedRow()

	/**
	 * No key, an unmatched key, or a joined row without the field is a null column, never a missing one.
	 *
	 * @return void
	 */
	public function testAnUnmatchedRowGetsANullColumn(): void {
		$rows   = [['org' => null], ['id' => 7], ['org' => 'o-9'], ['org' => 'o-3']];
		$joined = [['uuid' => 'o-3'], ['name' => 'no key'], ['uuid' => null, 'name' => 'null key']];

		$stamped = $this->projection()->stamp(rows: $rows, joinedRows: $joined);

		$this->assertSame(expected: [null, null, null, null], actual: array_column($stamped, 'orgName'));
		foreach ($stamped as $row) {
			$this->assertArrayHasKey(key: 'orgName', array: $row);
		}
	}//end testAnUnmatchedRowGetsANullColumn()

	/**
	 * Keys match by their string form, and a later joined row with the same key wins.
	 *
	 * @return void
	 */
	public function testKeysMatchAsStringsAndTheLastJoinedRowWins(): void {
		$projection = new JoinedColumnProjection(parentKey: 'ref', joinedKey: 'id', sourceField: 'label', column: 'refLabel');
		$joined     = [['id' => 5, 'label' => 'first'], ['id' => '5', 'label' => 'second'], ['id' => 6, 'label' => 0]];

		$stamped = $projection->stamp(rows: [['ref' => '5'], ['ref' => 6]], joinedRows: $joined);

		$this->assertSame(expected: ['second', 0], actual: array_column($stamped, 'refLabel'));
	}//end testKeysMatchAsStringsAndTheLastJoinedRowWins()

	/**
	 * An existing column is overwritten, and no rows stay no rows.
	 *
	 * @return void
	 */
	public function testTheColumnIsOverwrittenAndNoRowsStayNone(): void {
		$stamped = $this->projection()->stamp(rows: [['org' => 'o-1', 'orgName' => 'stale']], joinedRows: [['uuid' => 'o-1', 'name' => 'fresh']]);

		$this->assertSame(expected: 'fresh', actual: $stamped[0]['orgName']);
		$this->assertSame(expected: [], actual: $this->projection()->stamp(rows: [], joinedRows: [['uuid' => 'o-1', 'name' => 'x']]));
	}//end testTheColumnIsOverwrittenAndNoRowsStayNone()
}//end class
