<?php

/**
 * Unit test for ContractBindingsRegistrar: every contract binding reaches the registration context.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\ContractBindingsRegistrar;
use OCA\OpenRegister\Service\Rbac\AdminGate;
use OCA\OpenRegister\Service\Rbac\AdminGateInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ContractBindingsRegistrar
 * @uses   \OCA\OpenRegister\Service\ContractBindings
 */
class ContractBindingsRegistrarTest extends TestCase
{
    public function testEveryBindingIsRegisteredAsAServiceAliasExactlyOnce(): void
    {
        $aliases = [];
        $context = $this->createMock(IRegistrationContext::class);
        $context->method('registerServiceAlias')->willReturnCallback(
            static function (string $alias, string $target) use (&$aliases): void {
                $aliases[] = [$alias, $target];
            }
        );

        ContractBindingsRegistrar::register($context);

        $this->assertCount(count(ContractBindings::BINDINGS), $aliases);
        $this->assertSame(ContractBindings::BINDINGS, array_column($aliases, 1, 0));
        $this->assertContains([AdminGateInterface::class, AdminGate::class], $aliases);
    }//end testEveryBindingIsRegisteredAsAServiceAliasExactlyOnce()
}//end class
