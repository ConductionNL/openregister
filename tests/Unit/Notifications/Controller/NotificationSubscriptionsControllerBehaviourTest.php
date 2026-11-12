<?php

/**
 * Behaviour tests for NotificationSubscriptionsController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed fixture properties are self-describing.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Controller\NotificationSubscriptionsController;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationSubscriptionsControllerServices;
use OCA\OpenRegister\Notifications\Db\NotificationSubscription;
use OCA\OpenRegister\Notifications\Db\NotificationSubscriptionMapper;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use OCA\OpenRegister\Notifications\Db\SubscriptionKey;

#[CoversClass(NotificationSubscriptionsController::class)]
#[UsesClass(Register::class)]
#[UsesClass(Schema::class)]
#[UsesClass(NotificationSubscriptionsControllerServices::class)]
#[UsesClass(NotificationSubscription::class)]
class NotificationSubscriptionsControllerBehaviourTest extends TestCase {

	private NotificationSubscriptionMapper&MockObject $mapper;

	private RegisterMapperInterface&MockObject $registers;

	private SchemaMapperInterface&MockObject $schemas;

	private bool $signedIn = true;

	protected function setUp(): void {
		$this->mapper = $this->createMock(NotificationSubscriptionMapper::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
	}

	private function controller(array $params): NotificationSubscriptionsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($this->signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);
		return new NotificationSubscriptionsController(
			$request,
			new NotificationSubscriptionsControllerServices($this->mapper, $this->registers, $this->schemas, $session)
		);
	}

	private static function subscription(?int $registerId, ?int $schemaId): NotificationSubscription {
		$row = new NotificationSubscription();
		$row->setUserId('alice');
		$row->setRegisterId($registerId);
		$row->setSchemaId($schemaId);
		return $row;
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function actions(): array {
		return ['index' => ['index'], 'create' => ['create'], 'destroy' => ['destroy']];
	}

	#[DataProvider('actions')]
	public function testAnonymousCallerIs401(string $action): void {
		$this->signedIn = false;
		$this->mapper->expects($this->never())->method($this->anything());
		$response = $this->controller(['registerId' => '1'])->$action();
		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}

	public function testIndexListsTheCallersSubscriptions(): void {
		$this->mapper->expects($this->once())->method('findByUser')->with('alice')
			->willReturn([self::subscription(1, null), self::subscription(null, 2)]);
		$data = $this->controller([])->index()->getData();
		$this->assertSame(2, $data['total']);
		$this->assertSame([1, null], array_column($data['results'], 'registerId'));
		$this->assertSame([null, 2], array_column($data['results'], 'schemaId'));
	}

	public function testCreateWithUnreachableRegisterIs404(): void {
		$this->registers->expects($this->once())->method('find')->with(404)->willThrowException(new RuntimeException('gone'));
		$this->schemas->expects($this->never())->method('find');
		$this->mapper->expects($this->never())->method('subscribeTo');
		$response = $this->controller(['registerId' => '404', 'schemaId' => '2'])->create();
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Register not found or not accessible'], $response->getData());
	}

	public function testCreateWithUnreachableSchemaIs404(): void {
		$this->registers->method('find')->willReturn(new Register());
		$this->schemas->expects($this->once())->method('find')->with(404)->willThrowException(new RuntimeException('gone'));
		$this->mapper->expects($this->never())->method('subscribeTo');
		$response = $this->controller(['registerId' => 1, 'schemaId' => '404'])->create();
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Schema not found or not accessible'], $response->getData());
	}

	public function testCreateSubscribesAndAnswers201(): void {
		$this->registers->expects($this->once())->method('find')->with(1)->willReturn(new Register());
		$this->schemas->expects($this->once())->method('find')->with(2)->willReturn(new Schema());
		$this->mapper->expects($this->once())->method('subscribeTo')->with(new SubscriptionKey(userId: 'alice', registerId: 1, schemaId: 2))->willReturn(self::subscription(1, 2));
		$response = $this->controller(['registerId' => '1', 'schemaId' => 2])->create();
		$this->assertSame(201, $response->getStatus());
		$this->assertSame(1, $response->getData()['registerId']);
		$this->assertSame(2, $response->getData()['schemaId']);
	}

	public function testCreateIgnoresNonNumericAndEmptyIdsAndSkipsLookups(): void {
		$this->registers->expects($this->never())->method('find');
		$this->schemas->expects($this->never())->method('find');
		$this->mapper->expects($this->once())->method('subscribeTo')->with(new SubscriptionKey(userId: 'alice', registerId: null, schemaId: null))
			->willThrowException(new InvalidArgumentException('needs a scope'));
		$response = $this->controller(['registerId' => 'abc', 'schemaId' => ''])->create();
		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'needs a scope'], $response->getData());
	}

	public function testDestroyUnsubscribesWithCoercedIds(): void {
		$this->mapper->expects($this->once())->method('unsubscribeFrom')->with(new SubscriptionKey(userId: 'alice', registerId: null, schemaId: 7))->willReturn(false);
		$response = $this->controller(['registerId' => '', 'schemaId' => '7'])->destroy();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['deleted' => false], $response->getData());
	}
}
