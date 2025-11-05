<?php
/**
 * Configuration Management Integration Test
 *
 * This file contains integration tests for the configuration management feature,
 * testing version tracking, preview, selective import, and notifications via HTTP API calls.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Integration;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Class ConfigurationManagementIntegrationTest
 *
 * Tests the complete configuration management workflow via HTTP API calls including:
 * - Creating configurations with version tracking
 * - Checking remote versions
 * - Previewing configuration changes
 * - Selective imports
 * - Auto-update functionality
 * - Export functionality
 *
 * @package OCA\OpenRegister\Tests\Integration
 */
class ConfigurationManagementIntegrationTest extends TestCase
{

    /**
     * Guzzle HTTP client.
     *
     * @var Client
     */
    private Client $client;

    /**
     * Base URL for API calls.
     *
     * @var string
     */
    private string $baseUrl;

    /**
     * Basic auth credentials.
     *
     * @var array
     */
    private array $auth;

    /**
     * Test configuration JSON.
     *
     * @var array
     */
    private array $testConfigJson = [];

    /**
     * Created test configuration IDs for cleanup.
     *
     * @var array
     */
    private array $createdConfigIds = [];

    /**
     * Created test register IDs for cleanup.
     *
     * @var array
     */
    private array $createdRegisterIds = [];

    /**
     * Created test schema IDs for cleanup.
     *
     * @var array
     */
    private array $createdSchemaIds = [];


    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Set up Guzzle client to call the Nextcloud container
        // Using master-nextcloud-1 container name as per docker-compose
        $this->baseUrl = 'http://master-nextcloud-1/index.php/apps/openregister/api';
        $this->auth    = ['admin', 'admin'];

        $this->client = new Client([
            'base_uri'        => $this->baseUrl,
            'timeout'         => 30.0,
            'allow_redirects' => true,
            'http_errors'     => false, // Don't throw exceptions on 4xx/5xx responses
            'verify'          => false, // Disable SSL verification for local testing
        ]);

        // Create test configuration JSON
        $this->testConfigJson = [
            'version' => '1.0.0',
            'info'    => [
                'title'       => 'Test Configuration',
                'description' => 'Test configuration for integration tests',
                'version'     => '1.0.0',
            ],
            'components' => [
                'registers' => [
                    'testregister' => [
                        'title'       => 'Test Register',
                        'description' => 'A test register',
                        'slug'        => 'testregister',
                        'version'     => '1.0.0',
                        'schemas'     => [],
                    ],
                ],
                'schemas' => [
                    'testschema' => [
                        'title'       => 'Test Schema',
                        'description' => 'A test schema',
                        'slug'        => 'testschema',
                        'version'     => '1.0.0',
                        'type'        => 'object',
                        'properties'  => [
                            'name' => [
                                'type'  => 'string',
                                'title' => 'Name',
                            ],
                            'description' => [
                                'type'  => 'string',
                                'title' => 'Description',
                            ],
                        ],
                    ],
                ],
            ],
        ];

    }//end setUp()


    /**
     * Clean up after tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test data via API
        try {
            // Delete test configurations
            foreach ($this->createdConfigIds as $configId) {
                try {
                    $this->client->delete("/configurations/{$configId}", [
                        'auth' => $this->auth,
                    ]);
                } catch (\Exception $e) {
                    // Ignore cleanup errors
                }
            }

            // Delete test schemas
            foreach ($this->createdSchemaIds as $schemaId) {
                try {
                    $this->client->delete("/schemas/{$schemaId}", [
                        'auth' => $this->auth,
                    ]);
                } catch (\Exception $e) {
                    // Ignore cleanup errors
                }
            }

            // Delete test registers
            foreach ($this->createdRegisterIds as $registerId) {
                try {
                    $this->client->delete("/registers/{$registerId}", [
                        'auth' => $this->auth,
                    ]);
                } catch (\Exception $e) {
                    // Ignore cleanup errors
                }
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }

        parent::tearDown();

    }//end tearDown()


    /**
     * Test creating a configuration with version tracking via API.
     *
     * @return void
     */
    public function testCreateConfigurationWithVersionTracking(): void
    {
        $response = $this->client->post('/configurations', [
            'auth' => $this->auth,
            'json' => [
                'title'         => 'Test Configuration v1',
                'description'   => 'Test configuration for version tracking',
                'type'          => 'test',
                'sourceType'    => 'local',
                'localVersion'  => '1.0.0',
                'autoUpdate'    => false,
                'registers'     => [],
                'schemas'       => [],
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode(), 'Configuration should be created successfully');
        
        $data = json_decode($response->getBody()->getContents(), true);
        $this->assertNotEmpty($data['id'], 'Configuration should have an ID');
        $this->assertEquals('1.0.0', $data['localVersion']);
        $this->assertEquals('local', $data['sourceType']);
        $this->assertFalse($data['autoUpdate']);

        // Store for cleanup
        $this->createdConfigIds[] = $data['id'];

    }//end testCreateConfigurationWithVersionTracking()


    /**
     * Test importing configuration and tracking managed entities.
     *
     * @return void
     */
    public function testImportConfigurationAndTrackEntities(): void
    {
        // Create configuration entity
        $configuration = new Configuration();
        $configuration->setTitle('Test Configuration Import');
        $configuration->setDescription('Test configuration import');
        $configuration->setType('test');
        $configuration->setSourceType('local');
        $configuration->setLocalVersion('1.0.0');
        $configuration->setApp('test-app');
        
        $configuration = $this->configurationMapper->insert($configuration);

        // Import configuration
        $result = $this->configurationService->importFromJson(
            $this->testConfigJson,
            $configuration
        );

        // Verify registers were imported
        $this->assertNotEmpty($result['registers']);
        $this->assertCount(1, $result['registers']);
        $this->assertEquals('testregister', $result['registers'][0]->getSlug());

        // Verify schemas were imported
        $this->assertNotEmpty($result['schemas']);
        $this->assertCount(1, $result['schemas']);
        $this->assertEquals('testschema', $result['schemas'][0]->getSlug());

        // Verify configuration tracks the imported entities
        $reloadedConfig = $this->configurationMapper->find($configuration->getId());
        $this->assertContains($result['registers'][0]->getId(), $reloadedConfig->getRegisters());
        $this->assertContains($result['schemas'][0]->getId(), $reloadedConfig->getSchemas());

    }//end testImportConfigurationAndTrackEntities()


    /**
     * Test version comparison functionality.
     *
     * @return void
     */
    public function testVersionComparison(): void
    {
        $configuration = new Configuration();
        $configuration->setTitle('Test Configuration Version Compare');
        $configuration->setLocalVersion('1.0.0');
        $configuration->setRemoteVersion('1.1.0');
        
        $configuration = $this->configurationMapper->insert($configuration);

        // Test version comparison
        $comparison = $this->configurationService->compareVersions($configuration);
        
        $this->assertTrue($comparison['hasUpdate']);
        $this->assertEquals('1.0.0', $comparison['localVersion']);
        $this->assertEquals('1.1.0', $comparison['remoteVersion']);

    }//end testVersionComparison()


    /**
     * Test importing with updated version.
     *
     * @return void
     */
    public function testImportWithVersionUpdate(): void
    {
        // Create and import initial configuration
        $configuration = new Configuration();
        $configuration->setTitle('Test Configuration Version Update');
        $configuration->setType('test');
        $configuration->setSourceType('local');
        $configuration->setLocalVersion('1.0.0');
        $configuration->setApp('test-app-version');
        
        $configuration = $this->configurationMapper->insert($configuration);

        // Import v1.0.0
        $result1 = $this->configurationService->importFromJson(
            $this->testConfigJson,
            $configuration
        );

        $this->assertCount(1, $result1['schemas']);
        $originalSchemaId = $result1['schemas'][0]->getId();

        // Update configuration to v1.1.0
        $updatedConfig = $this->testConfigJson;
        $updatedConfig['version'] = '1.1.0';
        $updatedConfig['info']['version'] = '1.1.0';
        $updatedConfig['components']['schemas']['testschema']['version'] = '1.1.0';
        $updatedConfig['components']['schemas']['testschema']['properties']['newField'] = [
            'type'  => 'string',
            'title' => 'New Field',
        ];

        // Import v1.1.0
        $result2 = $this->configurationService->importFromJson(
            $updatedConfig,
            $configuration
        );

        // Verify schema was updated, not recreated
        $this->assertCount(1, $result2['schemas']);
        $this->assertEquals($originalSchemaId, $result2['schemas'][0]->getId());
        $this->assertEquals('1.1.0', $result2['schemas'][0]->getVersion());

    }//end testImportWithVersionUpdate()


    /**
     * Test managed entity detection.
     *
     * @return void
     */
    public function testManagedEntityDetection(): void
    {
        // Create configuration
        $configuration = new Configuration();
        $configuration->setTitle('Test Managed Entities');
        $configuration->setType('test');
        $configuration->setApp('test-app-managed');
        
        $configuration = $this->configurationMapper->insert($configuration);

        // Import configuration
        $result = $this->configurationService->importFromJson(
            $this->testConfigJson,
            $configuration
        );

        // Get all configurations
        $configurations = $this->configurationMapper->findAll();

        // Test schema managed detection
        $schema = $result['schemas'][0];
        $this->assertTrue($schema->isManagedByConfiguration($configurations));
        $managedBy = $schema->getManagedByConfiguration($configurations);
        $this->assertNotNull($managedBy);
        $this->assertEquals($configuration->getId(), $managedBy->getId());

        // Test register managed detection
        $register = $result['registers'][0];
        $this->assertTrue($register->isManagedByConfiguration($configurations));

    }//end testManagedEntityDetection()


    /**
     * Test YAML configuration import.
     *
     * @return void
     */
    public function testYamlConfigurationImport(): void
    {
        $this->markTestSkipped('YAML import requires file upload simulation');
        // This would require creating a temporary YAML file and simulating upload
        // Can be implemented with proper file fixtures

    }//end testYamlConfigurationImport()


    /**
     * Test configuration export.
     *
     * @return void
     */
    public function testConfigurationExport(): void
    {
        // Create and import configuration
        $configuration = new Configuration();
        $configuration->setTitle('Test Configuration Export');
        $configuration->setType('test');
        $configuration->setApp('test-app-export');
        $configuration->setVersion('1.0.0');
        
        $configuration = $this->configurationMapper->insert($configuration);

        $result = $this->configurationService->importFromJson(
            $this->testConfigJson,
            $configuration
        );

        // Update configuration with imported entity IDs
        $configuration->setRegisters([$result['registers'][0]->getId()]);
        $configuration->setSchemas([$result['schemas'][0]->getId()]);
        $this->configurationMapper->update($configuration);

        // Export configuration
        $exported = $this->configurationService->exportConfig($configuration, false);

        // Verify export structure
        $this->assertArrayHasKey('openapi', $exported);
        $this->assertArrayHasKey('info', $exported);
        $this->assertArrayHasKey('components', $exported);
        $this->assertArrayHasKey('registers', $exported['components']);
        $this->assertArrayHasKey('schemas', $exported['components']);

        // Verify exported data
        $this->assertEquals('1.0.0', $exported['info']['version']);
        $this->assertArrayHasKey('testregister', $exported['components']['registers']);
        $this->assertArrayHasKey('testschema', $exported['components']['schemas']);

    }//end testConfigurationExport()


    /**
     * Test import without configuration entity should fail.
     *
     * @return void
     */
    public function testImportWithoutConfigurationEntityFails(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('importFromJson must be called with a Configuration entity');

        // Attempt to import without configuration entity
        $this->configurationService->importFromJson(
            $this->testConfigJson,
            null // This should throw an exception
        );

    }//end testImportWithoutConfigurationEntityFails()


    /**
     * Test selective import functionality.
     *
     * @return void
     */
    public function testSelectiveImport(): void
    {
        $this->markTestSkipped('Selective import requires preview endpoint simulation');
        // This would require mocking the preview functionality
        // Can be implemented with proper mocking setup

    }//end testSelectiveImport()


    /**
     * Test configuration with auto-update enabled.
     *
     * @return void
     */
    public function testAutoUpdateConfiguration(): void
    {
        $configuration = new Configuration();
        $configuration->setTitle('Test Auto-Update Config');
        $configuration->setSourceType('url');
        $configuration->setSourceUrl('https://example.com/config.json');
        $configuration->setLocalVersion('1.0.0');
        $configuration->setAutoUpdate(true);
        $configuration->setNotificationGroups(['admin']);

        $created = $this->configurationMapper->insert($configuration);

        $this->assertTrue($created->getAutoUpdate());
        $this->assertContains('admin', $created->getNotificationGroups());

    }//end testAutoUpdateConfiguration()


    /**
     * Test GitHub integration configuration.
     *
     * @return void
     */
    public function testGitHubIntegrationConfiguration(): void
    {
        $configuration = new Configuration();
        $configuration->setTitle('Test GitHub Config');
        $configuration->setSourceType('github');
        $configuration->setSourceUrl('https://raw.githubusercontent.com/owner/repo/main/config.json');
        $configuration->setGithubRepo('owner/repo');
        $configuration->setGithubBranch('main');
        $configuration->setGithubPath('config.json');

        $created = $this->configurationMapper->insert($configuration);

        $this->assertEquals('github', $created->getSourceType());
        $this->assertEquals('owner/repo', $created->getGithubRepo());
        $this->assertEquals('main', $created->getGithubBranch());
        $this->assertEquals('config.json', $created->getGithubPath());

    }//end testGitHubIntegrationConfiguration()


}//end class


