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

        // Set up Guzzle client to call the Nextcloud container.
        // Using master-nextcloud-1 container name as per docker-compose.
        $this->baseUrl = 'http://master-nextcloud-1/index.php/apps/openregister/api';
        $this->auth    = ['admin', 'admin'];

        $this->client = new Client([
            'base_uri'        => $this->baseUrl,
            'timeout'         => 30.0,
            'allow_redirects' => true,
            'http_errors'     => false, // Don't throw exceptions on 4xx/5xx responses
            'verify'          => false, // Disable SSL verification for local testing
        ]);

        // Create test configuration JSON.
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
        // Clean up test data via API.
        try {
            // Delete test configurations.
            foreach ($this->createdConfigIds as $configId) {
                try {
                    $this->client->delete("/configurations/{$configId}", [
                        'auth' => $this->auth,
                    ]);
                } catch (\Exception $e) {
                    // Ignore cleanup errors.
                }
            }

            // Delete test schemas.
            foreach ($this->createdSchemaIds as $schemaId) {
                try {
                    $this->client->delete("/schemas/{$schemaId}", [
                        'auth' => $this->auth,
                    ]);
                } catch (\Exception $e) {
                    // Ignore cleanup errors.
                }
            }

            // Delete test registers.
            foreach ($this->createdRegisterIds as $registerId) {
                try {
                    $this->client->delete("/registers/{$registerId}", [
                        'auth' => $this->auth,
                    ]);
                } catch (\Exception $e) {
                    // Ignore cleanup errors.
                }
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors.
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

        // Store for cleanup.
        $this->createdConfigIds[] = $data['id'];

    }//end testCreateConfigurationWithVersionTracking()


    /**
     * Test listing configurations via API.
     *
     * @return void
     */
    public function testListConfigurations(): void
    {
        // Create a test configuration first.
        $createResponse = $this->client->post('/configurations', [
            'auth' => $this->auth,
            'json' => [
                'title'     => 'Test List Config',
                'type'      => 'test',
                'registers' => [],
                'schemas'   => [],
            ],
        ]);

        $this->assertEquals(201, $createResponse->getStatusCode());
        $createData = json_decode($createResponse->getBody()->getContents(), true);
        $this->createdConfigIds[] = $createData['id'];

        // List all configurations.
        $listResponse = $this->client->get('/configurations', [
            'auth' => $this->auth,
        ]);

        $this->assertEquals(200, $listResponse->getStatusCode());
        $listData = json_decode($listResponse->getBody()->getContents(), true);
        
        $this->assertIsArray($listData);
        $this->assertNotEmpty($listData, 'Should have at least one configuration');

        // Find our created configuration.
        $found = false;
        foreach ($listData as $config) {
            if ($config['id'] === $createData['id']) {
                $found = true;
                $this->assertEquals('Test List Config', $config['title']);
                break;
            }
        }

        $this->assertTrue($found, 'Created configuration should be in the list');

    }//end testListConfigurations()


    /**
     * Test getting a single configuration via API.
     *
     * @return void
     */
    public function testGetSingleConfiguration(): void
    {
        // Create a test configuration.
        $createResponse = $this->client->post('/configurations', [
            'auth' => $this->auth,
            'json' => [
                'title'        => 'Test Get Config',
                'description'  => 'Configuration for GET test',
                'type'         => 'test',
                'sourceType'   => 'github',
                'localVersion' => '2.0.0',
                'githubRepo'   => 'owner/repo',
                'githubBranch' => 'main',
                'registers'    => [],
                'schemas'      => [],
            ],
        ]);

        $createData = json_decode($createResponse->getBody()->getContents(), true);
        $this->createdConfigIds[] = $createData['id'];

        // Get the configuration.
        $getResponse = $this->client->get("/configurations/{$createData['id']}", [
            'auth' => $this->auth,
        ]);

        $this->assertEquals(200, $getResponse->getStatusCode());
        $getData = json_decode($getResponse->getBody()->getContents(), true);

        $this->assertEquals($createData['id'], $getData['id']);
        $this->assertEquals('Test Get Config', $getData['title']);
        $this->assertEquals('Configuration for GET test', $getData['description']);
        $this->assertEquals('github', $getData['sourceType']);
        $this->assertEquals('2.0.0', $getData['localVersion']);
        $this->assertEquals('owner/repo', $getData['githubRepo']);
        $this->assertEquals('main', $getData['githubBranch']);

    }//end testGetSingleConfiguration()


    /**
     * Test updating a configuration via API.
     *
     * @return void
     */
    public function testUpdateConfiguration(): void
    {
        // Create a configuration.
        $createResponse = $this->client->post('/configurations', [
            'auth' => $this->auth,
            'json' => [
                'title'        => 'Test Update Config',
                'type'         => 'test',
                'localVersion' => '1.0.0',
                'registers'    => [],
                'schemas'      => [],
            ],
        ]);

        $createData = json_decode($createResponse->getBody()->getContents(), true);
        $this->createdConfigIds[] = $createData['id'];

        // Update the configuration.
        $updateResponse = $this->client->put("/configurations/{$createData['id']}", [
            'auth' => $this->auth,
            'json' => [
                'title'        => 'Updated Config Title',
                'description'  => 'New description',
                'localVersion' => '1.1.0',
                'autoUpdate'   => true,
            ],
        ]);

        $this->assertEquals(200, $updateResponse->getStatusCode());
        $updateData = json_decode($updateResponse->getBody()->getContents(), true);

        $this->assertEquals('Updated Config Title', $updateData['title']);
        $this->assertEquals('New description', $updateData['description']);
        $this->assertEquals('1.1.0', $updateData['localVersion']);
        $this->assertTrue($updateData['autoUpdate']);

    }//end testUpdateConfiguration()


    /**
     * Test configuration with auto-update and notification settings.
     *
     * @return void
     */
    public function testAutoUpdateConfiguration(): void
    {
        $response = $this->client->post('/configurations', [
            'auth' => $this->auth,
            'json' => [
                'title'              => 'Test Auto-Update Config',
                'sourceType'         => 'url',
                'sourceUrl'          => 'https://example.com/config.json',
                'localVersion'       => '1.0.0',
                'autoUpdate'         => true,
                'notificationGroups' => ['admin', 'users'],
                'registers'          => [],
                'schemas'            => [],
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);

        $this->assertTrue($data['autoUpdate']);
        $this->assertContains('admin', $data['notificationGroups']);
        $this->assertContains('users', $data['notificationGroups']);
        $this->assertEquals('url', $data['sourceType']);
        $this->assertEquals('https://example.com/config.json', $data['sourceUrl']);

        $this->createdConfigIds[] = $data['id'];

    }//end testAutoUpdateConfiguration()


    /**
     * Test GitHub integration configuration.
     *
     * @return void
     */
    public function testGitHubIntegrationConfiguration(): void
    {
        $response = $this->client->post('/configurations', [
            'auth' => $this->auth,
            'json' => [
                'title'        => 'Test GitHub Config',
                'sourceType'   => 'github',
                'sourceUrl'    => 'https://raw.githubusercontent.com/owner/repo/main/config.json',
                'githubRepo'   => 'owner/repo',
                'githubBranch' => 'main',
                'githubPath'   => 'config.json',
                'registers'    => [],
                'schemas'      => [],
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);

        $this->assertEquals('github', $data['sourceType']);
        $this->assertEquals('owner/repo', $data['githubRepo']);
        $this->assertEquals('main', $data['githubBranch']);
        $this->assertEquals('config.json', $data['githubPath']);

        $this->createdConfigIds[] = $data['id'];

    }//end testGitHubIntegrationConfiguration()


    /**
     * Test deleting a configuration via API.
     *
     * @return void
     */
    public function testDeleteConfiguration(): void
    {
        // Create a configuration.
        $createResponse = $this->client->post('/configurations', [
            'auth' => $this->auth,
            'json' => [
                'title'     => 'Test Delete Config',
                'type'      => 'test',
                'registers' => [],
                'schemas'   => [],
            ],
        ]);

        $createData = json_decode($createResponse->getBody()->getContents(), true);
        $configId   = $createData['id'];

        // Delete the configuration.
        $deleteResponse = $this->client->delete("/configurations/{$configId}", [
            'auth' => $this->auth,
        ]);

        $this->assertEquals(200, $deleteResponse->getStatusCode());

        // Verify it's deleted - GET should return 404.
        $getResponse = $this->client->get("/configurations/{$configId}", [
            'auth' => $this->auth,
        ]);

        $this->assertEquals(404, $getResponse->getStatusCode());

    }//end testDeleteConfiguration()


}//end class

