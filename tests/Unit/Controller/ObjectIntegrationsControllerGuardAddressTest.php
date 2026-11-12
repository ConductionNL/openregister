<?php

/**
 * Characterisation of which object the integration guards read, and refuse.
 *
 * Pins, before the route address travels as one value, that the read and
 * the write guards resolve exactly the register, schema and id the route
 * names, that an object readable only under another address is a 404 with
 * nothing dispatched, and that a write without the update right is a 403.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- the provider stub's methods are its interface's.

use OCA\OpenRegister\Controller\ObjectIntegrationsController;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsAccess;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsDomain;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectIntegrationsController
 */
final class ObjectIntegrationsControllerGuardAddressTest extends TestCase {

	/**
	 * The provider calls that were dispatched.
	 *
	 * @var array<int, string>
	 */
	public array $dispatched = [];

	/**
	 * The register, schema and id the guard resolved, in call order.
	 *
	 * @var array<int, string>
	 */
	private array $scoped = [];

	/**
	 * Build the controller over the one address the caller may read.
	 *
	 * @param bool $mayUpdate Whether the caller holds `update`.
	 *
	 * @return ObjectIntegrationsController The controller.
	 */
	private function controller(bool $mayUpdate): ObjectIntegrationsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$address = ['register' => null, 'schema' => null, 'id' => null];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnCallback(
			function ($register) use (&$address) {
				$address['register'] = $register;
				$this->scoped[] = 'register:'.$register;
				return $this->createMock(ObjectService::class);
			}
		);
		$objects->method('setSchema')->willReturnCallback(
			function ($schema) use (&$address) {
				$address['schema'] = $schema;
				$this->scoped[] = 'schema:'.$schema;
				return $this->createMock(ObjectService::class);
			}
		);
		$objects->method('setObject')->willReturnCallback(
			function ($id) use (&$address) {
				$address['id'] = $id;
				$this->scoped[] = 'id:'.$id;
				return $this->createMock(ObjectService::class);
			}
		);
		$objects->method('getObject')->willReturnCallback(
			static function () use (&$address): ?ObjectEntity {
				if ($address !== ['register' => 'reg', 'schema' => 'sch', 'id' => 'o1']) {
					return null;
				}

				$object = new ObjectEntity();
				$object->setUuid('uuid-o1');

				return $object;
			}
		);
		$objects->method('getCurrentSchemaEntity')->willReturn(new Schema());
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturn($mayUpdate);
		$visibility = $this->createMock(TimelineVisibilityService::class);
		$visibility->method('isTimelineIntegration')->willReturn(false);
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider($this->provider());

		return new ObjectIntegrationsController(
			request: $request,
			services: new ObjectIntegrationsControllerServices(
				domain: new ObjectIntegrationsDomain(registry: $registry, objectService: $objects, schemaMapper: $this->createMock(SchemaMapper::class)),
				access: new ObjectIntegrationsAccess(visibility: $visibility, permissions: $permissions),
				logger: new NullLogger()
			)
		);
	}//end controller()

	/**
	 * A provider that records what reaches it.
	 *
	 * @return AbstractIntegrationProvider The provider.
	 */
	private function provider(): AbstractIntegrationProvider {
		$test = $this;

		return new class($test) extends AbstractIntegrationProvider {

			/**
			 * Constructor.
			 *
			 * @param ObjectIntegrationsControllerGuardAddressTest $test The recorder.
			 */
			public function __construct(private ObjectIntegrationsControllerGuardAddressTest $test) {
			}

			public function getId(): string {
				return 'stub';
			}

			public function getLabel(): string {
				return 'Stub';
			}

			public function getIcon(): string {
				return 'Cube';
			}

			public function getRequiredApp(): ?string {
				return null;
			}

			public function getStorageStrategy(): string {
				return 'magic-column';
			}

			public function isEnabled(): bool {
				return true;
			}

			public function list(ObjectRef $ref, array $filters = []): array {
				$this->test->dispatched[] = 'list:'.$ref->id;
				return [];
			}

			public function get(ObjectRef $ref, string $entityId): array {
				$this->test->dispatched[] = 'get:'.$ref->id;
				return ['id' => $entityId];
			}

			public function create(ObjectRef $ref, array $payload): array {
				$this->test->dispatched[] = 'create:'.$ref->id;
				return ['id' => 'new'];
			}

			public function update(ObjectRef $ref, string $entityId, array $payload): array {
				$this->test->dispatched[] = 'update:'.$ref->id;
				return ['id' => $entityId];
			}

			public function delete(ObjectRef $ref, string $entityId): void {
				$this->test->dispatched[] = 'delete:'.$ref->id;
			}
		};
	}//end provider()

	/**
	 * A response as status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array<int, mixed> Status and body.
	 */
	private function outcome(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end outcome()

	/**
	 * Every verb resolves the route's own register, schema and id before it dispatches.
	 *
	 * @return void
	 */
	public function testEveryVerbResolvesTheRouteAddress(): void {
		$controller = $this->controller(mayUpdate: true);

		$this->assertSame(200, $controller->show('reg', 'sch', 'o1', 'stub', 'e1')->getStatus());
		$this->assertSame(201, $controller->create('reg', 'sch', 'o1', 'stub')->getStatus());
		$this->assertSame(200, $controller->update('reg', 'sch', 'o1', 'stub', 'e1')->getStatus());
		$this->assertSame(204, $controller->destroy('reg', 'sch', 'o1', 'stub', 'e1')->getStatus());

		$this->assertSame(array_merge(...array_fill(0, 4, ['register:reg', 'schema:sch', 'id:o1'])), $this->scoped);
		$this->assertSame(['get:uuid-o1', 'create:uuid-o1', 'update:uuid-o1', 'delete:uuid-o1'], $this->dispatched);
	}//end testEveryVerbResolvesTheRouteAddress()

	/**
	 * The same id under another register or schema is a 404 on every verb, with nothing dispatched.
	 *
	 * @return void
	 */
	public function testTheIdUnderAnotherAddressIsNotFound(): void {
		$controller = $this->controller(mayUpdate: true);
		$notFound = [404, ['message' => 'Object not found']];

		$this->assertSame($notFound, $this->outcome($controller->show('sch', 'reg', 'o1', 'stub', 'e1')));
		$this->assertSame($notFound, $this->outcome($controller->create('reg', 'other', 'o1', 'stub')));
		$this->assertSame($notFound, $this->outcome($controller->update('other', 'sch', 'o1', 'stub', 'e1')));
		$this->assertSame($notFound, $this->outcome($controller->destroy('reg', 'sch', 'o2', 'stub', 'e1')));
		$this->assertSame([], $this->dispatched);
	}//end testTheIdUnderAnotherAddressIsNotFound()

	/**
	 * A readable object without the update right refuses every write with 403, and still answers a read.
	 *
	 * @return void
	 */
	public function testAWriteWithoutTheUpdateRightIsForbidden(): void {
		$controller = $this->controller(mayUpdate: false);
		$forbidden = [403, ['message' => 'You may not change this object, so you cannot change its integrations.']];

		$this->assertSame($forbidden, $this->outcome($controller->create('reg', 'sch', 'o1', 'stub')));
		$this->assertSame($forbidden, $this->outcome($controller->update('reg', 'sch', 'o1', 'stub', 'e1')));
		$this->assertSame($forbidden, $this->outcome($controller->destroy('reg', 'sch', 'o1', 'stub', 'e1')));
		$this->assertSame(200, $controller->show('reg', 'sch', 'o1', 'stub', 'e1')->getStatus());
		$this->assertSame(['get:uuid-o1'], $this->dispatched);
	}//end testAWriteWithoutTheUpdateRightIsForbidden()
}//end class
