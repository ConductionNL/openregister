<?php

/**
 * Golden: which writes an open action elevation lets through, and which it refuses.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\ObjectAction\ActionElevation;
use OCA\OpenRegister\Service\ObjectAction\ElevatedWrite;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope;
use PHPUnit\Framework\TestCase;

/**
 * permits(): allowed and refused writes, and the attribution an allowed one leaves.
 *
 * @covers \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 */
class ObjectActionElevationScopePermitsGoldenTest extends TestCase {

	/**
	 * Writes and whether the elevation of "publish" (register 3, schema 7, update and delete) permits them.
	 *
	 * @return array<string, array{0: ElevatedWrite, 1: bool}> The cases.
	 */
	public static function writes(): array {
		return [
			'listed update'          => [new ElevatedWrite(schemaId: 7, registerId: 3, verb: 'update'), true],
			'listed delete, strings' => [new ElevatedWrite(schemaId: '7', registerId: '3', verb: 'delete'), true],
			'unlisted verb'          => [new ElevatedWrite(schemaId: 7, registerId: 3, verb: 'create'), false],
			'not an elevatable verb' => [new ElevatedWrite(schemaId: 7, registerId: 3, verb: 'read'), false],
			'no schema'              => [new ElevatedWrite(schemaId: null, registerId: 3, verb: 'update'), false],
			'other schema'           => [new ElevatedWrite(schemaId: 8, registerId: 3, verb: 'update'), false],
			'other register'         => [new ElevatedWrite(schemaId: 7, registerId: 4, verb: 'update'), false],
			'no register'            => [new ElevatedWrite(schemaId: 7, registerId: null, verb: 'update'), false],
		];
	}//end writes()

	/**
	 * Inside the run the write is permitted or refused exactly as pinned; a permitted one is attributed.
	 *
	 * @param ElevatedWrite $write    The write.
	 * @param bool          $expected Whether it is permitted.
	 *
	 * @dataProvider writes
	 *
	 * @return void
	 */
	public function testPermits(ElevatedWrite $write, bool $expected): void {
		$elevation = new ActionElevation(
			action: 'publish',
			person: 'ann',
			entries: [
				['register' => '9', 'schema' => '7', 'verbs' => ['create']],
				['register' => '3', 'schema' => '7', 'verbs' => ['update', 'delete']],
			]
		);

		[$permitted, $attribution] = ObjectActionElevationScope::run(
			$elevation,
			static fn (): array => [ObjectActionElevationScope::permits($write), ObjectActionElevationScope::attributionFor($write->schemaId, $write->verb)]
		);

		$this->assertSame($expected, $permitted);
		$this->assertSame($expected ? ['elevatedBy' => 'publish', 'triggeredBy' => 'ann', 'runAs' => 'system'] : null, $attribution);
	}//end testPermits()

	/**
	 * Outside any run nothing is permitted.
	 *
	 * @return void
	 */
	public function testNothingIsPermittedOutsideARun(): void {
		$this->assertFalse(ObjectActionElevationScope::permits(new ElevatedWrite(schemaId: 7, registerId: 3, verb: 'update')));
	}//end testNothingIsPermittedOutsideARun()
}//end class
