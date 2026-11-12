<?php

/**
 * Mutation-killing tests for the caller-scoped object read.
 *
 * Written against the mutants Infection left alive in ObjectReadAccess: the
 * register is set before the schema (the schema slug resolves inside the
 * current register), the read runs with RBAC and multitenancy on, an
 * ambiguous lookup is surfaced by readableOrAmbiguous() and folded to null by
 * readable(), and an empty schema id never reaches the mapper.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ObjectReadAccessMutationTest extends TestCase {
	/**
	 * Test double or fixture for objects.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * Test double or fixture for schemas.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
	}//end setUp()

	private function access(): ObjectReadAccess {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new ObjectReadAccess($this->objects, $session, $this->schemas);
	}//end access()

	public function testRegisterIsSetBeforeSchemaAndTheReadIsScoped(): void {
		$calls = [];
		$this->objects->method('setRegister')->willReturnCallback(
			function (string $register) use (&$calls): ObjectOperationsInterface {
				$calls[] = 'register:' . $register;
				return $this->objects;
			}
		);
		$this->objects->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$calls): ObjectOperationsInterface {
				$calls[] = 'schema:' . $schema;
				return $this->objects;
			}
		);
		$found = new ObjectEntity();
		$found->setUuid('zaak-1');
		$this->objects->expects($this->once())
			->method('find')
			->with(...FindCallShape::findArgs('zaak-1', [], false, 'zaken', 'zaak', true, true))
			->willReturn($found);

		$this->assertSame($found, $this->access()->readableOrAmbiguous('zaken', 'zaak', 'zaak-1'));
		$this->assertSame(['register:zaken', 'schema:zaak'], $calls);
	}//end testRegisterIsSetBeforeSchemaAndTheReadIsScoped()

	public function testAnAmbiguousLookupIsSurfacedNotHidden(): void {
		$this->objects->method('find')->willThrowException(new MultipleObjectsReturnedException('two'));

		$this->expectException(MultipleObjectsReturnedException::class);
		$this->access()->readableOrAmbiguous('zaken', 'zaak', 'zaak-1');
	}//end testAnAmbiguousLookupIsSurfacedNotHidden()

	public function testReadableFoldsAnAmbiguousLookupToNull(): void {
		$this->objects->method('find')->willThrowException(new MultipleObjectsReturnedException('two'));

		$this->assertNull($this->access()->readable('zaken', 'zaak', 'zaak-1'));
	}//end testReadableFoldsAnAmbiguousLookupToNull()

	public function testAMissingOrForbiddenObjectReadsAsNull(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->assertNull($this->access()->readableOrAmbiguous('zaken', 'zaak', 'zaak-1'));
	}//end testAMissingOrForbiddenObjectReadsAsNull()

	public function testAnEmptySchemaIdNeverReachesTheMapper(): void {
		$this->schemas->expects($this->never())->method('find');

		$this->assertNull($this->access()->schemaById(''));
		$this->assertNull($this->access()->schemaById(null));
	}//end testAnEmptySchemaIdNeverReachesTheMapper()
}//end class
