<?php
/**
 * Test helper — a concrete IRegistrationContext that records every
 * registration, more robust than a PHPUnit mock for the many void register*
 * methods. Shared by the AppHost Bootstrap tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

// phpcs:disable
/**
 * Records all service/listener/widget/alias registrations for assertions.
 */
final class RecordingRegistrationContext implements IRegistrationContext
{
    /** @var array<int, string> */
    public array $services = [];
    /** @var array<string, callable> */
    public array $factories = [];
    /** @var array<int, array{event:string,listener:string}> */
    public array $listeners = [];
    /** @var array<int, string> */
    public array $widgets = [];
    /** @var array<int, array{alias:string,target:string}> */
    public array $aliases = [];

    public function registerService(string $name, callable $factory, bool $shared = true): void
    {
        $this->services[]       = $name;
        $this->factories[$name] = $factory;
    }
    public function registerEventListener(string $event, string $listener, int $priority = 0): void
    {
        $this->listeners[] = ['event' => $event, 'listener' => $listener];
    }
    public function registerDashboardWidget(string $widgetClass): void
    {
        $this->widgets[] = $widgetClass;
    }
    public function registerServiceAlias(string $alias, string $target): void
    {
        $this->aliases[] = ['alias' => $alias, 'target' => $target];
    }
    // Unused IRegistrationContext methods — no-ops for these tests.
    public function registerCapability(string $capability): void {}
    public function registerCrashReporter(string $reporterClass): void {}
    public function registerParameter(string $name, $value): void {}
    public function registerMiddleware(string $class, bool $global = false): void {}
    public function registerSearchProvider(string $class): void {}
    public function registerAlternativeLogin(string $class): void {}
    public function registerInitialStateProvider(string $class): void {}
    public function registerWellKnownHandler(string $class): void {}
    public function registerSpeechToTextProvider(string $providerClass): void {}
    public function registerTextProcessingProvider(string $providerClass): void {}
    public function registerTextToImageProvider(string $providerClass): void {}
    public function registerTemplateProvider(string $providerClass): void {}
    public function registerTranslationProvider(string $providerClass): void {}
    public function registerNotifierService(string $notifierClass): void {}
    public function registerTwoFactorProvider(string $twoFactorProviderClass): void {}
    public function registerPreviewProvider(string $previewProviderClass, string $mimeTypeRegex): void {}
    public function registerCalendarProvider(string $class): void {}
    public function registerReferenceProvider(string $class): void {}
    public function registerProfileLinkAction(string $actionClass): void {}
    public function registerTalkBackend(string $backend): void {}
    public function registerCalendarResourceBackend(string $class): void {}
    public function registerCalendarRoomBackend(string $class): void {}
    public function registerTeamResourceProvider(string $class): void {}
    public function registerUserMigrator(string $migratorClass): void {}
    public function registerSensitiveMethods(string $class, array $methods): void {}
    public function registerPublicShareTemplateProvider(string $class): void {}
    public function registerSetupCheck(string $setupCheckClass): void {}
    public function registerDeclarativeSettings(string $declarativeSettingsClass): void {}
    public function registerTaskProcessingProvider(string $taskProcessingProviderClass): void {}
    public function registerTaskProcessingTaskType(string $taskProcessingTaskTypeClass): void {}
    public function registerFileConversionProvider(string $class): void {}
    public function registerMailProvider(string $class): void {}
    public function registerConfigLexicon(string $configLexiconClass): void {}
}
// phpcs:enable
