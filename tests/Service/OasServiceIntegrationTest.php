<?php

/**
 * Integration tests for OasService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OasService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for OasService
 *
 * Tests OpenAPI Specification generation for registers and schemas.
 */
class OasServiceIntegrationTest extends TestCase
{
    /**
     * The OAS service instance
     *
     * @var OasService
     */
    private OasService $service;

    /**
     * Register mapper
     *
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * Schema mapper
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Test register for cleanup
     *
     * @var Register|null
     */
    private ?Register $testRegister = null;

    /**
     * Test schema for cleanup
     *
     * @var Schema|null
     */
    private ?Schema $testSchema = null;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(OasService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
    }

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->testSchema !== null) {
            try {
                $this->schemaMapper->delete($this->testSchema);
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->testRegister !== null) {
            try {
                $this->registerMapper->delete($this->testRegister);
            } catch (\Exception $e) {
                // Ignore
            }
        }

        parent::tearDown();
    }

    /**
     * Test createOas generates valid OAS for all registers
     *
     * @return void
     */
    public function testCreateOasAll(): void
    {
        $result = $this->service->createOas();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('openapi', $result);
        $this->assertArrayHasKey('info', $result);
        $this->assertArrayHasKey('servers', $result);
        $this->assertArrayHasKey('paths', $result);
        $this->assertArrayHasKey('components', $result);
    }

    /**
     * Test createOas has proper OpenAPI version
     *
     * @return void
     */
    public function testCreateOasVersion(): void
    {
        $result = $this->service->createOas();

        $this->assertStringStartsWith('3.', $result['openapi']);
    }

    /**
     * Test createOas has info section
     *
     * @return void
     */
    public function testCreateOasInfoSection(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('info', $result);
        $info = $result['info'];
        $this->assertIsArray($info);
        $this->assertArrayHasKey('title', $info);
        $this->assertArrayHasKey('version', $info);
    }

    /**
     * Test createOas has servers section
     *
     * @return void
     */
    public function testCreateOasServersSection(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('servers', $result);
        $this->assertIsArray($result['servers']);
        $this->assertNotEmpty($result['servers']);
        $this->assertArrayHasKey('url', $result['servers'][0]);
    }

    /**
     * Test createOas has components with schemas
     *
     * @return void
     */
    public function testCreateOasComponents(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('components', $result);
        $this->assertIsArray($result['components']);
        $this->assertArrayHasKey('schemas', $result['components']);
    }

    /**
     * Test createOas for specific register
     *
     * @return void
     */
    public function testCreateOasForSpecificRegister(): void
    {
        // Create a test register with a schema
        $schema = new Schema();
        $schema->setTitle('phpunit-test-oas-' . uniqid());
        $schema->setDescription('Test schema for OAS generation');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-test-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
            'body'  => ['type' => 'string', 'title' => 'Body'],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $register = new Register();
        $register->setTitle('phpunit-test-oas-reg-' . uniqid());
        $register->setDescription('Test register for OAS generation');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-test-' . uniqid());
        $register->setSchemas([$this->testSchema->getId()]);
        $register->setVersion('1.0.0');
        $this->testRegister = $this->registerMapper->insert($register);

        $result = $this->service->createOas((string) $this->testRegister->getId());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('info', $result);
        $this->assertStringContainsString($this->testRegister->getTitle(), $result['info']['title']);
        $this->assertSame('1.0.0', $result['info']['version']);
    }

    /**
     * Test createOas includes tags
     *
     * @return void
     */
    public function testCreateOasIncludesTags(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('tags', $result);
        $this->assertIsArray($result['tags']);
    }

    /**
     * Test createOas includes security schemes
     *
     * @return void
     */
    public function testCreateOasSecuritySchemes(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('components', $result);
        $this->assertArrayHasKey('securitySchemes', $result['components']);
    }

    /**
     * Test createOas paths is array
     *
     * @return void
     */
    public function testCreateOasPaths(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('paths', $result);
        $this->assertIsArray($result['paths']);
    }
}
