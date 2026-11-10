<?php

/**
 * Unit tests for UtilityHandler — uuid checks, array normalising, URL separators and entity lookup.
 *
 * Each helper has one deciding input; each test flips it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UtilityHandlerTest extends TestCase {

	private RegisterMapperInterface&MockObject $registers;

	private SchemaMapperInterface&MockObject $schemas;

	private UtilityHandler $handler;

	protected function setUp(): void {
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas   = $this->createMock(SchemaMapperInterface::class);
		$this->handler   = new UtilityHandler($this->registers, $this->schemas);
	}

	public function testOnlyAUuidStringIsAUuid(): void {
		$this->assertTrue($this->handler->isUuid('0b1e2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d'));
		$this->assertFalse($this->handler->isUuid('not-a-uuid'));
		$this->assertFalse($this->handler->isUuid(42));
		$this->assertFalse($this->handler->isUuid(null));
	}

	public function testNormalizeToArrayWrapsAScalarAndKeepsAnArray(): void {
		$this->assertSame(['a', 'b'], $this->handler->normalizeToArray(['a', 'b']));
		$this->assertSame(['a'], $this->handler->normalizeToArray('a'));
		$this->assertSame([null], $this->handler->normalizeToArray(null));
	}

	public function testTheUrlSeparatorDependsOnAnExistingQuery(): void {
		$this->assertSame('?', $this->handler->getUrlSeparator('https://x.example/path'));
		$this->assertSame('&', $this->handler->getUrlSeparator('https://x.example/path?a=1'));
	}

	public function testAnIdOrSlugIsLookedUpInTheMapperItsTypeNames(): void {
		$register = new Register();
		$schema = new Schema();
		$this->registers->expects($this->once())->method('find')->with('zaken')->willReturn($register);
		$this->schemas->expects($this->once())->method('find')->with(7)->willReturn($schema);

		$this->assertSame($register, $this->handler->normalizeEntity('zaken', 'register'));
		$this->assertSame($schema, $this->handler->normalizeEntity(7, 'schema'));
	}

	public function testAnEntityOrNullIsReturnedWithoutALookup(): void {
		$register = new Register();
		$this->registers->expects($this->never())->method('find');
		$this->schemas->expects($this->never())->method('find');

		$this->assertSame($register, $this->handler->normalizeEntity($register, 'register'));
		$this->assertNull($this->handler->normalizeEntity(null, 'schema'));
	}
}
