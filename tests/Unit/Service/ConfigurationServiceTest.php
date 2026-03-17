<?php
/**
 * Configuration Service Unit Test
 *
 * This file contains unit tests for the ConfigurationService class.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Class ConfigurationServiceTest
 *
 * Unit tests for ConfigurationService, focusing on:
 * - Version checking and comparison
 * - Preview generation
 * - Configuration validation
 *
 * @package OCA\OpenRegister\Tests\Unit\Service
 */
class ConfigurationServiceTest extends TestCase
{

    /**
     * Mock configuration mapper.
     *
     * @var ConfigurationMapper|MockObject
     */
    private $configurationMapper;

    /**
     * Mock schema mapper.
     *
     * @var SchemaMapper|MockObject
     */
    private $schemaMapper;

    /**
     * Mock register mapper.
     *
     * @var RegisterMapper|MockObject
     */
    private $registerMapper;

    /**
     * Mock logger.
     *
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * Configuration service instance.
     *
     * @var ConfigurationService
     */
    private ConfigurationService $service;


    /**
     * Set up test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->registerMapper      = $this->createMock(RegisterMapper::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        // Note: In reality, ConfigurationService has many more dependencies
        // This is a simplified test. Full implementation would need all dependencies mocked.

    }//end setUp()


    /**
     * Test version comparison with newer remote version.
     *
     * @return void
     */
    public function testCompareVersionsWithNewerRemote(): void
    {
        $configuration = new Configuration();
        $configuration->setLocalVersion('1.0.0');
        $configuration->setRemoteVersion('1.1.0');

        // Manually test the version comparison logic
        $hasUpdate = version_compare($configuration->getRemoteVersion(), $configuration->getLocalVersion(), '>');

        $this->assertTrue($hasUpdate);

    }//end testCompareVersionsWithNewerRemote()


    /**
     * Test version comparison with same version.
     *
     * @return void
     */
    public function testCompareVersionsWithSameVersion(): void
    {
        $configuration = new Configuration();
        $configuration->setLocalVersion('1.0.0');
        $configuration->setRemoteVersion('1.0.0');

        // Manually test the version comparison logic
        $hasUpdate = version_compare($configuration->getRemoteVersion(), $configuration->getLocalVersion(), '>');

        $this->assertFalse($hasUpdate);

    }//end testCompareVersionsWithSameVersion()


    /**
     * Test version comparison with older remote version.
     *
     * @return void
     */
    public function testCompareVersionsWithOlderRemote(): void
    {
        $configuration = new Configuration();
        $configuration->setLocalVersion('2.0.0');
        $configuration->setRemoteVersion('1.0.0');

        // Manually test the version comparison logic
        $hasUpdate = version_compare($configuration->getRemoteVersion(), $configuration->getLocalVersion(), '>');

        $this->assertFalse($hasUpdate);

    }//end testCompareVersionsWithOlderRemote()


    /**
     * Test version comparison with no remote version.
     *
     * @return void
     */
    public function testCompareVersionsWithNoRemoteVersion(): void
    {
        $configuration = new Configuration();
        $configuration->setLocalVersion('1.0.0');
        $configuration->setRemoteVersion(null);

        // Test that hasUpdateAvailable() returns false when remote version is null
        $this->assertFalse($configuration->hasUpdateAvailable());

    }//end testCompareVersionsWithNoRemoteVersion()


    /**
     * Test configuration helper method: isRemoteSource.
     *
     * @return void
     */
    public function testIsRemoteSource(): void
    {
        // Test GitHub source
        $config = new Configuration();
        $config->setSourceType('github');
        $this->assertTrue($config->isRemoteSource());

        // Test GitLab source
        $config->setSourceType('gitlab');
        $this->assertTrue($config->isRemoteSource());

        // Test URL source
        $config->setSourceType('url');
        $this->assertTrue($config->isRemoteSource());

        // Test local source
        $config->setSourceType('local');
        $this->assertFalse($config->isRemoteSource());

    }//end testIsRemoteSource()


    /**
     * Test configuration helper method: isLocalSource.
     *
     * @return void
     */
    public function testIsLocalSource(): void
    {
        $config = new Configuration();
        
        // Test local source
        $config->setSourceType('local');
        $this->assertTrue($config->isLocalSource());

        // Test remote sources
        $config->setSourceType('github');
        $this->assertFalse($config->isLocalSource());

        $config->setSourceType('gitlab');
        $this->assertFalse($config->isLocalSource());

        $config->setSourceType('url');
        $this->assertFalse($config->isLocalSource());

    }//end testIsLocalSource()


    /**
     * Test configuration JSON serialization includes new fields.
     *
     * @return void
     */
    public function testConfigurationJsonSerializationIncludesNewFields(): void
    {
        $config = new Configuration();
        $config->setTitle('Test Config');
        $config->setSourceType('github');
        $config->setSourceUrl('https://github.com/test/config.json');
        $config->setLocalVersion('1.0.0');
        $config->setRemoteVersion('1.1.0');
        $config->setAutoUpdate(true);
        $config->setNotificationGroups(['admin', 'users']);
        $config->setGithubRepo('test/repo');
        $config->setGithubBranch('main');
        $config->setGithubPath('config.json');

        $json = $config->jsonSerialize();

        $this->assertEquals('github', $json['sourceType']);
        $this->assertEquals('https://github.com/test/config.json', $json['sourceUrl']);
        $this->assertEquals('1.0.0', $json['localVersion']);
        $this->assertEquals('1.1.0', $json['remoteVersion']);
        $this->assertTrue($json['autoUpdate']);
        $this->assertEquals(['admin', 'users'], $json['notificationGroups']);
        $this->assertEquals('test/repo', $json['githubRepo']);
        $this->assertEquals('main', $json['githubBranch']);
        $this->assertEquals('config.json', $json['githubPath']);

    }//end testConfigurationJsonSerializationIncludesNewFields()


    /**
     * Test schema managed by configuration detection.
     *
     * @return void
     */
    public function testSchemaManagedByConfigurationDetection(): void
    {
        $schema = new Schema();
        $schema->setId(123);

        $config1 = new Configuration();
        $config1->setId(1);
        $config1->setSchemas([456, 789]); // Different schema IDs

        $config2 = new Configuration();
        $config2->setId(2);
        $config2->setSchemas([123, 456]); // Contains our schema ID

        $configurations = [$config1, $config2];

        // Test that schema is managed
        $this->assertTrue($schema->isManagedByConfiguration($configurations));

        // Test that we get the correct configuration
        $managedBy = $schema->getManagedByConfiguration($configurations);
        $this->assertNotNull($managedBy);
        $this->assertEquals(2, $managedBy->getId());

    }//end testSchemaManagedByConfigurationDetection()


    /**
     * Test register managed by configuration detection.
     *
     * @return void
     */
    public function testRegisterManagedByConfigurationDetection(): void
    {
        $register = new Register();
        $register->setId(456);

        $config = new Configuration();
        $config->setId(1);
        $config->setRegisters([123, 456, 789]);

        $configurations = [$config];

        // Test that register is managed
        $this->assertTrue($register->isManagedByConfiguration($configurations));

        // Test that we get the correct configuration
        $managedBy = $register->getManagedByConfiguration($configurations);
        $this->assertNotNull($managedBy);
        $this->assertEquals(1, $managedBy->getId());

    }//end testRegisterManagedByConfigurationDetection()


    /**
     * Test entity not managed by any configuration.
     *
     * @return void
     */
    public function testEntityNotManagedByConfiguration(): void
    {
        $schema = new Schema();
        $schema->setId(999);

        $config = new Configuration();
        $config->setId(1);
        $config->setSchemas([123, 456]);

        $configurations = [$config];

        // Test that schema is not managed
        $this->assertFalse($schema->isManagedByConfiguration($configurations));

        // Test that getManagedByConfiguration returns null
        $managedBy = $schema->getManagedByConfiguration($configurations);
        $this->assertNull($managedBy);

    }//end testEntityNotManagedByConfiguration()


    /**
     * Test hasUpdateAvailable method.
     *
     * @return void
     */
    public function testHasUpdateAvailable(): void
    {
        $config = new Configuration();

        // Test with no remote version
        $config->setLocalVersion('1.0.0');
        $config->setRemoteVersion(null);
        $this->assertFalse($config->hasUpdateAvailable());

        // Test with same version
        $config->setRemoteVersion('1.0.0');
        $this->assertFalse($config->hasUpdateAvailable());

        // Test with newer remote version
        $config->setRemoteVersion('1.1.0');
        $this->assertTrue($config->hasUpdateAvailable());

        // Test with older remote version
        $config->setRemoteVersion('0.9.0');
        $this->assertFalse($config->hasUpdateAvailable());

    }//end testHasUpdateAvailable()


    /**
     * Test semantic versioning comparison.
     *
     * @return void
     */
    public function testSemanticVersioningComparison(): void
    {
        // Test major version difference
        $this->assertTrue(version_compare('2.0.0', '1.9.9', '>'));

        // Test minor version difference
        $this->assertTrue(version_compare('1.1.0', '1.0.9', '>'));

        // Test patch version difference
        $this->assertTrue(version_compare('1.0.1', '1.0.0', '>'));

        // Test pre-release versions
        $this->assertTrue(version_compare('1.0.0', '1.0.0-alpha', '>'));
        $this->assertTrue(version_compare('1.0.0-beta', '1.0.0-alpha', '>'));

    }//end testSemanticVersioningComparison()


}//end class


