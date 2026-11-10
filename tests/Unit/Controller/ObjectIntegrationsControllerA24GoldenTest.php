<?php

/**
 * Golden outcomes of the object-scoped integration routes.
 *
 * Pins, before a restructure, the exact bodies of the read refusal (404),
 * the write refusal (403), an unknown integration, and the allowed answers
 * of every verb, with what reaches the provider.
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
final class ObjectIntegrationsControllerA24GoldenTest extends TestCase {

	/**
	 * The calls that reached the provider.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param ObjectEntity|null $object    What the RBAC read answers.
	 * @param bool              $mayUpdate Whether the caller holds `update`.
	 *
	 * @return ObjectIntegrationsController The controller.
	 */
	private function controller(?ObjectEntity $object, bool $mayUpdate): ObjectIntegrationsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['register' => 'r', 'id' => 'slug', 'title' => 'T', 'page' => 2]);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('getObject')->willReturn($object);
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
			 * @param ObjectIntegrationsControllerA24GoldenTest $test The recorder.
			 */
			public function __construct(private ObjectIntegrationsControllerA24GoldenTest $test) {
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

			public function list(string $register, string $schema, string $objectId, array $filters = []): array {
				$this->test->calls[] = ['list', $objectId, $filters];
				return [['id' => 'a']];
			}

			public function get(string $register, string $schema, string $objectId, string $entityId): array {
				$this->test->calls[] = ['get', $objectId, $entityId];
				return ['id' => $entityId];
			}

			public function create(string $register, string $schema, string $objectId, array $payload): array {
				$this->test->calls[] = ['create', $objectId, $payload];
				return ['id' => 'new'];
			}

			public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
				$this->test->calls[] = ['update', $objectId, $entityId, $payload];
				return ['id' => $entityId];
			}

			public function delete(string $register, string $schema, string $objectId, string $entityId): void {
				$this->test->calls[] = ['delete', $objectId, $entityId];
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
	 * An object the caller may not read is the same 404 on every verb, and nothing is dispatched.
	 *
	 * @return void
	 */
	public function testAnUnreadableObject(): void {
		$controller = $this->controller(object: null, mayUpdate: true);
		$notFound = [404, ['message' => 'Object not found']];

		$this->assertSame($notFound, $this->outcome($controller->index('r', 's', 'slug', 'stub')));
		$this->assertSame($notFound, $this->outcome($controller->show('r', 's', 'slug', 'stub', 'e')));
		$this->assertSame($notFound, $this->outcome($controller->create('r', 's', 'slug', 'stub')));
		$this->assertSame($notFound, $this->outcome($controller->update('r', 's', 'slug', 'stub', 'e')));
		$this->assertSame($notFound, $this->outcome($controller->destroy('r', 's', 'slug', 'stub', 'e')));
		$this->assertSame([], $this->calls);
	}//end testAnUnreadableObject()

	/**
	 * A readable object without `update` is the same 403 on every write; reads proceed.
	 *
	 * @return void
	 */
	public function testAReaderMayNotWrite(): void {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$controller = $this->controller(object: $object, mayUpdate: false);
		$forbidden = [403, ['message' => 'You may not change this object, so you cannot change its integrations.']];

		$this->assertSame($forbidden, $this->outcome($controller->create('r', 's', 'slug', 'stub')));
		$this->assertSame($forbidden, $this->outcome($controller->update('r', 's', 'slug', 'stub', 'e')));
		$this->assertSame($forbidden, $this->outcome($controller->destroy('r', 's', 'slug', 'stub', 'e')));
		$this->assertSame([], $this->calls);
		$this->assertSame([200, ['id' => 'e']], $this->outcome($controller->show('r', 's', 'slug', 'stub', 'e')));
	}//end testAReaderMayNotWrite()

	/**
	 * Every allowed verb answers its status, and the provider gets the resolved uuid.
	 *
	 * @return void
	 */
	public function testEveryAllowedVerb(): void {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$controller = $this->controller(object: $object, mayUpdate: true);

		$this->assertSame(
			[200, ['items' => [['id' => 'a']], 'results' => [['id' => 'a']], 'total' => 1, 'nextCursor' => null]],
			$this->outcome($controller->index('r', 's', 'slug', 'stub'))
		);
		$this->assertSame([201, ['id' => 'new']], $this->outcome($controller->create('r', 's', 'slug', 'stub')));
		$this->assertSame([200, ['id' => 'e']], $this->outcome($controller->update('r', 's', 'slug', 'stub', 'e')));
		$this->assertSame([204, null], $this->outcome($controller->destroy('r', 's', 'slug', 'stub', 'e')));
		$this->assertSame(
			[
				['list', 'u-1', ['title' => 'T', 'page' => 2]],
				['create', 'u-1', ['title' => 'T', 'page' => 2]],
				['update', 'u-1', 'e', ['title' => 'T', 'page' => 2]],
				['delete', 'u-1', 'e'],
			],
			$this->calls
		);
	}//end testEveryAllowedVerb()

	/**
	 * An integration nobody registered is 404 with the registered ids named.
	 *
	 * @return void
	 */
	public function testAnUnknownIntegration(): void {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$controller = $this->controller(object: $object, mayUpdate: true);
		$body = ['message' => "Integration 'nope' is not registered. Registered: stub"];

		$this->assertSame([404, $body], $this->outcome($controller->show('r', 's', 'slug', 'nope', 'e')));
		$this->assertSame([404, $body], $this->outcome($controller->destroy('r', 's', 'slug', 'nope', 'e')));
	}//end testAnUnknownIntegration()
}//end class
