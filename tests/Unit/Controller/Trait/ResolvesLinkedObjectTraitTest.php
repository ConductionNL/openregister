<?php

/**
 * ResolvesLinkedObjectTrait outcomes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Trait
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Trait;

use OCA\OpenRegister\Controller\Trait\ResolvesLinkedObjectTrait;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Pins what resolveLinkedObject() hands the object service, in which order, and what it returns.
 */
class ResolvesLinkedObjectTraitTest extends TestCase {

	/**
	 * Build a user of the trait over an object service mock.
	 *
	 * @param ObjectService $objectService The mock.
	 *
	 * @return object The trait user.
	 */
	private function resolver(ObjectService $objectService): object {
		return new class($objectService) {
			use ResolvesLinkedObjectTrait;

			public function __construct(private readonly ObjectService $objectService) {
			}

			public function resolve(string $register, string $schema, string $id): ?ObjectEntity {
				return $this->resolveLinkedObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
			}
		};
	}

	public function testSetsRegisterSchemaAndObjectInThatOrderAndReturnsTheObject(): void {
		$calls = [];
		$object = new ObjectEntity();
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnCallback(function ($value) use (&$calls, $service) {
			$calls[] = ['register', $value];
			return $service;
		});
		$service->method('setSchema')->willReturnCallback(function ($value) use (&$calls, $service) {
			$calls[] = ['schema', $value];
			return $service;
		});
		$service->method('setObject')->willReturnCallback(function ($value) use (&$calls, $service) {
			$calls[] = ['object', $value];
			return $service;
		});
		$service->method('getObject')->willReturn($object);

		$this->assertSame($object, $this->resolver($service)->resolve('reg', 'sch', 'uuid-1'));
		$this->assertSame([['register', 'reg'], ['schema', 'sch'], ['object', 'uuid-1']], $calls);
	}

	public function testAMissingObjectPassesTheNotFoundThrough(): void {
		$service = $this->createMock(ObjectService::class);
		$service->method('setObject')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(DoesNotExistException::class);
		$this->resolver($service)->resolve('reg', 'sch', 'uuid-2');
	}
}//end class
