<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Repair\CredentialOnboarding;
use OCA\OpenRegister\AppHost\Repair\GenericInitializeSettings;
use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\AppHost\Settings\AdminFormServices;
use OCA\OpenRegister\AppHost\Settings\GenericAdminSettings;
use OCA\OpenRegister\AppHost\Settings\GenericSettingsSection;
use OCA\OpenRegister\AppHost\Settings\SectionAppearance;
use OCA\OpenRegister\AppHost\Settings\SettingsPlacement;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenServiceInterface;
use OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrarInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;

/**
 * Pins the value-object construction of the generic settings classes and
 * that the built objects answer exactly as the former scalar constructors did.
 *
 * @covers \OCA\OpenRegister\AppHost\Settings\GenericAdminSettings
 * @covers \OCA\OpenRegister\AppHost\Settings\GenericSettingsSection
 * @covers \OCA\OpenRegister\AppHost\Repair\GenericInitializeSettings
 * @covers \OCA\OpenRegister\AppHost\Settings\SettingsPlacement
 * @covers \OCA\OpenRegister\AppHost\Settings\SectionAppearance
 * @covers \OCA\OpenRegister\AppHost\Settings\AdminFormServices
 * @covers \OCA\OpenRegister\AppHost\Repair\CredentialOnboarding
 */
class GenericSettingsConstructionTest extends TestCase
{

    /**
     * Read a non-public property.
     *
     * @param object $object The object.
     * @param string $name   The property name.
     *
     * @return mixed
     */
    private function prop(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    /**
     * No generic settings constructor or value object takes more than four parameters.
     *
     * @return void
     */
    public function testNoGenericSettingsConstructorTakesMoreThanFourParameters(): void
    {
        foreach ([
            GenericAdminSettings::class,
            GenericSettingsSection::class,
            GenericInitializeSettings::class,
            SettingsPlacement::class,
            SectionAppearance::class,
            AdminFormServices::class,
            CredentialOnboarding::class,
        ] as $class) {
            $ctor = (new ReflectionClass($class))->getConstructor();
            $this->assertNotNull($ctor, $class);
            $this->assertLessThanOrEqual(4, $ctor->getNumberOfParameters(), $class);
        }
    }

    /**
     * The value objects are final and every property is readonly.
     *
     * @return void
     */
    public function testValueObjectsAreFinalAndReadonly(): void
    {
        foreach ([SettingsPlacement::class, SectionAppearance::class, AdminFormServices::class, CredentialOnboarding::class] as $class) {
            $ref = new ReflectionClass($class);
            $this->assertTrue($ref->isFinal(), $class);
            foreach ($ref->getProperties() as $property) {
                $this->assertTrue($property->isReadOnly(), $class.'::$'.$property->getName());
            }
        }
    }

    /**
     * The admin form answers exactly as the scalar constructor did.
     *
     * @return void
     */
    public function testAdminSettingsAnswersAsBefore(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppVersion')->with(appId: 'petstore')->willReturn('1.2.3');
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->with('petstore', 'config_version', '')->willReturn('1.2.3');

        $provided = [];
        $initialState = $this->createMock(IInitialState::class);
        $initialState->method('provideInitialState')->willReturnCallback(
            function (string $key, mixed $value) use (&$provided): void {
                $provided[$key] = $value;
            }
        );

        $admin = new GenericAdminSettings(
            placement: new SettingsPlacement(appId: 'petstore', sectionId: 'petstore-section', priority: 10),
            services: new AdminFormServices(appManager: $appManager, initialState: $initialState, appConfig: $appConfig)
        );

        $this->assertSame('petstore-section', $admin->getSection());
        $this->assertSame(10, $admin->getPriority());
        $this->assertNull($admin->getName());
        $this->assertSame([], $admin->getAuthorizedAppConfig());
        $this->assertSame('petstore', $this->prop($admin, 'appId'));
        $this->assertSame('petstore-section', $this->prop($admin, 'sectionId'));
        $this->assertSame($appManager, $this->prop($admin, 'appManager'));
        $this->assertSame($initialState, $this->prop($admin, 'initialState'));
        $this->assertSame($appConfig, $this->prop($admin, 'appConfig'));

        $form = $admin->getForm();
        $this->assertInstanceOf(TemplateResponse::class, $form);
        $this->assertSame('settings/admin', $form->getTemplateName());
        $this->assertSame(['version' => '1.2.3', 'configuredVersion' => '1.2.3', 'isUpToDate' => true], $provided);
    }

    /**
     * Without an app config only the version is provided.
     *
     * @return void
     */
    public function testAdminSettingsWithoutAppConfigProvidesOnlyTheVersion(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppVersion')->willReturn('2.0.0');
        $provided = [];
        $initialState = $this->createMock(IInitialState::class);
        $initialState->method('provideInitialState')->willReturnCallback(
            function (string $key, mixed $value) use (&$provided): void {
                $provided[$key] = $value;
            }
        );

        $admin = new GenericAdminSettings(
            placement: new SettingsPlacement(appId: 'shop', sectionId: 'shop', priority: 3),
            services: new AdminFormServices(appManager: $appManager, initialState: $initialState)
        );
        $admin->getForm();

        $this->assertNull($this->prop($admin, 'appConfig'));
        $this->assertSame(['version' => '2.0.0'], $provided);
    }

    /**
     * The section answers exactly as the scalar constructor did.
     *
     * @return void
     */
    public function testSettingsSectionAnswersAsBefore(): void
    {
        $url = $this->createMock(IURLGenerator::class);
        $url->expects($this->once())->method('imagePath')->with(appName: 'petstore', file: 'app-dark.svg')
            ->willReturn('/apps/petstore/img/app-dark.svg');

        $section = new GenericSettingsSection(
            placement: new SettingsPlacement(appId: 'petstore', sectionId: 'petstore-section', priority: 75),
            appearance: new SectionAppearance(name: 'Pet Store', iconFile: 'app-dark.svg'),
            urlGenerator: $url
        );

        $this->assertSame('petstore-section', $section->getID());
        $this->assertSame('Pet Store', $section->getName());
        $this->assertSame(75, $section->getPriority());
        $this->assertSame('/apps/petstore/img/app-dark.svg', $section->getIcon());
        $this->assertSame('petstore', $this->prop($section, 'appId'));
        $this->assertSame('app-dark.svg', $this->prop($section, 'iconFile'));
    }

    /**
     * The repair step keeps the onboarding collaborators it is given.
     *
     * @return void
     */
    public function testInitializeSettingsCarriesTheOnboardingCollaborators(): void
    {
        $settings = $this->createMock(AppHostSettingsService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $appManager = $this->createMock(IAppManager::class);
        $tokens = $this->createMock(CredentialAppTokenServiceInterface::class);
        $registrar = $this->createMock(DoriathApplicationRegistrarInterface::class);

        $step = new GenericInitializeSettings(
            appId: 'petstore',
            settingsService: $settings,
            logger: $logger,
            onboarding: new CredentialOnboarding(appManager: $appManager, tokenService: $tokens, applicationRegistrar: $registrar)
        );

        $this->assertSame('Initialize petstore register and schemas via ConfigurationService', $step->getName());
        $this->assertSame($settings, $this->prop($step, 'settingsService'));
        $this->assertSame($logger, $this->prop($step, 'logger'));
        $this->assertSame($appManager, $this->prop($step, 'appManager'));
        $this->assertSame($tokens, $this->prop($step, 'tokenService'));
        $this->assertSame($registrar, $this->prop($step, 'applicationRegistrar'));
    }

    /**
     * Without onboarding collaborators every one stays null (lazily resolved).
     *
     * @return void
     */
    public function testInitializeSettingsWithoutOnboardingLeavesCollaboratorsLazy(): void
    {
        $step = new GenericInitializeSettings(
            appId: 'petstore',
            settingsService: $this->createMock(AppHostSettingsService::class),
            logger: $this->createMock(LoggerInterface::class)
        );

        $this->assertNull($this->prop($step, 'appManager'));
        $this->assertNull($this->prop($step, 'tokenService'));
        $this->assertNull($this->prop($step, 'applicationRegistrar'));
    }
}
