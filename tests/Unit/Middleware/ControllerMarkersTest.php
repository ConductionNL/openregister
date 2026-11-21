<?php

/**
 * ControllerMarkersTest: the two Platform middlewares act for exactly the
 * controllers they acted for before they asked Core's marker interfaces.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaChangelogMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\EnvelopedDefinitionControllerInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SchemaChangeReportingControllerInterface;
use OCA\OpenRegister\Middleware\AnonymousEnvelopeMiddleware;
use OCA\OpenRegister\Middleware\SchemaChangeHeaderMiddleware;
use OCA\OpenRegister\Service\Rbac\AnonymousEnvelopeInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Middleware\AnonymousEnvelopeMiddleware
 * @covers \OCA\OpenRegister\Middleware\SchemaChangeHeaderMiddleware
 * @uses   \OCA\OpenRegister\Db\SchemaChangelog
 */
final class ControllerMarkersTest extends TestCase {

	/**
	 * Every controller class under lib/Controller that implements the marker, by short name.
	 *
	 * @param class-string $marker The marker interface.
	 *
	 * @return array<int, string> The controller short names, sorted.
	 */
	private static function implementers(string $marker): array {
		$found = [];
		foreach (glob(__DIR__.'/../../../lib/Controller/*Controller.php') as $file) {
			$class = 'OCA\\OpenRegister\\Controller\\'.basename($file, '.php');
			if (class_exists($class) === true && is_subclass_of($class, $marker) === true) {
				$found[] = basename($file, '.php');
			}
		}

		sort($found);
		return $found;
	}//end implementers()

	/**
	 * A controller instance built without its collaborators.
	 *
	 * @param class-string<Controller> $class The controller class.
	 *
	 * @return Controller The controller.
	 */
	private function controller(string $class): Controller {
		return (new ReflectionClass($class))->newInstanceWithoutConstructor();
	}//end controller()

	/**
	 * Which redaction the anonymous envelope applied to an answer of the controller.
	 *
	 * @param Controller $controller The controller that answered.
	 *
	 * @return string `configuration` or `data`.
	 */
	private function redactionFor(Controller $controller): string {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$envelope = $this->createMock(AnonymousEnvelopeInterface::class);
		$envelope->method('redactConfiguration')->willReturn(['as' => 'configuration']);
		$envelope->method('redact')->willReturn(['as' => 'data']);

		$response = (new AnonymousEnvelopeMiddleware($session, $envelope))
			->afterController($controller, 'show', new JSONResponse(['owner' => 'alice']));

		return $response->getData()['as'];
	}//end redactionFor()

	/**
	 * Whether the schema change header middleware added its header to the controller's answer.
	 *
	 * @param Controller $controller The controller that answered.
	 * @param string     $method     The action.
	 *
	 * @return bool True when the header was added.
	 */
	private function announcesChange(Controller $controller, string $method): bool {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('melding');
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());
		$entry = new SchemaChangelog();
		$entry->setSchemaId(12);
		$entry->setVersion('2.0.0');
		$entry->setCreated(new DateTime('2026-11-12T09:30:00+00:00'));
		$changelog = $this->createMock(SchemaChangelogMapperInterface::class);
		$changelog->method('findLatestBreakingSince')->willReturn($entry);

		$response = (new SchemaChangeHeaderMiddleware($request, $schemas, $changelog))
			->afterController($controller, $method, new JSONResponse([]));

		return array_key_exists(SchemaChangeHeaderMiddleware::HEADER, $response->getHeaders());
	}//end announcesChange()

	public function testOnlyTheRegisterAndSchemaControllersAreEnvelopedDefinitions(): void {
		$this->assertSame(['RegistersController', 'SchemasController'], self::implementers(EnvelopedDefinitionControllerInterface::class));
	}//end testOnlyTheRegisterAndSchemaControllersAreEnvelopedDefinitions()

	public function testOnlyTheObjectsControllerReportsSchemaChanges(): void {
		$this->assertSame(['ObjectsController'], self::implementers(SchemaChangeReportingControllerInterface::class));
	}//end testOnlyTheObjectsControllerReportsSchemaChanges()

	/**
	 * Each controller and the redaction its anonymous answer gets.
	 *
	 * @return array<string, array{0: class-string<Controller>, 1: string}> The cases.
	 */
	public static function envelopeCases(): array {
		return [
			'registers' => [RegistersController::class, 'configuration'],
			'schemas'   => [SchemasController::class, 'configuration'],
			'objects'   => [ObjectsController::class, 'data'],
		];
	}//end envelopeCases()

	#[DataProvider('envelopeCases')]
	public function testTheEnvelopeRedactsEachControllerAsBefore(string $class, string $expected): void {
		$this->assertSame($expected, $this->redactionFor($this->controller($class)));
	}//end testTheEnvelopeRedactsEachControllerAsBefore()

	public function testAnUnmarkedControllerIsRedactedAsData(): void {
		$this->assertSame('data', $this->redactionFor($this->createMock(Controller::class)));
	}//end testAnUnmarkedControllerIsRedactedAsData()

	/**
	 * Each controller and whether its object reads announce a schema change.
	 *
	 * @return array<string, array{0: class-string<Controller>, 1: bool}> The cases.
	 */
	public static function headerCases(): array {
		return [
			'objects'   => [ObjectsController::class, true],
			'registers' => [RegistersController::class, false],
			'schemas'   => [SchemasController::class, false],
		];
	}//end headerCases()

	#[DataProvider('headerCases')]
	public function testTheHeaderIsAddedForEachControllerAsBefore(string $class, bool $expected): void {
		$controller = $this->controller($class);
		$this->assertSame($expected, $this->announcesChange($controller, 'index'));
		$this->assertSame($expected, $this->announcesChange($controller, 'show'));
		$this->assertFalse($this->announcesChange($controller, 'create'));
	}//end testTheHeaderIsAddedForEachControllerAsBefore()

	public function testAnUnmarkedControllerGetsNoHeader(): void {
		$this->assertFalse($this->announcesChange($this->createMock(Controller::class), 'index'));
	}//end testAnUnmarkedControllerGetsNoHeader()
}//end class
