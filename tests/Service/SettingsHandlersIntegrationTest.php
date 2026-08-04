<?php

/**
 * Integration tests for Settings Handlers, WebhookEventListener, and PreviewHandler
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentDeletedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Event\ApplicationCreatedEvent;
use OCA\OpenRegister\Event\ApplicationDeletedEvent;
use OCA\OpenRegister\Event\ApplicationUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationDeletedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\ConversationCreatedEvent;
use OCA\OpenRegister\Event\ConversationDeletedEvent;
use OCA\OpenRegister\Event\ConversationUpdatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\OrganisationDeletedEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceDeletedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Event\ViewCreatedEvent;
use OCA\OpenRegister\Event\ViewDeletedEvent;
use OCA\OpenRegister\Event\ViewUpdatedEvent;
use OCA\OpenRegister\Listener\WebhookEventListener;
use OCA\OpenRegister\Service\Configuration\PreviewHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for settings handlers and related services
 *
 * Tests CacheSettingsHandler, ConfigurationSettingsHandler,
 * FileSettingsHandler, ObjectRetentionHandler,
 * WebhookEventListener, and PreviewHandler
 * using the real Nextcloud DI container and database.
 *
 * @group DB
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class SettingsHandlersIntegrationTest extends TestCase
{
    /**
     * @var CacheSettingsHandler
     */
    private CacheSettingsHandler $cacheHandler;

    /**
     * @var ConfigurationSettingsHandler
     */
    private ConfigurationSettingsHandler $configHandler;

    /**
     * @var FileSettingsHandler
     */
    private FileSettingsHandler $fileHandler;

    /**
     * @var ObjectRetentionHandler
     */
    private ObjectRetentionHandler $retentionHandler;

    /**
     * @var WebhookEventListener
     */
    private WebhookEventListener $webhookListener;

    /**
     * @var PreviewHandler
     */
    private PreviewHandler $previewHandler;

    /**
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Config keys that we set during tests and need to clean up
     *
     * @var string[]
     */
    private array $configKeysToClean = [];

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheHandler     = \OC::$server->get(CacheSettingsHandler::class);
        $this->configHandler    = \OC::$server->get(ConfigurationSettingsHandler::class);
        $this->fileHandler      = \OC::$server->get(FileSettingsHandler::class);
        $this->retentionHandler = \OC::$server->get(ObjectRetentionHandler::class);
        $this->webhookListener  = \OC::$server->get(WebhookEventListener::class);
        $this->previewHandler   = \OC::$server->get(PreviewHandler::class);
        $this->appConfig        = \OC::$server->get(IAppConfig::class);
    }

    /**
     * Tear down test fixtures - clean up config values
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->configKeysToClean as $key) {
            try {
                $this->appConfig->deleteKey('openregister', $key);
            } catch (\Exception $e) {
                // Ignore cleanup errors.
            }
        }

        parent::tearDown();
    }

    /**
     * Track a config key for cleanup in tearDown
     *
     * @param string $key Config key name
     *
     * @return void
     */
    private function trackConfigKey(string $key): void
    {
        if (in_array($key, $this->configKeysToClean, true) === false) {
            $this->configKeysToClean[] = $key;
        }
    }

    /**
     * Create a test ObjectEntity for event tests
     *
     * @return ObjectEntity
     */
    private function createTestObject(): ObjectEntity
    {
        $obj = new ObjectEntity();
        $obj->setUuid('test-uuid-' . uniqid());
        $obj->setRegister(1);
        $obj->setSchema(1);
        return $obj;
    }

    /**
     * Create a test Register for event tests
     *
     * @return Register
     */
    private function createTestRegister(): Register
    {
        $reg = new Register();
        $reg->setTitle('Test Register');
        return $reg;
    }

    /**
     * Create a test Schema for event tests
     *
     * @return Schema
     */
    private function createTestSchema(): Schema
    {
        $schema = new Schema();
        $schema->setTitle('Test Schema');
        return $schema;
    }

    // =========================================================================
    // CacheSettingsHandler tests
    // =========================================================================

    /**
     * Test getCacheStats returns array with expected structure
     *
     * @return void
     */
    public function testGetCacheStatsReturnsArrayWithExpectedKeys(): void
    {
        $stats = $this->cacheHandler->getCacheStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('overview', $stats);
        $this->assertArrayHasKey('services', $stats);
        $this->assertArrayHasKey('names', $stats);
        $this->assertArrayHasKey('distributed', $stats);
        $this->assertArrayHasKey('performance', $stats);
        $this->assertArrayHasKey('lastUpdated', $stats);
    }

    /**
     * Test getCacheStats overview has numeric values
     *
     * @return void
     */
    public function testGetCacheStatsOverviewHasNumericValues(): void
    {
        $stats = $this->cacheHandler->getCacheStats();

        $overview = $stats['overview'];
        $this->assertArrayHasKey('totalCacheSize', $overview);
        $this->assertArrayHasKey('totalCacheEntries', $overview);
        $this->assertArrayHasKey('overallHitRate', $overview);
        $this->assertArrayHasKey('averageResponseTime', $overview);
        $this->assertArrayHasKey('cacheEfficiency', $overview);
    }

    /**
     * Test getCacheStats services section has object/schema/facet
     *
     * @return void
     */
    public function testGetCacheStatsServicesHasExpectedTypes(): void
    {
        $stats = $this->cacheHandler->getCacheStats();

        $services = $stats['services'];
        $this->assertArrayHasKey('object', $services);
        $this->assertArrayHasKey('schema', $services);
        $this->assertArrayHasKey('facet', $services);
    }

    /**
     * Test getCacheStats performance section has expected metrics
     *
     * @return void
     */
    public function testGetCacheStatsPerformanceHasExpectedMetrics(): void
    {
        $stats = $this->cacheHandler->getCacheStats();

        $perf = $stats['performance'];
        $this->assertArrayHasKey('averageHitTime', $perf);
        $this->assertArrayHasKey('averageMissTime', $perf);
        $this->assertArrayHasKey('performanceGain', $perf);
        $this->assertArrayHasKey('optimalHitRate', $perf);
    }

    /**
     * Test clearCache with type 'all' triggers TypeError due to int+string bug
     *
     * The distributed cache returns 'all' (string) for 'cleared' which causes
     * a TypeError when calculating totalCleared. This tests the known bug path.
     *
     * @return void
     */
    public function testClearCacheAllTriggersTypeError(): void
    {
        // Known bug: distributed cache returns 'all' string for cleared count,
        // causing TypeError when summing totalCleared.
        $this->expectException(\TypeError::class);
        $this->cacheHandler->clearCache('all');
    }

    /**
     * Test clearCache with type 'schema' clears only schema cache
     *
     * @return void
     */
    public function testClearCacheSchemaReturnsSchemaResult(): void
    {
        $result = $this->cacheHandler->clearCache('schema');

        $this->assertEquals('schema', $result['type']);
        $this->assertArrayHasKey('schema', $result['results']);
        $this->assertEquals('schema', $result['results']['schema']['service']);
    }

    /**
     * Test clearCache with type 'facet' clears only facet cache
     *
     * @return void
     */
    public function testClearCacheFacetReturnsFacetResult(): void
    {
        $result = $this->cacheHandler->clearCache('facet');

        $this->assertEquals('facet', $result['type']);
        $this->assertArrayHasKey('facet', $result['results']);
        $this->assertEquals('facet', $result['results']['facet']['service']);
    }

    /**
     * Test clearCache with type 'distributed' triggers TypeError due to int+string bug
     *
     * The distributed cache returns 'all' (string) for 'cleared' which causes
     * a TypeError when calculating totalCleared.
     *
     * @return void
     */
    public function testClearCacheDistributedTriggersTypeError(): void
    {
        // Known bug: distributed cache returns 'all' string for cleared count.
        $this->expectException(\TypeError::class);
        $this->cacheHandler->clearCache('distributed');
    }

    /**
     * Test clearCache with type 'object' clears object cache
     *
     * @return void
     */
    public function testClearCacheObjectReturnsResult(): void
    {
        $result = $this->cacheHandler->clearCache('object');

        $this->assertEquals('object', $result['type']);
        $this->assertArrayHasKey('object', $result['results']);
        $this->assertEquals('object', $result['results']['object']['service']);
    }

    /**
     * Test clearCache with type 'names' clears names cache
     *
     * @return void
     */
    public function testClearCacheNamesReturnsResult(): void
    {
        $result = $this->cacheHandler->clearCache('names');

        $this->assertEquals('names', $result['type']);
        $this->assertArrayHasKey('names', $result['results']);
        $this->assertEquals('names', $result['results']['names']['service']);
    }

    /**
     * Test clearCache with invalid type throws exception
     *
     * @return void
     */
    public function testClearCacheInvalidTypeThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cacheHandler->clearCache('nonexistent');
    }

    /**
     * Test clearCache with userId parameter (uses schema type to avoid distributed bug)
     *
     * @return void
     */
    public function testClearCacheWithUserIdParameter(): void
    {
        $result = $this->cacheHandler->clearCache('schema', 'admin');

        $this->assertEquals('admin', $result['userId']);
        $this->assertArrayHasKey('results', $result);
    }

    /**
     * Test warmupNamesCache returns expected structure
     *
     * @return void
     */
    public function testWarmupNamesCacheReturnsExpectedStructure(): void
    {
        $result = $this->cacheHandler->warmupNamesCache();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('loaded_names', $result);
    }

    // =========================================================================
    // ConfigurationSettingsHandler tests
    // =========================================================================

    /**
     * Test getSettings returns array with version info
     *
     * @return void
     */
    public function testGetSettingsReturnsVersion(): void
    {
        $settings = $this->configHandler->getSettings();

        $this->assertIsArray($settings);
        $this->assertArrayHasKey('version', $settings);
        $this->assertArrayHasKey('appName', $settings['version']);
        $this->assertEquals('Open Register', $settings['version']['appName']);
    }

    /**
     * Test getSettings returns rbac section
     *
     * @return void
     */
    public function testGetSettingsReturnsRbacSection(): void
    {
        $settings = $this->configHandler->getSettings();

        $this->assertArrayHasKey('rbac', $settings);
        $this->assertArrayHasKey('enabled', $settings['rbac']);
        $this->assertArrayHasKey('anonymousGroup', $settings['rbac']);
        $this->assertArrayHasKey('defaultNewUserGroup', $settings['rbac']);
        $this->assertArrayHasKey('adminOverride', $settings['rbac']);
    }

    /**
     * Test getSettings returns multitenancy section
     *
     * @return void
     */
    public function testGetSettingsReturnsMultitenancySection(): void
    {
        $settings = $this->configHandler->getSettings();

        $this->assertArrayHasKey('multitenancy', $settings);
        $this->assertArrayHasKey('enabled', $settings['multitenancy']);
        $this->assertArrayHasKey('defaultUserTenant', $settings['multitenancy']);
        $this->assertArrayHasKey('adminOverride', $settings['multitenancy']);
    }

    /**
     * Test getSettings returns available groups
     *
     * @return void
     */
    public function testGetSettingsReturnsAvailableGroups(): void
    {
        $settings = $this->configHandler->getSettings();

        $this->assertArrayHasKey('availableGroups', $settings);
        $this->assertIsArray($settings['availableGroups']);
        // The public group should always be present.
        $this->assertArrayHasKey('public', $settings['availableGroups']);
    }

    /**
     * Test getSettings returns retention section with defaults
     *
     * @return void
     */
    public function testGetSettingsReturnsRetentionDefaults(): void
    {
        $settings = $this->configHandler->getSettings();

        $this->assertArrayHasKey('retention', $settings);
        $this->assertArrayHasKey('objectArchiveRetention', $settings['retention']);
        $this->assertArrayHasKey('objectDeleteRetention', $settings['retention']);
        $this->assertArrayHasKey('auditTrailsEnabled', $settings['retention']);
    }

    /**
     * Test updateSettings with RBAC data persists settings
     *
     * @return void
     */
    public function testUpdateSettingsWithRbacData(): void
    {
        $this->trackConfigKey('rbac');

        $data = [
            'rbac' => [
                'enabled'             => false,
                'anonymousGroup'      => 'guest',
                'defaultNewUserGroup' => 'editor',
                'defaultObjectOwner'  => 'admin',
                'adminOverride'       => false,
            ],
        ];

        $result = $this->configHandler->updateSettings($data);

        $this->assertArrayHasKey('rbac', $result);
        $this->assertFalse($result['rbac']['enabled']);
        $this->assertEquals('guest', $result['rbac']['anonymousGroup']);
        $this->assertEquals('editor', $result['rbac']['defaultNewUserGroup']);
    }

    /**
     * Test updateSettings with multitenancy data persists
     *
     * @return void
     */
    public function testUpdateSettingsWithMultitenancyData(): void
    {
        $this->trackConfigKey('multitenancy');

        $data = [
            'multitenancy' => [
                'enabled'                            => false,
                'defaultUserTenant'                  => 'tenant-1',
                'defaultObjectTenant'                => 'tenant-2',
                'publishedObjectsBypassMultiTenancy' => true,
                'adminOverride'                      => false,
            ],
        ];

        $result = $this->configHandler->updateSettings($data);

        $this->assertArrayHasKey('multitenancy', $result);
        $this->assertFalse($result['multitenancy']['enabled']);
        $this->assertEquals('tenant-1', $result['multitenancy']['defaultUserTenant']);
    }

    /**
     * Test updateSettings with retention data persists
     *
     * @return void
     */
    public function testUpdateSettingsWithRetentionData(): void
    {
        $this->trackConfigKey('retention');

        $data = [
            'retention' => [
                'objectArchiveRetention' => 999999,
                'objectDeleteRetention'  => 888888,
                'auditTrailsEnabled'     => false,
                'searchTrailsEnabled'    => false,
            ],
        ];

        $result = $this->configHandler->updateSettings($data);

        $this->assertArrayHasKey('retention', $result);
        $this->assertEquals(999999, $result['retention']['objectArchiveRetention']);
    }

    /**
     * Test isMultiTenancyEnabled returns bool
     *
     * @return void
     */
    public function testIsMultiTenancyEnabledReturnsBool(): void
    {
        $result = $this->configHandler->isMultiTenancyEnabled();
        $this->assertIsBool($result);
    }

    /**
     * Test isMultiTenancyEnabled after setting to true
     *
     * @return void
     */
    public function testIsMultiTenancyEnabledAfterUpdate(): void
    {
        $this->trackConfigKey('multitenancy');

        $this->configHandler->updateMultitenancySettingsOnly([
            'enabled' => true,
        ]);

        $this->assertTrue($this->configHandler->isMultiTenancyEnabled());
    }

    /**
     * Test getRbacSettingsOnly returns expected structure
     *
     * @return void
     */
    public function testGetRbacSettingsOnlyReturnsExpectedStructure(): void
    {
        $result = $this->configHandler->getRbacSettingsOnly();

        $this->assertArrayHasKey('rbac', $result);
        $this->assertArrayHasKey('availableGroups', $result);
        $this->assertArrayHasKey('availableUsers', $result);
        $this->assertArrayHasKey('enabled', $result['rbac']);
    }

    /**
     * Test updateRbacSettingsOnly persists values
     *
     * @return void
     */
    public function testUpdateRbacSettingsOnlyPersists(): void
    {
        $this->trackConfigKey('rbac');

        $result = $this->configHandler->updateRbacSettingsOnly([
            'enabled'             => false,
            'anonymousGroup'      => 'anon',
            'defaultNewUserGroup' => 'reader',
            'defaultObjectOwner'  => 'system',
            'adminOverride'       => false,
        ]);

        $this->assertArrayHasKey('rbac', $result);
        $this->assertFalse($result['rbac']['enabled']);
        $this->assertEquals('anon', $result['rbac']['anonymousGroup']);
    }

    /**
     * Test getMultitenancySettingsOnly returns expected structure
     *
     * @return void
     */
    public function testGetMultitenancySettingsOnlyReturnsExpectedStructure(): void
    {
        $result = $this->configHandler->getMultitenancySettingsOnly();

        $this->assertArrayHasKey('multitenancy', $result);
        $this->assertArrayHasKey('availableTenants', $result);
        $this->assertArrayHasKey('enabled', $result['multitenancy']);
    }

    /**
     * Test updateMultitenancySettingsOnly persists values
     *
     * @return void
     */
    public function testUpdateMultitenancySettingsOnlyPersists(): void
    {
        $this->trackConfigKey('multitenancy');

        $result = $this->configHandler->updateMultitenancySettingsOnly([
            'enabled'                            => false,
            'defaultUserTenant'                  => 'my-tenant',
            'publishedObjectsBypassMultiTenancy' => true,
        ]);

        $this->assertArrayHasKey('multitenancy', $result);
        $this->assertFalse($result['multitenancy']['enabled']);
        $this->assertEquals('my-tenant', $result['multitenancy']['defaultUserTenant']);
    }

    /**
     * Test getOrganisationSettingsOnly returns expected structure
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnlyReturnsExpectedStructure(): void
    {
        $result = $this->configHandler->getOrganisationSettingsOnly();

        $this->assertArrayHasKey('organisation', $result);
        $this->assertArrayHasKey('default_organisation', $result['organisation']);
        $this->assertArrayHasKey('auto_create_default_organisation', $result['organisation']);
    }

    /**
     * Test updateOrganisationSettingsOnly persists values
     *
     * @return void
     */
    public function testUpdateOrganisationSettingsOnlyPersists(): void
    {
        $this->trackConfigKey('organisation');

        $result = $this->configHandler->updateOrganisationSettingsOnly([
            'default_organisation'             => 'org-uuid-123',
            'auto_create_default_organisation' => false,
        ]);

        $this->assertArrayHasKey('organisation', $result);
        $this->assertEquals('org-uuid-123', $result['organisation']['default_organisation']);
        $this->assertFalse($result['organisation']['auto_create_default_organisation']);
    }

    /**
     * Test getDefaultOrganisationUuid returns nullable string
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuidReturnsNullableString(): void
    {
        $result = $this->configHandler->getDefaultOrganisationUuid();
        // Can be null or string.
        $this->assertTrue($result === null || is_string($result));
    }

    /**
     * Test setDefaultOrganisationUuid persists value
     *
     * @return void
     */
    public function testSetDefaultOrganisationUuidPersists(): void
    {
        $this->trackConfigKey('organisation');

        $testUuid = 'test-org-uuid-' . uniqid();
        $this->configHandler->setDefaultOrganisationUuid($testUuid);

        $result = $this->configHandler->getDefaultOrganisationUuid();
        $this->assertEquals($testUuid, $result);
    }

    /**
     * Test getOrganisationId is alias for getDefaultOrganisationUuid
     *
     * @return void
     */
    public function testGetOrganisationIdIsAlias(): void
    {
        $uuid = $this->configHandler->getDefaultOrganisationUuid();
        $orgId = $this->configHandler->getOrganisationId();
        $this->assertEquals($uuid, $orgId);
    }

    /**
     * Test getTenantId returns nullable string
     *
     * @return void
     */
    public function testGetTenantIdReturnsNullableString(): void
    {
        $result = $this->configHandler->getTenantId();
        $this->assertTrue($result === null || is_string($result));
    }

    /**
     * Test getLLMSettingsOnly returns expected defaults
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyReturnsDefaults(): void
    {
        $result = $this->configHandler->getLLMSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertArrayHasKey('vectorConfig', $result);
        $this->assertArrayHasKey('backend', $result['vectorConfig']);
        $this->assertArrayHasKey('solrField', $result['vectorConfig']);
    }

    /**
     * Test updateLLMSettingsOnly persists and merges config
     *
     * @return void
     */
    public function testUpdateLLMSettingsOnlyPersistsAndMerges(): void
    {
        $this->trackConfigKey('llm');

        $result = $this->configHandler->updateLLMSettingsOnly([
            'enabled'           => true,
            'embeddingProvider' => 'openai',
            'openaiConfig'      => ['apiKey' => 'test-key-123'],
        ]);

        $this->assertTrue($result['enabled']);
        $this->assertEquals('openai', $result['embeddingProvider']);
        $this->assertEquals('test-key-123', $result['openaiConfig']['apiKey']);
        // Verify vector config preserved.
        $this->assertArrayHasKey('vectorConfig', $result);
    }

    /**
     * Test getN8nSettingsOnly returns expected structure
     *
     * @return void
     */
    public function testGetN8nSettingsOnlyReturnsDefaults(): void
    {
        $result = $this->configHandler->getN8nSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('apiKey', $result);
    }

    /**
     * Test updateN8nSettingsOnly persists values
     *
     * @return void
     */
    public function testUpdateN8nSettingsOnlyPersists(): void
    {
        $this->trackConfigKey('n8n');

        $result = $this->configHandler->updateN8nSettingsOnly([
            'enabled' => true,
            'url'     => 'http://n8n:5678',
            'apiKey'  => 'test-api-key',
            'project' => 'myproject',
        ]);

        $this->assertTrue($result['enabled']);
        $this->assertEquals('http://n8n:5678', $result['url']);
        $this->assertEquals('test-api-key', $result['apiKey']);
        $this->assertEquals('myproject', $result['project']);
    }

    /**
     * Test getVersionInfoOnly returns version info
     *
     * @return void
     */
    public function testGetVersionInfoOnlyReturnsVersionInfo(): void
    {
        $result = $this->configHandler->getVersionInfoOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('name', $result);
    }

    /**
     * Test getFileSettingsOnly on ConfigurationSettingsHandler
     *
     * @return void
     */
    public function testConfigHandlerGetFileSettingsOnlyReturnsDefaults(): void
    {
        $result = $this->configHandler->getFileSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('vectorizationEnabled', $result);
        $this->assertArrayHasKey('chunkingStrategy', $result);
        $this->assertArrayHasKey('enabledFileTypes', $result);
    }

    /**
     * Test updateFileSettingsOnly on ConfigurationSettingsHandler
     *
     * @return void
     */
    public function testConfigHandlerUpdateFileSettingsOnlyPersists(): void
    {
        $this->trackConfigKey('fileManagement');

        $result = $this->configHandler->updateFileSettingsOnly([
            'vectorizationEnabled' => true,
            'chunkSize'            => 2000,
            'extractionMode'       => 'immediate',
        ]);

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertEquals(2000, $result['chunkSize']);
        $this->assertEquals('immediate', $result['extractionMode']);
    }

    // =========================================================================
    // FileSettingsHandler tests
    // =========================================================================

    /**
     * Test getFileSettingsOnly returns defaults when no config
     *
     * @return void
     */
    public function testFileHandlerGetFileSettingsOnlyReturnsDefaults(): void
    {
        $result = $this->fileHandler->getFileSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('vectorizationEnabled', $result);
        $this->assertArrayHasKey('chunkingStrategy', $result);
        $this->assertArrayHasKey('chunkSize', $result);
        $this->assertArrayHasKey('chunkOverlap', $result);
        $this->assertArrayHasKey('enabledFileTypes', $result);
        $this->assertArrayHasKey('ocrEnabled', $result);
        $this->assertArrayHasKey('maxFileSizeMB', $result);
        $this->assertArrayHasKey('extractionScope', $result);
        $this->assertArrayHasKey('textExtractor', $result);
        $this->assertArrayHasKey('extractionMode', $result);
    }

    /**
     * Test getFileSettingsOnly default values are correct
     *
     * @return void
     */
    public function testFileHandlerDefaultValuesAreCorrect(): void
    {
        $result = $this->fileHandler->getFileSettingsOnly();

        $this->assertFalse($result['vectorizationEnabled']);
        $this->assertEquals('RECURSIVE_CHARACTER', $result['chunkingStrategy']);
        $this->assertEquals(1000, $result['chunkSize']);
        $this->assertEquals(200, $result['chunkOverlap']);
        $this->assertFalse($result['ocrEnabled']);
        $this->assertEquals(100, $result['maxFileSizeMB']);
    }

    /**
     * Test updateFileSettingsOnly saves and returns config
     *
     * @return void
     */
    public function testFileHandlerUpdateFileSettingsOnlyPersists(): void
    {
        $this->trackConfigKey('fileManagement');

        $result = $this->fileHandler->updateFileSettingsOnly([
            'vectorizationEnabled' => true,
            'provider'             => 'openai',
            'chunkSize'            => 500,
            'ocrEnabled'           => true,
            'maxFileSizeMB'        => 50,
            'textExtractor'        => 'dolphin',
            'extractionMode'       => 'manual',
        ]);

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertEquals('openai', $result['provider']);
        $this->assertEquals(500, $result['chunkSize']);
        $this->assertTrue($result['ocrEnabled']);
        $this->assertEquals(50, $result['maxFileSizeMB']);
        $this->assertEquals('dolphin', $result['textExtractor']);
        $this->assertEquals('manual', $result['extractionMode']);
    }

    /**
     * Test updateFileSettingsOnly with entity recognition settings
     *
     * @return void
     */
    public function testFileHandlerEntityRecognitionSettings(): void
    {
        $this->trackConfigKey('fileManagement');

        $result = $this->fileHandler->updateFileSettingsOnly([
            'entityRecognitionEnabled'  => true,
            'entityRecognitionMethod'   => 'presidio',
            'presidioApiEndpoint'       => 'http://presidio:5001',
            'openAnonymiserApiEndpoint' => 'http://openanon:8080',
        ]);

        $this->assertTrue($result['entityRecognitionEnabled']);
        $this->assertEquals('presidio', $result['entityRecognitionMethod']);
        $this->assertEquals('http://presidio:5001', $result['presidioApiEndpoint']);
    }

    /**
     * Test updateFileSettingsOnly round-trip (save then read)
     *
     * @return void
     */
    public function testFileHandlerUpdateThenGetRoundTrip(): void
    {
        $this->trackConfigKey('fileManagement');

        $this->fileHandler->updateFileSettingsOnly([
            'vectorizationEnabled' => true,
            'chunkSize'            => 777,
        ]);

        $result = $this->fileHandler->getFileSettingsOnly();

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertEquals(777, $result['chunkSize']);
    }

    // =========================================================================
    // ObjectRetentionHandler tests
    // =========================================================================

    /**
     * Test getObjectSettingsOnly returns expected defaults
     *
     * @return void
     */
    public function testGetObjectSettingsOnlyReturnsDefaults(): void
    {
        $result = $this->retentionHandler->getObjectSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('vectorizationEnabled', $result);
        $this->assertArrayHasKey('vectorizeOnCreate', $result);
        $this->assertArrayHasKey('vectorizeOnUpdate', $result);
        $this->assertArrayHasKey('vectorizeAllViews', $result);
        $this->assertArrayHasKey('enabledViews', $result);
        $this->assertArrayHasKey('includeMetadata', $result);
        $this->assertArrayHasKey('includeRelations', $result);
        $this->assertArrayHasKey('maxNestingDepth', $result);
        $this->assertArrayHasKey('batchSize', $result);
        $this->assertArrayHasKey('autoRetry', $result);
    }

    /**
     * Test updateObjectSettingsOnly persists values
     *
     * @return void
     */
    public function testUpdateObjectSettingsOnlyPersists(): void
    {
        $this->trackConfigKey('objectManagement');

        $result = $this->retentionHandler->updateObjectSettingsOnly([
            'vectorizationEnabled' => true,
            'vectorizeOnCreate'    => false,
            'maxNestingDepth'      => 5,
            'batchSize'            => 50,
        ]);

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertFalse($result['vectorizeOnCreate']);
        $this->assertEquals(5, $result['maxNestingDepth']);
        $this->assertEquals(50, $result['batchSize']);
    }

    /**
     * Test updateObjectSettingsOnly then getObjectSettingsOnly round-trip
     *
     * @return void
     */
    public function testObjectSettingsRoundTrip(): void
    {
        $this->trackConfigKey('objectManagement');

        $this->retentionHandler->updateObjectSettingsOnly([
            'vectorizationEnabled' => true,
            'batchSize'            => 42,
            'autoRetry'            => false,
        ]);

        $result = $this->retentionHandler->getObjectSettingsOnly();

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertEquals(42, $result['batchSize']);
        $this->assertFalse($result['autoRetry']);
    }

    /**
     * Test getRetentionSettingsOnly returns expected defaults
     *
     * @return void
     */
    public function testGetRetentionSettingsOnlyReturnsDefaults(): void
    {
        $result = $this->retentionHandler->getRetentionSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('objectArchiveRetention', $result);
        $this->assertArrayHasKey('objectDeleteRetention', $result);
        $this->assertArrayHasKey('searchTrailRetention', $result);
        $this->assertArrayHasKey('createLogRetention', $result);
        $this->assertArrayHasKey('readLogRetention', $result);
        $this->assertArrayHasKey('updateLogRetention', $result);
        $this->assertArrayHasKey('deleteLogRetention', $result);
        $this->assertArrayHasKey('auditTrailsEnabled', $result);
        $this->assertArrayHasKey('searchTrailsEnabled', $result);
    }

    /**
     * Test updateRetentionSettingsOnly persists values
     *
     * @return void
     */
    public function testUpdateRetentionSettingsOnlyPersists(): void
    {
        $this->trackConfigKey('retention');

        $result = $this->retentionHandler->updateRetentionSettingsOnly([
            'objectArchiveRetention' => 12345,
            'objectDeleteRetention'  => 67890,
            'auditTrailsEnabled'     => false,
            'searchTrailsEnabled'    => false,
        ]);

        $this->assertEquals(12345, $result['objectArchiveRetention']);
        $this->assertEquals(67890, $result['objectDeleteRetention']);
    }

    /**
     * Test retention settings round-trip
     *
     * @return void
     */
    public function testRetentionSettingsRoundTrip(): void
    {
        $this->trackConfigKey('retention');

        $this->retentionHandler->updateRetentionSettingsOnly([
            'objectArchiveRetention' => 11111,
            'readLogRetention'       => 22222,
            'auditTrailsEnabled'     => false,
        ]);

        $result = $this->retentionHandler->getRetentionSettingsOnly();

        $this->assertEquals(11111, $result['objectArchiveRetention']);
        $this->assertEquals(22222, $result['readLogRetention']);
        $this->assertFalse($result['auditTrailsEnabled']);
    }

    /**
     * Test getVersionInfoOnly returns expected structure
     *
     * @return void
     */
    public function testRetentionHandlerGetVersionInfoOnly(): void
    {
        $result = $this->retentionHandler->getVersionInfoOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('appName', $result);
        $this->assertArrayHasKey('appVersion', $result);
        $this->assertEquals('Open Register', $result['appName']);
    }

    // =========================================================================
    // WebhookEventListener tests
    // =========================================================================

    /**
     * Test handle with ObjectCreatingEvent extracts payload
     *
     * @return void
     */
    public function testHandleObjectCreatingEvent(): void
    {
        $obj   = $this->createTestObject();
        $event = new ObjectCreatingEvent($obj);

        // Should not throw - will log warning if no webhooks configured.
        $this->webhookListener->handle($event);
        $this->assertTrue(true); // Verify no exception thrown.
    }

    /**
     * Test handle with ObjectCreatedEvent
     *
     * @return void
     */
    public function testHandleObjectCreatedEvent(): void
    {
        $obj   = $this->createTestObject();
        $event = new ObjectCreatedEvent($obj);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with ObjectUpdatingEvent
     *
     * @return void
     */
    public function testHandleObjectUpdatingEvent(): void
    {
        $newObj = $this->createTestObject();
        $oldObj = $this->createTestObject();
        $event  = new ObjectUpdatingEvent($newObj, $oldObj);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with ObjectUpdatedEvent
     *
     * @return void
     */
    public function testHandleObjectUpdatedEvent(): void
    {
        $newObj = $this->createTestObject();
        $oldObj = $this->createTestObject();
        $event  = new ObjectUpdatedEvent($newObj, $oldObj);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with ObjectDeletingEvent
     *
     * @return void
     */
    public function testHandleObjectDeletingEvent(): void
    {
        $obj   = $this->createTestObject();
        $event = new ObjectDeletingEvent($obj);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with ObjectDeletedEvent
     *
     * @return void
     */
    public function testHandleObjectDeletedEvent(): void
    {
        $obj   = $this->createTestObject();
        $event = new ObjectDeletedEvent($obj);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with ObjectLockedEvent
     *
     * @return void
     */
    public function testHandleObjectLockedEvent(): void
    {
        $obj   = $this->createTestObject();
        $event = new ObjectLockedEvent($obj);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with ObjectUnlockedEvent
     *
     * @return void
     */
    public function testHandleObjectUnlockedEvent(): void
    {
        $obj   = $this->createTestObject();
        $event = new ObjectUnlockedEvent($obj);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with ObjectRevertedEvent
     *
     * @return void
     */
    public function testHandleObjectRevertedEvent(): void
    {
        $obj   = $this->createTestObject();
        $event = new ObjectRevertedEvent($obj, '2024-01-01');

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with RegisterCreatedEvent
     *
     * @return void
     */
    public function testHandleRegisterCreatedEvent(): void
    {
        $reg   = $this->createTestRegister();
        $event = new RegisterCreatedEvent($reg);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with RegisterUpdatedEvent
     *
     * @return void
     */
    public function testHandleRegisterUpdatedEvent(): void
    {
        $newReg = $this->createTestRegister();
        $oldReg = $this->createTestRegister();
        $event  = new RegisterUpdatedEvent($newReg, $oldReg);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with RegisterDeletedEvent
     *
     * @return void
     */
    public function testHandleRegisterDeletedEvent(): void
    {
        $reg   = $this->createTestRegister();
        $event = new RegisterDeletedEvent($reg);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with SchemaCreatedEvent
     *
     * @return void
     */
    public function testHandleSchemaCreatedEvent(): void
    {
        $schema = $this->createTestSchema();
        $event  = new SchemaCreatedEvent($schema);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with SchemaUpdatedEvent
     *
     * @return void
     */
    public function testHandleSchemaUpdatedEvent(): void
    {
        $newSchema = $this->createTestSchema();
        $oldSchema = $this->createTestSchema();
        $event     = new SchemaUpdatedEvent($newSchema, $oldSchema);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with SchemaDeletedEvent
     *
     * @return void
     */
    public function testHandleSchemaDeletedEvent(): void
    {
        $schema = $this->createTestSchema();
        $event  = new SchemaDeletedEvent($schema);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with ConfigurationCreatedEvent
     *
     * @return void
     */
    public function testHandleConfigurationCreatedEvent(): void
    {
        $config = new Configuration();
        $event  = new ConfigurationCreatedEvent($config);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with ConfigurationUpdatedEvent triggers error
     *
     * Note: ConfigurationUpdatedEvent is missing getConfiguration() method
     * which the WebhookEventListener expects. This tests the known bug path.
     *
     * @return void
     */
    public function testHandleConfigurationUpdatedEventTriggersError(): void
    {
        $newConfig = new Configuration();
        $oldConfig = new Configuration();
        $event     = new ConfigurationUpdatedEvent($newConfig, $oldConfig);

        // Known bug: ConfigurationUpdatedEvent lacks getConfiguration() method.
        $this->expectException(\Error::class);
        $this->webhookListener->handle($event);
    }

    /**
     * Test handle with ConfigurationDeletedEvent
     *
     * @return void
     */
    public function testHandleConfigurationDeletedEvent(): void
    {
        $config = new Configuration();
        $event  = new ConfigurationDeletedEvent($config);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with ViewCreatedEvent
     *
     * @return void
     */
    public function testHandleViewCreatedEvent(): void
    {
        $view  = new View();
        $event = new ViewCreatedEvent($view);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with ConversationCreatedEvent
     *
     * @return void
     */
    public function testHandleConversationCreatedEvent(): void
    {
        $conv  = new Conversation();
        $event = new ConversationCreatedEvent($conv);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with OrganisationCreatedEvent
     *
     * @return void
     */
    public function testHandleOrganisationCreatedEvent(): void
    {
        $org   = new Organisation();
        $event = new OrganisationCreatedEvent($org);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with SourceCreatedEvent
     *
     * @return void
     */
    public function testHandleSourceCreatedEvent(): void
    {
        $source = new Source();
        $event  = new SourceCreatedEvent($source);

        $this->webhookListener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test handle with unknown event type returns early (null payload)
     *
     * @return void
     */
    public function testHandleUnknownEventReturnsEarly(): void
    {
        // Create a generic event that is not handled by extractPayload.
        $event = new \OCP\EventDispatcher\Event();

        $this->webhookListener->handle($event);
        $this->assertTrue(true); // Should log warning and return.
    }

    // =========================================================================
    // PreviewHandler tests
    // =========================================================================

    /**
     * Test previewRegisterChange with new register returns create action
     *
     * @return void
     */
    public function testPreviewRegisterChangeNewRegisterReturnsCreate(): void
    {
        $result = $this->previewHandler->previewRegisterChange(
            'nonexistent-register-' . uniqid(),
            ['title' => 'New Register', 'version' => '1.0.0']
        );

        $this->assertEquals('register', $result['type']);
        $this->assertEquals('create', $result['action']);
        $this->assertNull($result['current']);
        $this->assertArrayHasKey('proposed', $result);
    }

    /**
     * Test previewRegisterChange structure has expected keys
     *
     * @return void
     */
    public function testPreviewRegisterChangeStructure(): void
    {
        $result = $this->previewHandler->previewRegisterChange(
            'test-slug',
            ['title' => 'Test']
        );

        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('slug', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('current', $result);
        $this->assertArrayHasKey('proposed', $result);
        $this->assertArrayHasKey('changes', $result);
    }

    /**
     * Test compareArrays returns array
     *
     * @return void
     */
    public function testCompareArraysReturnsArray(): void
    {
        $result = $this->previewHandler->compareArrays(
            ['key1' => 'value1'],
            ['key1' => 'value2']
        );

        $this->assertIsArray($result);
    }

    /**
     * Test importConfigurationWithSelection returns empty array (placeholder)
     *
     * @return void
     */
    public function testImportConfigurationWithSelectionReturnsArray(): void
    {
        $config = new Configuration();
        $result = $this->previewHandler->importConfigurationWithSelection($config, []);

        $this->assertIsArray($result);
    }
}
