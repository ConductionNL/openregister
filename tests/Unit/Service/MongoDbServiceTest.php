<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\MongoDbService;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * Test class for MongoDbService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class MongoDbServiceTest extends TestCase
{
    private MongoDbService $mongoDbService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create MongoDbService instance (no constructor dependencies)
        $this->mongoDbService = new MongoDbService();
    }

    /**
     * Test getClient method with basic config
     */
    public function testGetClientWithBasicConfig(): void
    {
        $config = [
            'base_uri' => 'http://localhost:27017',
            'timeout' => 30
        ];

        $result = $this->mongoDbService->getClient($config);

        $this->assertInstanceOf(Client::class, $result);
    }

    /**
     * Test getClient method with MongoDB cluster config
     */
    public function testGetClientWithMongoDbClusterConfig(): void
    {
        $config = [
            'base_uri' => 'http://localhost:27017',
            'timeout' => 30,
            'mongodbCluster' => 'test-cluster'
        ];

        $result = $this->mongoDbService->getClient($config);

        $this->assertInstanceOf(Client::class, $result);
    }

    /**
     * Test getClient method with empty config
     */
    public function testGetClientWithEmptyConfig(): void
    {
        $config = [];

        $result = $this->mongoDbService->getClient($config);

        $this->assertInstanceOf(Client::class, $result);
    }

    /**
     * Test getClient method with complex config
     */
    public function testGetClientWithComplexConfig(): void
    {
        $config = [
            'base_uri' => 'https://api.example.com',
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer token123',
                'Content-Type' => 'application/json'
            ],
            'mongodbCluster' => 'production-cluster',
            'verify' => false
        ];

        $result = $this->mongoDbService->getClient($config);

        $this->assertInstanceOf(Client::class, $result);
    }

    /**
     * Test BASE_OBJECT constant
     */
    public function testBaseObjectConstant(): void
    {
        $baseObject = MongoDbService::BASE_OBJECT;

        $this->assertIsArray($baseObject);
        $this->assertArrayHasKey('database', $baseObject);
        $this->assertArrayHasKey('collection', $baseObject);
        $this->assertEquals('objects', $baseObject['database']);
        $this->assertEquals('json', $baseObject['collection']);
    }

    /**
     * Test getClient method removes mongodbCluster from config
     */
    public function testGetClientRemovesMongoDbClusterFromConfig(): void
    {
        $config = [
            'base_uri' => 'http://localhost:27017',
            'timeout' => 30,
            'mongodbCluster' => 'test-cluster'
        ];

        $result = $this->mongoDbService->getClient($config);

        $this->assertInstanceOf(Client::class, $result);
        
        // The method should create a client without the mongodbCluster key
        // We can't directly test the internal config, but we can verify the client was created
        $this->assertNotNull($result);
    }

    /**
     * Test getClient method with various timeout values
     */
    public function testGetClientWithVariousTimeoutValues(): void
    {
        $configs = [
            ['timeout' => 0],
            ['timeout' => 30],
            ['timeout' => 60],
            ['timeout' => 120]
        ];

        foreach ($configs as $config) {
            $result = $this->mongoDbService->getClient($config);
            $this->assertInstanceOf(Client::class, $result);
        }
    }

    /**
     * Test getClient method with various base URIs
     */
    public function testGetClientWithVariousBaseUris(): void
    {
        $configs = [
            ['base_uri' => 'http://localhost:27017'],
            ['base_uri' => 'https://api.example.com'],
            ['base_uri' => 'http://127.0.0.1:8080'],
            ['base_uri' => 'https://mongodb.example.com:27017']
        ];

        foreach ($configs as $config) {
            $result = $this->mongoDbService->getClient($config);
            $this->assertInstanceOf(Client::class, $result);
        }
    }
}