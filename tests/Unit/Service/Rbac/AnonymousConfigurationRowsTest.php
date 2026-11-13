<?php

/**
 * What an anonymous caller reads of a register or schema row.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Middleware\AnonymousEnvelopeMiddleware;
use OCA\OpenRegister\Service\Rbac\AnonymousEnvelope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\AnonymousEnvelope
 * @covers \OCA\OpenRegister\Middleware\AnonymousEnvelopeMiddleware
 */
class AnonymousConfigurationRowsTest extends TestCase {

	/**
	 * A schema row, with a property named like a withheld field.
	 *
	 * @return array<string, mixed>
	 */
	private static function schemaRow(): array {
		return [
			'id'            => 2,
			'title'         => 'Case',
			'owner'         => 'alice',
			'authorization' => ['read' => ['public', 'user:alice']],
			'properties'    => ['owner' => ['type' => 'string']],
		];
	}//end schemaRow()

	/**
	 * The data of a JSON answer.
	 *
	 * @param mixed $response The answer.
	 *
	 * @return JSONResponse The answer as JSON.
	 */
	private function answer(mixed $response): JSONResponse {
		$this->assertInstanceOf(JSONResponse::class, $response);

		return $response;
	}//end answer()

	public function testAListOfRegistersLosesOwnerAndAuthorizationAtEveryRow(): void {
		$redacted = (new AnonymousEnvelope())->redactConfiguration(
			data: [
				'results' => [
					[
						'id'            => 1,
						'owner'         => 'alice',
						'authorization' => ['read' => ['user:alice']],
						'schemas'       => [self::schemaRow(), 7],
					],
				],
			]
		);

		$this->assertSame(
			[
				'results' => [
					[
						'id'      => 1,
						'schemas' => [
							['id' => 2, 'title' => 'Case', 'properties' => ['owner' => ['type' => 'string']]],
							7,
						],
					],
				],
			],
			$redacted
		);
	}//end testAListOfRegistersLosesOwnerAndAuthorizationAtEveryRow()

	public function testOneSchemaLosesOwnerAndAuthorizationButKeepsItsProperties(): void {
		$redacted = (new AnonymousEnvelope())->redactConfiguration(data: self::schemaRow());

		$this->assertSame(
			['id' => 2, 'title' => 'Case', 'properties' => ['owner' => ['type' => 'string']]],
			$redacted
		);
	}//end testOneSchemaLosesOwnerAndAuthorizationButKeepsItsProperties()

	public function testAnExtendedRegisterOrSchemaInAnEnvelopeLosesItsAuthorization(): void {
		$redacted = (new AnonymousEnvelope())->redact(
			data: [
				'@self' => [
					'register' => ['id' => 1, 'owner' => 'alice', 'authorization' => ['read' => ['user:alice']]],
					'schema'   => ['id' => 2, 'owner' => 'alice', 'authorization' => ['read' => ['user:alice']]],
				],
			]
		);

		$this->assertSame(['@self' => ['register' => ['id' => 1], 'schema' => ['id' => 2]]], $redacted);
	}//end testAnExtendedRegisterOrSchemaInAnEnvelopeLosesItsAuthorization()

	/**
	 * @return array<string, array{0: class-string<Controller>}>
	 */
	public static function configurationControllers(): array {
		return [
			'registers' => [RegistersController::class],
			'schemas'   => [SchemasController::class],
		];
	}//end configurationControllers()

	/**
	 * @dataProvider configurationControllers
	 *
	 * @param class-string<Controller> $class The controller that answered.
	 */
	public function testTheMiddlewareRedactsAnAnonymousConfigurationAnswer(string $class): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$middleware = new AnonymousEnvelopeMiddleware($session, new AnonymousEnvelope());

		$response = $this->answer(response: $middleware->afterController(
			$this->createMock($class),
			'index',
			new JSONResponse(['results' => [self::schemaRow()]])
		));

		$this->assertSame(
			['results' => [['id' => 2, 'title' => 'Case', 'properties' => ['owner' => ['type' => 'string']]]]],
			$response->getData()
		);
	}//end testTheMiddlewareRedactsAnAnonymousConfigurationAnswer()

	public function testASignedInConfigurationAnswerIsNotRewritten(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$middleware = new AnonymousEnvelopeMiddleware($session, new AnonymousEnvelope());

		$response = $this->answer(response: $middleware->afterController(
			$this->createMock(SchemasController::class),
			'show',
			new JSONResponse(self::schemaRow())
		));

		$this->assertSame(self::schemaRow(), $response->getData());
	}//end testASignedInConfigurationAnswerIsNotRewritten()

	public function testAnotherControllersAnswerKeepsItsBodyOwner(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$middleware = new AnonymousEnvelopeMiddleware($session, new AnonymousEnvelope());

		$response = $this->answer(response: $middleware->afterController(
			$this->createMock(Controller::class),
			'index',
			new JSONResponse(['results' => [['title' => 'x', 'owner' => 'a body property']]])
		));

		$this->assertSame(['results' => [['title' => 'x', 'owner' => 'a body property']]], $response->getData());
	}//end testAnotherControllersAnswerKeepsItsBodyOwner()
}//end class
