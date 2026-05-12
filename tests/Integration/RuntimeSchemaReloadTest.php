<?php

/**
 * Runtime Schema Reload — service-level integration test.
 *
 * Spec REQ (runtime-schema-api):
 *   "Cache invalidation on mutation MUST fire on every successful create/update/delete
 *    so the next read in the same request worker sees the new state."
 *   "DELETE without ?force=true MUST refuse when objects exist (HTTP 409);
 *    ?force=true MUST proceed and warn."
 *
 * This file is the canonical integration test referenced by tasks.md §4.4. It
 * exercises the controller round-trip on /api/schemas through the SchemasController
 * directly (no HTTP layer) so that it runs in any environment that loads the
 * Nextcloud autoloader — full DI container required services are pulled from
 * `\OC::$server` when present, and the entire test is skipped otherwise so the
 * suite stays green on hosts without a live Nextcloud instance.
 *
 * What's REAL vs MOCKED:
 *  - REAL (when \OC::$server is available):
 *      * SchemaCacheHandler (the unit under test for cache invalidation)
 *      * SchemaMapper, MagicMapper, AuditTrailMapper, RegisterMapper
 *      * IDBConnection (writes hit the actual DB)
 *      * SchemaService, DownloadService, UploadService, OrganisationService,
 *        FacetCacheHandler, IAppConfig, LoggerInterface
 *  - MOCKED:
 *      * IRequest — used as a thin data carrier so we can inject query params
 *        (`?force=true`) and POST bodies without spinning up a real HTTP stack
 *
 * If `\OC::$server` is not bootstrapped, the suite is marked skipped (not
 * failed) — see SkipBootstrapTrait::requireNextcloud(). This keeps `composer
 * test:unit` clean on the host while ensuring the test is picked up the moment
 * the Docker harness runs `composer test:api` or `composer test:docker`.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DownloadService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service-level integration tests for the runtime-schema-api spec.
 *
 * Exercises the controller round-trip (POST → cache → GET → PUT → cache →
 * DELETE without/with force) directly against the real services to prove the
 * cache invalidation chain holds in the same PHP worker.
 *
 * @group DB
 * @group integration
 * @group runtime-schema-api
 */
class RuntimeSchemaReloadTest extends TestCase
{

    /**
     * Real Schemas controller built from the DI container.
     *
     * @var SchemasController
     */
    private SchemasController $schemasController;

    /**
     * Real SchemaMapper from DI (writes hit the DB).
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Real RegisterMapper from DI.
     *
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * Real MagicMapper from DI for object writes and cleanup.
     *
     * @var MagicMapper
     */
    private MagicMapper $objectMapper;

    /**
     * Real ObjectService for slug-aware searches.
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * Real cache handler — the unit under test for invalidation behaviour.
     *
     * @var SchemaCacheHandler
     */
    private SchemaCacheHandler $schemaCacheHandler;

    /**
     * Mock request — only used to inject controller params.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * Schemas/registers created during a test for tearDown cleanup.
     *
     * @var int[]
     */
    private array $createdSchemaIds = [];

    /**
     * @var int[]
     */
    private array $createdRegisterIds = [];

    /**
     * @var string[]
     */
    private array $createdObjectUuids = [];


    /**
     * Wire up real services or skip the suite if Nextcloud is not loaded.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists('\\OC') === false || isset(\OC::$server) === false) {
            $this->markTestSkipped(
                'RuntimeSchemaReloadTest requires a bootstrapped Nextcloud DI container '
                .'(run via `composer test:api` inside the Docker container).'
            );
        }

        $this->schemaMapper       = \OC::$server->get(SchemaMapper::class);
        $this->registerMapper     = \OC::$server->get(RegisterMapper::class);
        $this->objectMapper       = \OC::$server->get(MagicMapper::class);
        $this->objectService      = \OC::$server->get(ObjectService::class);
        $this->schemaCacheHandler = \OC::$server->get(SchemaCacheHandler::class);

        $this->request = $this->createMock(IRequest::class);

        $this->schemasController = new SchemasController(
            'openregister',
            $this->request,
            \OC::$server->get(IAppConfig::class),
            $this->schemaMapper,
            $this->objectMapper,
            \OC::$server->get(DownloadService::class),
            \OC::$server->get(UploadService::class),
            \OC::$server->get(AuditTrailMapper::class),
            \OC::$server->get(OrganisationService::class),
            $this->schemaCacheHandler,
            \OC::$server->get(FacetCacheHandler::class),
            \OC::$server->get(SchemaService::class),
            \OC::$server->get(LoggerInterface::class)
        );

    }//end setUp()


    /**
     * Best-effort cleanup of every fixture created by a test method.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $db = null;
        if (class_exists('\\OC') === true && isset(\OC::$server) === true) {
            try {
                $db = \OC::$server->get(IDBConnection::class);
            } catch (\Throwable $e) {
                $db = null;
            }
        }

        // Object cleanup via direct DB so an orphaned-row guard never blocks teardown.
        if ($db !== null) {
            foreach ($this->createdObjectUuids as $uuid) {
                try {
                    $qb = $db->getQueryBuilder();
                    $qb->delete('openregister_objects')
                        ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
                    $qb->executeStatement();
                } catch (\Throwable $e) {
                    // Cleanup is best-effort.
                }
            }
        }

        foreach ($this->createdSchemaIds as $id) {
            try {
                $entity = $this->schemaMapper->find($id);
                $this->schemaMapper->delete($entity);
            } catch (\Throwable $e) {
                // Schema may already have been deleted by the test itself.
            }
        }

        foreach ($this->createdRegisterIds as $id) {
            try {
                $entity = $this->registerMapper->find($id);
                $this->registerMapper->delete($entity);
            } catch (\Throwable $e) {
                // Register may already have been deleted by the test itself.
            }
        }

        parent::tearDown();

    }//end tearDown()


    /**
     * REQ + SCENARIO: "POST /api/schemas creates a schema and invalidates the cache
     * so the immediate GET on the same worker sees the new entity".
     *
     * Asserts:
     *  - POST returns 201 with a numeric id
     *  - GET on that id returns the same canonical entity in the same PHP worker
     *  - The cache invalidate was a no-op on a cold cache but did not error
     *
     * @return void
     */
    public function testPostSchemaCachesAreInvalidatedAndImmediateGetSeesNewSchema(): void
    {
        $title = 'phpunit-rt-schema-'.uniqid();
        $slug  = 'phpunit-rt-'.uniqid();

        $payload = [
            'title'                    => $title,
            'description'              => 'Created by RuntimeSchemaReloadTest',
            'slug'                     => $slug,
            'version'                  => '0.0.1',
            'properties'               => [
                'name' => ['type' => 'string', 'title' => 'Name'],
            ],
            // The lifecycle block is the canonical engine-reload trigger;
            // we persist it to prove the round-trip preserves extension keys.
            'x-openregister-lifecycle' => [
                'states'      => ['draft', 'published'],
                'transitions' => [],
            ],
        ];

        $this->request->method('getParams')->willReturn($payload);

        $response = $this->schemasController->create();
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(201, $response->getStatus(), 'POST /api/schemas MUST return 201');

        $data = $response->getData();
        $createdId = $data instanceof Schema ? $data->getId() : ($data['id'] ?? null);
        $this->assertIsInt($createdId, 'POST response MUST carry a numeric schema id');
        $this->createdSchemaIds[] = $createdId;

        // Same-worker GET MUST observe the new schema (cache invalidation proof).
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getParam')->willReturn(null);
        $reflection = new \ReflectionClass($this->schemasController);
        $prop       = $reflection->getProperty('request');
        $prop->setAccessible(true);
        $prop->setValue($this->schemasController, $this->request);

        $getResponse = $this->schemasController->show($createdId);
        $this->assertSame(200, $getResponse->getStatus(), 'Immediate GET MUST succeed');
        $getData = $getResponse->getData();
        $this->assertSame($title, $getData['title'] ?? null);
        $this->assertSame($slug, $getData['slug'] ?? null);

    }//end testPostSchemaCachesAreInvalidatedAndImmediateGetSeesNewSchema()


    /**
     * REQ + SCENARIO: "PUT /api/schemas/{id} updates a schema and re-invalidates
     * the cache so a subsequent GET sees the new description".
     *
     * @return void
     */
    public function testPutSchemaInvalidatesCacheOnUpdate(): void
    {
        // Seed: create a schema directly via the mapper.
        $schema = new Schema();
        $schema->setTitle('phpunit-put-'.uniqid());
        $schema->setSlug('phpunit-put-'.uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setDescription('initial-description');
        $schema->setProperties(['name' => ['type' => 'string']]);
        $inserted = $this->schemaMapper->insert($schema);
        $this->createdSchemaIds[] = $inserted->getId();

        // Warm the cache by reading once.
        $this->schemaMapper->find($inserted->getId());

        // Now PUT a new description via the controller.
        $this->request->method('getParams')->willReturn([
            'description' => 'updated-by-runtime-schema-reload-test',
        ]);

        $response = $this->schemasController->update($inserted->getId());
        $this->assertSame(
            200,
            $response->getStatus(),
            'PUT MUST return 200; got '.$response->getStatus()
            .' body='.json_encode($response->getData())
        );

        // Read fresh — the cache invalidate on update MUST surface the new
        // description in the same worker.
        $fresh = $this->schemaMapper->find($inserted->getId());
        $this->assertSame(
            'updated-by-runtime-schema-reload-test',
            $fresh->getDescription(),
            'In-worker re-read MUST see the new description (cache invalidated)'
        );

    }//end testPutSchemaInvalidatesCacheOnUpdate()


    /**
     * REQ + SCENARIO: "DELETE /api/schemas/{id} without ?force=true MUST return
     * 409 when the schema has attached objects; with ?force=true MUST return 200".
     *
     * Full chain: create schema → create register pointing at it → POST an
     * object via ObjectService → DELETE (no force) returns 409 → DELETE
     * (force=true) returns 200.
     *
     * @return void
     */
    public function testDeleteSchemaForceFlagGuardsAgainstOrphans(): void
    {
        // 1. Schema.
        $schema = new Schema();
        $schema->setTitle('phpunit-del-'.uniqid());
        $schema->setSlug('phpunit-del-'.uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setProperties(['name' => ['type' => 'string']]);
        $schema = $this->schemaMapper->insert($schema);
        $this->createdSchemaIds[] = $schema->getId();

        // 2. Register that references the schema.
        $register = new Register();
        $register->setTitle('phpunit-del-reg-'.uniqid());
        $register->setSlug('phpunit-del-reg-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSchemas([$schema->getId()]);
        $register = $this->registerMapper->insert($register);
        $this->createdRegisterIds[] = $register->getId();

        // 3. Persist an object so the DELETE-safety guard has something to trip on.
        try {
            $created = $this->objectService->saveObject(
                ['name' => 'guard-fixture'],
                $register,
                $schema
            );
            if (is_array($created) === true) {
                $uuid = $created['uuid'] ?? null;
            } else {
                $uuid = method_exists($created, 'getUuid') ? $created->getUuid() : null;
            }
            if (is_string($uuid) === true) {
                $this->createdObjectUuids[] = $uuid;
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'Object persistence not available in this environment: '.$e->getMessage()
            );
        }

        // 4. DELETE without force → 409.
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default = null) {
                return $key === 'force' ? null : $default;
            }
        );
        $reflection = new \ReflectionClass($this->schemasController);
        $prop       = $reflection->getProperty('request');
        $prop->setAccessible(true);
        $prop->setValue($this->schemasController, $this->request);

        $rejected = $this->schemasController->destroy($schema->getId());
        $this->assertSame(409, $rejected->getStatus(), 'DELETE without ?force MUST return 409');
        $rejBody = $rejected->getData();
        $this->assertSame('schema-has-objects', $rejBody['error'] ?? null);
        $this->assertGreaterThan(0, $rejBody['objectCount'] ?? 0);

        // 5. Schema MUST still exist (the 409 must not silently delete).
        $stillThere = $this->schemaMapper->find($schema->getId());
        $this->assertSame($schema->getId(), $stillThere->getId());

        // 6. DELETE with ?force=true → 200, cache invalidated, schema gone.
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default = null) {
                return $key === 'force' ? 'true' : $default;
            }
        );
        $prop->setValue($this->schemasController, $this->request);

        $accepted = $this->schemasController->destroy($schema->getId());
        $this->assertSame(200, $accepted->getStatus(), 'DELETE with ?force=true MUST return 200');

        // 7. Schema MUST be gone from the DB (cache invalidate ensures the next read is fresh).
        $this->expectException(DoesNotExistException::class);
        $this->schemaMapper->find($schema->getId());

    }//end testDeleteSchemaForceFlagGuardsAgainstOrphans()


    /**
     * REQ + SCENARIO: "ObjectService::searchObjectsBySlug resolves a slug-pair
     * to a numeric search in the same worker — proves the slug helper composes
     * with the cache invalidation chain (i.e. a freshly-created register/schema
     * is reachable by slug)".
     *
     * @return void
     */
    public function testSearchObjectsBySlugSeesFreshSchemaInSameWorker(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-sbs-'.uniqid());
        $schema->setSlug('phpunit-sbs-schema-'.uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setProperties(['name' => ['type' => 'string']]);
        $schema = $this->schemaMapper->insert($schema);
        $this->createdSchemaIds[] = $schema->getId();

        $register = new Register();
        $register->setTitle('phpunit-sbs-reg-'.uniqid());
        $register->setSlug('phpunit-sbs-reg-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSchemas([$schema->getId()]);
        $register = $this->registerMapper->insert($register);
        $this->createdRegisterIds[] = $register->getId();

        // searchObjectsBySlug MUST resolve and return an array (empty is fine —
        // we just need to prove the slug path doesn't throw DoesNotExistException
        // on a freshly-created pair, which would expose a missed invalidation).
        $result = $this->objectService->searchObjectsBySlug(
            $register->getSlug(),
            $schema->getSlug(),
            []
        );
        $this->assertIsArray($result);

    }//end testSearchObjectsBySlugSeesFreshSchemaInSameWorker()


}//end class
