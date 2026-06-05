<?php

/**
 * Tests for OpenProjectProvider.
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-6
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the OpenProject integration provider.
 *
 * Covers id, label, icon, group, storage strategy, auth requirements,
 * isEnabled (depends on OpenConnector source presence), and health status.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author   Conduction Development Team <info@conduction.nl>
 */
class OpenProjectProviderTest extends TestCase
{

    private ExternalIntegrationRouter&MockObject $router;
    private LoggerInterface&MockObject $logger;
    private OpenProjectProvider $provider;

    protected function setUp(): void
    {
        $this->router   = $this->createMock(ExternalIntegrationRouter::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->provider = new OpenProjectProvider(
            router: $this->router,
            logger: $this->logger,
        );
    }

    /**
     * Provider implements the IntegrationProvider interface.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testImplementsIntegrationProviderInterface(): void
    {
        $this->assertInstanceOf(IntegrationProvider::class, $this->provider);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testIdIsOpenproject(): void
    {
        $this->assertSame(expected: 'openproject', actual: $this->provider->getId());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testLabelIsProjects(): void
    {
        $this->assertSame(expected: 'Projects', actual: $this->provider->getLabel());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testIconIsBriefcase(): void
    {
        $this->assertSame(expected: 'Briefcase', actual: $this->provider->getIcon());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testGroupIsExternal(): void
    {
        $this->assertSame(expected: 'external', actual: $this->provider->getGroup());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testStorageStrategyIsExternal(): void
    {
        $this->assertSame(expected: 'external', actual: $this->provider->getStorageStrategy());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testGetOpenConnectorSourceReturnsOpenproject(): void
    {
        $this->assertSame(expected: 'openproject', actual: $this->provider->getOpenConnectorSource());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testRequiresPermissionIsNull(): void
    {
        $this->assertNull($this->provider->requiresPermission());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testRequiredAppIsNull(): void
    {
        $this->assertNull($this->provider->getRequiredApp());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testAuthRequirementsDeclaresOauth2(): void
    {
        $authReqs = $this->provider->authRequirements();

        $this->assertNotNull($authReqs);
        $this->assertSame(expected: 'oauth2', actual: $authReqs['type']);
        $this->assertArrayHasKey(key: 'configSchema', array: $authReqs);
        $this->assertArrayHasKey(key: 'url', array: $authReqs['configSchema']);
        $this->assertArrayHasKey(key: 'client_id', array: $authReqs['configSchema']);
        $this->assertArrayHasKey(key: 'client_secret', array: $authReqs['configSchema']);
        $this->assertArrayHasKey(key: 'scope', array: $authReqs['configSchema']);
    }

    /**
     * Provider is enabled when OpenConnector source status is 'configured'.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testIsEnabledWhenSourceConfigured(): void
    {
        $this->router->method('checkAuthStatus')
            ->with('openproject')
            ->willReturn('configured');

        $this->assertTrue($this->provider->isEnabled());
    }

    /**
     * Provider is disabled when OpenConnector source is 'missing'.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testIsDisabledWhenSourceMissing(): void
    {
        $this->router->method('checkAuthStatus')
            ->with('openproject')
            ->willReturn('missing');

        $this->assertFalse($this->provider->isEnabled());
    }

    /**
     * Provider is enabled when auth is 'expired' (source exists, just needs re-auth).
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testIsEnabledWhenSourceExpired(): void
    {
        $this->router->method('checkAuthStatus')
            ->with('openproject')
            ->willReturn('expired');

        $this->assertTrue($this->provider->isEnabled());
    }

    /**
     * isEnabled() returns false on exception (defensive: source unreachable).
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testIsEnabledReturnsFalseOnException(): void
    {
        $this->router->method('checkAuthStatus')
            ->willThrowException(new \RuntimeException(message: 'Connection refused'));

        $this->assertFalse($this->provider->isEnabled());
    }

    /**
     * Health returns 'available' when source is configured.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testHealthReturnsAvailableWhenConfigured(): void
    {
        $this->router->method('checkAuthStatus')->willReturn('configured');
        $this->assertSame(expected: 'available', actual: $this->provider->health());
    }

    /**
     * Health returns 'expired' when auth is expired.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testHealthReturnsExpiredWhenAuthExpired(): void
    {
        $this->router->method('checkAuthStatus')->willReturn('expired');
        $this->assertSame(expected: 'expired', actual: $this->provider->health());
    }

    /**
     * Health returns 'unavailable' when source is missing.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function testHealthReturnsUnavailableWhenMissing(): void
    {
        $this->router->method('checkAuthStatus')->willReturn('missing');
        $this->assertSame(expected: 'unavailable', actual: $this->provider->health());
    }

    /**
     * listWorkPackages delegates to router.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testListWorkPackagesDelegatesToRouter(): void
    {
        $expected = ['items' => [], 'total' => 0, 'authStatus' => 'configured'];

        $this->router->expects($this->once())
            ->method('listItems')
            ->with('openproject', 'obj-uuid', [])
            ->willReturn($expected);

        $result = $this->provider->listWorkPackages(objectId: 'obj-uuid');
        $this->assertSame(expected: $expected, actual: $result);
    }

    /**
     * linkWorkPackageById delegates to router with workPackageId.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testLinkWorkPackageByIdDelegatesToRouter(): void
    {
        $expected = ['item' => ['id' => 42], 'authStatus' => 'configured'];

        $this->router->expects($this->once())
            ->method('linkItem')
            ->with('openproject', 'obj-uuid', ['workPackageId' => 42])
            ->willReturn($expected);

        $result = $this->provider->linkWorkPackageById(objectId: 'obj-uuid', workPackageId: 42);
        $this->assertSame(expected: $expected, actual: $result);
    }

    /**
     * linkWorkPackageByUrl delegates to router with workPackageUrl.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testLinkWorkPackageByUrlDelegatesToRouter(): void
    {
        $url      = 'https://openproject.example.com/work_packages/42';
        $expected = ['item' => ['id' => 42], 'authStatus' => 'configured'];

        $this->router->expects($this->once())
            ->method('linkItem')
            ->with('openproject', 'obj-uuid', ['workPackageUrl' => $url])
            ->willReturn($expected);

        $result = $this->provider->linkWorkPackageByUrl(objectId: 'obj-uuid', workPackageUrl: $url);
        $this->assertSame(expected: $expected, actual: $result);
    }

    /**
     * unlinkWorkPackage delegates to router.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testUnlinkWorkPackageDelegatesToRouter(): void
    {
        $expected = ['authStatus' => 'configured'];

        $this->router->expects($this->once())
            ->method('unlinkItem')
            ->with('openproject', 'obj-uuid', '42')
            ->willReturn($expected);

        $result = $this->provider->unlinkWorkPackage(objectId: 'obj-uuid', workPackageId: '42');
        $this->assertSame(expected: $expected, actual: $result);
    }

}//end class
