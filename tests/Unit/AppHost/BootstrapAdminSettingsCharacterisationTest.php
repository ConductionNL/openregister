<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\AppHost\LeafAdoption;
use OCA\OpenRegister\AppHost\Settings\GenericAdminSettings;
use OCA\OpenRegister\AppHost\Settings\GenericSettingsSection;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__.'/RecordingRegistrationContext.php';

/**
 * Pins the leaf metadata Bootstrap hands the generic admin settings form and
 * settings section, for default and explicit options.
 *
 * @covers \OCA\OpenRegister\AppHost\Bootstrap
 */
class BootstrapAdminSettingsCharacterisationTest extends TestCase
{

    private function container(): ContainerInterface
    {
        $url = $this->createMock(IURLGenerator::class);
        $url->method('imagePath')->willReturnCallback(fn (string $appName, string $file): string => $appName.'/img/'.$file);
        $map = [
            'OCP\\App\\IAppManager'                      => $this->createMock(IAppManager::class),
            'OCP\\AppFramework\\Services\\IInitialState' => $this->createMock(IInitialState::class),
            'OCP\\IAppConfig'                            => $this->createMock(IAppConfig::class),
            'OCP\\IURLGenerator'                         => $url,
        ];
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => $map[$id]);
        return $container;
    }

    private function prop(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    /** @return array<string, array{0: string, 1: array, 2: string, 3: string, 4: array}> */
    public static function optionsProvider(): array
    {
        return [
            'defaults' => [
                'pet-store',
                [],
                'OCA\\PetStore\\Settings\\AdminSettings',
                'OCA\\PetStore\\Sections\\SettingsSection',
                ['pet-store', 'PetStore', 'pet-store/img/app-dark.svg', 75, 10],
            ],
            'explicit' => [
                'petstore',
                [
                    'namespace'         => 'OCA\\Shop\\',
                    'settingsNamespace' => 'OCA\\Shop\\Admin',
                    'sectionId'         => 'shop',
                    'sectionName'       => 'The Shop',
                    'sectionIcon'       => 'shop.svg',
                    'sectionPriority'   => '80',
                    'adminPriority'     => 3.9,
                ],
                'OCA\\Shop\\Admin\\AdminSettings',
                'OCA\\Shop\\Sections\\SettingsSection',
                ['shop', 'The Shop', 'petstore/img/shop.svg', 80, 3],
            ],
            'sections namespace' => [
                'petstore',
                ['sectionsNamespace' => 'OCA\\Elsewhere\\S', 'sectionName' => 7],
                'OCA\\Petstore\\Settings\\AdminSettings',
                'OCA\\Elsewhere\\S\\SettingsSection',
                ['petstore', '7', 'petstore/img/app-dark.svg', 75, 10],
            ],
        ];
    }

    #[DataProvider('optionsProvider')]
    public function testAdminFormAndSectionCarryTheLeafMetadata(
        string $appId,
        array $options,
        string $adminAlias,
        string $sectionAlias,
        array $expected
    ): void {
        $context = new RecordingRegistrationContext();
        Bootstrap::register(context: $context, adoption: new LeafAdoption(appId: $appId, options: $options));
        [$sectionId, $name, $icon, $sectionPriority, $adminPriority] = $expected;

        $admin = $context->factories[$adminAlias]($this->container());
        $this->assertInstanceOf(GenericAdminSettings::class, $admin);
        $this->assertSame($sectionId, $admin->getSection());
        $this->assertSame($adminPriority, $admin->getPriority());
        $this->assertSame($appId, $this->prop($admin, 'appId'));

        $section = $context->factories[$sectionAlias]($this->container());
        $this->assertInstanceOf(GenericSettingsSection::class, $section);
        $this->assertSame($sectionId, $section->getID());
        $this->assertSame($name, $section->getName());
        $this->assertSame($icon, $section->getIcon());
        $this->assertSame($sectionPriority, $section->getPriority());

        // The form is registered before its section.
        $order = array_keys($context->factories);
        $this->assertLessThan(array_search($sectionAlias, $order, true), array_search($adminAlias, $order, true));
    }
}
