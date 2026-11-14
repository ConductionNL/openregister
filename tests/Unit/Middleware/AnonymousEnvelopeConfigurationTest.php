<?php

/**
 * AnonymousEnvelopeConfigurationTest — an anonymous answer from a configuration
 * controller goes through the configuration redaction, not the data one.
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

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Middleware\AnonymousEnvelopeMiddleware;
use OCA\OpenRegister\Service\Rbac\AnonymousEnvelopeInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Middleware\AnonymousEnvelopeMiddleware
 */
class AnonymousEnvelopeConfigurationTest extends TestCase {

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
	 * A session with nobody signed in.
	 *
	 * @return IUserSession The session.
	 */
	private function anonymous(): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		return $session;
	}//end anonymous()

	/**
	 * The configuration controllers.
	 *
	 * @return array<string, array{0: class-string<Controller>}> The controllers.
	 */
	public static function configurationControllers(): array {
		return [
			'registers' => [RegistersController::class],
			'schemas'   => [SchemasController::class],
		];
	}//end configurationControllers()

	#[DataProvider('configurationControllers')]
	public function testAnAnonymousConfigurationAnswerIsRedactedAsConfiguration(string $class): void {
		$envelope = $this->createMock(AnonymousEnvelopeInterface::class);
		$envelope->expects($this->once())->method('redactConfiguration')
			->with(['title' => 'Register', 'owner' => 'alice'])
			->willReturn(['title' => 'Register']);
		$envelope->expects($this->never())->method('redact');

		$middleware = new AnonymousEnvelopeMiddleware($this->anonymous(), $envelope);
		$response   = new JSONResponse(['title' => 'Register', 'owner' => 'alice']);

		$returned = $middleware->afterController($this->controller($class), 'show', $response);

		$this->assertSame($response, $returned);
		$this->assertSame(['title' => 'Register'], $returned->getData());
	}//end testAnAnonymousConfigurationAnswerIsRedactedAsConfiguration()

	public function testAnAnonymousDataAnswerIsRedactedAsData(): void {
		$envelope = $this->createMock(AnonymousEnvelopeInterface::class);
		$envelope->expects($this->once())->method('redact')
			->with(['name' => 'x', 'owner' => 'alice'])
			->willReturn(['name' => 'x']);
		$envelope->expects($this->never())->method('redactConfiguration');

		$middleware = new AnonymousEnvelopeMiddleware($this->anonymous(), $envelope);
		$response   = new DataResponse(['name' => 'x', 'owner' => 'alice']);

		$returned = $middleware->afterController($this->controller(ObjectsController::class), 'show', $response);

		$this->assertSame(['name' => 'x'], $returned->getData());
	}//end testAnAnonymousDataAnswerIsRedactedAsData()
}//end class
