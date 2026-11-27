<?php

/**
 * A body field `register` or `schema` no longer moves an object write.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Support\RawRequestBody;
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
 * `urlParams`, and `post` answering the MERGED parameters (the route's over
 * the body's), as Request::getContent() does.
 */
abstract class RoutedRequestDouble implements IRequest {

	/**
	 * The route's parameters.
	 *
	 * @var array<string, mixed>
	 */
	public array $urlParams = [];

	/**
	 * What `post` answers: the merged parameters.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $post = null;
}//end class

/**
 * A body field `register` or `schema` no longer moves an object write.
 *
 * Nextcloud merges a JSON body over the route's parameters, so a body that
 * carried `register` and `schema` (buildiq's form definitions name the
 * register and schema they target, shillinq's fee schedules the case type they
 * price) handed the controller those values, or lost them: the request's
 * post/put/patch accessors answer the merged parameters, in which the route
 * wins. Only the raw JSON body tells the two apart.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
class ObjectsControllerRoutedRegisterSchemaTest extends TestCase {

	/**
	 * The request the controller reads.
	 *
	 * @var RoutedRequestDouble&MockObject
	 */
	private RoutedRequestDouble&MockObject $request;

	/**
	 * The controller under test.
	 *
	 * @var ObjectsController
	 */
	private ObjectsController $controller;

	/**
	 * Build the controller over a routed request double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request    = $this->createMock(originalClassName: RoutedRequestDouble::class);
		$this->controller = ObjectsControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(originalClassName: IAppConfig::class),
			$this->createMock(originalClassName: IAppManager::class),
			$this->createMock(originalClassName: ContainerInterface::class),
			$this->createMock(originalClassName: RegisterMapper::class),
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: AuditTrailMapper::class),
			$this->createMock(originalClassName: ObjectService::class),
			$this->createMock(originalClassName: IUserSession::class),
			$this->createMock(originalClassName: IGroupManager::class),
			$this->createMock(originalClassName: ExportService::class),
			$this->createMock(originalClassName: ImportService::class),
			$this->createMock(originalClassName: IEventDispatcher::class),
			$this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Call a private method of the controller.
	 *
	 * @param string            $method    The method.
	 * @param array<int, mixed> $arguments Its arguments.
	 *
	 * @return mixed What it answers.
	 */
	private function call(string $method, array $arguments): mixed {
		$reflection = new \ReflectionMethod(objectOrMethod: ObjectsController::class, method: $method);
		return $reflection->invokeArgs(object: $this->controller, args: $arguments);
	}//end call()

	/**
	 * Give the request a JSON body, as the bytes the client sent.
	 *
	 * @param array<string, mixed> $body The body.
	 *
	 * @return void
	 */
	private function sentJson(array $body): void {
		$file = tempnam(directory: sys_get_temp_dir(), prefix: 'or-body');
		file_put_contents(filename: $file, data: json_encode(value: $body));
		$this->request->method('getHeader')->willReturnMap([['Content-Type', 'application/json']]);
		$property = new \ReflectionProperty(class: ObjectsController::class, property: 'rawRequestBody');
		$property->setValue(objectOrValue: $this->controller, value: new RawRequestBody(stream: $file));
	}//end sentJson()

	/**
	 * A schema declaring these properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema
	 */
	private function schemaDeclaring(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		return $schema;
	}//end schemaDeclaring()

	/**
	 * The route names the register and schema, whatever the body says.
	 *
	 * @return void
	 */
	public function testTheRouteNamesTheRegisterAndSchemaWhateverTheBodySays(): void {
		$this->request->urlParams = ['register' => 'buildiq', 'schema' => 'registrationForm'];

		$this->assertSame(expected: 'buildiq', actual: $this->call(method: 'routeParameter', arguments: ['register', 'portaliq']));
		$this->assertSame(expected: 'registrationForm', actual: $this->call(method: 'routeParameter', arguments: ['schema', 'portalCase']));
	}//end testTheRouteNamesTheRegisterAndSchemaWhateverTheBodySays()

	/**
	 * Without route parameters the given value stands.
	 *
	 * @return void
	 */
	public function testWithoutRouteParametersTheGivenValueStands(): void {
		$this->request->urlParams = [];

		$this->assertSame(expected: 'portaliq', actual: $this->call(method: 'routeParameter', arguments: ['register', 'portaliq']));
	}//end testWithoutRouteParametersTheGivenValueStands()

	/**
	 * A body's register and schema are kept when the schema declares them,
	 * although the merged parameters carry the route's.
	 *
	 * @return void
	 */
	public function testABodyRegisterAndSchemaAreKeptWhenTheSchemaDeclaresThem(): void {
		$this->request->method('getMethod')->willReturn('POST');
		$this->sentJson(body: ['name' => 'form', 'register' => 'portaliq', 'schema' => 'portalCase']);
		$merged = ['name' => 'form', 'register' => 'buildiq', 'schema' => 'registrationForm', 'uuid' => 'x'];
		$schema = $this->schemaDeclaring(
			properties: ['name' => ['type' => 'string'], 'register' => ['type' => 'string'], 'schema' => ['type' => 'string']]
		);

		$kept = $this->call(method: 'submittableBody', arguments: [$merged, $schema]);

		$this->assertSame(expected: 'portaliq', actual: ($kept['register'] ?? null));
		$this->assertSame(expected: 'portalCase', actual: ($kept['schema'] ?? null));
		$this->assertArrayNotHasKey(key: 'uuid', array: $kept);
	}//end testABodyRegisterAndSchemaAreKeptWhenTheSchemaDeclaresThem()

	/**
	 * An undeclared register and schema are still dropped.
	 *
	 * @return void
	 */
	public function testAnUndeclaredRegisterAndSchemaAreStillDropped(): void {
		$this->request->method('getMethod')->willReturn('POST');
		$this->sentJson(body: ['title' => 'x', 'register' => 'other', 'schema' => 'other']);
		$schema = $this->schemaDeclaring(properties: ['title' => ['type' => 'string']]);

		$kept = $this->call(method: 'submittableBody', arguments: [['title' => 'x', 'register' => 'other', 'schema' => 'other'], $schema]);

		$this->assertSame(expected: ['title' => 'x'], actual: $kept);
	}//end testAnUndeclaredRegisterAndSchemaAreStillDropped()

	/**
	 * A route value the body did not carry is never stored as data.
	 *
	 * @return void
	 */
	public function testARouteValueTheBodyDidNotCarryIsNeverStoredAsData(): void {
		$this->request->method('getMethod')->willReturn('PATCH');
		$this->sentJson(body: ['name' => 'renamed']);
		$schema = $this->schemaDeclaring(properties: ['name' => ['type' => 'string'], 'register' => ['type' => 'string']]);

		$kept = $this->call(method: 'submittableBody', arguments: [['name' => 'renamed', 'register' => 'buildiq'], $schema]);

		$this->assertArrayNotHasKey(key: 'register', array: $kept, message: 'the merged parameters carried the route register, the body did not');
	}//end testARouteValueTheBodyDidNotCarryIsNeverStoredAsData()

	/**
	 * The merged `post` parameters are not taken for the body: live on
	 * Nextcloud 34 that stored a shillinq fee schedule's route register as its data.
	 *
	 * @return void
	 */
	public function testTheMergedPostParametersAreNotTakenForTheBody(): void {
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->post = ['name' => 'form', 'register' => 'shillinq', 'schema' => 'FeeSchedule'];
		$this->sentJson(body: ['name' => 'form', 'register' => 'dossiq', 'schema' => 'Zaak']);
		$schema = $this->schemaDeclaring(
			properties: ['name' => ['type' => 'string'], 'register' => ['type' => 'string'], 'schema' => ['type' => 'string']]
		);

		$kept = $this->call(method: 'submittableBody', arguments: [['name' => 'form', 'register' => 'shillinq', 'schema' => 'FeeSchedule'], $schema]);

		$this->assertSame(expected: ['name' => 'form', 'register' => 'dossiq', 'schema' => 'Zaak'], actual: $kept);
	}//end testTheMergedPostParametersAreNotTakenForTheBody()

	/**
	 * A body that is not JSON keeps no routing word: only JSON carries a typed value.
	 *
	 * @return void
	 */
	public function testAFormBodyKeepsNoRoutingWord(): void {
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getHeader')->willReturn('multipart/form-data; boundary=x');
		$schema = $this->schemaDeclaring(properties: ['register' => ['type' => 'string']]);

		$kept = $this->call(method: 'submittableBody', arguments: [['register' => 'buildiq'], $schema]);

		$this->assertArrayNotHasKey(key: 'register', array: $kept);
	}//end testAFormBodyKeepsNoRoutingWord()
}//end class
