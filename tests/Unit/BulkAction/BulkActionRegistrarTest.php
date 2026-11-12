<?php

/**
 * BulkActionRegistrar binds every bulk-action contract to its built-in class.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BulkAction;

use OCA\OpenRegister\BulkAction\AssignAction;
use OCA\OpenRegister\BulkAction\AssignActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionRegistrar;
use OCA\OpenRegister\BulkAction\RestorePriorValuesAction;
use OCA\OpenRegister\BulkAction\RestorePriorValuesActionInterface;
use OCA\OpenRegister\BulkAction\SetPropertiesAction;
use OCA\OpenRegister\BulkAction\SetPropertiesActionInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the registrar's register().
 */
class BulkActionRegistrarTest extends TestCase {

	public function testEveryContractIsAliasedToItsBuiltInAction(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		BulkActionRegistrar::register($context);

		$this->assertCount(5, $aliases);
		$this->assertSame(AssignAction::class, $aliases[AssignActionInterface::class]);
		$this->assertSame(SetPropertiesAction::class, $aliases[SetPropertiesActionInterface::class]);
		$this->assertSame(RestorePriorValuesAction::class, $aliases[RestorePriorValuesActionInterface::class]);
		$this->assertSame(BulkActionRegistrar::BINDINGS, $aliases);
	}
}
