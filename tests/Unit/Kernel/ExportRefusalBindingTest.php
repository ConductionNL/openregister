<?php

/**
 * ExportRefusalInterface is bound to ExportGate.
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

use OCA\OpenRegister\Kernel\Contracts\Core\ExportRefusalInterface;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\Export\ExportGateInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The export endpoints ask the Core port; the export gate answers both names.
 */
final class ExportRefusalBindingTest extends TestCase {

	public function testThePortAndTheOldInterfaceResolveToTheSameClass(): void {
		$context = new RecordingRegistrationContext();
		ContractBindings::register(context: $context);

		$this->assertContains(['alias' => ExportRefusalInterface::class, 'target' => ExportGate::class], $context->aliases);
		$this->assertContains(['alias' => ExportGateInterface::class, 'target' => ExportGate::class], $context->aliases);
	}

	public function testTheOldInterfaceIsThePort(): void {
		$this->assertTrue(is_subclass_of(ExportGateInterface::class, ExportRefusalInterface::class));
		$this->assertTrue(is_subclass_of(ExportGate::class, ExportRefusalInterface::class));
	}
}//end class
