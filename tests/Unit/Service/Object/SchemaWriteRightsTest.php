<?php

/**
 * SchemaWriteRights: the Core answer to the Kernel schema write-rights port.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
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
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Object\SchemaWriteRights;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The port asks the permission check for `update` on the schema in the register.
 */
#[CoversClass(SchemaWriteRights::class)]
#[UsesClass(PermissionAsk::class)]
#[UsesClass(Schema::class)]
final class SchemaWriteRightsTest extends TestCase {

	public function testItAsksForUpdateOnTheSchemaInTheRegister(): void {
		$schema = new Schema();
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->expects($this->once())
			->method('hasPermission')
			->with($this->callback(static fn (PermissionAsk $ask): bool => $ask->schema === $schema
				&& $ask->action === 'update'
				&& $ask->userId === null
				&& $ask->objectOwner === null
				&& $ask->object === null
				&& $ask->register === '7'))
			->willReturn(true);

		$this->assertTrue((new SchemaWriteRights(permissions: $permissions))->mayUpdate(schema: $schema, register: '7'));
	}

	public function testARefusalIsANo(): void {
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->method('hasPermission')->willReturn(false);

		$this->assertFalse((new SchemaWriteRights(permissions: $permissions))->mayUpdate(schema: new Schema(), register: '7'));
	}

	public function testAnErrorReachesTheCaller(): void {
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->method('hasPermission')->willThrowException(new RuntimeException('no session'));

		$this->expectException(RuntimeException::class);
		(new SchemaWriteRights(permissions: $permissions))->mayUpdate(schema: new Schema(), register: '7');
	}

	public function testASchemaThePlatformDidNotLoadIsANo(): void {
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->expects($this->never())->method('hasPermission');

		$this->assertFalse((new SchemaWriteRights(permissions: $permissions))->mayUpdate(schema: $this->createMock(SchemaInterface::class), register: '7'));
	}
}//end class
