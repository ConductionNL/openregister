<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use OCP\IURLGenerator;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Test class for OasService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class OasServiceTest extends TestCase
{
    private OasService $oasService;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private IURLGenerator $urlGenerator;
    private IConfig $config;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create OasService instance
        $this->oasService = new OasService(
            $this->registerMapper,
            $this->schemaMapper,
            $this->urlGenerator,
            $this->config,
            $this->logger
        );
    }

    /**
     * Test createOas method with no register ID
     */
    public function testCreateOasWithNoRegisterId(): void
    {
        $result = $this->oasService->createOas();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('openapi', $result);
        $this->assertArrayHasKey('info', $result);
        $this->assertArrayHasKey('paths', $result);
        $this->assertArrayHasKey('components', $result);
    }

    /**
     * Test createOas method with specific register ID
     */
    public function testCreateOasWithRegisterId(): void
    {
        $registerId = 'test-register';

        $result = $this->oasService->createOas($registerId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('openapi', $result);
        $this->assertArrayHasKey('info', $result);
        $this->assertArrayHasKey('paths', $result);
        $this->assertArrayHasKey('components', $result);
    }

    /**
     * Test createOas method returns valid OpenAPI structure
     */
    public function testCreateOasReturnsValidStructure(): void
    {
        $result = $this->oasService->createOas();

        // Check required OpenAPI fields
        $this->assertArrayHasKey('openapi', $result);
        $this->assertArrayHasKey('info', $result);
        $this->assertArrayHasKey('paths', $result);
        $this->assertArrayHasKey('components', $result);

        // Check info structure
        $this->assertArrayHasKey('title', $result['info']);
        $this->assertArrayHasKey('version', $result['info']);
        $this->assertArrayHasKey('description', $result['info']);

        // Check components structure
        $this->assertArrayHasKey('schemas', $result['components']);
    }

    /**
     * Test createOas method with null register ID
     */
    public function testCreateOasWithNullRegisterId(): void
    {
        $result = $this->oasService->createOas(null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('openapi', $result);
        $this->assertArrayHasKey('info', $result);
        $this->assertArrayHasKey('paths', $result);
        $this->assertArrayHasKey('components', $result);
    }

    /**
     * Test createOas method with empty string register ID
     */
    public function testCreateOasWithEmptyStringRegisterId(): void
    {
        $result = $this->oasService->createOas('');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('openapi', $result);
        $this->assertArrayHasKey('info', $result);
        $this->assertArrayHasKey('paths', $result);
        $this->assertArrayHasKey('components', $result);
    }

    /**
     * Test createOas method returns consistent results
     */
    public function testCreateOasReturnsConsistentResults(): void
    {
        $result1 = $this->oasService->createOas();
        $result2 = $this->oasService->createOas();

        $this->assertEquals($result1, $result2);
    }

    /**
     * Test createOas method with different register IDs returns different results
     */
    public function testCreateOasWithDifferentRegisterIds(): void
    {
        $result1 = $this->oasService->createOas('register1');
        $result2 = $this->oasService->createOas('register2');

        // Both should be valid arrays
        $this->assertIsArray($result1);
        $this->assertIsArray($result2);
        
        // Both should have required OpenAPI structure
        $this->assertArrayHasKey('openapi', $result1);
        $this->assertArrayHasKey('openapi', $result2);
        $this->assertArrayHasKey('info', $result1);
        $this->assertArrayHasKey('info', $result2);
    }
}
