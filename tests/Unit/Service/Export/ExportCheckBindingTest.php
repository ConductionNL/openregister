<?php

/**
 * The export gate answers the Core export check: its interface extends the
 * Core contract, and Configuration's bindings bind the contract to the gate.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Kernel\Contracts\Core\ExportGuardInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\Export\ExportGateInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ExportCheckBindingTest extends TestCase {

	public function testTheGateInterfaceExtendsTheCoreCheck(): void {
		$this->assertContains(ExportGuardInterface::class, class_implements(ExportGate::class));
		$this->assertTrue(is_subclass_of(ExportGateInterface::class, ExportGuardInterface::class));
	}//end testTheGateInterfaceExtendsTheCoreCheck()

	public function testTheGateInterfaceDeclaresNothingOfItsOwn(): void {
		$own = array_filter(
			(new ReflectionClass(ExportGateInterface::class))->getMethods(),
			static fn ($method): bool => $method->getDeclaringClass()->getName() === ExportGateInterface::class
		);

		$this->assertSame([], array_values($own));
	}//end testTheGateInterfaceDeclaresNothingOfItsOwn()

	public function testBothContractsAreBoundToTheGate(): void {
		$this->assertSame(ExportGate::class, ContractBindings::BINDINGS[ExportGuardInterface::class]);
		$this->assertSame(ExportGate::class, ContractBindings::BINDINGS[ExportGateInterface::class]);
	}//end testBothContractsAreBoundToTheGate()
}//end class
