<?php

/**
 * ActionRightsInterface is bound to the action-rights catalogue.
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

use OCA\OpenRegister\Kernel\Contracts\Core\ActionRightsInterface;
use OCA\OpenRegister\Service\ActionRightsCatalogue;
use OCA\OpenRegister\Service\ActionRightsCatalogueInterface;
use OCA\OpenRegister\Service\PlatformServiceRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The access-control controllers ask the Core port; the catalogue answers both names.
 */
final class ActionRightsBindingTest extends TestCase {

	public function testThePortAndTheCatalogueInterfaceResolveToTheSameCatalogue(): void {
		$context = new RecordingRegistrationContext();
		PlatformServiceRegistrar::register(context: $context);

		$this->assertContains(['alias' => ActionRightsInterface::class, 'target' => ActionRightsCatalogue::class], $context->aliases);
		$this->assertContains(['alias' => ActionRightsCatalogueInterface::class, 'target' => ActionRightsCatalogue::class], $context->aliases);
	}

	public function testTheCatalogueInterfaceIsThePort(): void {
		$this->assertTrue(is_subclass_of(ActionRightsCatalogueInterface::class, ActionRightsInterface::class));
		$this->assertTrue(is_subclass_of(ActionRightsCatalogue::class, ActionRightsInterface::class));
	}
}//end class
