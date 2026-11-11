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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- PHPUnit fixture properties are named by their type.
// phpcs:disable Squiz.PHP.DisallowInlineIf.Found -- PHPUnit fixture defaults.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ObjectIntegrationsController;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsAccess;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsDomain;
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
class _ControllerStubProvider extends AbstractIntegrationProvider {

	public array $listCalled = [];
	public array $getCalled = [];
	public array $createCalled = [];
	public array $updateCalled = [];
	public array $deleteCalled = [];

	public function __construct(
		private string $id = 'stub',
		private string $storage = 'magic-column',
		private bool $listThrowsNotImplemented = false,
		private bool $createThrowsUnavailable = false,
		private ?array $listEnvelope = null,
	) {
	}//end __construct()

	public function getId(): string {
		return $this->id;
	}//end getId()

	public function getLabel(): string {
		return ucfirst($this->id);
	}//end getLabel()

	public function getIcon(): string {
		return 'Cube';
	}//end getIcon()

	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return $this->storage;
	}//end getStorageStrategy()

	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	public function list(ObjectRef $ref, array $filters = []): array {
		$this->listCalled = ['register' => $ref->register, 'schema' => $ref->schema, 'objectId' => $ref->id, 'filters' => $filters];
		if ($this->listThrowsNotImplemented === true) {
			throw new NotImplementedException('list not supported');
		}
		if ($this->listEnvelope !== null) {
			return $this->listEnvelope;
		}
		return [['id' => 'a'], ['id' => 'b']];
	}//end list()

	public function get(ObjectRef $ref, string $entityId): array {
		$this->getCalled = ['register' => $ref->register, 'schema' => $ref->schema, 'objectId' => $ref->id, 'entityId' => $entityId];
		return ['id' => $entityId, 'name' => 'thing'];
	}//end get()

	public function create(ObjectRef $ref, array $payload): array {
		$this->createCalled = ['register' => $ref->register, 'schema' => $ref->schema, 'objectId' => $ref->id, 'payload' => $payload];
		if ($this->createThrowsUnavailable === true) {
			throw new ProviderUnavailableException(
				'upstream down',
				ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN
			);
		}
		return ['id' => 'new-id'];
	}//end create()

	public function delete(ObjectRef $ref, string $entityId): void {
		$this->deleteCalled = ['register' => $ref->register, 'schema' => $ref->schema, 'objectId' => $ref->id, 'entityId' => $entityId];
	}//end delete()

	public function update(ObjectRef $ref, string $entityId, array $payload): array {
		$this->updateCalled = ['register' => $ref->register, 'schema' => $ref->schema, 'objectId' => $ref->id, 'entityId' => $entityId, 'payload' => $payload];
		return ['id' => $entityId];
	}//end update()

}//end class

/**
 * Unit tests for ObjectIntegrationsController.
 */
class ObjectIntegrationsControllerTest extends TestCase {

	/** @var array<int, string> The actions the permission handler was asked about. */
	private array $askedActions = [];

	private function buildController(
		IntegrationRegistry $registry,
		?IRequest $request = null,
		?\OCA\OpenRegister\Service\ObjectService $objectService = null,
		?\OCA\OpenRegister\Notifications\Service\TimelineVisibilityService $visibility = null,
		?\OCA\OpenRegister\Service\Object\PermissionHandler $permissions = null,
	): ObjectIntegrationsController {
		$request = $request ?? $this->createMock(IRequest::class);
		return new ObjectIntegrationsController(
			request: $request,
			services: new ObjectIntegrationsControllerServices(
				domain: new ObjectIntegrationsDomain(
					registry: $registry,
					objectService: $objectService ?? $this->accessibleObjectService(),
					schemaMapper: $this->createMock(SchemaMapper::class)
				),
				access: new ObjectIntegrationsAccess(
					visibility: $visibility ?? $this->passThroughVisibility(),
					permissions: $permissions ?? $this->permissions(mayUpdate: true)
				),
				logger: new NullLogger()
			)
		);
	}//end buildController()

	/**
	 * A permission handler that answers the `update` right with $mayUpdate and records the actions asked.
	 *
	 * @param bool $mayUpdate The answer for `update`.
	 *
	 * @return \OCA\OpenRegister\Service\Object\PermissionHandler
	 */
	private function permissions(bool $mayUpdate): \OCA\OpenRegister\Service\Object\PermissionHandler {
		$permissions = $this->createMock(\OCA\OpenRegister\Service\Object\PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (\OCA\OpenRegister\Db\Schema $schema, string $action) use ($mayUpdate): bool {
				$this->askedActions[] = $action;
				return $action === 'update' ? $mayUpdate : true;
			}
		);
		return $permissions;
	}//end permissions()

	/**
	 * A visibility service that lets every row through, for the dispatch tests
	 * that are not about the timeline leaves.
	 *
	 * @return \OCA\OpenRegister\Notifications\Service\TimelineVisibilityService
	 */
	private function passThroughVisibility(): \OCA\OpenRegister\Notifications\Service\TimelineVisibilityService {
		$visibility = $this->createMock(\OCA\OpenRegister\Notifications\Service\TimelineVisibilityService::class);
		$visibility->method('isTimelineIntegration')->willReturn(false);
		return $visibility;
	}//end passThroughVisibility()

	/**
	 * An ObjectService mock whose getObject() resolves a non-null object, so
	 * the per-object access guard passes for the dispatch happy-path tests.
	 */
	private function accessibleObjectService(): \OCA\OpenRegister\Service\ObjectService {
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('o-uuid');
		$objectService->method('getObject')->willReturn($object);
		$objectService->method('getCurrentSchemaEntity')->willReturn(new \OCA\OpenRegister\Db\Schema());
		return $objectService;
	}//end accessibleObjectService()

	/**
	 * An ObjectService mock whose getObject() resolves null — the caller cannot
	 * read the target object (RBAC/multitenancy filtered it out).
	 */
	private function deniedObjectService(): \OCA\OpenRegister\Service\ObjectService {
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('getObject')->willReturn(null);
		return $objectService;
	}//end deniedObjectService()

	/**
	 * An ObjectService whose setObject() THROWS, which is what the real one
	 * does for an id that matches nothing: it is not a setter, it calls
	 * `objectMapper->find()`.
	 *
	 * `deniedObjectService()` above cannot cover this. It stubs `getObject()`
	 * alone, so on a mock `setObject()` is a silent no-op and the throwing
	 * path is unreachable — which is exactly why a 500 lived here unnoticed
	 * behind a green test named "denies inaccessible object".
	 */
	private function missingObjectService(): \OCA\OpenRegister\Service\ObjectService {
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('setObject')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException('Object not found in magic table')
		);

		return $objectService;
	}//end missingObjectService()

	/**
	 * An object id that resolves to nothing is a 404, not a 500.
	 *
	 * Found via openconnector's `synced-from-leaf` e2e spec, which asks this
	 * endpoint about a deliberately absent uuid and expects an empty 200/404.
	 * It got a 500 with "Object not found in magic table" — and had never run,
	 * because it is skipped unless `openconnector.storage_migrated` is true,
	 * which no fresh install ever set (ocon#1180).
	 */
	public function testIndexReturns404WhenTheObjectDoesNotExist(): void {
		$stub = new _ControllerStubProvider('stub');
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider($stub);

		$controller = $this->buildController($registry, null, $this->missingObjectService());

		$response = $controller->index('r', 's', '00000000-0000-0000-0000-000000000000', 'stub');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $stub->listCalled, 'the provider must not be reached');
	}//end testIndexReturns404WhenTheObjectDoesNotExist()

	/**
	 * The same holds for the other four verbs, which share the guard.
	 *
	 * @dataProvider guardedVerbProvider
	 */
	public function testEveryGuardedVerbReturns404WhenTheObjectDoesNotExist(string $verb): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider(new _ControllerStubProvider('stub'));

		$controller = $this->buildController($registry, $this->buildRequest(), $this->missingObjectService());

		$missing = '00000000-0000-0000-0000-000000000000';

		$response = match ($verb) {
			'show' => $controller->show('r', 's', $missing, 'stub', 'e1'),
			'create' => $controller->create('r', 's', $missing, 'stub'),
			'update' => $controller->update('r', 's', $missing, 'stub', 'e1'),
			'destroy' => $controller->destroy('r', 's', $missing, 'stub', 'e1'),
		};

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $verb . ' must 404, not 500');
	}//end testEveryGuardedVerbReturns404WhenTheObjectDoesNotExist()

	/**
	 * The verbs that go through guardObjectAccess().
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function guardedVerbProvider(): array {
		return [
			'show' => ['show'],
			'create' => ['create'],
			'update' => ['update'],
			'destroy' => ['destroy'],
		];
	}//end guardedVerbProvider()

	public function testIndexDeniesInaccessibleObject(): void {
		// IDOR: the caller cannot read the target OR object → 404, and the
		// provider is never reached.
		$stub = new _ControllerStubProvider('stub');
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider($stub);

		$controller = $this->buildController($registry, null, $this->deniedObjectService());

		$response = $controller->index('r', 's', 'o', 'stub');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $stub->listCalled);
	}//end testIndexDeniesInaccessibleObject()

	private function buildRequest(array $params = []): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			fn ($k, $default = null) => $params[$k] ?? $default
		);
		return $request;
	}//end buildRequest()

	public function testIndexDispatchesAndReturnsItems(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$stub = new _ControllerStubProvider('stub');
		$registry->addProvider($stub);

		$request = $this->buildRequest(['register' => 'r', 'schema' => 's', 'id' => 'o', 'integrationId' => 'stub', '_limit' => '5']);
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

	public function testIndexPassesThroughProviderEnvelope(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider(
			new _ControllerStubProvider(
				id: 'stub',
				listEnvelope: [
					'items' => [['id' => 'a']],
					'total' => 42,
					'nextCursor' => '1',
				]
			)
		);

		$controller = $this->buildController($registry);
		$response = $controller->index('r', 's', 'o', 'stub');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$body = $response->getData();
		// A provider that already returns an envelope keeps its real
		// total + cursor — they are not flattened to count().
		$this->assertSame([['id' => 'a']], $body['items']);
		$this->assertSame(42, $body['total']);
		$this->assertSame('1', $body['nextCursor']);
	}//end testIndexPassesThroughProviderEnvelope()

	public function testIndexNormalizesResultsKeyedEnvelope(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider(
			new _ControllerStubProvider(
				id: 'stub',
				listEnvelope: [
					'results' => [['id' => 'x'], ['id' => 'y']],
					'total' => 2,
				]
			)
		);

		$controller = $this->buildController($registry);
		$response = $controller->index('r', 's', 'o', 'stub');

		$body = $response->getData();
		// The legacy `results`-keyed shape is coerced to canonical
		// `items` while preserving the provided total.
		$this->assertSame([['id' => 'x'], ['id' => 'y']], $body['items']);
		$this->assertSame(2, $body['total']);
		$this->assertNull($body['nextCursor']);
	}//end testIndexNormalizesResultsKeyedEnvelope()

	public function testShowDispatchesAndReturnsEntity(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$stub = new _ControllerStubProvider('stub');
		$registry->addProvider($stub);

		$controller = $this->buildController($registry);
		$response = $controller->show('r', 's', 'o', 'stub', 'entity-id');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'entity-id', 'name' => 'thing'], $response->getData());
	}//end testShowDispatchesAndReturnsEntity()

	public function testCreateReturns201(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$stub = new _ControllerStubProvider('stub');
		$registry->addProvider($stub);

		$request = $this->buildRequest(['title' => 'X', 'register' => 'r', 'schema' => 's', 'id' => 'o', 'integrationId' => 'stub']);
		$controller = $this->buildController($registry, $request);

		$response = $controller->create('r', 's', 'o', 'stub');
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['title' => 'X'], $stub->createCalled['payload']);
	}//end testCreateReturns201()

	/**
	 * A create that fails keeps the failure's own status: only a success becomes 201.
	 *
	 * @return void
	 */
	public function testAFailedCreateKeepsItsStatus(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider(new _ControllerStubProvider('stub'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->buildController($registry)->create('r', 's', 'o', 'missing')->getStatus());
	}//end testAFailedCreateKeepsItsStatus()

	public function testAnItemOfAnotherObjectAnswers404(): void {
		$provider = new class('stub') extends _ControllerStubProvider {
			public function delete(ObjectRef $ref, string $entityId): void {
				throw new \Exception('Note not found', 404);
			}
		};
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider($provider);

		$response = $this->buildController($registry)->destroy('r', 's', 'o', 'stub', 'entity-id');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnItemOfAnotherObjectAnswers404()

	public function testDestroyReturns204(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider(new _ControllerStubProvider('stub'));

		$controller = $this->buildController($registry);
		$response = $controller->destroy('r', 's', 'o', 'stub', 'entity-id');
		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testDestroyReturns204()

	public function testWritesNeedTheObjectUpdateRight(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$stub = new _ControllerStubProvider('stub');
		$registry->addProvider($stub);
		$request = $this->buildRequest(['title' => 'X']);
		$controller = $this->buildController(registry: $registry, request: $request, permissions: $this->permissions(mayUpdate: false));

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->create('r', 's', 'o', 'stub')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->update('r', 's', 'o', 'stub', 'e')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->destroy('r', 's', 'o', 'stub', 'e')->getStatus());
		$this->assertSame([], $stub->createCalled);
		$this->assertSame([], $stub->updateCalled);
		$this->assertSame([], $stub->deleteCalled);
		$this->assertSame(['update', 'update', 'update'], $this->askedActions, 'the right asked is update, not read');
	}//end testWritesNeedTheObjectUpdateRight()

	public function testWritesProceedWithTheObjectUpdateRight(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$stub = new _ControllerStubProvider('stub');
		$registry->addProvider($stub);
		$controller = $this->buildController(registry: $registry, request: $this->buildRequest(['role' => 'x']));

		$this->assertSame(Http::STATUS_OK, $controller->update('r', 's', 'o', 'stub', 'e')->getStatus());
		$this->assertSame(Http::STATUS_NO_CONTENT, $controller->destroy('r', 's', 'o', 'stub', 'e')->getStatus());
		$this->assertSame('e', $stub->deleteCalled['entityId']);
	}//end testWritesProceedWithTheObjectUpdateRight()

	public function testReadsNeedNoUpdateRight(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider(new _ControllerStubProvider('stub'));
		$controller = $this->buildController(registry: $registry, permissions: $this->permissions(mayUpdate: false));

		$this->assertSame(Http::STATUS_OK, $controller->show('r', 's', 'o', 'stub', 'e')->getStatus());
	}//end testReadsNeedNoUpdateRight()

	public function testProvidersReceiveTheResolvedUuidNotTheRouteId(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$stub = new _ControllerStubProvider('stub');
		$registry->addProvider($stub);
		$controller = $this->buildController(registry: $registry, request: $this->buildRequest(['role' => 'x']));

		$controller->index('r', 's', 'my-slug', 'stub');
		$controller->update('r', 's', 'my-slug', 'stub', 'e');
		$controller->destroy('r', 's', 'my-slug', 'stub', 'e');

		$this->assertSame('o-uuid', $stub->listCalled['objectId']);
		$this->assertSame('o-uuid', $stub->updateCalled['objectId']);
		$this->assertSame('o-uuid', $stub->deleteCalled['objectId']);
	}//end testProvidersReceiveTheResolvedUuidNotTheRouteId()

	public function testALinkOfAnotherObjectAnswers404Not500(): void {
		$contacts = $this->createMock(\OCA\OpenRegister\Service\ContactService::class);
		$contacts->expects($this->once())
			->method('unlinkContact')
			->with(77, 'o-uuid')
			->willThrowException(new \Exception('Contact link not found', 404));
		$contacts->method('updateRole')->willThrowException(new \Exception('Contact link not found', 404));
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider(
			new \OCA\OpenRegister\Service\Integration\Providers\ContactsProvider(
				new \OCA\OpenRegister\Service\Integration\Providers\AppProviderDescription(
					$this->createMock(\OCP\App\IAppManager::class),
					$this->createMock(\OCP\IL10N::class)
				),
				$contacts
			)
		);
		$controller = $this->buildController(registry: $registry, request: $this->buildRequest(['role' => 'x']));

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->destroy('r', 's', 'my-slug', 'contacts', '77')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->update('r', 's', 'my-slug', 'contacts', '77')->getStatus());
	}//end testALinkOfAnotherObjectAnswers404Not500()

	public function testAnObjectWithoutAUuidIsNotFound(): void {
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('getObject')->willReturn(new \OCA\OpenRegister\Db\ObjectEntity());
		$registry = new IntegrationRegistry(new NullLogger());
		$stub = new _ControllerStubProvider('stub');
		$registry->addProvider($stub);
		$controller = $this->buildController(registry: $registry, objectService: $objectService);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->destroy('r', 's', 'o', 'stub', 'e')->getStatus());
		$this->assertSame([], $stub->deleteCalled);
	}//end testAnObjectWithoutAUuidIsNotFound()

	public function testUnknownIntegrationReturns404(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$controller = $this->buildController($registry);

		$response = $controller->index('r', 's', 'o', 'missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertStringContainsString("'missing'", $response->getData()['message']);
	}//end testUnknownIntegrationReturns404()

	public function testNotImplementedFromRegisteredProviderReturns501(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider(new _ControllerStubProvider(id: 'stub', listThrowsNotImplemented: true));

		$controller = $this->buildController($registry);
		$response = $controller->index('r', 's', 'o', 'stub');

		$this->assertSame(501, $response->getStatus());
		$body = $response->getData();
		$this->assertSame('stub', $body['details']['integration']);
		$this->assertSame('query-time-storage-no-mutation', $body['details']['reason']);
	}//end testNotImplementedFromRegisteredProviderReturns501()

	public function testProviderUnavailableReturns503WithCause(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider(new _ControllerStubProvider(id: 'stub', createThrowsUnavailable: true));

		$request = $this->buildRequest([]);
		$controller = $this->buildController($registry, $request);
		$response = $controller->create('r', 's', 'o', 'stub');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$body = $response->getData();
		$this->assertSame(ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, $body['details']['cause']);
	}//end testProviderUnavailableReturns503WithCause()


	/**
	 * The notes leaf, read by someone who cannot update the object, answers
	 * with the public rows only — and the caller never asked for that filter.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function testATimelineLeafIsFilteredForACallerWithoutUpdate(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider(
			new _ControllerStubProvider(
				id: 'notes',
				listEnvelope: [
					['id' => '1', 'visibility' => 'internal'],
					['id' => '2', 'visibility' => 'public'],
					['id' => '3'],
				]
			)
		);

		$visibility = new \OCA\OpenRegister\Notifications\Service\TimelineVisibilityService(
			new \OCA\OpenRegister\Notifications\Service\TimelineVisibilityPlatform($this->createMock(SchemaMapper::class), $this->createMock(\OCP\IUserSession::class), new NullLogger()),
			$this->permissionHandlerAnswering(false),
			$this->createMock(\OCA\OpenRegister\Db\AuditTrailMapper::class)
		);

		$controller = $this->buildController(
			$registry,
			$this->buildRequest(['register' => 'r', 'schema' => 's', 'id' => 'o', 'integrationId' => 'notes']),
			null,
			$visibility
		);

		$body = $controller->index('r', 's', 'o', 'notes')->getData();

		$this->assertSame([['id' => '2', 'visibility' => 'public']], $body['results']);
	}//end testATimelineLeafIsFilteredForACallerWithoutUpdate()

	/**
	 * A leaf that is not a timeline answers exactly as before: a file list is
	 * not a timeline entry, and treating one as internal would empty a
	 * reader's screen for no gain.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function testANonTimelineLeafIsUntouched(): void {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider(new _ControllerStubProvider('files'));

		$visibility = new \OCA\OpenRegister\Notifications\Service\TimelineVisibilityService(
			new \OCA\OpenRegister\Notifications\Service\TimelineVisibilityPlatform($this->createMock(SchemaMapper::class), $this->createMock(\OCP\IUserSession::class), new NullLogger()),
			$this->permissionHandlerAnswering(false),
			$this->createMock(\OCA\OpenRegister\Db\AuditTrailMapper::class)
		);

		$controller = $this->buildController(
			$registry,
			$this->buildRequest(['register' => 'r', 'schema' => 's', 'id' => 'o', 'integrationId' => 'files']),
			null,
			$visibility
		);

		$body = $controller->index('r', 's', 'o', 'files')->getData();

		$this->assertSame([['id' => 'a'], ['id' => 'b']], $body['results']);
	}//end testANonTimelineLeafIsUntouched()

	/**
	 * A PermissionHandler that answers one verdict for every question.
	 *
	 * @param bool $verdict The answer every permission question gets.
	 *
	 * @return \OCA\OpenRegister\Service\Object\PermissionHandler
	 */
	private function permissionHandlerAnswering(bool $verdict): \OCA\OpenRegister\Service\Object\PermissionHandler {
		$handler = $this->createMock(\OCA\OpenRegister\Service\Object\PermissionHandler::class);
		$handler->method('hasPermission')->willReturn($verdict);
		return $handler;
	}//end permissionHandlerAnswering()
}//end class
