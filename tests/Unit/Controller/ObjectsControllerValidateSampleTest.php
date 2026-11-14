<?php

declare(strict_types=1);

/**
 * POST /api/objects/{register}/{schema}/validate: a sample checked without saving,
 * with the rights a create or an update of it would need.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectValidationVerdict;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectValidationVerdict
 */
class ObjectsControllerValidateSampleTest extends TestCase {

	private PermissionHandlerInterface&MockObject $permissions;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->schema = new Schema();
		$this->schema->setId(2);
	}

	/**
	 * The request's object service: resolves reg/sch to 1/2 and answers the given verdict.
	 */
	private function service(ObjectValidationVerdict $verdict, ?array &$asked=null): ObjectService {
		$register = new Register();
		$register->setId(1);
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('getRegister')->willReturn(1);
		$service->method('getSchema')->willReturn(2);
		$service->method('getCurrentRegisterEntity')->willReturn($register);
		$service->method('getCurrentSchemaEntity')->willReturn($this->schema);
		$service->method('validateObject')->willReturnCallback(
			static function (array $object, $register, $schema, ?string $id=null) use ($verdict, &$asked): ObjectValidationVerdict {
				$asked = ['object' => $object, 'register' => $register, 'schema' => $schema, 'id' => $id];
				return $verdict;
			}
		);

		return $service;
	}

	/**
	 * The controller with a request carrying these params and a stored object for an update.
	 */
	private function controller(array $params, ?ObjectEntity $stored=null): ObjectsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default=null) => ($params[$key] ?? $default));
		$request->method('getParams')->willReturn($params);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => $id === PermissionHandlerInterface::class ? $this->permissions : $this->createMock($id));
		$storedService = $this->createMock(ObjectService::class);
		$storedService->method('setRegister')->willReturnSelf();
		$storedService->method('setSchema')->willReturnSelf();
		$storedService->method('find')->willReturn($stored);

		return ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$storedService,
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ObjectExporterInterface::class),
			$this->createMock(ObjectImporterInterface::class),
			null,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testAValidSampleIsAnswered200Valid(): void {
		$this->permissions->method('hasPermission')->willReturnCallback(static fn ($schema, string $action): bool => $action === 'create');
		$asked = null;

		$response = $this->controller(['gemeente' => 'Utrecht', '_route' => 'x'])->validateSample('reg', 'sch', $this->service(new ObjectValidationVerdict(), $asked));

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['valid' => true, 'errors' => []], $response->getData());
		$this->assertSame(['object' => ['gemeente' => 'Utrecht'], 'register' => 1, 'schema' => 2, 'id' => null], $asked);
	}

	public function testAnInvalidSampleIsAnswered200WithItsErrors(): void {
		$this->permissions->method('hasPermission')->willReturn(true);
		$errors = [['property' => 'gemeente', 'message' => 'Atlantis is not in the list.', 'rule' => 'coded-value']];

		$response = $this->controller(['gemeente' => 'Atlantis'])->validateSample('reg', 'sch', $this->service(new ObjectValidationVerdict($errors)));

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['valid' => false, 'errors' => $errors], $response->getData());
	}

	public function testWithoutCreateTheVerdictIsRefused(): void {
		$this->permissions->method('hasPermission')->willReturn(false);
		$asked = null;

		$response = $this->controller(['gemeente' => 'Utrecht'])->validateSample('reg', 'sch', $this->service(new ObjectValidationVerdict(), $asked));

		$this->assertSame(403, $response->getStatus());
		$this->assertNull($asked, 'no verdict was computed');
	}

	public function testAnUpdateSampleNeedsUpdateOnThatObject(): void {
		$stored = new ObjectEntity();
		$stored->setUuid('u-1');
		$stored->setOwner('alice');
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($this->schema, 'update', null, 'alice', true, $stored)
			->willReturn(true);
		$asked = null;

		$response = $this->controller(['id' => 'u-1', 'wijk' => 'Zuid'], $stored)->validateSample('reg', 'sch', $this->service(new ObjectValidationVerdict(), $asked));

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('u-1', $asked['id']);
		$this->assertSame(['wijk' => 'Zuid'], $asked['object'], 'the id is the target, not part of the sample');
	}

	public function testAnUpdateOfAnObjectTheCallerCannotReadIs404(): void {
		$asked = null;

		$response = $this->controller(['id' => 'missing'], null)->validateSample('reg', 'sch', $this->service(new ObjectValidationVerdict(), $asked));

		$this->assertSame(404, $response->getStatus());
		$this->assertNull($asked);
	}

	public function testTheRouteIsDeclaredBeforeTheObjectWildcardThatWouldSwallowIt(): void {
		$routes = (require dirname(__DIR__, 3).'/appinfo/routes.php')['routes'];
		$index = static fn (string $name, string $url, string $verb): ?int => array_key_first(array_filter(
			$routes,
			static fn (array $route): bool => ($route['name'] ?? '') === $name && ($route['url'] ?? '') === $url && ($route['verb'] ?? '') === $verb
		));

		$validate = $index('objects#validateSample', '/api/objects/{register}/{schema}/validate', 'POST');
		$postPatch = $index('objects#postPatch', '/api/objects/{register}/{schema}/{id}', 'POST');

		$this->assertNotNull($validate);
		$this->assertNotNull($postPatch);
		$this->assertLessThan($postPatch, $validate);
	}
}
