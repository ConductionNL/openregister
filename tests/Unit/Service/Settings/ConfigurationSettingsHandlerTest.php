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
use OCA\OpenRegister\Db\Organisation;
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
 * Tests settings retrieval, update, RBAC, multitenancy, organisation,
 * LLM, file management, n8n, publishing options, and error handling.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)  Comprehensive coverage requires many test methods
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)   Full coverage of large handler class
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Test class must reference all dependencies
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

    /**
     * Set up test fixtures
     *
     * @return void
     */
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
    // Helper to build a handler with specific mocks
    // =========================================================================

    /**
     * Create handler with custom group/user/org mocks
     *
     * @param array $groups Array of [gid => displayName]
     * @param array $users  Array of [uid => displayName]
     * @param array $orgs   Array of [uuid => name]
     *
     * @return ConfigurationSettingsHandler
     */
    private function buildHandler(
        array $groups = [],
        array $users = [],
        array $orgs = []
    ): ConfigurationSettingsHandler {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupMocks = [];
        foreach ($groups as $gid => $displayName) {
            $group = $this->createMock(IGroup::class);
            $group->method('getGID')->willReturn($gid);
            $group->method('getDisplayName')->willReturn($displayName);
            $groupMocks[] = $group;
        }

        $groupManager->method('search')->willReturn($groupMocks);

        $userManager = $this->createMock(IUserManager::class);
        $userMocks = [];
        foreach ($users as $uid => $displayName) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('getDisplayName')->willReturn($displayName);
            $userMocks[] = $user;
        }

        $userManager->method('search')->willReturn($userMocks);

        $orgMapper = $this->createMock(OrganisationMapper::class);
        $orgMocks = [];
        foreach ($orgs as $uuid => $name) {
            $org = new Organisation();
            $org->setUuid($uuid);
            $org->setName($name);
            $orgMocks[] = $org;
        }

        $orgMapper->method('findAllWithUserCount')->willReturn($orgMocks);

        return new ConfigurationSettingsHandler(
            $this->appConfig,
            $groupManager,
            $userManager,
            $orgMapper,
            $this->logger
        );
    }

    // =========================================================================
    // isMultiTenancyEnabled
    // =========================================================================

    /**
     * Test isMultiTenancyEnabled returns false when no config stored
     *
     * @return void
     */
    public function testIsMultiTenancyEnabledReturnsFalseWhenNotConfigured(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->isMultiTenancyEnabled();

        $this->assertFalse($result);
    }

    /**
     * Test isMultiTenancyEnabled returns true when enabled
     *
     * @return void
     */
    public function testIsMultiTenancyEnabledReturnsTrueWhenEnabled(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn(json_encode(['enabled' => true]));

        $result = $this->handler->isMultiTenancyEnabled();

        $this->assertTrue($result);
    }

    /**
     * Test isMultiTenancyEnabled returns false when disabled
     *
     * @return void
     */
    public function testIsMultiTenancyEnabledReturnsFalseWhenDisabled(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn(json_encode(['enabled' => false]));

        $result = $this->handler->isMultiTenancyEnabled();

        $this->assertFalse($result);
    }

    /**
     * Test isMultiTenancyEnabled returns false when enabled key missing
     *
     * @return void
     */
    public function testIsMultiTenancyEnabledReturnsFalseWhenKeyMissing(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn(json_encode(['other' => 'value']));

        $result = $this->handler->isMultiTenancyEnabled();

        $this->assertFalse($result);
    }

    // =========================================================================
    // getSettings
    // =========================================================================

    /**
     * Test getSettings returns defaults when no config stored
     *
     * @return void
     */
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
        $this->assertSame('', $result['rbac']['defaultObjectOwner']);
        $this->assertTrue($result['rbac']['adminOverride']);

        // Multitenancy defaults.
        $this->assertArrayHasKey('multitenancy', $result);
        $this->assertTrue($result['multitenancy']['enabled']);
        $this->assertSame('', $result['multitenancy']['defaultUserTenant']);
        $this->assertSame('', $result['multitenancy']['defaultObjectTenant']);
        $this->assertFalse($result['multitenancy']['publishedObjectsBypassMultiTenancy']);
        $this->assertTrue($result['multitenancy']['adminOverride']);

        // Retention defaults.
        $this->assertArrayHasKey('retention', $result);
        $this->assertSame(31536000000, $result['retention']['objectArchiveRetention']);
        $this->assertSame(63072000000, $result['retention']['objectDeleteRetention']);
        $this->assertSame(2592000000, $result['retention']['searchTrailRetention']);
        $this->assertSame(2592000000, $result['retention']['createLogRetention']);
        $this->assertSame(86400000, $result['retention']['readLogRetention']);
        $this->assertSame(604800000, $result['retention']['updateLogRetention']);
        $this->assertSame(2592000000, $result['retention']['deleteLogRetention']);
        $this->assertTrue($result['retention']['auditTrailsEnabled']);
        $this->assertTrue($result['retention']['searchTrailsEnabled']);

        // Solr defaults.
        $this->assertArrayHasKey('solr', $result);
        $this->assertFalse($result['solr']['enabled']);
        $this->assertSame('solr', $result['solr']['host']);
        $this->assertSame(8983, $result['solr']['port']);
        $this->assertSame('/solr', $result['solr']['path']);
        $this->assertSame('openregister', $result['solr']['core']);
        $this->assertSame('_default', $result['solr']['configSet']);
        $this->assertSame('http', $result['solr']['scheme']);
        $this->assertSame('solr', $result['solr']['username']);
        $this->assertSame('SolrRocks', $result['solr']['password']);
        $this->assertSame(30, $result['solr']['timeout']);
        $this->assertTrue($result['solr']['autoCommit']);
        $this->assertSame(1000, $result['solr']['commitWithin']);
        $this->assertTrue($result['solr']['enableLogging']);
        $this->assertSame('zookeeper:2181', $result['solr']['zookeeperHosts']);
        $this->assertSame('', $result['solr']['zookeeperUsername']);
        $this->assertSame('', $result['solr']['zookeeperPassword']);
        $this->assertSame('openregister', $result['solr']['collection']);
        $this->assertTrue($result['solr']['useCloud']);
        $this->assertNull($result['solr']['objectCollection']);
        $this->assertNull($result['solr']['fileCollection']);

        // Groups, tenants, users.
        $this->assertArrayHasKey('availableGroups', $result);
        $this->assertArrayHasKey('availableTenants', $result);
        $this->assertArrayHasKey('availableUsers', $result);
    }

    /**
     * Test getSettings with stored RBAC config
     *
     * @return void
     */
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

    /**
     * Test getSettings with stored multitenancy config
     *
     * @return void
     */
    public function testGetSettingsWithStoredMultitenancyConfig(): void
    {
        $multitenancyConfig = json_encode([
            'enabled' => false,
            'defaultUserTenant' => 'tenant-1',
            'defaultObjectTenant' => 'tenant-2',
            'publishedObjectsBypassMultiTenancy' => true,
            'adminOverride' => false,
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($appName, $key, $default) use ($multitenancyConfig) {
                if ($key === 'multitenancy') {
                    return $multitenancyConfig;
                }
                return '';
            });

        $result = $this->handler->getSettings();

        $this->assertFalse($result['multitenancy']['enabled']);
        $this->assertSame('tenant-1', $result['multitenancy']['defaultUserTenant']);
        $this->assertSame('tenant-2', $result['multitenancy']['defaultObjectTenant']);
        $this->assertTrue($result['multitenancy']['publishedObjectsBypassMultiTenancy']);
        $this->assertFalse($result['multitenancy']['adminOverride']);
    }

    /**
     * Test getSettings with stored retention config
     *
     * @return void
     */
    public function testGetSettingsWithStoredRetentionConfig(): void
    {
        $retentionConfig = json_encode([
            'objectArchiveRetention' => 100,
            'objectDeleteRetention' => 200,
            'searchTrailRetention' => 300,
            'createLogRetention' => 400,
            'readLogRetention' => 500,
            'updateLogRetention' => 600,
            'deleteLogRetention' => 700,
            'auditTrailsEnabled' => false,
            'searchTrailsEnabled' => false,
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($appName, $key, $default) use ($retentionConfig) {
                if ($key === 'retention') {
                    return $retentionConfig;
                }
                return '';
            });

        $result = $this->handler->getSettings();

        $this->assertSame(100, $result['retention']['objectArchiveRetention']);
        $this->assertSame(200, $result['retention']['objectDeleteRetention']);
        $this->assertSame(300, $result['retention']['searchTrailRetention']);
        $this->assertSame(400, $result['retention']['createLogRetention']);
        $this->assertSame(500, $result['retention']['readLogRetention']);
        $this->assertSame(600, $result['retention']['updateLogRetention']);
        $this->assertSame(700, $result['retention']['deleteLogRetention']);
        $this->assertFalse($result['retention']['auditTrailsEnabled']);
        $this->assertFalse($result['retention']['searchTrailsEnabled']);
    }

    /**
     * Test getSettings with stored Solr config
     *
     * @return void
     */
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
        // Defaults for unset keys.
        $this->assertSame('/solr', $result['solr']['path']);
        $this->assertSame('openregister', $result['solr']['core']);
    }

    /**
     * Test getSettings with all configs stored simultaneously
     *
     * @return void
     */
    public function testGetSettingsWithAllConfigsStored(): void
    {
        $configs = [
            'rbac' => json_encode(['enabled' => false]),
            'multitenancy' => json_encode(['enabled' => false]),
            'retention' => json_encode(['objectArchiveRetention' => 999]),
            'solr' => json_encode(['enabled' => true, 'host' => 'custom-solr']),
        ];

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($appName, $key, $default) use ($configs) {
                return $configs[$key] ?? '';
            });

        $result = $this->handler->getSettings();

        $this->assertFalse($result['rbac']['enabled']);
        $this->assertFalse($result['multitenancy']['enabled']);
        $this->assertSame(999, $result['retention']['objectArchiveRetention']);
        $this->assertTrue($result['solr']['enabled']);
        $this->assertSame('custom-solr', $result['solr']['host']);
    }

    /**
     * Test getSettings includes available groups
     *
     * @return void
     */
    public function testGetSettingsIncludesAvailableGroups(): void
    {
        $handler = $this->buildHandler(
            ['editors' => 'Editors', 'admins' => 'Administrators']
        );

        $this->appConfig->method('getValueString')->willReturn('');

        $result = $handler->getSettings();

        $this->assertArrayHasKey('public', $result['availableGroups']);
        $this->assertSame('Public (No restrictions)', $result['availableGroups']['public']);
        $this->assertArrayHasKey('editors', $result['availableGroups']);
        $this->assertSame('Editors', $result['availableGroups']['editors']);
        $this->assertArrayHasKey('admins', $result['availableGroups']);
        $this->assertSame('Administrators', $result['availableGroups']['admins']);
    }

    /**
     * Test getSettings includes available users
     *
     * @return void
     */
    public function testGetSettingsIncludesAvailableUsers(): void
    {
        $handler = $this->buildHandler([], ['john' => 'John Doe', 'jane' => 'Jane Smith']);

        $this->appConfig->method('getValueString')->willReturn('');

        $result = $handler->getSettings();

        $this->assertArrayHasKey('john', $result['availableUsers']);
        $this->assertSame('John Doe', $result['availableUsers']['john']);
        $this->assertArrayHasKey('jane', $result['availableUsers']);
        $this->assertSame('Jane Smith', $result['availableUsers']['jane']);
    }

    /**
     * Test getSettings includes available tenants (organisations)
     *
     * @return void
     */
    public function testGetSettingsIncludesAvailableTenants(): void
    {
        $handler = $this->buildHandler([], [], ['uuid-1' => 'Org One', 'uuid-2' => 'Org Two']);

        $this->appConfig->method('getValueString')->willReturn('');

        $result = $handler->getSettings();

        $this->assertArrayHasKey('uuid-1', $result['availableTenants']);
        $this->assertSame('Org One', $result['availableTenants']['uuid-1']);
        $this->assertArrayHasKey('uuid-2', $result['availableTenants']);
        $this->assertSame('Org Two', $result['availableTenants']['uuid-2']);
    }

    /**
     * Test getSettings with user that has null display name falls back to UID
     *
     * @return void
     */
    public function testGetSettingsUserWithNullDisplayNameFallsBackToUid(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('noname');
        $user->method('getDisplayName')->willReturn(null);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('search')->willReturn([$user]);

        $handler = new ConfigurationSettingsHandler(
            $this->appConfig,
            $this->groupManager,
            $userManager,
            $this->organisationMapper,
            $this->logger
        );

        $this->appConfig->method('getValueString')->willReturn('');

        $result = $handler->getSettings();

        $this->assertArrayHasKey('noname', $result['availableUsers']);
        $this->assertSame('noname', $result['availableUsers']['noname']);
    }

    /**
     * Test getSettings with user that has empty display name falls back to UID
     *
     * @return void
     */
    public function testGetSettingsUserWithEmptyDisplayNameFallsBackToUid(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('emptyname');
        $user->method('getDisplayName')->willReturn('');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('search')->willReturn([$user]);

        $handler = new ConfigurationSettingsHandler(
            $this->appConfig,
            $this->groupManager,
            $userManager,
            $this->organisationMapper,
            $this->logger
        );

        $this->appConfig->method('getValueString')->willReturn('');

        $result = $handler->getSettings();

        $this->assertArrayHasKey('emptyname', $result['availableUsers']);
        $this->assertSame('emptyname', $result['availableUsers']['emptyname']);
    }

    /**
     * Test getSettings when organisationMapper throws exception
     *
     * @return void
     */
    public function testGetSettingsOrganisationMapperExceptionReturnsEmptyTenants(): void
    {
        $orgMapper = $this->createMock(OrganisationMapper::class);
        $orgMapper->method('findAllWithUserCount')
            ->willThrowException(new Exception('DB error'));

        $handler = new ConfigurationSettingsHandler(
            $this->appConfig,
            $this->groupManager,
            $this->userManager,
            $orgMapper,
            $this->logger
        );

        $this->appConfig->method('getValueString')->willReturn('');

        $result = $handler->getSettings();

        $this->assertSame([], $result['availableTenants']);
    }

    // =========================================================================
    // updateSettings
    // =========================================================================

    /**
     * Test updateSettings stores RBAC config
     *
     * @return void
     */
    public function testUpdateSettingsStoresRbacConfig(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $data = [
            'rbac' => [
                'enabled' => false,
                'anonymousGroup' => 'none',
                'defaultNewUserGroup' => 'admin',
                'defaultObjectOwner' => 'owner1',
                'adminOverride' => false,
            ],
        ];

        $result = $this->handler->updateSettings($data);

        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('rbac', $result);
        $this->assertFalse($result['rbac']['enabled']);
        $this->assertSame('none', $result['rbac']['anonymousGroup']);
    }

    /**
     * Test updateSettings stores multitenancy config
     *
     * @return void
     */
    public function testUpdateSettingsStoresMultitenancyConfig(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $data = [
            'multitenancy' => [
                'enabled' => false,
                'defaultUserTenant' => 'org-1',
                'defaultObjectTenant' => 'org-2',
                'publishedObjectsBypassMultiTenancy' => true,
                'adminOverride' => false,
            ],
        ];

        $result = $this->handler->updateSettings($data);

        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('multitenancy', $result);
        $this->assertFalse($result['multitenancy']['enabled']);
    }

    /**
     * Test updateSettings stores retention config
     *
     * @return void
     */
    public function testUpdateSettingsStoresRetentionConfig(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $data = [
            'retention' => [
                'objectArchiveRetention' => 86400000,
                'auditTrailsEnabled' => false,
            ],
        ];

        $result = $this->handler->updateSettings($data);

        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('retention', $result);
        $this->assertSame(86400000, $result['retention']['objectArchiveRetention']);
        $this->assertFalse($result['retention']['auditTrailsEnabled']);
    }

    /**
     * Test updateSettings stores Solr config
     *
     * @return void
     */
    public function testUpdateSettingsStoresSolrConfig(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $data = [
            'solr' => [
                'enabled' => true,
                'host' => 'my-solr',
                'port' => '9999',
                'timeout' => '60',
                'commitWithin' => '2000',
            ],
        ];

        $result = $this->handler->updateSettings($data);

        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('solr', $result);
        $this->assertTrue($result['solr']['enabled']);
        $this->assertSame('my-solr', $result['solr']['host']);
        // Port should be cast to int.
        $this->assertSame(9999, $result['solr']['port']);
        $this->assertSame(60, $result['solr']['timeout']);
        $this->assertSame(2000, $result['solr']['commitWithin']);
    }

    /**
     * Test updateSettings with empty data (no sections) returns getSettings
     *
     * @return void
     */
    public function testUpdateSettingsWithEmptyDataReturnsGetSettings(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->expects($this->never())->method('setValueString');

        $result = $this->handler->updateSettings([]);

        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('rbac', $result);
    }

    /**
     * Test updateSettings with all sections
     *
     * @return void
     */
    public function testUpdateSettingsWithAllSections(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $data = [
            'rbac' => ['enabled' => false],
            'multitenancy' => ['enabled' => false],
            'retention' => ['auditTrailsEnabled' => false],
            'solr' => ['enabled' => true],
        ];

        $result = $this->handler->updateSettings($data);

        $this->assertFalse($result['rbac']['enabled']);
        $this->assertFalse($result['multitenancy']['enabled']);
        $this->assertFalse($result['retention']['auditTrailsEnabled']);
        $this->assertTrue($result['solr']['enabled']);
    }

    /**
     * Test updateSettings throws RuntimeException on error
     *
     * @return void
     */
    public function testUpdateSettingsThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update settings');

        $this->handler->updateSettings(['rbac' => ['enabled' => false]]);
    }

    // =========================================================================
    // updatePublishingOptions
    // =========================================================================

    /**
     * Test updatePublishingOptions stores valid options
     *
     * @return void
     */
    public function testUpdatePublishingOptionsStoresValidOptions(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $result = $this->handler->updatePublishingOptions([
            'auto_publish_attachments' => true,
            'auto_publish_objects' => false,
        ]);

        $this->assertArrayHasKey('auto_publish_attachments', $result);
        $this->assertTrue($result['auto_publish_attachments']);
        $this->assertArrayHasKey('auto_publish_objects', $result);
        $this->assertFalse($result['auto_publish_objects']);
    }

    /**
     * Test updatePublishingOptions accepts string 'true'
     *
     * @return void
     */
    public function testUpdatePublishingOptionsAcceptsStringTrue(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $result = $this->handler->updatePublishingOptions([
            'auto_publish_attachments' => 'true',
        ]);

        $this->assertArrayHasKey('auto_publish_attachments', $result);
        $this->assertTrue($result['auto_publish_attachments']);
    }

    /**
     * Test updatePublishingOptions with use_old_style_publishing_view
     *
     * @return void
     */
    public function testUpdatePublishingOptionsWithOldStylePublishingView(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $result = $this->handler->updatePublishingOptions([
            'use_old_style_publishing_view' => true,
        ]);

        $this->assertArrayHasKey('use_old_style_publishing_view', $result);
        $this->assertTrue($result['use_old_style_publishing_view']);
    }

    /**
     * Test updatePublishingOptions ignores invalid keys
     *
     * @return void
     */
    public function testUpdatePublishingOptionsIgnoresInvalidKeys(): void
    {
        $this->appConfig->expects($this->never())
            ->method('setValueString');

        $result = $this->handler->updatePublishingOptions([
            'invalid_option' => true,
        ]);

        $this->assertSame([], $result);
    }

    /**
     * Test updatePublishingOptions stores false value
     *
     * @return void
     */
    public function testUpdatePublishingOptionsStoresFalseValue(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $result = $this->handler->updatePublishingOptions([
            'auto_publish_objects' => false,
        ]);

        $this->assertArrayHasKey('auto_publish_objects', $result);
        $this->assertFalse($result['auto_publish_objects']);
    }

    /**
     * Test updatePublishingOptions throws RuntimeException on error
     *
     * @return void
     */
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

    // =========================================================================
    // getRbacSettingsOnly
    // =========================================================================

    /**
     * Test getRbacSettingsOnly returns defaults
     *
     * @return void
     */
    public function testGetRbacSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getRbacSettingsOnly();

        $this->assertArrayHasKey('rbac', $result);
        $this->assertTrue($result['rbac']['enabled']);
        $this->assertSame('public', $result['rbac']['anonymousGroup']);
        $this->assertSame('viewer', $result['rbac']['defaultNewUserGroup']);
        $this->assertSame('', $result['rbac']['defaultObjectOwner']);
        $this->assertTrue($result['rbac']['adminOverride']);
        $this->assertArrayHasKey('availableGroups', $result);
        $this->assertArrayHasKey('availableUsers', $result);
    }

    /**
     * Test getRbacSettingsOnly parses stored config
     *
     * @return void
     */
    public function testGetRbacSettingsOnlyParsesStoredConfig(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn(json_encode([
                'enabled' => false,
                'anonymousGroup' => 'guests',
                'defaultNewUserGroup' => 'admin',
                'defaultObjectOwner' => 'owner',
                'adminOverride' => false,
            ]));

        $result = $this->handler->getRbacSettingsOnly();

        $this->assertFalse($result['rbac']['enabled']);
        $this->assertSame('guests', $result['rbac']['anonymousGroup']);
        $this->assertSame('admin', $result['rbac']['defaultNewUserGroup']);
        $this->assertSame('owner', $result['rbac']['defaultObjectOwner']);
        $this->assertFalse($result['rbac']['adminOverride']);
    }

    /**
     * Test getRbacSettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testGetRbacSettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve RBAC settings');

        $this->handler->getRbacSettingsOnly();
    }

    // =========================================================================
    // updateRbacSettingsOnly
    // =========================================================================

    /**
     * Test updateRbacSettingsOnly stores and returns
     *
     * @return void
     */
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
        // Defaults for unset keys.
        $this->assertSame('viewer', $result['rbac']['defaultNewUserGroup']);
        $this->assertSame('', $result['rbac']['defaultObjectOwner']);
        $this->assertTrue($result['rbac']['adminOverride']);
        $this->assertArrayHasKey('availableGroups', $result);
        $this->assertArrayHasKey('availableUsers', $result);
    }

    /**
     * Test updateRbacSettingsOnly with all fields
     *
     * @return void
     */
    public function testUpdateRbacSettingsOnlyWithAllFields(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->handler->updateRbacSettingsOnly([
            'enabled' => false,
            'anonymousGroup' => 'guests',
            'defaultNewUserGroup' => 'editor',
            'defaultObjectOwner' => 'admin',
            'adminOverride' => false,
        ]);

        $this->assertFalse($result['rbac']['enabled']);
        $this->assertSame('guests', $result['rbac']['anonymousGroup']);
        $this->assertSame('editor', $result['rbac']['defaultNewUserGroup']);
        $this->assertSame('admin', $result['rbac']['defaultObjectOwner']);
        $this->assertFalse($result['rbac']['adminOverride']);
    }

    /**
     * Test updateRbacSettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testUpdateRbacSettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update RBAC settings');

        $this->handler->updateRbacSettingsOnly(['enabled' => false]);
    }

    /**
     * Test updateRbacSettingsOnly persists `inheritFromPublicDefault: false`
     * to the dedicated IAppConfig key (not into the JSON `rbac` blob) so
     * PermissionHandler::resolveInheritFromPublic finds it on the next request.
     *
     * @return void
     */
    public function testUpdateRbacSettingsOnlyPersistsInheritFromPublicDefaultBoolean(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueBool')
            ->with('openregister', 'rbac.inherit_from_public_default', false);

        $result = $this->handler->updateRbacSettingsOnly([
            'enabled'                  => true,
            'inheritFromPublicDefault' => false,
        ]);

        $this->assertFalse($result['rbac']['inheritFromPublicDefault']);
    }

    /**
     * Test updateRbacSettingsOnly accepts the string "false" via the documented
     * tolerance (filter_var coercion) and persists it as boolean false — NOT as
     * `(bool) "false" === true`. Pins the security-relevant gate against the
     * silent-flip foot-gun called out in PR #1440 review.
     *
     * @return void
     */
    public function testUpdateRbacSettingsOnlyCoercesStringFalseToBooleanFalse(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueBool')
            ->with('openregister', 'rbac.inherit_from_public_default', false);

        $result = $this->handler->updateRbacSettingsOnly([
            'enabled'                  => true,
            'inheritFromPublicDefault' => 'false',
        ]);

        $this->assertFalse(
            $result['rbac']['inheritFromPublicDefault'],
            'String "false" must coerce to boolean false, not (bool) "false" === true.'
        );
    }

    /**
     * Test updateRbacSettingsOnly rejects garbage strings rather than silently
     * coercing them to a permissive default. Garbage in the API payload should
     * surface as an exception, not as a silent setting flip.
     *
     * @return void
     */
    public function testUpdateRbacSettingsOnlyRejectsGarbageInheritFromPublicDefault(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inheritFromPublicDefault');

        $this->handler->updateRbacSettingsOnly([
            'enabled'                  => true,
            'inheritFromPublicDefault' => 'maybe',
        ]);
    }

    // =========================================================================
    // getOrganisationSettingsOnly
    // =========================================================================

    /**
     * Test getOrganisationSettingsOnly returns defaults
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getOrganisationSettingsOnly();

        $this->assertArrayHasKey('organisation', $result);
        $this->assertNull($result['organisation']['default_organisation']);
        $this->assertTrue($result['organisation']['auto_create_default_organisation']);
    }

    /**
     * Test getOrganisationSettingsOnly parses stored config
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnlyParsesStoredConfig(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                if ($key === 'organisation') {
                    return json_encode([
                        'default_organisation' => 'uuid-org-1',
                        'auto_create_default_organisation' => false,
                    ]);
                }
                return '';
            });

        $result = $this->handler->getOrganisationSettingsOnly();

        $this->assertSame('uuid-org-1', $result['organisation']['default_organisation']);
        $this->assertFalse($result['organisation']['auto_create_default_organisation']);
    }

    /**
     * Test getOrganisationSettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve Organisation settings');

        $this->handler->getOrganisationSettingsOnly();
    }

    // =========================================================================
    // updateOrganisationSettingsOnly
    // =========================================================================

    /**
     * Test updateOrganisationSettingsOnly stores and returns
     *
     * @return void
     */
    public function testUpdateOrganisationSettingsOnlyStoresAndReturns(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'organisation', $this->isType('string'));

        $result = $this->handler->updateOrganisationSettingsOnly([
            'default_organisation' => 'uuid-456',
            'auto_create_default_organisation' => false,
        ]);

        $this->assertArrayHasKey('organisation', $result);
        $this->assertSame('uuid-456', $result['organisation']['default_organisation']);
        $this->assertFalse($result['organisation']['auto_create_default_organisation']);
    }

    /**
     * Test updateOrganisationSettingsOnly with defaults for missing keys
     *
     * @return void
     */
    public function testUpdateOrganisationSettingsOnlyDefaultsMissingKeys(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->handler->updateOrganisationSettingsOnly([]);

        $this->assertNull($result['organisation']['default_organisation']);
        $this->assertTrue($result['organisation']['auto_create_default_organisation']);
    }

    /**
     * Test updateOrganisationSettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testUpdateOrganisationSettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update Organisation settings');

        $this->handler->updateOrganisationSettingsOnly(['default_organisation' => 'x']);
    }

    // =========================================================================
    // getMultitenancySettingsOnly
    // =========================================================================

    /**
     * Test getMultitenancySettingsOnly returns defaults
     *
     * @return void
     */
    public function testGetMultitenancySettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getMultitenancySettingsOnly();

        $this->assertArrayHasKey('multitenancy', $result);
        $this->assertTrue($result['multitenancy']['enabled']);
        $this->assertSame('', $result['multitenancy']['defaultUserTenant']);
        $this->assertSame('', $result['multitenancy']['defaultObjectTenant']);
        $this->assertFalse($result['multitenancy']['publishedObjectsBypassMultiTenancy']);
        $this->assertTrue($result['multitenancy']['adminOverride']);
        $this->assertArrayHasKey('availableTenants', $result);
    }

    /**
     * Test getMultitenancySettingsOnly parses stored config
     *
     * @return void
     */
    public function testGetMultitenancySettingsOnlyParsesStoredConfig(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                if ($key === 'multitenancy') {
                    return json_encode([
                        'enabled' => false,
                        'defaultUserTenant' => 'tenant-x',
                        'defaultObjectTenant' => 'tenant-y',
                        'publishedObjectsBypassMultiTenancy' => true,
                        'adminOverride' => false,
                    ]);
                }
                return '';
            });

        $result = $this->handler->getMultitenancySettingsOnly();

        $this->assertFalse($result['multitenancy']['enabled']);
        $this->assertSame('tenant-x', $result['multitenancy']['defaultUserTenant']);
        $this->assertSame('tenant-y', $result['multitenancy']['defaultObjectTenant']);
        $this->assertTrue($result['multitenancy']['publishedObjectsBypassMultiTenancy']);
        $this->assertFalse($result['multitenancy']['adminOverride']);
    }

    /**
     * Test getMultitenancySettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testGetMultitenancySettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve Multitenancy settings');

        $this->handler->getMultitenancySettingsOnly();
    }

    // =========================================================================
    // updateMultitenancySettingsOnly
    // =========================================================================

    /**
     * Test updateMultitenancySettingsOnly stores and returns
     *
     * @return void
     */
    public function testUpdateMultitenancySettingsOnlyStoresAndReturns(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'multitenancy', $this->isType('string'));

        $result = $this->handler->updateMultitenancySettingsOnly([
            'enabled' => false,
            'defaultUserTenant' => 'ten-1',
            'defaultObjectTenant' => 'ten-2',
            'publishedObjectsBypassMultiTenancy' => true,
            'adminOverride' => false,
        ]);

        $this->assertArrayHasKey('multitenancy', $result);
        $this->assertFalse($result['multitenancy']['enabled']);
        $this->assertSame('ten-1', $result['multitenancy']['defaultUserTenant']);
        $this->assertSame('ten-2', $result['multitenancy']['defaultObjectTenant']);
        $this->assertTrue($result['multitenancy']['publishedObjectsBypassMultiTenancy']);
        $this->assertFalse($result['multitenancy']['adminOverride']);
        $this->assertArrayHasKey('availableTenants', $result);
    }

    /**
     * Test updateMultitenancySettingsOnly with defaults for missing keys
     *
     * @return void
     */
    public function testUpdateMultitenancySettingsOnlyDefaultsMissingKeys(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->handler->updateMultitenancySettingsOnly([]);

        $this->assertTrue($result['multitenancy']['enabled']);
        $this->assertSame('', $result['multitenancy']['defaultUserTenant']);
        $this->assertSame('', $result['multitenancy']['defaultObjectTenant']);
        $this->assertFalse($result['multitenancy']['publishedObjectsBypassMultiTenancy']);
        $this->assertTrue($result['multitenancy']['adminOverride']);
    }

    /**
     * Test updateMultitenancySettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testUpdateMultitenancySettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update Multitenancy settings');

        $this->handler->updateMultitenancySettingsOnly(['enabled' => false]);
    }

    // =========================================================================
    // getDefaultOrganisationUuid / setDefaultOrganisationUuid
    // =========================================================================

    /**
     * Test getDefaultOrganisationUuid returns null when empty
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuidReturnsNullWhenEmpty(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getDefaultOrganisationUuid();

        $this->assertNull($result);
    }

    /**
     * Test getDefaultOrganisationUuid returns stored value
     *
     * @return void
     */
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

    /**
     * Test getDefaultOrganisationUuid returns null on exception
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuidReturnsNullOnException(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->logger->expects($this->once())
            ->method('warning');

        $result = $this->handler->getDefaultOrganisationUuid();

        $this->assertNull($result);
    }

    /**
     * Test setDefaultOrganisationUuid stores value
     *
     * @return void
     */
    public function testSetDefaultOrganisationUuid(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $this->handler->setDefaultOrganisationUuid('uuid-456');

        $this->assertArrayHasKey('organisation', $storedValues);
        $decoded = json_decode($storedValues['organisation'], true);
        $this->assertSame('uuid-456', $decoded['default_organisation']);
    }

    /**
     * Test setDefaultOrganisationUuid with null
     *
     * @return void
     */
    public function testSetDefaultOrganisationUuidWithNull(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $this->handler->setDefaultOrganisationUuid(null);

        $decoded = json_decode($storedValues['organisation'], true);
        $this->assertNull($decoded['default_organisation']);
    }

    /**
     * Test setDefaultOrganisationUuid logs error on exception
     *
     * @return void
     */
    public function testSetDefaultOrganisationUuidLogsErrorOnException(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->logger->expects($this->once())
            ->method('error');

        $this->handler->setDefaultOrganisationUuid('uuid-789');
    }

    // =========================================================================
    // getTenantId
    // =========================================================================

    /**
     * Test getTenantId returns empty string when no tenant set (default)
     *
     * @return void
     */
    public function testGetTenantIdReturnsEmptyStringWhenDefault(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getTenantId();

        $this->assertSame('', $result);
    }

    /**
     * Test getTenantId returns stored tenant ID
     *
     * @return void
     */
    public function testGetTenantIdReturnsStoredValue(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                if ($key === 'multitenancy') {
                    return json_encode(['defaultUserTenant' => 'my-tenant']);
                }
                return '';
            });

        $result = $this->handler->getTenantId();

        $this->assertSame('my-tenant', $result);
    }

    /**
     * Test getTenantId returns null on exception
     *
     * @return void
     */
    public function testGetTenantIdReturnsNullOnException(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->logger->expects($this->once())
            ->method('warning');

        $result = $this->handler->getTenantId();

        $this->assertNull($result);
    }

    // =========================================================================
    // getOrganisationId
    // =========================================================================

    /**
     * Test getOrganisationId delegates to getDefaultOrganisationUuid
     *
     * @return void
     */
    public function testGetOrganisationIdDelegatesToGetDefaultOrganisationUuid(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                if ($key === 'organisation') {
                    return json_encode(['default_organisation' => 'org-uuid']);
                }
                return '';
            });

        $result = $this->handler->getOrganisationId();

        $this->assertSame('org-uuid', $result);
    }

    /**
     * Test getOrganisationId returns null when not set
     *
     * @return void
     */
    public function testGetOrganisationIdReturnsNullWhenNotSet(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getOrganisationId();

        $this->assertNull($result);
    }

    // =========================================================================
    // getLLMSettingsOnly
    // =========================================================================

    /**
     * Test getLLMSettingsOnly returns defaults when empty
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertArrayHasKey('enabled', $result);
        $this->assertFalse($result['enabled']);
        $this->assertNull($result['embeddingProvider']);
        $this->assertNull($result['chatProvider']);

        // OpenAI config.
        $this->assertArrayHasKey('openaiConfig', $result);
        $this->assertSame('', $result['openaiConfig']['apiKey']);
        $this->assertNull($result['openaiConfig']['model']);
        $this->assertNull($result['openaiConfig']['chatModel']);
        $this->assertSame('', $result['openaiConfig']['organizationId']);

        // Ollama config.
        $this->assertArrayHasKey('ollamaConfig', $result);
        $this->assertSame('http://localhost:11434', $result['ollamaConfig']['url']);
        $this->assertNull($result['ollamaConfig']['model']);
        $this->assertNull($result['ollamaConfig']['chatModel']);

        // Fireworks config.
        $this->assertArrayHasKey('fireworksConfig', $result);
        $this->assertSame('', $result['fireworksConfig']['apiKey']);
        $this->assertNull($result['fireworksConfig']['embeddingModel']);
        $this->assertNull($result['fireworksConfig']['chatModel']);
        $this->assertSame('https://api.fireworks.ai/inference/v1', $result['fireworksConfig']['baseUrl']);

        // Vector config.
        $this->assertArrayHasKey('vectorConfig', $result);
        $this->assertSame('php', $result['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $result['vectorConfig']['solrField']);
    }

    /**
     * Test getLLMSettingsOnly parses stored config
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyParsesStoredConfig(): void
    {
        $storedConfig = json_encode([
            'enabled' => true,
            'embeddingProvider' => 'openai',
            'chatProvider' => 'ollama',
            'openaiConfig' => [
                'apiKey' => 'sk-test',
                'model' => 'text-embedding-3-small',
            ],
            'ollamaConfig' => [
                'url' => 'http://ollama:11434',
                'chatModel' => 'llama3',
            ],
            'vectorConfig' => [
                'backend' => 'solr',
                'solrField' => '_vector_',
            ],
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use ($storedConfig) {
                if ($key === 'llm') {
                    return $storedConfig;
                }
                return '';
            });

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertTrue($result['enabled']);
        $this->assertSame('openai', $result['embeddingProvider']);
        $this->assertSame('ollama', $result['chatProvider']);
        $this->assertSame('sk-test', $result['openaiConfig']['apiKey']);
        $this->assertSame('solr', $result['vectorConfig']['backend']);
        $this->assertSame('_vector_', $result['vectorConfig']['solrField']);
    }

    /**
     * Test getLLMSettingsOnly adds enabled=false when missing
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyAddsEnabledWhenMissing(): void
    {
        $storedConfig = json_encode([
            'embeddingProvider' => 'openai',
            'vectorConfig' => ['backend' => 'solr', 'solrField' => '_v_'],
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use ($storedConfig) {
                if ($key === 'llm') {
                    return $storedConfig;
                }
                return '';
            });

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertFalse($result['enabled']);
    }

    /**
     * Test getLLMSettingsOnly adds vectorConfig when missing
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyAddsVectorConfigWhenMissing(): void
    {
        $storedConfig = json_encode([
            'enabled' => true,
            'embeddingProvider' => 'openai',
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use ($storedConfig) {
                if ($key === 'llm') {
                    return $storedConfig;
                }
                return '';
            });

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertSame('php', $result['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $result['vectorConfig']['solrField']);
    }

    /**
     * Test getLLMSettingsOnly fills missing vectorConfig fields
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyFillsMissingVectorConfigFields(): void
    {
        $storedConfig = json_encode([
            'enabled' => true,
            'vectorConfig' => [],
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use ($storedConfig) {
                if ($key === 'llm') {
                    return $storedConfig;
                }
                return '';
            });

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertSame('php', $result['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $result['vectorConfig']['solrField']);
    }

    /**
     * Test getLLMSettingsOnly fills missing backend in vectorConfig
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyFillsMissingBackendOnly(): void
    {
        $storedConfig = json_encode([
            'enabled' => true,
            'vectorConfig' => ['solrField' => '_custom_'],
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use ($storedConfig) {
                if ($key === 'llm') {
                    return $storedConfig;
                }
                return '';
            });

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertSame('php', $result['vectorConfig']['backend']);
        $this->assertSame('_custom_', $result['vectorConfig']['solrField']);
    }

    /**
     * Test getLLMSettingsOnly fills missing solrField in vectorConfig
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyFillsMissingSolrFieldOnly(): void
    {
        $storedConfig = json_encode([
            'enabled' => true,
            'vectorConfig' => ['backend' => 'solr'],
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use ($storedConfig) {
                if ($key === 'llm') {
                    return $storedConfig;
                }
                return '';
            });

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertSame('solr', $result['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $result['vectorConfig']['solrField']);
    }

    /**
     * Test getLLMSettingsOnly removes deprecated solrCollection
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyRemovesDeprecatedSolrCollection(): void
    {
        $storedConfig = json_encode([
            'enabled' => true,
            'vectorConfig' => [
                'backend' => 'solr',
                'solrField' => '_v_',
                'solrCollection' => 'old_collection',
            ],
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use ($storedConfig) {
                if ($key === 'llm') {
                    return $storedConfig;
                }
                return '';
            });

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertArrayNotHasKey('solrCollection', $result['vectorConfig']);
    }

    /**
     * Test getLLMSettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve LLM settings');

        $this->handler->getLLMSettingsOnly();
    }

    // =========================================================================
    // updateLLMSettingsOnly
    // =========================================================================

    /**
     * Test updateLLMSettingsOnly stores and returns merged config
     *
     * @return void
     */
    public function testUpdateLLMSettingsOnlyStoresAndReturns(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'llm', $this->isType('string'));

        $result = $this->handler->updateLLMSettingsOnly([
            'enabled' => true,
            'embeddingProvider' => 'openai',
            'openaiConfig' => ['apiKey' => 'sk-new'],
        ]);

        $this->assertTrue($result['enabled']);
        $this->assertSame('openai', $result['embeddingProvider']);
        $this->assertSame('sk-new', $result['openaiConfig']['apiKey']);
        // Defaults for unset.
        $this->assertNull($result['chatProvider']);
        $this->assertSame('php', $result['vectorConfig']['backend']);
    }

    /**
     * Test updateLLMSettingsOnly merges with existing config (PATCH behavior)
     *
     * @return void
     */
    public function testUpdateLLMSettingsOnlyMergesWithExisting(): void
    {
        $existingConfig = json_encode([
            'enabled' => true,
            'embeddingProvider' => 'openai',
            'chatProvider' => 'ollama',
            'openaiConfig' => [
                'apiKey' => 'sk-old',
                'model' => 'text-embedding-3-small',
            ],
            'ollamaConfig' => [
                'url' => 'http://ollama:11434',
                'chatModel' => 'llama3',
            ],
            'vectorConfig' => [
                'backend' => 'solr',
                'solrField' => '_v_',
            ],
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use ($existingConfig) {
                if ($key === 'llm') {
                    return $existingConfig;
                }
                return '';
            });

        $this->appConfig->expects($this->once())
            ->method('setValueString');

        // Only update apiKey; everything else should be preserved.
        $result = $this->handler->updateLLMSettingsOnly([
            'openaiConfig' => ['apiKey' => 'sk-updated'],
        ]);

        $this->assertSame('sk-updated', $result['openaiConfig']['apiKey']);
        // Preserved from existing.
        $this->assertTrue($result['enabled']);
        $this->assertSame('openai', $result['embeddingProvider']);
        $this->assertSame('ollama', $result['chatProvider']);
        $this->assertSame('text-embedding-3-small', $result['openaiConfig']['model']);
        $this->assertSame('http://ollama:11434', $result['ollamaConfig']['url']);
        $this->assertSame('solr', $result['vectorConfig']['backend']);
    }

    /**
     * Test updateLLMSettingsOnly with full fireworks config
     *
     * @return void
     */
    public function testUpdateLLMSettingsOnlyWithFireworksConfig(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->handler->updateLLMSettingsOnly([
            'fireworksConfig' => [
                'apiKey' => 'fw-key',
                'embeddingModel' => 'nomic-embed',
                'chatModel' => 'llama-3',
                'baseUrl' => 'https://custom.fireworks.ai',
            ],
        ]);

        $this->assertSame('fw-key', $result['fireworksConfig']['apiKey']);
        $this->assertSame('nomic-embed', $result['fireworksConfig']['embeddingModel']);
        $this->assertSame('llama-3', $result['fireworksConfig']['chatModel']);
        $this->assertSame('https://custom.fireworks.ai', $result['fireworksConfig']['baseUrl']);
    }

    /**
     * Test updateLLMSettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testUpdateLLMSettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update LLM settings');

        $this->handler->updateLLMSettingsOnly(['enabled' => true]);
    }

    // =========================================================================
    // getFileSettingsOnly
    // =========================================================================

    /**
     * Test getFileSettingsOnly returns defaults
     *
     * @return void
     */
    public function testGetFileSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getFileSettingsOnly();

        $this->assertFalse($result['vectorizationEnabled']);
        $this->assertNull($result['provider']);
        $this->assertSame('RECURSIVE_CHARACTER', $result['chunkingStrategy']);
        $this->assertSame(1000, $result['chunkSize']);
        $this->assertSame(200, $result['chunkOverlap']);
        $this->assertIsArray($result['enabledFileTypes']);
        $this->assertContains('pdf', $result['enabledFileTypes']);
        $this->assertContains('docx', $result['enabledFileTypes']);
        $this->assertFalse($result['ocrEnabled']);
        $this->assertSame(100, $result['maxFileSizeMB']);
        $this->assertSame('objects', $result['extractionScope']);
        $this->assertSame('llphant', $result['textExtractor']);
        $this->assertSame('background', $result['extractionMode']);
        $this->assertSame(100, $result['maxFileSize']);
        $this->assertSame(10, $result['batchSize']);
        $this->assertSame('', $result['dolphinApiEndpoint']);
        $this->assertSame('', $result['dolphinApiKey']);
        $this->assertSame('', $result['presidioApiEndpoint']);
        $this->assertFalse($result['entityRecognitionEnabled']);
        $this->assertSame('hybrid', $result['entityRecognitionMethod']);
    }

    /**
     * Test getFileSettingsOnly parses stored config
     *
     * @return void
     */
    public function testGetFileSettingsOnlyParsesStoredConfig(): void
    {
        $storedConfig = json_encode([
            'vectorizationEnabled' => true,
            'provider' => 'openai',
            'chunkSize' => 500,
            'ocrEnabled' => true,
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use ($storedConfig) {
                if ($key === 'fileManagement') {
                    return $storedConfig;
                }
                return '';
            });

        $result = $this->handler->getFileSettingsOnly();

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertSame('openai', $result['provider']);
        $this->assertSame(500, $result['chunkSize']);
        $this->assertTrue($result['ocrEnabled']);
    }

    /**
     * Test getFileSettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testGetFileSettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve File Management settings');

        $this->handler->getFileSettingsOnly();
    }

    // =========================================================================
    // updateFileSettingsOnly
    // =========================================================================

    /**
     * Test updateFileSettingsOnly stores and returns config
     *
     * @return void
     */
    public function testUpdateFileSettingsOnlyStoresAndReturns(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'fileManagement', $this->isType('string'));

        $result = $this->handler->updateFileSettingsOnly([
            'vectorizationEnabled' => true,
            'provider' => 'openai',
            'chunkSize' => 500,
            'ocrEnabled' => true,
            'extractionScope' => 'all',
            'textExtractor' => 'dolphin',
            'extractionMode' => 'immediate',
            'dolphinApiEndpoint' => 'http://dolphin:8000',
            'dolphinApiKey' => 'dk-123',
            'presidioApiEndpoint' => 'http://presidio:5002',
            'entityRecognitionEnabled' => true,
            'entityRecognitionMethod' => 'presidio',
        ]);

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertSame('openai', $result['provider']);
        $this->assertSame(500, $result['chunkSize']);
        $this->assertTrue($result['ocrEnabled']);
        $this->assertSame('all', $result['extractionScope']);
        $this->assertSame('dolphin', $result['textExtractor']);
        $this->assertSame('immediate', $result['extractionMode']);
        $this->assertSame('http://dolphin:8000', $result['dolphinApiEndpoint']);
        $this->assertSame('dk-123', $result['dolphinApiKey']);
        $this->assertSame('http://presidio:5002', $result['presidioApiEndpoint']);
        $this->assertTrue($result['entityRecognitionEnabled']);
        $this->assertSame('presidio', $result['entityRecognitionMethod']);
    }

    /**
     * Test updateFileSettingsOnly with defaults for missing keys
     *
     * @return void
     */
    public function testUpdateFileSettingsOnlyDefaultsMissingKeys(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->handler->updateFileSettingsOnly([]);

        $this->assertFalse($result['vectorizationEnabled']);
        $this->assertNull($result['provider']);
        $this->assertSame('RECURSIVE_CHARACTER', $result['chunkingStrategy']);
        $this->assertSame(1000, $result['chunkSize']);
        $this->assertSame(200, $result['chunkOverlap']);
        $this->assertFalse($result['ocrEnabled']);
        $this->assertSame(100, $result['maxFileSizeMB']);
        $this->assertSame('objects', $result['extractionScope']);
        $this->assertSame('llphant', $result['textExtractor']);
        $this->assertSame('background', $result['extractionMode']);
        $this->assertSame(100, $result['maxFileSize']);
        $this->assertSame(10, $result['batchSize']);
        $this->assertSame('', $result['dolphinApiEndpoint']);
        $this->assertSame('', $result['dolphinApiKey']);
        $this->assertSame('', $result['presidioApiEndpoint']);
        $this->assertFalse($result['entityRecognitionEnabled']);
        $this->assertSame('hybrid', $result['entityRecognitionMethod']);
    }

    /**
     * Test updateFileSettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testUpdateFileSettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update File Management settings');

        $this->handler->updateFileSettingsOnly(['vectorizationEnabled' => true]);
    }

    // =========================================================================
    // getN8nSettingsOnly
    // =========================================================================

    /**
     * Test getN8nSettingsOnly returns defaults
     *
     * @return void
     */
    public function testGetN8nSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getN8nSettingsOnly();

        $this->assertFalse($result['enabled']);
        $this->assertSame('', $result['url']);
        $this->assertSame('', $result['apiKey']);
        $this->assertSame('openregister', $result['project']);
    }

    /**
     * Test getN8nSettingsOnly parses stored config
     *
     * @return void
     */
    public function testGetN8nSettingsOnlyParsesStoredConfig(): void
    {
        $storedConfig = json_encode([
            'enabled' => true,
            'url' => 'http://n8n:5678',
            'apiKey' => 'n8n-key-123',
            'project' => 'my-project',
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use ($storedConfig) {
                if ($key === 'n8n') {
                    return $storedConfig;
                }
                return '';
            });

        $result = $this->handler->getN8nSettingsOnly();

        $this->assertTrue($result['enabled']);
        $this->assertSame('http://n8n:5678', $result['url']);
        $this->assertSame('n8n-key-123', $result['apiKey']);
        $this->assertSame('my-project', $result['project']);
    }

    /**
     * Test getN8nSettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testGetN8nSettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve n8n settings');

        $this->handler->getN8nSettingsOnly();
    }

    // =========================================================================
    // updateN8nSettingsOnly
    // =========================================================================

    /**
     * Test updateN8nSettingsOnly stores and returns
     *
     * @return void
     */
    public function testUpdateN8nSettingsOnlyStoresAndReturns(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'n8n', $this->isType('string'));

        $result = $this->handler->updateN8nSettingsOnly([
            'enabled' => true,
            'url' => 'http://n8n:5678',
            'apiKey' => 'my-key',
            'project' => 'my-project',
        ]);

        $this->assertTrue($result['enabled']);
        $this->assertSame('http://n8n:5678', $result['url']);
        $this->assertSame('my-key', $result['apiKey']);
        $this->assertSame('my-project', $result['project']);
    }

    /**
     * Test updateN8nSettingsOnly with defaults for missing keys
     *
     * @return void
     */
    public function testUpdateN8nSettingsOnlyDefaultsMissingKeys(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->handler->updateN8nSettingsOnly([]);

        $this->assertFalse($result['enabled']);
        $this->assertSame('', $result['url']);
        $this->assertSame('', $result['apiKey']);
        $this->assertSame('openregister', $result['project']);
    }

    /**
     * Test updateN8nSettingsOnly throws RuntimeException on error
     *
     * @return void
     */
    public function testUpdateN8nSettingsOnlyThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update n8n settings');

        $this->handler->updateN8nSettingsOnly(['enabled' => true]);
    }

    // =========================================================================
    // getVersionInfoOnly
    // =========================================================================

    /**
     * Test getVersionInfoOnly skips when OC class unavailable
     *
     * @return void
     */
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
    // Error handling - getSettings
    // =========================================================================

    /**
     * Test getSettings throws RuntimeException on exception
     *
     * @return void
     */
    public function testGetSettingsThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve settings');

        $this->handler->getSettings();
    }

    // =========================================================================
    // Custom appName
    // =========================================================================

    /**
     * Test constructor with custom appName
     *
     * @return void
     */
    public function testConstructorWithCustomAppName(): void
    {
        $handler = new ConfigurationSettingsHandler(
            $this->appConfig,
            $this->groupManager,
            $this->userManager,
            $this->organisationMapper,
            $this->logger,
            'custom-app'
        );

        $this->appConfig->method('getValueString')
            ->willReturn('');

        // Verify it works without error (appName is used internally).
        $result = $handler->getSettings();
        $this->assertArrayHasKey('version', $result);
    }

    // =========================================================================
    // Retention config with partial stored data
    // =========================================================================

    /**
     * Test getSettings retention config uses defaults for missing keys
     *
     * @return void
     */
    public function testGetSettingsRetentionPartialConfigUsesDefaults(): void
    {
        $retentionConfig = json_encode([
            'objectArchiveRetention' => 12345,
        ]);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($appName, $key, $default) use ($retentionConfig) {
                if ($key === 'retention') {
                    return $retentionConfig;
                }
                return '';
            });

        $result = $this->handler->getSettings();

        $this->assertSame(12345, $result['retention']['objectArchiveRetention']);
        // Defaults for unset keys.
        $this->assertSame(63072000000, $result['retention']['objectDeleteRetention']);
        $this->assertSame(2592000000, $result['retention']['searchTrailRetention']);
        $this->assertTrue($result['retention']['auditTrailsEnabled']);
        $this->assertTrue($result['retention']['searchTrailsEnabled']);
    }

    // =========================================================================
    // Solr config with partial stored data
    // =========================================================================

    /**
     * Test getSettings Solr config uses defaults for missing keys
     *
     * @return void
     */
    public function testGetSettingsSolrPartialConfigUsesDefaults(): void
    {
        $solrConfig = json_encode([
            'enabled' => true,
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
        $this->assertSame('solr', $result['solr']['host']);
        $this->assertSame(8983, $result['solr']['port']);
        $this->assertSame('/solr', $result['solr']['path']);
        $this->assertSame('openregister', $result['solr']['core']);
        $this->assertSame('_default', $result['solr']['configSet']);
        $this->assertSame('http', $result['solr']['scheme']);
        $this->assertSame('solr', $result['solr']['username']);
        $this->assertSame('SolrRocks', $result['solr']['password']);
        $this->assertSame(30, $result['solr']['timeout']);
        $this->assertTrue($result['solr']['autoCommit']);
        $this->assertSame(1000, $result['solr']['commitWithin']);
        $this->assertTrue($result['solr']['enableLogging']);
        $this->assertSame('zookeeper:2181', $result['solr']['zookeeperHosts']);
        $this->assertSame('', $result['solr']['zookeeperUsername']);
        $this->assertSame('', $result['solr']['zookeeperPassword']);
        $this->assertSame('openregister', $result['solr']['collection']);
        $this->assertTrue($result['solr']['useCloud']);
        $this->assertNull($result['solr']['objectCollection']);
        $this->assertNull($result['solr']['fileCollection']);
    }

    // =========================================================================
    // updateSettings retention defaults
    // =========================================================================

    /**
     * Test updateSettings retention uses defaults for missing keys
     *
     * @return void
     */
    public function testUpdateSettingsRetentionDefaultsMissingKeys(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $result = $this->handler->updateSettings([
            'retention' => [],
        ]);

        $this->assertSame(31536000000, $result['retention']['objectArchiveRetention']);
        $this->assertSame(63072000000, $result['retention']['objectDeleteRetention']);
        $this->assertTrue($result['retention']['auditTrailsEnabled']);
        $this->assertTrue($result['retention']['searchTrailsEnabled']);
    }

    // =========================================================================
    // updateSettings solr port/timeout/commitWithin casting
    // =========================================================================

    /**
     * Test updateSettings Solr config casts numeric string values
     *
     * @return void
     */
    public function testUpdateSettingsSolrCastsNumericValues(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $this->handler->updateSettings([
            'solr' => [
                'port' => '1234',
                'timeout' => '45',
                'commitWithin' => '3000',
            ],
        ]);

        $decoded = json_decode($storedValues['solr'], true);
        $this->assertSame(1234, $decoded['port']);
        $this->assertSame(45, $decoded['timeout']);
        $this->assertSame(3000, $decoded['commitWithin']);
    }

    // =========================================================================
    // Multitenancy partial config
    // =========================================================================

    /**
     * Test getMultitenancySettingsOnly with partial stored config
     *
     * @return void
     */
    public function testGetMultitenancySettingsOnlyPartialConfig(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                if ($key === 'multitenancy') {
                    return json_encode(['enabled' => false]);
                }
                return '';
            });

        $result = $this->handler->getMultitenancySettingsOnly();

        $this->assertFalse($result['multitenancy']['enabled']);
        // Defaults for unset keys.
        $this->assertSame('', $result['multitenancy']['defaultUserTenant']);
        $this->assertSame('', $result['multitenancy']['defaultObjectTenant']);
        $this->assertFalse($result['multitenancy']['publishedObjectsBypassMultiTenancy']);
        $this->assertTrue($result['multitenancy']['adminOverride']);
    }

    // =========================================================================
    // RBAC partial config
    // =========================================================================

    /**
     * Test getRbacSettingsOnly with partial stored config
     *
     * @return void
     */
    public function testGetRbacSettingsOnlyPartialConfig(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn(json_encode(['enabled' => false]));

        $result = $this->handler->getRbacSettingsOnly();

        $this->assertFalse($result['rbac']['enabled']);
        // Defaults for unset keys.
        $this->assertSame('public', $result['rbac']['anonymousGroup']);
        $this->assertSame('viewer', $result['rbac']['defaultNewUserGroup']);
        $this->assertSame('', $result['rbac']['defaultObjectOwner']);
        $this->assertTrue($result['rbac']['adminOverride']);
    }

    // =========================================================================
    // Organisation partial config
    // =========================================================================

    /**
     * Test getOrganisationSettingsOnly with partial stored config
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnlyPartialConfig(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                if ($key === 'organisation') {
                    return json_encode(['default_organisation' => 'uuid-x']);
                }
                return '';
            });

        $result = $this->handler->getOrganisationSettingsOnly();

        $this->assertSame('uuid-x', $result['organisation']['default_organisation']);
        $this->assertTrue($result['organisation']['auto_create_default_organisation']);
    }

    // =========================================================================
    // updateSettings with Solr objectCollection/fileCollection
    // =========================================================================

    /**
     * Test updateSettings Solr with objectCollection and fileCollection
     *
     * @return void
     */
    public function testUpdateSettingsSolrWithCollectionFields(): void
    {
        $storedValues = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use (&$storedValues) {
                return $storedValues[$key] ?? '';
            });

        $result = $this->handler->updateSettings([
            'solr' => [
                'enabled' => true,
                'objectCollection' => 'obj_col',
                'fileCollection' => 'file_col',
            ],
        ]);

        $this->assertSame('obj_col', $result['solr']['objectCollection']);
        $this->assertSame('file_col', $result['solr']['fileCollection']);
    }

    // =========================================================================
    // updateLLMSettingsOnly with empty existing and full new
    // =========================================================================

    /**
     * Test updateLLMSettingsOnly with full config from empty state
     *
     * @return void
     */
    public function testUpdateLLMSettingsOnlyFullConfigFromEmpty(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->handler->updateLLMSettingsOnly([
            'enabled' => true,
            'embeddingProvider' => 'openai',
            'chatProvider' => 'ollama',
            'openaiConfig' => [
                'apiKey' => 'sk-test',
                'model' => 'embed-3',
                'chatModel' => 'gpt-4',
                'organizationId' => 'org-1',
            ],
            'ollamaConfig' => [
                'url' => 'http://ollama:11434',
                'model' => 'nomic-embed',
                'chatModel' => 'llama3',
            ],
            'fireworksConfig' => [
                'apiKey' => 'fw-key',
                'embeddingModel' => 'nomic',
                'chatModel' => 'llama',
                'baseUrl' => 'https://fw.ai/v1',
            ],
            'vectorConfig' => [
                'backend' => 'solr',
                'solrField' => '_vec_',
            ],
        ]);

        $this->assertTrue($result['enabled']);
        $this->assertSame('openai', $result['embeddingProvider']);
        $this->assertSame('ollama', $result['chatProvider']);
        $this->assertSame('sk-test', $result['openaiConfig']['apiKey']);
        $this->assertSame('embed-3', $result['openaiConfig']['model']);
        $this->assertSame('gpt-4', $result['openaiConfig']['chatModel']);
        $this->assertSame('org-1', $result['openaiConfig']['organizationId']);
        $this->assertSame('http://ollama:11434', $result['ollamaConfig']['url']);
        $this->assertSame('nomic-embed', $result['ollamaConfig']['model']);
        $this->assertSame('llama3', $result['ollamaConfig']['chatModel']);
        $this->assertSame('fw-key', $result['fireworksConfig']['apiKey']);
        $this->assertSame('nomic', $result['fireworksConfig']['embeddingModel']);
        $this->assertSame('llama', $result['fireworksConfig']['chatModel']);
        $this->assertSame('https://fw.ai/v1', $result['fireworksConfig']['baseUrl']);
        $this->assertSame('solr', $result['vectorConfig']['backend']);
        $this->assertSame('_vec_', $result['vectorConfig']['solrField']);
    }

    // =========================================================================
    // updateFileSettingsOnly with custom enabledFileTypes
    // =========================================================================

    /**
     * Test updateFileSettingsOnly with custom file types list
     *
     * @return void
     */
    public function testUpdateFileSettingsOnlyWithCustomFileTypes(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->handler->updateFileSettingsOnly([
            'enabledFileTypes' => ['pdf', 'txt'],
        ]);

        $this->assertSame(['pdf', 'txt'], $result['enabledFileTypes']);
    }
}
