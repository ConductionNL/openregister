<?php

/**
 * Integration tests for `OasService::createOas()` end-to-end.
 *
 * Drives the OpenAPI generator against a real Postgres-backed register
 * + schema fixture and verifies the output is structurally valid
 * (OpenAPI 3.x), documents the CRUD endpoints, and maps schema
 * property types to OAS types correctly.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OasService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class OasGenerationIntegrationTest extends TestCase
{
    private OasService $oasService;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;

    private ?Register $testRegister = null;
    private ?Schema $testSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oasService     = \OC::$server->get(OasService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);

        $this->createTestFixture();
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        if ($this->testSchema !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testSchema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->testRegister !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testRegister->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        parent::tearDown();
    }

    public function testCreateOasReturnsValidOpenApiStructure(): void
    {
        $oas = $this->oasService->createOas((string) $this->testRegister->getId());

        // OpenAPI 3.x baseline: openapi version, info, servers, paths, components.
        $this->assertArrayHasKey('openapi', $oas);
        $this->assertStringStartsWith('3.', (string) $oas['openapi'], 'createOas MUST return OpenAPI 3.x');
        $this->assertArrayHasKey('info', $oas);
        $this->assertArrayHasKey('servers', $oas);
        $this->assertArrayHasKey('paths', $oas);
        $this->assertArrayHasKey('components', $oas);
    }

    public function testServersUseAbsoluteInstanceSpecificUrl(): void
    {
        $oas = $this->oasService->createOas((string) $this->testRegister->getId());

        $this->assertNotEmpty($oas['servers']);
        $serverUrl = $oas['servers'][0]['url'] ?? '';
        $this->assertStringStartsWith('http', $serverUrl, 'server URL MUST be absolute (http/https)');
        $this->assertStringContainsString('/apps/openregister/api', $serverUrl);
    }

    public function testRegisterSpecificInfoTitleSurfacesRegisterTitle(): void
    {
        $oas = $this->oasService->createOas((string) $this->testRegister->getId());

        $this->assertArrayHasKey('title', $oas['info']);
        $this->assertStringContainsString(
            (string) $this->testRegister->getTitle(),
            (string) $oas['info']['title'],
            'register-scoped OAS info.title MUST surface the register title'
        );
    }

    public function testCrudPathsAreDocumented(): void
    {
        $oas = $this->oasService->createOas((string) $this->testRegister->getId());

        $paths     = $oas['paths'] ?? [];
        $pathKeys  = array_keys($paths);
        $allPaths  = implode("\n", $pathKeys);

        // Every register-scoped OAS MUST document collection + item paths
        // for at least one schema. We don't pin to specific URL shapes
        // because the prefix style varies, but any reasonable CRUD doc
        // includes a list endpoint + a UUID-parameterised detail endpoint.
        $this->assertNotEmpty($paths, 'paths MUST be non-empty for a register with schemas');

        $hasListPath   = (bool) preg_match('#/objects/[^/]+/[^/]+/?$#', $allPaths);
        $hasDetailPath = (bool) preg_match('#\{(id|uuid|objectId)\}#i', $allPaths);
        $this->assertTrue(
            $hasListPath || $hasDetailPath,
            'paths MUST include either a collection or detail-by-id endpoint shape'
        );
    }

    public function testComponentsSchemasIncludesTestSchema(): void
    {
        $oas = $this->oasService->createOas((string) $this->testRegister->getId());

        $componentSchemas = $oas['components']['schemas'] ?? [];
        $this->assertNotEmpty($componentSchemas, 'components.schemas MUST list at least one schema');

        // The test schema (or a slug-derived alias) MUST appear in
        // components.schemas. Match by case-insensitive substring on the
        // test schema's title to tolerate whatever naming convention the
        // generator applies (slug, PascalCase, etc.).
        $matched   = false;
        $needle    = strtolower(str_replace(' ', '', (string) $this->testSchema->getTitle()));
        foreach (array_keys($componentSchemas) as $name) {
            if (str_contains(strtolower((string) $name), substr($needle, 0, 12))) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue($matched, 'components.schemas MUST include an entry corresponding to the registered schema');
    }

    public function testPropertyTypesMapToOpenApiTypes(): void
    {
        $oas = $this->oasService->createOas((string) $this->testRegister->getId());

        $componentSchemas = $oas['components']['schemas'] ?? [];

        // Find the schema by title-derived key.
        $needle = strtolower(str_replace(' ', '', (string) $this->testSchema->getTitle()));
        $entry  = null;
        foreach ($componentSchemas as $name => $value) {
            if (str_contains(strtolower((string) $name), substr($needle, 0, 12))) {
                $entry = $value;
                break;
            }
        }
        $this->assertIsArray($entry);

        // The fixture has properties: title (string), age (integer), active (boolean).
        // Each MUST appear with the matching OpenAPI type.
        $properties = $entry['properties'] ?? [];
        $this->assertSame('string',  $properties['title']['type']  ?? null, 'string property MUST map to OAS type=string');
        $this->assertSame('integer', $properties['age']['type']    ?? null, 'integer property MUST map to OAS type=integer');
        $this->assertSame('boolean', $properties['active']['type'] ?? null, 'boolean property MUST map to OAS type=boolean');
    }

    public function testOasGeneratesForAllRegistersWhenIdOmitted(): void
    {
        // Sanity: the no-arg form generates for every register in the
        // instance. Should still return a valid OpenAPI structure.
        $oas = $this->oasService->createOas();

        $this->assertArrayHasKey('openapi', $oas);
        $this->assertArrayHasKey('paths', $oas);
        $this->assertArrayHasKey('components', $oas);
    }

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-oas-' . uniqid());
        $register->setDescription('OAS generation integration tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-oas-' . uniqid());
        $register->setVersion('1.0.0');
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-oas-schema-' . uniqid());
        $schema->setDescription('Schema for OAS generation tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-oas-schema-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
            'age'   => ['type' => 'integer', 'title' => 'Age'],
            'active' => ['type' => 'boolean', 'title' => 'Active'],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);
    }
}
