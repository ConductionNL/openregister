<?php

/**
 * ArchivalSettingsInterface is bound to ObjectRetentionHandler.
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

use OCA\OpenRegister\Kernel\Contracts\Compliance\ArchivalSettingsInterface;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Retention and the selection lists ask the Compliance port; the settings store answers both names.
 */
final class ArchivalSettingsBindingTest extends TestCase {

	public function testThePortAndTheOldInterfaceResolveToTheSameClass(): void {
		$context = new RecordingRegistrationContext();
		ContractBindings::register(context: $context);

		$this->assertContains(['alias' => ArchivalSettingsInterface::class, 'target' => ObjectRetentionHandler::class], $context->aliases);
		$this->assertContains(['alias' => ArchivalSettingsReaderInterface::class, 'target' => ObjectRetentionHandler::class], $context->aliases);
	}

	public function testTheOldInterfaceIsThePort(): void {
		$this->assertTrue(is_subclass_of(ArchivalSettingsReaderInterface::class, ArchivalSettingsInterface::class));
		$this->assertTrue(is_subclass_of(ObjectRetentionHandler::class, ArchivalSettingsInterface::class));
	}
}//end class
