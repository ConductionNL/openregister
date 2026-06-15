<?php

declare(strict_types=1);

/**
 * McpResourcesService Unit Tests
 *
 * Covers the MCP "Resource Definitions" requirement of the mcp-discovery
 * spec: static + dynamic resource listing, deleted-schema skipping,
 * URI template enumeration, URI parsing/reading, and invalid-scheme
 * rejection. These scenarios were previously only exercised indirectly
 * through the controller mock; this suite tests the service directly.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Mcp
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Mcp;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for McpResourcesService.
 *
 * @spec openspec/specs/mcp-discovery/spec.md
 */
class McpResourcesServiceTest extends TestCase
{

    /** @var RegisterMapper&MockObject */
    private $registerMapper;

    /** @var SchemaMapper&MockObject */
    private $schemaMapper;

    /** @var ObjectService&MockObject */
    private $objectService;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var McpResourcesService */
    private McpResourcesService $service;


    protected function setUp(): void
    {
        parent::setUp();

        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);
        $this->objectService   = $this->createMock(ObjectService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new McpResourcesService(
            $this->registerMapper,
            $this->schemaMapper,
            $this->objectService,
            $this->logger
        );

    }//end setUp()


    /**
     * Build a Register stub with id, title, and schema id list.
     *
     * @param int        $id      Register id
     * @param string     $title   Register title
     * @param array<int> $schemas Schema id list
     *
     * @return Register&MockObject
     */
    private function makeRegister(int $id, string $title, array $schemas): Register
    {
        // getId() / getTitle() are magic Entity accessors (resolved via
        // __call), so they must be declared on the mock via addMethods()
        // before they can be configured. getSchemas() is a real declared
        // method and is mocked by the regular onlyMethods set.
        $register = $this->getMockBuilder(Register::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSchemas', 'jsonSerialize'])
            ->addMethods(['getId', 'getTitle'])
            ->getMock();
        $register->method('getId')->willReturn($id);
        $register->method('getTitle')->willReturn($title);
        $register->method('getSchemas')->willReturn($schemas);
        $register->method('jsonSerialize')->willReturn(['id' => $id, 'title' => $title]);
        return $register;

    }//end makeRegister()


    /**
     * Build a Schema stub with id and title.
     *
     * @param int    $id    Schema id
     * @param string $title Schema title
     *
     * @return Schema&MockObject
     */
    private function makeSchema(int $id, string $title): Schema
    {
        // getId() / getTitle() are magic Entity accessors — declare them
        // via addMethods() so the mock builder can configure them.
        $schema = $this->getMockBuilder(Schema::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize'])
            ->addMethods(['getId', 'getTitle'])
            ->getMock();
        $schema->method('getId')->willReturn($id);
        $schema->method('getTitle')->willReturn($title);
        $schema->method('jsonSerialize')->willReturn(['id' => $id, 'title' => $title]);
        return $schema;

    }//end makeSchema()


    /**
     * Static register/schema resources are always present.
     *
     * @return void
     */
    public function testListResourcesIncludesStaticEntries(): void
    {
        $this->registerMapper->method('findAll')->willReturn([]);

        $result = $this->service->listResources();

        $this->assertArrayHasKey('resources', $result);
        $uris = array_column($result['resources'], 'uri');
        $this->assertContains('openregister://registers', $uris);
        $this->assertContains('openregister://schemas', $uris);

    }//end testListResourcesIncludesStaticEntries()


    /**
     * Each register+schema pair produces a dynamic object resource entry
     * with the "{registerTitle} — {schemaTitle}" name and JSON mime type.
     *
     * @return void
     */
    public function testListResourcesIncludesDynamicPair(): void
    {
        $register = $this->makeRegister(id: 1, title: 'People', schemas: [2]);
        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('find')->with(2)->willReturn($this->makeSchema(id: 2, title: 'Person'));

        $result = $this->service->listResources();

        $entry = null;
        foreach ($result['resources'] as $r) {
            if ($r['uri'] === 'openregister://objects/1/2') {
                $entry = $r;
            }
        }

        $this->assertNotNull($entry, 'Dynamic object resource should be present');
        $this->assertSame('People — Person', $entry['name']);
        $this->assertSame('application/json', $entry['mimeType']);

    }//end testListResourcesIncludesDynamicPair()


    /**
     * A register referencing a deleted schema must not break the listing;
     * the missing schema is skipped (DoesNotExistException caught).
     *
     * @return void
     */
    public function testListResourcesSkipsDeletedSchema(): void
    {
        $register = $this->makeRegister(id: 1, title: 'People', schemas: [99]);
        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('find')->with(99)
            ->willThrowException(new DoesNotExistException('gone'));

        $result = $this->service->listResources();

        // Only the two static entries survive; the dynamic pair is skipped.
        $uris = array_column($result['resources'], 'uri');
        $this->assertNotContains('openregister://objects/1/99', $uris);
        $this->assertContains('openregister://registers', $uris);

    }//end testListResourcesSkipsDeletedSchema()


    /**
     * Templates list declares the three single-entity access patterns.
     *
     * @return void
     */
    public function testListTemplatesDeclaresAllPatterns(): void
    {
        $result = $this->service->listTemplates();

        $this->assertArrayHasKey('resourceTemplates', $result);
        $templates = array_column($result['resourceTemplates'], 'uriTemplate');
        $this->assertContains('openregister://registers/{id}', $templates);
        $this->assertContains('openregister://schemas/{id}', $templates);
        $this->assertContains('openregister://objects/{register}/{schema}/{id}', $templates);

    }//end testListTemplatesDeclaresAllPatterns()


    /**
     * Reading an objects URI parses the register/schema, scopes the
     * ObjectService, and returns a contents array with JSON text.
     *
     * @return void
     */
    public function testReadResourceObjectsListScopesService(): void
    {
        $this->objectService->expects($this->once())->method('setRegister')->with(1);
        $this->objectService->expects($this->once())->method('setSchema')->with(2);
        $this->objectService->method('findAll')->willReturn([]);

        $result = $this->service->readResource(uri: 'openregister://objects/1/2');

        $this->assertArrayHasKey('contents', $result);
        $this->assertSame('openregister://objects/1/2', $result['contents'][0]['uri']);
        $this->assertSame('application/json', $result['contents'][0]['mimeType']);
        $this->assertIsString($result['contents'][0]['text']);

    }//end testReadResourceObjectsListScopesService()


    /**
     * Reading a single register URI fetches that register.
     *
     * @return void
     */
    public function testReadResourceSingleRegister(): void
    {
        $this->registerMapper->method('find')->with(5)
            ->willReturn($this->makeRegister(id: 5, title: 'Cases', schemas: []));

        $result = $this->service->readResource(uri: 'openregister://registers/5');

        $decoded = json_decode($result['contents'][0]['text'], true);
        $this->assertSame(5, $decoded['id']);
        $this->assertSame('Cases', $decoded['title']);

    }//end testReadResourceSingleRegister()


    /**
     * A URI that is not under the openregister:// scheme is rejected.
     *
     * @return void
     */
    public function testReadResourceRejectsForeignScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URI scheme, expected openregister://');

        $this->service->readResource(uri: 'http://example.com/foo');

    }//end testReadResourceRejectsForeignScheme()


    /**
     * An objects URI missing the schema segment is rejected.
     *
     * @return void
     */
    public function testReadResourceObjectsRequiresRegisterAndSchema(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->readResource(uri: 'openregister://objects/1');

    }//end testReadResourceObjectsRequiresRegisterAndSchema()
}//end class
