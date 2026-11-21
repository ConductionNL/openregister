<?php

/**
 * Unit tests for ObjectServiceMapperAdapter find, write and pagination delegation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectServiceMapperAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ObjectServiceMapperAdapter
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 */
final class ObjectServiceMapperAdapterDelegationTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Create the ObjectService mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
	}//end setUp()

	/**
	 * An adapter bound to this register and schema.
	 *
	 * @param int|string|null $register The register.
	 * @param int|string|null $schema   The schema.
	 *
	 * @return ObjectServiceMapperAdapter
	 */
	private function adapter(int|string|null $register = 4, int|string|null $schema = '12'): ObjectServiceMapperAdapter {
		return new ObjectServiceMapperAdapter(objectService: $this->objectService, register: $register, schema: $schema);
	}//end adapter()

	/**
	 * find() reads in the bound scope with the requested extensions.
	 *
	 * @return void
	 */
	public function testFindReadsInTheBoundScopeWithExtensions(): void {
		$entity = new ObjectEntity();
		$this->objectService->expects($this->once())->method('find')
			->with(
				'abc',
				$this->callback(
					static fn (ObjectFindOptions $o): bool => $o->register === 4 && $o->schema === '12' && $o->extend === ['owner']
				)
			)
			->willReturn($entity);

		$this->assertSame($entity, $this->adapter()->find('abc', ['owner']));
	}//end testFindReadsInTheBoundScopeWithExtensions()

	/**
	 * find() without extensions passes an empty list and forwards a null result.
	 *
	 * @return void
	 */
	public function testFindWithoutExtensionsPassesAnEmptyList(): void {
		$this->objectService->expects($this->once())->method('find')
			->with(7, $this->callback(static fn (ObjectFindOptions $o): bool => $o->extend === [] && $o->register === null))
			->willReturn(null);

		$this->assertNull($this->adapter(null, null)->find(7));
	}//end testFindWithoutExtensionsPassesAnEmptyList()

	/**
	 * createFromArray() saves the array unchanged in the bound scope.
	 *
	 * @return void
	 */
	public function testCreateFromArraySavesInTheBoundScope(): void {
		$saved = new ObjectEntity();
		$this->objectService->expects($this->once())->method('saveObject')
			->with(
				['title' => 'A'],
				$this->callback(
					static fn (ObjectSaveOptions $o): bool => $o->target->register === 4 && $o->target->schema === '12' && $o->uuid === null
				)
			)
			->willReturn($saved);

		$this->assertSame($saved, $this->adapter()->createFromArray(['title' => 'A']));
	}//end testCreateFromArraySavesInTheBoundScope()

	/**
	 * update() saves the entity itself in the bound scope.
	 *
	 * @return void
	 */
	public function testUpdateSavesTheEntityInTheBoundScope(): void {
		$entity = new ObjectEntity();
		$this->objectService->expects($this->once())->method('saveObject')
			->with(
				$this->identicalTo($entity),
				$this->callback(static fn (ObjectSaveOptions $o): bool => $o->target->register === 4 && $o->target->schema === '12')
			)
			->willReturn($entity);

		$this->assertSame($entity, $this->adapter()->update($entity));
	}//end testUpdateSavesTheEntityInTheBoundScope()

	/**
	 * The bound register and schema are reported as integers.
	 *
	 * @return void
	 */
	public function testGettersCastTheBoundScopeToIntegers(): void {
		$adapter = $this->adapter('4', '12');

		$this->assertSame(4, $adapter->getRegister());
		$this->assertSame(12, $adapter->getSchema());
	}//end testGettersCastTheBoundScopeToIntegers()

	/**
	 * An unbound adapter reports no register or schema.
	 *
	 * @return void
	 */
	public function testGettersReturnNullWhenUnbound(): void {
		$adapter = $this->adapter(null, null);

		$this->assertNull($adapter->getRegister());
		$this->assertNull($adapter->getSchema());
	}//end testGettersReturnNullWhenUnbound()

	/**
	 * findAllPaginated() adds the bound scope and normalises the result keys.
	 *
	 * @return void
	 */
	public function testFindAllPaginatedAddsScopeAndNormalisesTheResult(): void {
		$this->objectService->expects($this->once())->method('searchObjectsPaginated')
			->with(['_limit' => 5, '_register' => 4, '_schema' => '12'])
			->willReturn(['results' => [['id' => 1]], 'total' => 9, 'page' => 2, 'pages' => 3, 'facets' => ['x']]);

		$this->assertSame(
			['results' => [['id' => 1]], 'total' => 9, 'page' => 2, 'pages' => 3],
			$this->adapter()->findAllPaginated(['_limit' => 5])
		);
	}//end testFindAllPaginatedAddsScopeAndNormalisesTheResult()

	/**
	 * findAllPaginated() keeps the caller's scope and defaults missing result keys.
	 *
	 * @return void
	 */
	public function testFindAllPaginatedKeepsCallerScopeAndDefaultsKeys(): void {
		$this->objectService->expects($this->once())->method('searchObjectsPaginated')
			->with(['_register' => 99, '_schema' => 98])
			->willReturn([]);

		$this->assertSame(
			['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1],
			$this->adapter()->findAllPaginated(['_register' => 99, '_schema' => 98])
		);
	}//end testFindAllPaginatedKeepsCallerScopeAndDefaultsKeys()

	/**
	 * An unbound adapter adds no scope to the paginated query.
	 *
	 * @return void
	 */
	public function testFindAllPaginatedUnboundAddsNoScope(): void {
		$this->objectService->expects($this->once())->method('searchObjectsPaginated')
			->with([])
			->willReturn(['total' => 1]);

		$this->assertSame(1, $this->adapter(null, null)->findAllPaginated()['total']);
	}//end testFindAllPaginatedUnboundAddsNoScope()

	/**
	 * getValidateHandler() hands out the ObjectService's validator.
	 *
	 * @return void
	 */
	public function testGetValidateHandlerDelegates(): void {
		$handler = $this->createMock(ValidateObjectInterface::class);
		$this->objectService->expects($this->once())->method('getValidateHandler')->willReturn($handler);

		$this->assertSame($handler, $this->adapter()->getValidateHandler());
	}//end testGetValidateHandlerDelegates()
}//end class
