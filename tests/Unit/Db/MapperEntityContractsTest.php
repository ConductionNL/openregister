<?php

/**
 * The source and application mapper contracts return the Kernel entity contracts.
 *
 * SourceMapperInterface and ApplicationMapperInterface are read below the area of
 * their entity (Core reads sources, Integrations reads applications). Their native
 * return types name the Kernel contracts, while the mappers keep returning the
 * entity itself, so every caller still receives the same object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ApplicationMapper;
use OCA\OpenRegister\Db\ApplicationMapperInterface;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ApplicationInterface;
use OCA\OpenRegister\Kernel\Contracts\SourceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * @coversNothing
 */
final class MapperEntityContractsTest extends TestCase {

	/**
	 * Contract, mapper, method, the Kernel type the contract returns and the entity the mapper returns.
	 *
	 * @return array<string, array{class-string, class-string, string, class-string, class-string}>
	 */
	public static function entityReturningMethods(): array {
		$cases = [];
		foreach (['createFromArray', 'find', 'findForSystem', 'updateFromArray'] as $method) {
			$cases['source ' . $method] = [SourceMapperInterface::class, SourceMapper::class, $method, SourceInterface::class, Source::class];
		}

		foreach (['createFromArray', 'find', 'findByUuid', 'updateFromArray'] as $method) {
			$cases['application ' . $method] = [
				ApplicationMapperInterface::class,
				ApplicationMapper::class,
				$method,
				ApplicationInterface::class,
				Application::class,
			];
		}

		return $cases;
	}//end entityReturningMethods()

	/**
	 * The contract returns the Kernel type; the mapper narrows it to its entity.
	 *
	 * @param class-string $contract The mapper contract.
	 * @param class-string $mapper   The mapper.
	 * @param string       $method   The method.
	 * @param class-string $kernel   The Kernel entity contract.
	 * @param class-string $entity   The entity.
	 *
	 * @return void
	 */
	#[DataProvider('entityReturningMethods')]
	public function testTheContractReturnsTheKernelTypeAndTheMapperTheEntity(
		string $contract,
		string $mapper,
		string $method,
		string $kernel,
		string $entity
	): void {
		$this->assertSame(expected: $kernel, actual: $this->returnTypeOf(class: $contract, method: $method));
		$this->assertSame(expected: $entity, actual: $this->returnTypeOf(class: $mapper, method: $method));
		$this->assertContains(needle: $kernel, haystack: class_implements($entity), message: $entity . ' implements ' . $kernel);
	}//end testTheContractReturnsTheKernelTypeAndTheMapperTheEntity()

	/**
	 * The contracts name no entity class in their method signatures.
	 *
	 * @return void
	 */
	public function testTheContractsNameNoEntityInASignature(): void {
		foreach ([SourceMapperInterface::class => Source::class, ApplicationMapperInterface::class => Application::class] as $contract => $entity) {
			foreach ((new \ReflectionClass($contract))->getMethods() as $method) {
				$name = $method->getName();
				$this->assertNotSame(expected: $entity, actual: $this->returnTypeOf(class: $contract, method: $name), message: $contract . '::' . $name);
			}
		}
	}//end testTheContractsNameNoEntityInASignature()

	/**
	 * The name of a method's native return type, without its nullability.
	 *
	 * @param class-string $class  The class.
	 * @param string       $method The method.
	 *
	 * @return string The type name, or '' when it has none or a union.
	 */
	private function returnTypeOf(string $class, string $method): string {
		$type = (new ReflectionMethod($class, $method))->getReturnType();
		if ($type instanceof ReflectionNamedType === false) {
			return '';
		}

		return $type->getName();
	}//end returnTypeOf()
}//end class
