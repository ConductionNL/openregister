<?php

/**
 * The Compliance area binds its own service interfaces, each once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\Core\SearchTrailRecorderInterface;
use OCA\OpenRegister\Service\ComplianceServiceRegistrar;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * @covers \OCA\OpenRegister\Service\ComplianceServiceRegistrar
 * @covers \OCA\OpenRegister\Service\ComplianceServiceBindings
 * @uses   \OCA\OpenRegister\Db\DbRegistrar
 */
final class ComplianceServiceRegistrarTest extends TestCase {

	/**
	 * The registrar aliases exactly the bindings of its map.
	 *
	 * @return void
	 */
	public function testTheRegistrarAliasesEveryBindingOfItsMap(): void {
		$context = new RecordingRegistrationContext();
		ComplianceServiceRegistrar::register(context: $context);

		$this->assertNotSame(expected: [], actual: ComplianceServiceRegistrar::BINDINGS);
		$this->assertSame(expected: ComplianceServiceRegistrar::BINDINGS, actual: array_column($context->aliases, 'target', 'alias'));
	}//end testTheRegistrarAliasesEveryBindingOfItsMap()

	/**
	 * Every bound class implements its interface.
	 *
	 * @return void
	 */
	public function testEveryBindingIsImplementedByItsClass(): void {
		foreach (ComplianceServiceRegistrar::BINDINGS as $interface => $class) {
			$this->assertTrue(condition: (new ReflectionClass($class))->implementsInterface($interface), message: $class . ' implements ' . $interface);
		}
	}//end testEveryBindingIsImplementedByItsClass()

	/**
	 * ContractBindings does not bind any of these interfaces as well.
	 *
	 * @return void
	 */
	public function testContractBindingsDoesNotBindThemAgain(): void {
		$twice = array_intersect_key(ComplianceServiceRegistrar::BINDINGS, ContractBindings::BINDINGS);
		$this->assertSame(expected: [], actual: $twice);
	}//end testContractBindingsDoesNotBindThemAgain()

	/**
	 * The object search records its trails through the Core port the search trail service answers.
	 *
	 * @return void
	 */
	public function testTheObjectSearchRecordsThroughThePort(): void {
		$this->assertSame(expected: SearchTrailService::class, actual: ComplianceServiceRegistrar::BINDINGS[SearchTrailRecorderInterface::class] ?? null);

		$parameter = null;
		foreach ((new ReflectionMethod(SearchQueryHandler::class, '__construct'))->getParameters() as $candidate) {
			if ($candidate->getName() === 'searchTrailService') {
				$parameter = $candidate;
			}
		}

		$this->assertNotNull($parameter);
		$type = $parameter->getType();
		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(expected: SearchTrailRecorderInterface::class, actual: $type->getName());
	}//end testTheObjectSearchRecordsThroughThePort()
}//end class
