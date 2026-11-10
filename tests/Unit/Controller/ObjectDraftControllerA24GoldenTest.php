<?php

/**
 * Golden outcomes of the draft activation route.
 *
 * Pins, before a restructure, the exact bodies of the 401, 404 and 500
 * answers, that the read decides before the activation, and what an allowed
 * activation receives and answers.
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

use OCA\OpenRegister\Service\Object\UserDraftActivation;
use OCA\OpenRegister\Controller\ObjectDraftController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\DraftActivationRefusedException;
use OCA\OpenRegister\Service\Object\DraftActivation;
use OCA\OpenRegister\Service\Object\DraftAddress;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectDraftControllerFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectDraftController
 */
final class ObjectDraftControllerA24GoldenTest extends TestCase {

	/**
	 * The object read double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The activation double.
	 *
	 * @var DraftActivation&MockObject
	 */
	private DraftActivation&MockObject $activation;

	/**
	 * The register mapper double.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registers;

	/**
	 * The schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemas;

	/**
	 * Build the controller.
	 *
	 * @param IUser|null $user  The signed-in user.
	 * @param mixed      $body  The `object` parameter.
	 *
	 * @return ObjectDraftController The controller.
	 */
	private function controller(?IUser $user, mixed $body = null): ObjectDraftController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ['object' => $body][$key] ?? null);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->objects = $this->createMock(ObjectService::class);
		$this->activation = $this->createMock(DraftActivation::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);

		return ObjectDraftControllerFactory::create(
			appName: 'openregister',
			request: $request,
			objects: $this->objects,
			registers: $this->registers,
			schemas: $this->schemas,
			activation: $this->activation,
			session: $session,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class)
		);
	}//end controller()

	/**
	 * The register, schema and draft resolve.
	 *
	 * @return array<int, mixed> The register and the schema.
	 */
	private function resolves(): array {
		$register = new Register();
		$schema = new Schema();
		$this->registers->method('find')->willReturn($register);
		$this->schemas->method('find')->willReturn($schema);
		$this->objects->method('find')->willReturn(new ObjectEntity());

		return [$register, $schema];
	}//end resolves()

	/**
	 * Nobody signed in: the exact 401 body, nothing looked up.
	 *
	 * @return void
	 */
	public function testNobodySignedIn(): void {
		$controller = $this->controller(user: null);
		$this->registers->expects($this->never())->method('find');
		$response = $controller->activate('reg', 'sch', 'd1');

		$this->assertSame(
			[401, ['message' => 'Please sign in to make this draft definitive.', 'reason' => 'not-signed-in', 'findings' => []]],
			[$response->getStatus(), $response->getData()]
		);
	}//end testNobodySignedIn()

	/**
	 * A draft that is not readable, or not an entity, is the exact 404 and nothing is activated.
	 *
	 * @return void
	 */
	public function testNotReadable(): void {
		$controller = $this->controller(user: $this->createMock(IUser::class));
		$this->registers->expects($this->once())->method('find')->with('reg', false, false)->willReturn(new Register());
		$this->schemas->expects($this->once())->method('find')->with('sch', [], false, false)->willReturn(new Schema());
		$this->objects->expects($this->once())->method('find')
			->with('d1', [], false, $this->isInstanceOf(Register::class), $this->isInstanceOf(Schema::class), true, true, false, false)
			->willReturn(null);
		$this->activation->expects($this->never())->method('activateAsUser');
		$response = $controller->activate('reg', 'sch', 'd1');

		$this->assertSame(
			[404, ['message' => 'This draft does not exist.', 'reason' => 'not-found', 'findings' => []]],
			[$response->getStatus(), $response->getData()]
		);
	}//end testNotReadable()

	/**
	 * A refusal answers its own body; an outage answers the exact 500.
	 *
	 * @return void
	 */
	public function testRefusalAndOutage(): void {
		$controller = $this->controller(user: $this->createMock(IUser::class));
		$this->resolves();
		$refused = new DraftActivationRefusedException(message: 'not yours', reason: DraftActivationRefusedException::NOT_THE_OWNER);
		$this->activation->method('activateAsUser')->willReturnOnConsecutiveCalls(
			$this->throwException($refused),
			$this->throwException(new \RuntimeException('storage'))
		);

		$first = $controller->activate('reg', 'sch', 'd1');
		$second = $controller->activate('reg', 'sch', 'd1');

		$this->assertSame([$refused->getStatus(), $refused->toBody()], [$first->getStatus(), $first->getData()]);
		$this->assertSame(
			[
				500,
				[
					'message' => 'The draft could not be made definitive right now. Nothing was changed; try again later.',
					'reason' => 'unavailable',
					'findings' => [],
				],
			],
			[$second->getStatus(), $second->getData()]
		);
	}//end testRefusalAndOutage()

	/**
	 * An allowed activation receives the body's changes and answers the rendered object.
	 *
	 * @return void
	 */
	public function testAllowed(): void {
		$user = $this->createMock(IUser::class);
		$controller = $this->controller(user: $user, body: ['a' => 1]);
		[$register, $schema] = $this->resolves();
		$activated = new ObjectEntity();
		$this->activation->expects($this->once())->method('activateAsUser')->with(new UserDraftActivation(address: new DraftAddress(register: $register, schema: $schema, draftId: 'd1'), user: $user, changes: ['a' => 1]))->willReturn($activated);
		$this->objects->method('renderEntity')->willReturn(['rendered' => true]);
		$response = $controller->activate('reg', 'sch', 'd1');

		$this->assertSame([200, ['rendered' => true]], [$response->getStatus(), $response->getData()]);
	}//end testAllowed()
}//end class
