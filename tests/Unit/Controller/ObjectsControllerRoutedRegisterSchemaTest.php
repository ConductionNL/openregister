<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\Support\RawRequestBody;
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

	/**
	 * Give the request a JSON body, as the bytes the client sent.
	 *
	 * @param array<string, mixed> $body The body.
	 */
	private function sentJson(array $body): void {
		$file = tempnam(sys_get_temp_dir(), 'or-body');
		file_put_contents($file, json_encode($body));
		$this->request->method('getHeader')->willReturnCallback(static fn (string $name): string => strtolower($name) === 'content-type' ? 'application/json' : '');
		$property = new \ReflectionProperty(ObjectsController::class, 'rawRequestBody');
		$property->setValue($this->controller, new RawRequestBody($file));
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
		$this->sentJson(['name' => 'form', 'register' => 'portaliq', 'schema' => 'portalCase']);
		// The merged parameters, in which the route's register and schema win.
		$body = ['name' => 'form', 'register' => 'buildiq', 'schema' => 'registrationForm', 'uuid' => 'x'];
		$schema = $this->schemaDeclaring(['name' => ['type' => 'string'], 'register' => ['type' => 'string'], 'schema' => ['type' => 'string']]);

		$kept = $this->call('submittableBody', [$body, $schema]);

		$this->assertSame('portaliq', $kept['register'] ?? null);
		$this->assertSame('portalCase', $kept['schema'] ?? null);
		$this->assertArrayNotHasKey('uuid', $kept);
	}

	public function testAnUndeclaredRegisterAndSchemaAreStillDropped(): void {
		$this->request->method('getMethod')->willReturn('POST');
		$this->sentJson(['title' => 'x', 'register' => 'other', 'schema' => 'other']);
		$schema = $this->schemaDeclaring(['title' => ['type' => 'string']]);

		$kept = $this->call('submittableBody', [['title' => 'x', 'register' => 'other', 'schema' => 'other'], $schema]);

		$this->assertSame(['title' => 'x'], $kept);
	}

	public function testARouteValueTheBodyDidNotCarryIsNeverStoredAsData(): void {
		$this->request->method('getMethod')->willReturn('PATCH');
		$this->sentJson(['name' => 'renamed']);
		$schema = $this->schemaDeclaring(['name' => ['type' => 'string'], 'register' => ['type' => 'string']]);

		$kept = $this->call('submittableBody', [['name' => 'renamed', 'register' => 'buildiq'], $schema]);

		$this->assertArrayNotHasKey('register', $kept, 'the merged parameters carried the route register, the body did not');
	}

	public function testTheMergedPostParametersAreNotTakenForTheBody(): void {
		// Live on Nextcloud 34, $request->post answers the merged parameters (the
		// route's register over the body's), so reading it stored the route value.
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->post = ['name' => 'form', 'register' => 'shillinq', 'schema' => 'FeeSchedule'];
		$this->sentJson(['name' => 'form', 'register' => 'dossiq', 'schema' => 'Zaak']);
		$schema = $this->schemaDeclaring(['name' => ['type' => 'string'], 'register' => ['type' => 'string'], 'schema' => ['type' => 'string']]);

		$kept = $this->call('submittableBody', [['name' => 'form', 'register' => 'shillinq', 'schema' => 'FeeSchedule'], $schema]);

		$this->assertSame(['name' => 'form', 'register' => 'dossiq', 'schema' => 'Zaak'], $kept);
	}

	public function testAFormBodyKeepsNoRoutingWord(): void {
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getHeader')->willReturn('multipart/form-data; boundary=x');
		$schema = $this->schemaDeclaring(['register' => ['type' => 'string']]);

		$kept = $this->call('submittableBody', [['register' => 'buildiq'], $schema]);

		$this->assertArrayNotHasKey('register', $kept);
	}
}//end class
