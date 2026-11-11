<?php

/**
 * LogDanglingLinkedTypes wiring test.
 *
 * The repair step is resolved by the container. Every constructor parameter is
 * typed against a contract the container resolves, and the registry contract
 * aliases the same shared IntegrationRegistry the step was built with before,
 * so the step needs no hand-written factory in another component.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\LogDanglingLinkedTypes;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\IntegrationWiring;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * @covers \OCA\OpenRegister\Repair\LogDanglingLinkedTypes
 */
final class LogDanglingLinkedTypesWiringTest extends TestCase {

	/**
	 * The constructor takes only container-resolvable contracts, in this order.
	 *
	 * @return void
	 */
	public function testTheConstructorIsAutowirable(): void {
		$constructor = (new ReflectionClass(LogDanglingLinkedTypes::class))->getConstructor();
		$this->assertNotNull($constructor);

		$types = [];
		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type);
			$this->assertFalse($type->isBuiltin(), $parameter->getName() . ' is a class or interface');
			$types[] = $type->getName();
		}

		$this->assertSame(
			[$this->registryContract(), ContainerInterface::class, LoggerInterface::class],
			$types
		);
	}//end testTheConstructorIsAutowirable()

	/**
	 * The registry contract resolves to the shared IntegrationRegistry.
	 *
	 * @return void
	 */
	public function testTheRegistryContractAliasesTheSharedRegistry(): void {
		$this->assertSame(IntegrationRegistry::class, IntegrationWiring::BINDINGS[$this->registryContract()]);
		$this->assertContains($this->registryContract(), class_implements(IntegrationRegistry::class));
	}//end testTheRegistryContractAliasesTheSharedRegistry()

	/**
	 * The type of the step's registry parameter.
	 *
	 * @return string
	 */
	private function registryContract(): string {
		$constructor = (new ReflectionClass(LogDanglingLinkedTypes::class))->getConstructor();
		$type        = $constructor?->getParameters()[0]->getType();

		return $type instanceof ReflectionNamedType ? $type->getName() : '';
	}//end registryContract()
}//end class
