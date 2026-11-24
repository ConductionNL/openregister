<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaReadCheckInterface;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Object\SchemaReadCheck;
use PHPUnit\Framework\TestCase;

/**
 * SchemaReadCheck answers the Kernel schema read port with the permission check's read verdict.
 *
 * @covers \OCA\OpenRegister\Service\Object\SchemaReadCheck
 */
class SchemaReadCheckTest extends TestCase {

	public function testAnAllowedReadIsTheSameHasPermissionCall(): void {
		$schema      = $this->createMock(Schema::class);
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->expects($this->once())->method('hasPermission')
			->with(...PermissionAskArgs::with($schema, 'read', null, null, true, null, '4'))->willReturn(true);

		$this->assertTrue((new SchemaReadCheck(permissions: $permissions))->canReadSchema(schema: $schema, register: '4'));
	}

	public function testARefusedReadIsRefused(): void {
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->method('hasPermission')->willReturn(false);

		$this->assertFalse((new SchemaReadCheck(permissions: $permissions))->canReadSchema(schema: $this->createMock(Schema::class), register: '4'));
	}

	public function testASchemaThatIsNotAStoredSchemaIsRefusedWithoutAsking(): void {
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->expects($this->never())->method('hasPermission');

		$check = new SchemaReadCheck(permissions: $permissions);
		$this->assertFalse($check->canReadSchema(schema: $this->createMock(SchemaInterface::class), register: '4'));
	}

	public function testThePortIsBoundByCore(): void {
		$this->assertSame(SchemaReadCheck::class, CoreServiceBindings::BINDINGS[SchemaReadCheckInterface::class]);
	}
}
