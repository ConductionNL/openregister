<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A request as Nextcloud's own carries it: the route's parameters in
 * `urlParams` and the decoded body under the method's name.
 */
abstract class RoutedRequestDouble implements IRequest {
	/** @var array<string, mixed> */
	public array $urlParams = [];

	/** @var array<string, mixed>|null */
	public ?array $post = null;

	/** @var array<string, mixed>|null */
	public ?array $patch = null;
}

/**
 * A body field `register` or `schema` no longer moves an object write.
 *
 * Nextcloud merges a JSON body over the route's parameters, so a body that
 * carried `register` and `schema` (buildiq's form definitions name the
 * register and schema they target) handed the controller those values: POST
 * /objects/buildiq/registrationForm created the object in the register and
 * schema the body named, and stripped both fields.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
class ObjectsControllerRoutedRegisterSchemaTest extends TestCase {

	private RoutedRequestDouble&MockObject $request;

	private ObjectsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(RoutedRequestDouble::class);
		$this->controller = ObjectsControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ObjectService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function call(string $method, array $arguments): mixed {
		$reflection = new \ReflectionMethod(ObjectsController::class, $method);
		return $reflection->invokeArgs($this->controller, $arguments);
	}

	private function schemaDeclaring(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		return $schema;
	}

	public function testTheRouteNamesTheRegisterAndSchemaWhateverTheBodySays(): void {
		$this->request->urlParams = ['register' => 'buildiq', 'schema' => 'registrationForm'];

		$this->assertSame('buildiq', $this->call('routeParameter', ['register', 'portaliq']));
		$this->assertSame('registrationForm', $this->call('routeParameter', ['schema', 'portalCase']));
	}

	public function testWithoutRouteParametersTheGivenValueStands(): void {
		$this->request->urlParams = [];

		$this->assertSame('portaliq', $this->call('routeParameter', ['register', 'portaliq']));
	}

	public function testABodyRegisterAndSchemaAreKeptWhenTheSchemaDeclaresThem(): void {
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->post = ['name' => 'form', 'register' => 'portaliq', 'schema' => 'portalCase'];
		$body = ['name' => 'form', 'register' => 'portaliq', 'schema' => 'portalCase', 'uuid' => 'x'];
		$schema = $this->schemaDeclaring(['name' => ['type' => 'string'], 'register' => ['type' => 'string'], 'schema' => ['type' => 'string']]);

		$kept = $this->call('submittableBody', [$body, $schema]);

		$this->assertSame('portaliq', $kept['register'] ?? null);
		$this->assertSame('portalCase', $kept['schema'] ?? null);
		$this->assertArrayNotHasKey('uuid', $kept);
	}

	public function testAnUndeclaredRegisterAndSchemaAreStillDropped(): void {
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->post = ['title' => 'x', 'register' => 'other', 'schema' => 'other'];
		$schema = $this->schemaDeclaring(['title' => ['type' => 'string']]);

		$kept = $this->call('submittableBody', [['title' => 'x', 'register' => 'other', 'schema' => 'other'], $schema]);

		$this->assertSame(['title' => 'x'], $kept);
	}

	public function testARouteValueTheBodyDidNotCarryIsNeverStoredAsData(): void {
		$this->request->method('getMethod')->willReturn('PATCH');
		$this->request->patch = ['name' => 'renamed'];
		$schema = $this->schemaDeclaring(['name' => ['type' => 'string'], 'register' => ['type' => 'string']]);

		$kept = $this->call('submittableBody', [['name' => 'renamed', 'register' => 'buildiq'], $schema]);

		$this->assertArrayNotHasKey('register', $kept, 'the merged parameters carried the route register, the body did not');
	}
}//end class
