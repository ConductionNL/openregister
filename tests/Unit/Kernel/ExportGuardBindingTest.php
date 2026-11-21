<?php

/**
 * ExportGuardBindingTest: the Core export paths ask the export guard port; the export gate answers both names.
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

use OCA\OpenRegister\Kernel\Contracts\Core\ExportGuardInterface;
use OCA\OpenRegister\Service\ContractBindingsRegistrar;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\Export\ExportGateInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The export guard port and the export gate interface resolve to the same gate.
 */
final class ExportGuardBindingTest extends TestCase {

	public function testThePortAndTheGateInterfaceResolveToTheSameGate(): void {
		$context = new RecordingRegistrationContext();
		ContractBindingsRegistrar::register(context: $context);

		$this->assertContains(['alias' => ExportGuardInterface::class, 'target' => ExportGate::class], $context->aliases);
		$this->assertContains(['alias' => ExportGateInterface::class, 'target' => ExportGate::class], $context->aliases);
	}

	public function testTheGateInterfaceIsThePort(): void {
		$this->assertTrue(is_subclass_of(ExportGateInterface::class, ExportGuardInterface::class));
		$this->assertTrue(is_subclass_of(ExportGate::class, ExportGuardInterface::class));
	}
}//end class
