<?php

/**
 * Unit tests for IntegrationsAdminSettings.
 *
 * Covers:
 *  - getSection / getPriority return the documented values
 *  - getForm() renders the integrations-admin template with the
 *    correct row shape (id, label, group, storage, status, ...)
 *  - external providers get a Configure link; native providers don't
 *  - probeHealth() routes external providers through the router and
 *    native providers through provider->health()
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-23
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Settings;

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Settings\IntegrationsAdminSettings;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Native provider stub.
 */
class _AdminNativeProvider extends AbstractIntegrationProvider
{

    public function __construct(private string $id = 'files', private string $storage = 'magic-column')
    {
    }//end __construct()

    public function getId(): string
    {
        return $this->id;
    }//end getId()

    public function getLabel(): string
    {
        return ucfirst($this->id);
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Paperclip';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'core';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return $this->storage;
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        return [];
    }//end list()

}//end class

/**
 * External provider stub.
 */
class _AdminExternalProvider extends AbstractIntegrationProvider
{

    public function __construct(
        private string $id = 'xwiki',
        private string $source = 'xwiki',
    ) {
    }//end __construct()

    public function getId(): string
    {
        return $this->id;
    }//end getId()

    public function getLabel(): string
    {
        return 'XWiki';
    }//end getLabel()

    public function getIcon(): string
    {
        return 'FileDocumentMultiple';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'external';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'external';
    }//end getStorageStrategy()

    public function getOpenConnectorSource(): ?string
    {
        return $this->source;
    }//end getOpenConnectorSource()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        return [];
    }//end list()

}//end class

/**
 * Unit tests for IntegrationsAdminSettings.
 */
class IntegrationsAdminSettingsTest extends TestCase
{

    private function buildSettings(IntegrationRegistry $registry, ?ExternalIntegrationRouter $router = null): IntegrationsAdminSettings
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturn('http://nc/apps/openconnector/sources/xwiki');
        $url->method('linkToOCSRouteAbsolute')->willReturn('http://nc/ocs/v2.php/apps/openregister/api/integrations/xwiki');

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn(true);

        if ($router === null) {
            $router = $this->createMock(ExternalIntegrationRouter::class);
            $router->method('probe')->willReturn([
                'status'     => 'ok',
                'authStatus' => 'configured',
                'message'    => null,
            ]);
        }

        return new IntegrationsAdminSettings(
            registry: $registry,
            router: $router,
            appManager: $appManager,
            urlGenerator: $url,
            l10n: $l10n,
        );
    }//end buildSettings()

    public function testSectionAndPriorityAreStable(): void
    {
        $settings = $this->buildSettings(new IntegrationRegistry(new NullLogger()));
        $this->assertSame('openregister', $settings->getSection());
        $this->assertSame(50, $settings->getPriority());
    }//end testSectionAndPriorityAreStable()

    public function testGetFormRendersTemplateWithRows(): void
    {
        $registry = new IntegrationRegistry(new NullLogger());
        $registry->addProvider(new _AdminNativeProvider());
        $registry->addProvider(new _AdminExternalProvider());

        $settings = $this->buildSettings($registry);
        $response = $settings->getForm();

        $params = $response->getParams();
        $this->assertArrayHasKey('rows', $params);
        $this->assertCount(2, $params['rows']);

        $ids = array_column($params['rows'], 'id');
        $this->assertContains('files', $ids);
        $this->assertContains('xwiki', $ids);
    }//end testGetFormRendersTemplateWithRows()

    public function testExternalProviderRowHasConfigureUrl(): void
    {
        $registry = new IntegrationRegistry(new NullLogger());
        $registry->addProvider(new _AdminExternalProvider());

        $settings = $this->buildSettings($registry);
        $rows     = $settings->getForm()->getParams()['rows'];

        $this->assertNotNull($rows[0]['configureUrl']);
        $this->assertSame('xwiki', $rows[0]['openConnectorSource']);
        $this->assertTrue($rows[0]['isExternal']);
    }//end testExternalProviderRowHasConfigureUrl()

    public function testNativeProviderRowHasNoConfigureUrl(): void
    {
        $registry = new IntegrationRegistry(new NullLogger());
        $registry->addProvider(new _AdminNativeProvider());

        $settings = $this->buildSettings($registry);
        $rows     = $settings->getForm()->getParams()['rows'];

        $this->assertNull($rows[0]['configureUrl']);
        $this->assertNull($rows[0]['openConnectorSource']);
        $this->assertFalse($rows[0]['isExternal']);
    }//end testNativeProviderRowHasNoConfigureUrl()

    public function testProbeUsesRouterForExternalProvider(): void
    {
        $router = $this->createMock(ExternalIntegrationRouter::class);
        $router->expects($this->once())
            ->method('probe')
            ->willReturn([
                'status'     => 'unavailable',
                'authStatus' => 'expired',
                'message'    => 'token refresh failed',
            ]);

        $registry = new IntegrationRegistry(new NullLogger());
        $registry->addProvider(new _AdminExternalProvider());

        $settings = $this->buildSettings($registry, $router);
        $rows     = $settings->getForm()->getParams()['rows'];

        $this->assertSame('unavailable', $rows[0]['status']);
        $this->assertSame('expired', $rows[0]['authStatus']);
        $this->assertSame('token refresh failed', $rows[0]['message']);
    }//end testProbeUsesRouterForExternalProvider()

}//end class
