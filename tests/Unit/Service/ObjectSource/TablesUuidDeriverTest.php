<?php

/**
 * Unit tests for TablesUuidDeriver.
 *
 * Covers the deterministic UUIDv5 derivation contract (design D9): the same
 * `(tableId, rowId)` always yields the same uuid, different inputs yield
 * different uuids, and the result is a valid RFC-4122 UUID — so a relation cell
 * and the target object's own uuid always agree.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Test class for TablesUuidDeriver.
 */
class TablesUuidDeriverTest extends TestCase {

	/**
	 * The derivation is deterministic and produces a valid UUID.
	 *
	 * @return void
	 */
	public function testDerivationIsDeterministicAndValid(): void {
		$deriver = new TablesUuidDeriver();

		$first = $deriver->deriveObjectUuid(tableId: 7, rowId: 42);
		$second = $deriver->deriveObjectUuid(tableId: 7, rowId: 42);

		$this->assertSame($first, $second);
		$this->assertTrue(Uuid::isValid($first));
	}//end testDerivationIsDeterministicAndValid()

	/**
	 * Different table/row pairs derive to different uuids.
	 *
	 * @return void
	 */
	public function testDifferentInputsDeriveDifferently(): void {
		$deriver = new TablesUuidDeriver();

		$a = $deriver->deriveObjectUuid(tableId: 7, rowId: 42);
		$b = $deriver->deriveObjectUuid(tableId: 7, rowId: 43);
		$c = $deriver->deriveObjectUuid(tableId: 8, rowId: 42);

		$this->assertNotSame($a, $b);
		$this->assertNotSame($a, $c);
	}//end testDifferentInputsDeriveDifferently()

	/**
	 * looksLikeUuid() distinguishes uuids from numeric row ids.
	 *
	 * @return void
	 */
	public function testLooksLikeUuid(): void {
		$deriver = new TablesUuidDeriver();

		$this->assertTrue($deriver->looksLikeUuid($deriver->deriveObjectUuid(tableId: 1, rowId: 1)));
		$this->assertFalse($deriver->looksLikeUuid('42'));
		$this->assertFalse($deriver->looksLikeUuid('not-a-uuid'));
	}//end testLooksLikeUuid()
}//end class
