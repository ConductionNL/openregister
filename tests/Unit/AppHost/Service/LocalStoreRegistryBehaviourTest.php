<?php

/**
 * Behaviour tests for LocalStoreRegistry's refusal and fallback branches.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Service;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\LocalStoreRegistry;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Unusable object service, non-array search answers, failed writes.
 */
class LocalStoreRegistryBehaviourTest extends TestCase {

	/**
	 * A container that answers each get() with the next of the given values.
	 *
	 * @param mixed ...$answers What get() returns, in order.
	 *
	 * @return ContainerInterface
	 */
	private function container(mixed ...$answers): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->exactly(count($answers)))
			->method('get')
			->with(ObjectOperationsInterface::class)
			->willReturnOnConsecutiveCalls(...$answers);
		return $container;
	}//end container()

	/**
	 * A container entry that is not an object service reads as unreachable.
	 *
	 * @return void
	 */
	public function testAReadWithoutAnObjectServiceIsUnreachable(): void {
		$registry = new LocalStoreRegistry($this->container(new stdClass()), $this->createMock(LoggerInterface::class));

		$this->assertSame(
			['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'results' => []],
			$registry->read(new RegisterSchemaRef(register: 'store', schema: 'item'), ['_limit' => 10])
		);
	}//end testAReadWithoutAnObjectServiceIsUnreachable()

	/**
	 * A count (int) answer from the search is not a list of items: ok, but empty.
	 *
	 * @return void
	 */
	public function testANonArraySearchAnswerReadsAsEmpty(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())->method('searchObjectsBySlug')->willReturn(7);

		$registry = new LocalStoreRegistry($this->container($objects), $this->createMock(LoggerInterface::class));

		$this->assertSame(
			['outcome' => GenericStoreService::OUTCOME_OK, 'results' => []],
			$registry->read(new RegisterSchemaRef(register: 'store', schema: 'item'), ['_limit' => 10])
		);
	}//end testANonArraySearchAnswerReadsAsEmpty()

	/**
	 * Paired control: an array answer is returned as rows.
	 *
	 * @return void
	 */
	public function testAnArraySearchAnswerIsReturned(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())->method('searchObjectsBySlug')->willReturn([['slug' => 'a']]);

		$registry = new LocalStoreRegistry($this->container($objects), $this->createMock(LoggerInterface::class));

		$this->assertSame(
			['outcome' => GenericStoreService::OUTCOME_OK, 'results' => [['slug' => 'a']]],
			$registry->read(new RegisterSchemaRef(register: 'store', schema: 'item'), ['_limit' => 10])
		);
	}//end testAnArraySearchAnswerIsReturned()

	/**
	 * A write whose lookup read is unreachable stops there: it answers the
	 * read's outcome and never resolves the service a second time to save.
	 *
	 * @return void
	 */
	public function testAWriteWhoseLookupFailsAnswersTheLookupOutcome(): void {
		$registry = new LocalStoreRegistry($this->container(new stdClass()), $this->createMock(LoggerInterface::class));

		$this->assertSame(
			['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'slug' => ''],
			$registry->write(new RegisterSchemaRef(register: 'store', schema: 'item'), ['slug' => 'demo'])
		);
	}//end testAWriteWhoseLookupFailsAnswersTheLookupOutcome()

	/**
	 * A write whose second container lookup is not an object service is
	 * unreachable, and nothing is saved.
	 *
	 * @return void
	 */
	public function testAWriteWhoseServiceVanishesIsUnreachable(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willReturn([]);
		$objects->expects($this->never())->method('saveObject');

		$registry = new LocalStoreRegistry(
			$this->container($objects, new stdClass()),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(
			['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'slug' => ''],
			$registry->write(new RegisterSchemaRef(register: 'store', schema: 'item'), ['slug' => 'demo'])
		);
	}//end testAWriteWhoseServiceVanishesIsUnreachable()

	/**
	 * A save that throws is logged and answered unreachable, never ok.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsUnreachable(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willReturn([['slug' => 'demo', 'id' => 'uuid-1']]);
		$objects->expects($this->once())
			->method('saveObject')
			->with(['slug' => 'demo'], $this->callback(static fn (ObjectSaveOptions $options): bool => $options->extend == [] && $options->target->register == 'store' && $options->target->schema == 'item' && $options->uuid == 'uuid-1'))
			->willThrowException(new RuntimeException('disk full'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('disk full'));

		$registry = new LocalStoreRegistry($this->container($objects, $objects), $logger);

		$this->assertSame(
			['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'slug' => ''],
			$registry->write(new RegisterSchemaRef(register: 'store', schema: 'item'), ['slug' => 'demo'])
		);
	}//end testAFailedSaveIsUnreachable()

	/**
	 * Paired control: a save that succeeds answers ok with the slug.
	 *
	 * @return void
	 */
	public function testASucceedingSaveIsOk(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willReturn([]);
		$objects->expects($this->once())
			->method('saveObject')
			->with(['slug' => 'demo'], $this->callback(static fn (ObjectSaveOptions $options): bool => $options->extend == [] && $options->target->register == 'store' && $options->target->schema == 'item' && $options->uuid === null));

		$registry = new LocalStoreRegistry($this->container($objects, $objects), $this->createMock(LoggerInterface::class));

		$this->assertSame(
			['outcome' => GenericStoreService::OUTCOME_OK, 'slug' => 'demo'],
			$registry->write(new RegisterSchemaRef(register: 'store', schema: 'item'), ['slug' => 'demo'])
		);
	}//end testASucceedingSaveIsOk()
}//end class
