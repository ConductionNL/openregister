<?php

/**
 * Unit tests for ObjectIntegrationsController dispatch + error translation.
 *
 * Covers:
 *  - GET list dispatches to provider->list()
 *  - GET show dispatches to provider->get()
 *  - POST dispatches to provider->create() with 201
 *  - DELETE 204
 *  - Unknown integration id → 404
 *  - NotImplementedException from registered provider → 501 + QueryTimeContract envelope
 *  - ProviderUnavailableException → 503 with cause payload
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectIntegrationsController;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * In-memory provider stub used to exercise the dispatch paths.
 */
class _ControllerStubProvider extends AbstractIntegrationProvider
{

    public array $listCalled   = [];
    public array $getCalled    = [];
    public array $createCalled = [];

    public function __construct(
        private string $id = 'stub',
        private string $storage = 'magic-column',
        private bool $listThrowsNotImplemented = false,
        private bool $createThrowsUnavailable = false,
        private ?array $listEnvelope = null,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return $this->id;
    }//end getId()

    public function getLabel(): string
    {
        return ucfirst($this->id);
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Cube';
    }//end getIcon()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return $this->storage;
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        $this->listCalled = compact('register', 'schema', 'objectId', 'filters');
        if ($this->listThrowsNotImplemented === true) {
            throw new NotImplementedException('list not supported');
        }
        if ($this->listEnvelope !== null) {
            return $this->listEnvelope;
        }
        return [['id' => 'a'], ['id' => 'b']];
    }//end list()

    public function get(string $register, string $schema, string $objectId, string $entityId): array
    {
        $this->getCalled = compact('register', 'schema', 'objectId', 'entityId');
        return ['id' => $entityId, 'name' => 'thing'];
    }//end get()

    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $this->createCalled = compact('register', 'schema', 'objectId', 'payload');
        if ($this->createThrowsUnavailable === true) {
            throw new ProviderUnavailableException(
                'upstream down',
                ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN
            );
        }
        return ['id' => 'new-id'];
    }//end create()

    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        // no-op
    }//end delete()

}//end class

/**
 * Unit tests for ObjectIntegrationsController.
 */
class ObjectIntegrationsControllerTest extends TestCase
{

    private function buildController(
        IntegrationRegistry $registry,
        ?IRequest $request = null,
        ?\OCA\OpenRegister\Service\ObjectService $objectService = null
    ): ObjectIntegrationsController {
        $request = $request ?? $this->createMock(IRequest::class);
        return new ObjectIntegrationsController(
            appName: 'openregister',
            request: $request,
            registry: $registry,
            logger: new NullLogger(),
            objectService: $objectService ?? $this->accessibleObjectService(),
            schemaMapper: $this->createMock(SchemaMapper::class)
        );
    }//end buildController()

    /**
     * An ObjectService mock whose getObject() resolves a non-null object, so
     * the per-object access guard passes for the dispatch happy-path tests.
     */
    private function accessibleObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('getObject')
            ->willReturn($this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class));
        return $objectService;
    }//end accessibleObjectService()

    /**
     * An ObjectService mock whose getObject() resolves null — the caller cannot
     * read the target object (RBAC/multitenancy filtered it out).
     */
    private function deniedObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('getObject')->willReturn(null);
        return $objectService;
    }//end deniedObjectService()

    public function testIndexDeniesInaccessibleObject(): void
    {
        // IDOR: the caller cannot read the target OR object → 404, and the
        // provider is never reached.
        $stub     = new _ControllerStubProvider('stub');
        $registry = new IntegrationRegistry(new NullLogger());
        $registry->addProvider($stub);

        $controller = $this->buildController($registry, null, $this->deniedObjectService());

        $response = $controller->index('r', 's', 'o', 'stub');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame([], $stub->listCalled);
    }//end testIndexDeniesInaccessibleObject()

    private function buildRequest(array $params = []): IRequest
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn($params);
        $request->method('getParam')->willReturnCallback(
            fn ($k, $default = null) => $params[$k] ?? $default
        );
        return $request;
    }//end buildRequest()

    public function testIndexDispatchesAndReturnsItems(): void
    {
        $registry = new IntegrationRegistry(new NullLogger());
        $stub     = new _ControllerStubProvider('stub');
        $registry->addProvider($stub);

        $request    = $this->buildRequest(['register' => 'r', 'schema' => 's', 'id' => 'o', 'integrationId' => 'stub', '_limit' => '5']);
        $controller = $this->buildController($registry, $request);

        $response = $controller->index('r', 's', 'o', 'stub');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $body = $response->getData();
        // Flat-array provider output is normalized into the canonical
        // {items, total, nextCursor} envelope; total defaults to count.
        $this->assertSame([['id' => 'a'], ['id' => 'b']], $body['items']);
        $this->assertSame([['id' => 'a'], ['id' => 'b']], $body['results']);
        $this->assertSame(2, $body['total']);
        $this->assertNull($body['nextCursor']);
        $this->assertSame(['_limit' => '5'], $stub->listCalled['filters']);
    }//end testIndexDispatchesAndReturnsItems()

    public function testIndexPassesThroughProviderEnvelope(): void
    {
        $registry = new IntegrationRegistry(new NullLogger());
        $registry->addProvider(
            new _ControllerStubProvider(
                id: 'stub',
                listEnvelope: [
                    'items'      => [['id' => 'a']],
                    'total'      => 42,
                    'nextCursor' => '1',
                ]
            )
        );

        $controller = $this->buildController($registry);
        $response   = $controller->index('r', 's', 'o', 'stub');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $body = $response->getData();
        // A provider that already returns an envelope keeps its real
        // total + cursor — they are not flattened to count().
        $this->assertSame([['id' => 'a']], $body['items']);
        $this->assertSame(42, $body['total']);
        $this->assertSame('1', $body['nextCursor']);
    }//end testIndexPassesThroughProviderEnvelope()

    public function testIndexNormalizesResultsKeyedEnvelope(): void
    {
        $registry = new IntegrationRegistry(new NullLogger());
        $registry->addProvider(
            new _ControllerStubProvider(
                id: 'stub',
                listEnvelope: [
                    'results' => [['id' => 'x'], ['id' => 'y']],
                    'total'   => 2,
                ]
            )
        );

        $controller = $this->buildController($registry);
        $response   = $controller->index('r', 's', 'o', 'stub');

        $body = $response->getData();
        // The legacy `results`-keyed shape is coerced to canonical
        // `items` while preserving the provided total.
        $this->assertSame([['id' => 'x'], ['id' => 'y']], $body['items']);
        $this->assertSame(2, $body['total']);
        $this->assertNull($body['nextCursor']);
    }//end testIndexNormalizesResultsKeyedEnvelope()

    public function testShowDispatchesAndReturnsEntity(): void
    {
        $registry = new IntegrationRegistry(new NullLogger());
        $stub     = new _ControllerStubProvider('stub');
        $registry->addProvider($stub);

        $controller = $this->buildController($registry);
        $response   = $controller->show('r', 's', 'o', 'stub', 'entity-id');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['id' => 'entity-id', 'name' => 'thing'], $response->getData());
    }//end testShowDispatchesAndReturnsEntity()

    public function testCreateReturns201(): void
    {
        $registry = new IntegrationRegistry(new NullLogger());
        $stub     = new _ControllerStubProvider('stub');
        $registry->addProvider($stub);

        $request    = $this->buildRequest(['title' => 'X', 'register' => 'r', 'schema' => 's', 'id' => 'o', 'integrationId' => 'stub']);
        $controller = $this->buildController($registry, $request);

        $response = $controller->create('r', 's', 'o', 'stub');
        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame(['title' => 'X'], $stub->createCalled['payload']);
    }//end testCreateReturns201()

    public function testDestroyReturns204(): void
    {
        $registry = new IntegrationRegistry(new NullLogger());
        $registry->addProvider(new _ControllerStubProvider('stub'));

        $controller = $this->buildController($registry);
        $response   = $controller->destroy('r', 's', 'o', 'stub', 'entity-id');
        $this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
    }//end testDestroyReturns204()

    public function testUnknownIntegrationReturns404(): void
    {
        $registry   = new IntegrationRegistry(new NullLogger());
        $controller = $this->buildController($registry);

        $response = $controller->index('r', 's', 'o', 'missing');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertStringContainsString("'missing'", $response->getData()['message']);
    }//end testUnknownIntegrationReturns404()

    public function testNotImplementedFromRegisteredProviderReturns501(): void
    {
        $registry = new IntegrationRegistry(new NullLogger());
        $registry->addProvider(new _ControllerStubProvider(id: 'stub', listThrowsNotImplemented: true));

        $controller = $this->buildController($registry);
        $response   = $controller->index('r', 's', 'o', 'stub');

        $this->assertSame(501, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('stub', $body['details']['integration']);
        $this->assertSame('query-time-storage-no-mutation', $body['details']['reason']);
    }//end testNotImplementedFromRegisteredProviderReturns501()

    public function testProviderUnavailableReturns503WithCause(): void
    {
        $registry = new IntegrationRegistry(new NullLogger());
        $registry->addProvider(new _ControllerStubProvider(id: 'stub', createThrowsUnavailable: true));

        $request    = $this->buildRequest([]);
        $controller = $this->buildController($registry, $request);
        $response   = $controller->create('r', 's', 'o', 'stub');

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        $body = $response->getData();
        $this->assertSame(ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, $body['details']['cause']);
    }//end testProviderUnavailableReturns503WithCause()

}//end class
