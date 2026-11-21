<?php

/**
 * Unit tests for CasePayload
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Case;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Case\CasePayload;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins how a loaded case is checked and how a list field is read off case data.
 *
 * @covers \OCA\OpenRegister\Service\Gdpr\Case\CasePayload
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class CasePayloadTest extends TestCase {

	/**
	 * A loaded case is handed back as it is.
	 *
	 * @return void
	 */
	public function testLoadedReturnsTheCase(): void {
		$case = new ObjectEntity();

		$this->assertSame(expected: $case, actual: CasePayload::loaded(case: $case, caseUuid: 'case-1'));
	}//end testLoadedReturnsTheCase()

	/**
	 * A missing case is refused, naming the case.
	 *
	 * @return void
	 */
	public function testLoadedRefusesAMissingCase(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Case "case-9" not found or not authorised.');

		CasePayload::loaded(case: null, caseUuid: 'case-9');
	}//end testLoadedRefusesAMissingCase()

	/**
	 * A list field is returned as a list, its keys dropped.
	 *
	 * @return void
	 */
	public function testListFieldReindexesTheList(): void {
		$data = ['evidence' => ['a' => ['x' => 1], 'b' => ['x' => 2]]];

		$this->assertSame(expected: [['x' => 1], ['x' => 2]], actual: CasePayload::listField(data: $data, key: 'evidence'));
	}//end testListFieldReindexesTheList()

	/**
	 * A missing, null or scalar field reads as an empty list.
	 *
	 * @return void
	 */
	public function testListFieldReadsAnythingElseAsEmpty(): void {
		$this->assertSame(expected: [], actual: CasePayload::listField(data: [], key: 'evidence'));
		$this->assertSame(expected: [], actual: CasePayload::listField(data: ['evidence' => null], key: 'evidence'));
		$this->assertSame(expected: [], actual: CasePayload::listField(data: ['evidence' => 'none'], key: 'evidence'));
	}//end testListFieldReadsAnythingElseAsEmpty()
}//end class
