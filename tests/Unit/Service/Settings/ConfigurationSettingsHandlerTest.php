<?php

declare(strict_types=1);

/**
 * ConfigurationSettingsHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use Exception;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for ConfigurationSettingsHandler
 *
 * Tests settings retrieval, update, RBAC, multitenancy, and publishing options.
 */
class ConfigurationSettingsHandlerTest extends TestCase
{
    /** @var ConfigurationSettingsHandler */
    private ConfigurationSettingsHandler $handler;

    /** @var IAppConfig&MockObject */
    private IAppConfig $appConfig;

    /** @var IGroupManager&MockObject */
    private IGroupManager $groupManager;

    /** @var IUserManager&MockObject */
    private IUserManager $userManager;

    /** @var OrganisationMapper&MockObject */
    private OrganisationMapper $organisationMapper;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Default: no groups, no users, no organisations.
        $this->groupManager->method('search')->willReturn([]);
        $this->userManager->method('search')->willReturn([]);
        $this->organisationMapper->method('findAllWithUserCount')->willReturn([]);

        $this->handler = new ConfigurationSettingsHandler(
            $this->appConfig,
            $this->groupManager,
            $this->userManager,
            $this->organisationMapper,
            $this->logger
        );
    }

    // =========================================================================
    // isMultiTenancyEnabled
    // =========================================================================

    public function testIsMultiTenancyEnabledReturnsFalseWhenNotConfigured(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->isMultiTenancyEnabled();

        $this->assertFalse($result);
    }

    public function testIsMultiTenancyEnabledReturnsTrueWhenEnabled(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn(json_encode(['enabled' => true]));

        $result = $this->handler->isMultiTenancyEnabled();

        $this->assertTrue($result);
    }

    public function testIsMultiTenancyEnabledReturnsFalseWhenDisabled(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn(json_encode(['enabled' => false]));

        $result = $this->handler->isMultiTenancyEnabled();

        $this->assertFalse($result);
    }

    // =========================================================================
    // getSettings
    // =========================================================================

    public function testGetSettingsReturnsDefaultsWhenNoConfigStored(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getSettings();

        $this->assertArrayHasKey('version', $result);
        $this->assertSame('Open Register', $result['version']['appName']);
        $this->assertSame('0.2.3', $result['version']['appVersion']);

        // RBAC defaults.
        $this->assertArrayHasKey('rbac', $result);
        $this->assertTrue($result['rbac']['enabled']);
        $this->assertSame('public', $result['rbac']['anonymousGroup']);
        $this->assertSame('viewer', $result['rbac']['defaultNewUserGroup']);

        // Multitenancy defaults.
        $this->assertArrayHasKey('multitenancy', $result);
        $this->assertTrue($result['multitenancy']['enabled']);

        // Retention defaults.
        $this->assertArrayHasKey('retention', $result);
        $this->assertSame(31536000000, $result['retention']['objectArchiveRetention']);
        $this->assertTrue($result['retention']['auditTrailsEnabled']);

        // Solr defaults.
        $this->assertArrayHasKey('solr', $result);
        $this->assertFalse($result['solr']['enabled']);
        $this->assertSame('solr', $result['solr']['host']);

        // Groups, tenants, users.
        $this->assertArrayHasKey('availableGroups', $result);
        $this->assertArrayHasKey('availableTenants', $result);
        $this->assertArrayHasKey('availableUsers', $result);
    }

    public function testGetSettingsWithStoredRbacConfig(): void
    {
        $rbacConfig = json_encode([
            'enabled' => false,
            'anonymousGroup' => 'guests',
            'defaultNewUserGroup' => 'editor',
            'defaultObjectOwner' => 'admin',
            'adminOverride' => false,
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($appName, $key, $default) use ($rbacConfig) {
                if ($key === 'rbac') {
                    return $rbacConfig;
                }
                return '';
            });

        $result = $this->handler->getSettings();

        $this->assertFalse($result['rbac']['enabled']);
        $this->assertSame('guests', $result['rbac']['anonymousGroup']);
        $this->assertSame('editor', $result['rbac']['defaultNewUserGroup']);
        $this->assertSame('admin', $result['rbac']['defaultObjectOwner']);
        $this->assertFalse($result['rbac']['adminOverride']);
    }

    public function testGetSettingsWithStoredSolrConfig(): void
    {
        $solrConfig = json_encode([
            'enabled' => true,
            'host' => 'solr-server',
            'port' => 8984,
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($appName, $key, $default) use ($solrConfig) {
                if ($key === 'solr') {
                    return $solrConfig;
                }
                return '';
            });

        $result = $this->handler->getSettings();

        $this->assertTrue($result['solr']['enabled']);
        $this->assertSame('solr-server', $result['solr']['host']);
        $this->assertSame(8984, $result['solr']['port']);
    }

    public function testGetSettingsIncludesAvailableGroups(): void
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn('editors');
        $group->method('getDisplayName')->willReturn('Editors');

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('search')->willReturn([$group]);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('search')->willReturn([]);

        $handler = new ConfigurationSettingsHandler(
            $appConfig,
            $groupManager,
            $userManager,
            $this->organisationMapper,
            $this->logger
        );

        $result = $handler->getSettings();

        $this->assertArrayHasKey('public', $result['availableGroups']);
        $this->assertSame('Public (No restrictions)', $result['availableGroups']['public']);
        $this->assertArrayHasKey('editors', $result['availableGroups']);
        $this->assertSame('Editors', $result['availableGroups']['editors']);
    }

    public function testGetSettingsIncludesAvailableUsers(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('john');
        $user->method('getDisplayName')->willReturn('John Doe');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('search')->willReturn([$user]);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('search')->willReturn([]);

        $handler = new ConfigurationSettingsHandler(
            $appConfig,
            $groupManager,
            $userManager,
            $this->organisationMapper,
            $this->logger
        );

        $result = $handler->getSettings();

        $this->assertArrayHasKey('john', $result['availableUsers']);
        $this->assertSame('John Doe', $result['availableUsers']['john']);
    }

    // =========================================================================
    // updateSettings
    // =========================================================================

    public function testUpdateSettingsStoresRbacConfig(): void
    {
        $this->appConfig->expects($this->atLeastOnce())
            ->method('setValueString');

        $this->appConfig->method('getValueString')
            ->willReturn('');

        $data = [
            'rbac' => [
                'enabled' => false,
                'anonymousGroup' => 'none',
            ],
        ];

        $result = $this->handler->updateSettings($data);

        $this->assertArrayHasKey('version', $result);
    }

    public function testUpdateSettingsStoresMultitenancyConfig(): void
    {
        $this->appConfig->expects($this->atLeastOnce())
            ->method('setValueString');

        $this->appConfig->method('getValueString')
            ->willReturn('');

        $data = [
            'multitenancy' => [
                'enabled' => false,
                'defaultUserTenant' => 'org-1',
            ],
        ];

        $result = $this->handler->updateSettings($data);

        $this->assertArrayHasKey('version', $result);
    }

    public function testUpdateSettingsStoresRetentionConfig(): void
    {
        $this->appConfig->expects($this->atLeastOnce())
            ->method('setValueString');

        $this->appConfig->method('getValueString')
            ->willReturn('');

        $data = [
            'retention' => [
                'objectArchiveRetention' => 86400000,
                'auditTrailsEnabled' => false,
            ],
        ];

        $result = $this->handler->updateSettings($data);

        $this->assertArrayHasKey('version', $result);
    }

    public function testUpdateSettingsStoresSolrConfig(): void
    {
        $this->appConfig->expects($this->atLeastOnce())
            ->method('setValueString');

        $this->appConfig->method('getValueString')
            ->willReturn('');

        $data = [
            'solr' => [
                'enabled' => true,
                'host' => 'my-solr',
            ],
        ];

        $result = $this->handler->updateSettings($data);

        $this->assertArrayHasKey('version', $result);
    }

    // =========================================================================
    // updatePublishingOptions
    // =========================================================================

    public function testUpdatePublishingOptionsStoresValidOptions(): void
    {
        $this->appConfig->expects($this->atLeastOnce())
            ->method('setValueString');

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                return 'true';
            });

        $result = $this->handler->updatePublishingOptions([
            'auto_publish_attachments' => true,
            'auto_publish_objects' => false,
        ]);

        $this->assertArrayHasKey('auto_publish_attachments', $result);
    }

    public function testUpdatePublishingOptionsIgnoresInvalidKeys(): void
    {
        $this->appConfig->expects($this->never())
            ->method('setValueString');

        $result = $this->handler->updatePublishingOptions([
            'invalid_option' => true,
        ]);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // getRbacSettingsOnly
    // =========================================================================

    public function testGetRbacSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getRbacSettingsOnly();

        $this->assertArrayHasKey('rbac', $result);
        $this->assertTrue($result['rbac']['enabled']);
        $this->assertSame('public', $result['rbac']['anonymousGroup']);
        $this->assertArrayHasKey('availableGroups', $result);
        $this->assertArrayHasKey('availableUsers', $result);
    }

    public function testGetRbacSettingsOnlyParsesStoredConfig(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn(json_encode(['enabled' => false, 'anonymousGroup' => 'guests']));

        $result = $this->handler->getRbacSettingsOnly();

        $this->assertFalse($result['rbac']['enabled']);
        $this->assertSame('guests', $result['rbac']['anonymousGroup']);
    }

    // =========================================================================
    // updateRbacSettingsOnly
    // =========================================================================

    public function testUpdateRbacSettingsOnlyStoresAndReturns(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'rbac', $this->isType('string'));

        $result = $this->handler->updateRbacSettingsOnly([
            'enabled' => false,
            'anonymousGroup' => 'none',
        ]);

        $this->assertArrayHasKey('rbac', $result);
        $this->assertFalse($result['rbac']['enabled']);
        $this->assertSame('none', $result['rbac']['anonymousGroup']);
    }

    // =========================================================================
    // getOrganisationSettingsOnly
    // =========================================================================

    public function testGetOrganisationSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getOrganisationSettingsOnly();

        $this->assertArrayHasKey('organisation', $result);
        $this->assertNull($result['organisation']['default_organisation']);
        $this->assertTrue($result['organisation']['auto_create_default_organisation']);
    }

    // =========================================================================
    // getMultitenancySettingsOnly
    // =========================================================================

    public function testGetMultitenancySettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getMultitenancySettingsOnly();

        $this->assertArrayHasKey('multitenancy', $result);
        $this->assertTrue($result['multitenancy']['enabled']);
    }

    // =========================================================================
    // getVersionInfoOnly
    // =========================================================================

    public function testGetVersionInfoOnly(): void
    {
        // getVersionInfoOnly() uses \OCP\Server::get() which requires full Nextcloud bootstrap.
        // In unit test context (no OC class), this throws Error (not Exception), so the
        // catch block doesn't catch it. Skip in lightweight bootstrap.
        if (class_exists('OC') === false) {
            $this->markTestSkipped('Requires full Nextcloud bootstrap (OC class)');
        }

        $result = $this->handler->getVersionInfoOnly();

        $this->assertArrayHasKey('version', $result);
    }

    // =========================================================================
    // getDefaultOrganisationUuid / setDefaultOrganisationUuid
    // =========================================================================

    public function testGetDefaultOrganisationUuidReturnsNullWhenEmpty(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getDefaultOrganisationUuid();

        $this->assertNull($result);
    }

    public function testGetDefaultOrganisationUuidReturnsStoredValue(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                if ($key === 'organisation') {
                    return json_encode(['default_organisation' => 'uuid-123']);
                }
                return '';
            });

        $result = $this->handler->getDefaultOrganisationUuid();

        $this->assertSame('uuid-123', $result);
    }

    public function testSetDefaultOrganisationUuid(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $this->appConfig->method('getValueString')
            ->willReturn('');

        $this->handler->setDefaultOrganisationUuid('uuid-456');
    }

    // =========================================================================
    // getLLMSettingsOnly
    // =========================================================================

    public function testGetLLMSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertArrayHasKey('enabled', $result);
        $this->assertFalse($result['enabled']);
    }

    // =========================================================================
    // getFileSettingsOnly
    // =========================================================================

    public function testGetFileSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getFileSettingsOnly();

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function testGetSettingsThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve settings');

        $this->handler->getSettings();
    }

    public function testUpdatePublishingOptionsThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update publishing options');

        $this->handler->updatePublishingOptions([
            'auto_publish_attachments' => true,
        ]);
    }
}
