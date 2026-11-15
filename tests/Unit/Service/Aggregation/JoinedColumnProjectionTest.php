<?php

/**
 * JoinedColumnProjection: one joined field stamped onto each parent row.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
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
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Aggregation\JoinedColumnProjection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Aggregation\JoinedColumnProjection
 */
class JoinedColumnProjectionTest extends TestCase {

	public function testStampsTheJoinedFieldOfTheMatchingRowOnEachParent(): void {
		$projection = new JoinedColumnProjection('client', 'uuid', 'name', 'clientName');
		$rows       = [
			['id' => 1, 'client' => 'c-1'],
			['id' => 2, 'client' => 'c-2'],
			['id' => 3, 'client' => 'c-9'],
			['id' => 4],
		];
		$joined     = [
			['uuid' => 'c-1', 'name' => 'Bakkerij'],
			['uuid' => 'c-2'],
			['name' => 'no key'],
		];

		$this->assertSame([
			['id' => 1, 'client' => 'c-1', 'clientName' => 'Bakkerij'],
			['id' => 2, 'client' => 'c-2', 'clientName' => null],
			['id' => 3, 'client' => 'c-9', 'clientName' => null],
			['id' => 4, 'clientName' => null],
		], $projection->stamp($rows, $joined));
	}

	public function testMatchesKeysAsStringsAndTheLastJoinedRowWins(): void {
		$projection = new JoinedColumnProjection('register', 'id', 'title', 'registerTitle');

		$stamped = $projection->stamp(
			[['register' => 5]],
			[['id' => '5', 'title' => 'First'], ['id' => 5, 'title' => 'Second']]
		);

		$this->assertSame('Second', $stamped[0]['registerTitle']);
	}

	public function testNoRowsStayNoRows(): void {
		$this->assertSame([], (new JoinedColumnProjection('a', 'b', 'c', 'd'))->stamp([], [['b' => 1, 'c' => 2]]));
	}
}
