<?php

/**
 * LifecycleTransitionInterface is answered by the lifecycle transition engine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Controller\DsarCaseController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Compliance\LifecycleTransitionInterface;
use OCA\OpenRegister\Service\Flow\FlowRegistrar;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * The DSAR case API (Compliance) asks the port; the Processes engine answers it.
 */
final class LifecycleTransitionBindingTest extends TestCase {

	public function testTheProcessesRegistrarBindsThePortToTheEngine(): void {
		$this->assertSame(TransitionEngine::class, FlowRegistrar::BINDINGS[LifecycleTransitionInterface::class] ?? null);
	}

	public function testTheEngineImplementsThePort(): void {
		$this->assertTrue(is_subclass_of(TransitionEngine::class, LifecycleTransitionInterface::class));
	}

	public function testThePortReturnsTheSavedObjectForAnActionOnAnId(): void {
		$method = new \ReflectionMethod(LifecycleTransitionInterface::class, 'transition');
		$params = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters());

		$this->assertSame(['objectId', 'action'], $params);
		$this->assertSame(ObjectEntity::class, (string) $method->getReturnType());
	}

	public function testTheDsarCaseControllerHoldsThePort(): void {
		$type = (new ReflectionProperty(DsarCaseController::class, 'transitionEngine'))->getType();

		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(LifecycleTransitionInterface::class, $type->getName());
	}
}//end class
