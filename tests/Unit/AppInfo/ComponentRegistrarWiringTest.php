<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Mcp\McpRegistrar;
use OCA\OpenRegister\Notifications\NotificationsRegistrar;
use OCA\OpenRegister\Search\SearchRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Application::register reaches every component registrar, so a component's interfaces resolve.
 *
 * @covers \OCA\OpenRegister\AppInfo\Application
 */
class ComponentRegistrarWiringTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function components(): array
    {
        return [
            'files'         => [FilesRegistrar::BINDINGS],
            'mcp'           => [McpRegistrar::BINDINGS],
            'notifications' => [NotificationsRegistrar::BINDINGS],
            'search'        => [SearchRegistrar::BINDINGS],
        ];
    }//end components()

    /**
     * @param array<string, string> $bindings The component's interface to class bindings.
     */
    #[DataProvider('components')]
    public function testApplicationBindsTheComponentsInterfaces(array $bindings): void
    {
        $context = new RecordingRegistrationContext();
        (new ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);

        $this->assertNotSame(expected: [], actual: $bindings);
        foreach ($bindings as $contract => $service) {
            $this->assertContains(needle: ['alias' => $contract, 'target' => $service], haystack: $context->aliases);
        }
    }//end testApplicationBindsTheComponentsInterfaces()
}//end class
