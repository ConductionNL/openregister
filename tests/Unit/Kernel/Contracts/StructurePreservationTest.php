<?php

/**
 * A structure preservation report serialises every field under a fixed key order and lists its loss reasons.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts;

use OCA\OpenRegister\Kernel\Contracts\StructurePreservation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Kernel\Contracts\StructurePreservation
 */
class StructurePreservationTest extends TestCase {

	public function testJsonSerializeCarriesEveryFieldInOrder(): void {
		$report = new StructurePreservation(
			requested: true,
			preserved: false,
			tagCountBefore: 120,
			tagCountAfter: 0,
			lossReasons: [StructurePreservation::LOSS_REASON_STRUCTTREEROOT_DROPPED]
		);

		$this->assertSame(
			[
				'requested' => true,
				'preserved' => false,
				'tagCountBefore' => 120,
				'tagCountAfter' => 0,
				'lossReasons' => ['structtreeroot-dropped-on-rebuild'],
			],
			$report->jsonSerialize()
		);
		$this->assertSame(
			'{"requested":true,"preserved":false,"tagCountBefore":120,"tagCountAfter":0,"lossReasons":["structtreeroot-dropped-on-rebuild"]}',
			json_encode($report)
		);
	}//end testJsonSerializeCarriesEveryFieldInOrder()

	public function testLossReasonsDefaultToAnEmptyList(): void {
		$report = new StructurePreservation(requested: false, preserved: true, tagCountBefore: 3, tagCountAfter: 3);

		$this->assertSame([], $report->jsonSerialize()['lossReasons']);
		$this->assertFalse($report->jsonSerialize()['requested']);
		$this->assertTrue($report->jsonSerialize()['preserved']);
	}//end testLossReasonsDefaultToAnEmptyList()

	public function testTheKnownLossReasonsAreListedWithTheirWireValues(): void {
		$this->assertSame(
			[
				'engine-cannot-reauthor-structtree',
				'marked-content-correspondence-broken',
				'structtreeroot-dropped-on-rebuild',
				'input-not-tagged',
				'page-structure-not-preservable',
			],
			StructurePreservation::LOSS_REASONS
		);
	}//end testTheKnownLossReasonsAreListedWithTheirWireValues()
}//end class
