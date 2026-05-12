<?php

/**
 * SchemasController DELETE safety regression tests.
 *
 * Spec REQ (runtime-schema-api):
 *   "Runtime schema deletion is guarded by object count"
 *
 * Three scenarios are spec-mandated and covered here:
 *  - Delete a schema with N > 0 objects without ?force   → HTTP 409
 *  - Delete a schema with N > 0 objects and ?force=true → HTTP 200,
 *    cache invalidated, mapper delete called
 *  - Delete an unused schema (N = 0)                    → HTTP 200
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DownloadService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for SchemasController::destroy DELETE-safety guard.
 */
class SchemasDestroySafetyTest extends TestCase
{

    private SchemasController $controller;

    /** @var IRequest&MockObject */
    private IRequest $request;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var MagicMapper&MockObject */
    private MagicMapper $objectMapper;

    /** @var SchemaCacheHandler&MockObject */
    private SchemaCacheHandler $schemaCacheService;

    /** @var FacetCacheHandler&MockObject */
    private FacetCacheHandler $facetCacheSvc;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;


    /**
     * Wire up SchemasController with every dependency mocked.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request            = $this->createMock(IRequest::class);
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->objectMapper       = $this->createMock(MagicMapper::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheHandler::class);
        $this->facetCacheSvc      = $this->createMock(FacetCacheHandler::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        $this->controller = new SchemasController(
            'openregister',
            $this->request,
            $this->createMock(IAppConfig::class),
            $this->schemaMapper,
            $this->objectMapper,
            $this->createMock(DownloadService::class),
            $this->createMock(UploadService::class),
            $this->createMock(AuditTrailMapper::class),
            $this->createMock(OrganisationService::class),
            $this->schemaCacheService,
            $this->facetCacheSvc,
            $this->createMock(SchemaService::class),
            $this->logger,
            $this->createMock(ContainerInterface::class)
        );

    }//end setUp()


    /**
     * Build a Schema entity with injected id + slug.
     */
    private function makeSchema(int $id, string $slug = 'test-schema'): Schema
    {
        $schema = new Schema();
        $schema->setSlug($slug);
        $schema->setTitle($slug);

        $ref  = new ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);

        return $schema;

    }//end makeSchema()


    /**
     * REQ + SCENARIO: "Delete a schema with objects, no force flag".
     *
     * MUST return HTTP 409 with `{ error: 'schema-has-objects', objectCount: N }`
     * — and crucially MUST NOT call SchemaMapper::delete (the schema stays
     * persisted). Cache invalidation MUST NOT fire on the rejected path.
     */
    public function testDestroyWithoutForceReturns409WhenObjectsExist(): void
    {
        $schema = $this->makeSchema(42, 'application');

        $this->schemaMapper
            ->expects($this->once())
            ->method('find')
            ->with($this->equalTo(42))
            ->willReturn($schema);

        // 5 objects still reference this schema.
        $this->objectMapper
            ->expects($this->once())
            ->method('getStatistics')
            ->with($this->equalTo(null), $this->equalTo(42))
            ->willReturn(['total' => 5]);

        // force is NOT set → guard MUST fire.
        $this->request
            ->method('getParam')
            ->with($this->equalTo('force'))
            ->willReturn(null);

        // The schema MUST remain persisted.
        $this->schemaMapper->expects($this->never())->method('delete');
        $this->schemaCacheService->expects($this->never())->method('invalidate');

        $response = $this->controller->destroy(42);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(409, $response->getStatus());

        $data = $response->getData();
        $this->assertSame('schema-has-objects', $data['error']);
        $this->assertSame(5, $data['objectCount']);

    }//end testDestroyWithoutForceReturns409WhenObjectsExist()


    /**
     * REQ + SCENARIO: "Delete a schema with objects and force=true".
     *
     * MUST proceed with delete (204/200), MUST invoke
     * SchemaCacheHandler::invalidate(id), MUST log a WARNING with
     * orphan count.
     */
    public function testDestroyWithForceTrueDeletesAndInvalidatesCache(): void
    {
        $schema = $this->makeSchema(42, 'application');

        $this->schemaMapper
            ->expects($this->once())
            ->method('find')
            ->with($this->equalTo(42))
            ->willReturn($schema);

        $this->objectMapper
            ->expects($this->once())
            ->method('getStatistics')
            ->willReturn(['total' => 7]);

        // ?force=true is set.
        $this->request
            ->method('getParam')
            ->with($this->equalTo('force'))
            ->willReturn('true');

        // Delete MUST be called once.
        $this->schemaMapper
            ->expects($this->once())
            ->method('delete')
            ->with($this->equalTo($schema));

        // Cache MUST be invalidated on the affected schema ID.
        $this->schemaCacheService
            ->expects($this->once())
            ->method('invalidate')
            ->with($this->equalTo(42));

        // A WARNING-level log MUST surface the orphan count for operator review.
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('Force-deleting schema with attached objects'),
                $this->callback(function (array $ctx): bool {
                    return ($ctx['schemaId'] ?? null) === 42
                        && ($ctx['objectCount'] ?? null) === 7;
                })
            );

        $response = $this->controller->destroy(42);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());

    }//end testDestroyWithForceTrueDeletesAndInvalidatesCache()


    /**
     * REQ + SCENARIO: "Delete an unused schema" (regression baseline).
     *
     * When zero objects reference the schema, the destroy path MUST proceed
     * straight through to delete + invalidate without involving the force
     * flag. Establishes the happy-path baseline so the 409 + force-true
     * paths above are not vacuous.
     */
    public function testDestroyOnUnusedSchemaSucceeds(): void
    {
        $schema = $this->makeSchema(99, 'orphan-free');

        $this->schemaMapper
            ->expects($this->once())
            ->method('find')
            ->with($this->equalTo(99))
            ->willReturn($schema);

        // Zero objects reference this schema.
        $this->objectMapper
            ->expects($this->once())
            ->method('getStatistics')
            ->willReturn(['total' => 0]);

        $this->request
            ->method('getParam')
            ->with($this->equalTo('force'))
            ->willReturn(null);

        $this->schemaMapper->expects($this->once())->method('delete');
        $this->schemaCacheService->expects($this->once())->method('invalidate')->with($this->equalTo(99));

        $response = $this->controller->destroy(99);

        $this->assertSame(200, $response->getStatus());

    }//end testDestroyOnUnusedSchemaSucceeds()


}//end class
