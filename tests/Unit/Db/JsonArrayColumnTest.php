<?php

/**
 * JsonArrayColumnTest: reading a JSON-array column whatever the driver hands back.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\JsonArrayColumn;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\JsonArrayColumn
 */
class JsonArrayColumnTest extends TestCase {

	public function testDecodedReturnsAJsonArrayOrObject(): void {
		$column = new JsonArrayColumn();

		$this->assertSame(['a', 'b'], $column->decoded('["a","b"]'));
		$this->assertSame(['k' => 1], $column->decoded('{"k":1}'));
	}//end testDecodedReturnsAJsonArrayOrObject()

	public function testDecodedRefusesInvalidJsonAndScalars(): void {
		$column = new JsonArrayColumn();

		$this->assertNull($column->decoded('{broken'));
		$this->assertNull($column->decoded('42'));
		$this->assertNull($column->decoded('"text"'));
	}//end testDecodedRefusesInvalidJsonAndScalars()

	public function testAsArrayKeepsAnArrayAsItIs(): void {
		$this->assertSame(['x' => [1]], (new JsonArrayColumn())->asArray(['x' => [1]]));
	}//end testAsArrayKeepsAnArrayAsItIs()

	public function testAsArrayDecodesAStringAndFallsBackToEmpty(): void {
		$column = new JsonArrayColumn();

		$this->assertSame([1, 2], $column->asArray('[1,2]'));
		$this->assertSame([], $column->asArray('not json'));
	}//end testAsArrayDecodesAStringAndFallsBackToEmpty()

	public function testAsArrayTreatsAnyOtherValueAsEmpty(): void {
		$column = new JsonArrayColumn();

		$this->assertSame([], $column->asArray(null));
		$this->assertSame([], $column->asArray(5));
		$this->assertSame([], $column->asArray(true));
	}//end testAsArrayTreatsAnyOtherValueAsEmpty()
}//end class
