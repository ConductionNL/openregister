<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\McpRegistrar;
use OCA\OpenRegister\Mcp\McpWiring;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Mcp\McpRegistrar
 */
class McpRegistrarTest extends TestCase
{
    public function testEveryInterfaceIsBoundToItsImplementation(): void
    {
        $context = new RecordingRegistrationContext();
        McpRegistrar::register(context: $context);

        $expected = [];
        foreach (McpRegistrar::BINDINGS as $contract => $service) {
            $this->assertTrue(is_subclass_of($service, $contract), $service.' implements '.$contract);
            $expected[] = ['alias' => $contract, 'target' => $service];
        }

        $this->assertCount(5, $expected);

        // The factory-registered McpToolsService is aliased after the autowired bindings.
        foreach (McpWiring::FACTORY_BINDINGS as $contract => $service) {
            $this->assertTrue(is_subclass_of($service, $contract), $service.' implements '.$contract);
            $expected[] = ['alias' => $contract, 'target' => $service];
        }

        $this->assertCount(6, $expected);
        $this->assertSame(expected: $expected, actual: $context->aliases);
    }//end testEveryInterfaceIsBoundToItsImplementation()
}//end class
