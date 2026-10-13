<?php

/**
 * The store hands the set's planned accounts to OpenRegister's validator.
 *
 * The installer tests run over an in-memory store, so this is the test that
 * the real store passes the planned account ids on to ValidateObject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetStore;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetStore
 */
class ExampleSetStoreTest extends TestCase {
	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function testValidatePassesThePlannedAccountsToTheValidator(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('getSchema')->willReturn(42);

		$validator = $this->createMock(ValidateObject::class);
		$validator->expects($this->once())
			->method('validateObjectWithPlannedUsers')
			->with(['assignee' => 'daan.verbeek'], 42, $this->anything(), ['daan.verbeek'])
			->willReturn(new ValidationResult(null));
		$validator->expects($this->never())->method('validateObject');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => match ($id) {
			ObjectService::class  => $objects,
			ValidateObject::class => $validator,
		});

		$store = new ExampleSetStore(container: $container, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame([], $store->validate(register: 'crm', schema: 'ticket', data: ['assignee' => 'daan.verbeek'], plannedUsers: ['daan.verbeek']));
	}

	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
	 */
	public function testARegisterAndASchemaAreFoundByTheirSlugsAndAMissOrAFailureIsNull(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnCallback(function (mixed $register) use ($objects): ObjectService {
			if ($register !== 'publication') {
				throw new \RuntimeException('no such register');
			}

			return $objects;
		});
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('getRegister')->willReturn(31);
		$objects->method('getSchema')->willReturn(1113);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$store = new ExampleSetStore(container: $container, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame(31, $store->registerId(register: 'publication'));
		$this->assertSame(1113, $store->schemaId(register: 'publication', schema: 'publication'));
		$this->assertNull($store->registerId(register: 'nope'));
		$this->assertNull($store->schemaId(register: 'nope', schema: 'publication'));
	}
}
