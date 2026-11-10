<?php

/**
 * Golden test for SchemaObjectReader's scan.
 *
 * Pins, before the read was split into small units, the exact search a
 * conversion preview runs (register and schema scope, the scan limit, RBAC
 * and multitenancy off), the register lookup it falls back on, and what a
 * non-array result yields.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schema\SchemaObjectReader;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class SchemaObjectReaderGoldenTest extends TestCase {

	private function catalog(RegisterMapper $registers, MagicMapper $objects): SchemaCatalog {
		return new SchemaCatalog($registers, $this->createMock(SchemaMapper::class), $objects);
	}

	private function register(int $id, array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSchemas($schemas);
		return $register;
	}

	public function testTheScanIsScopedLimitedAndUnrestricted(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('registerId')->willReturn(null);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->expects($this->once())->method('findAll')
			->with(null, null, [], [], [], false, false)
			->willReturn([$this->register(3, [1, 2]), $this->register(4, ['5']), $this->register(6, [5])]);
		$readable = $this->createMock(ObjectEntity::class);
		$readable->method('getObject')->willReturn(['name' => 'A']);
		$objects = $this->createMock(MagicMapper::class);
		$objects->expects($this->once())->method('searchObjects')
			->willReturnCallback(
				function (...$args) use ($readable): array {
					$this->assertSame(['@self' => ['register' => 4, 'schema' => 5], '_limit' => SchemaObjectReader::CONVERSION_SCAN_LIMIT], $args[0]);
					$this->assertFalse($args[2]);
					$this->assertFalse($args[3]);
					return [$readable];
				}
			);

		$this->assertSame(['A'], (new SchemaObjectReader($this->catalog($registers, $objects), $request))->storedValues(5, 'name'));
	}

	public function testANonArrayResultReadsAsNoValues(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('9');
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('searchObjects')->willReturn(7);

		$this->assertSame([], (new SchemaObjectReader($this->catalog($this->createMock(RegisterMapper::class), $objects), $request))->storedValues(5, 'name'));
	}

	public function testANonNumericOverrideFallsBackToTheRegisterLookup(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('abc');
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([$this->register(8, ['5'])]);

		$this->assertSame(8, (new SchemaObjectReader($this->catalog($registers, $this->createMock(MagicMapper::class)), $request))->resolveRegisterId(5));
	}
}
