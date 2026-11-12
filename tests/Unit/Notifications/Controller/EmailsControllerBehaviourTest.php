<?php

/**
 * Behaviour tests for EmailsController: the refusal and failure branches.
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

use Exception;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Controller\EmailsController;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsControllerServices;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsDomain;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsObjectAccess;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsPlatform;
use OCA\OpenRegister\Notifications\Service\EmailService;
use OCA\OpenRegister\Service\EmailLinkServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Notifications\Service\EmailLinkRequest;

#[CoversClass(EmailsController::class)]
#[UsesClass(EmailsControllerServices::class)]
#[UsesClass(EmailsDomain::class)]
#[UsesClass(EmailsObjectAccess::class)]
#[UsesClass(EmailsPlatform::class)]
#[UsesClass(EmailLink::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Schema::class)]
class EmailsControllerBehaviourTest extends TestCase {

	private EmailService&MockObject $emails;

	private EmailLinkServiceInterface&MockObject $links;

	private ObjectOperationsInterface&MockObject $objects;

	private PermissionHandlerInterface&MockObject $permissions;

	private ObjectEntity $object;

	protected function setUp(): void {
		$this->emails = $this->createMock(EmailService::class);
		$this->links = $this->createMock(EmailLinkServiceInterface::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->object = new ObjectEntity();
		$this->object->setUuid('uuid-1');
		$this->object->setRegister('3');
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('setObject')->willReturnSelf();
		$this->objects->method('getCurrentSchemaEntity')->willReturn(new Schema());
		$this->permissions->method('hasPermission')->willReturn(true);
	}

	private function controller(array $params = []): EmailsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		return new EmailsController(
			$request,
			new EmailsControllerServices(
				new EmailsDomain($this->emails, $this->links),
				new EmailsObjectAccess($this->objects, $this->permissions),
				new EmailsPlatform($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class))
			)
		);
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function mailRoutes(): array {
		return [
			'index'   => ['index', ['reg', 'sch', 'id']],
			'create'  => ['create', ['reg', 'sch', 'id']],
			'destroy' => ['destroy', ['reg', 'sch', 'id', '5']],
			'search'  => ['search', []],
		];
	}

	#[DataProvider('mailRoutes')]
	public function testWithoutMailAppEveryRouteIs501(string $action, array $args): void {
		$this->emails->method('isMailAvailable')->willReturn(false);
		$this->objects->expects($this->never())->method('getObject');
		$this->emails->expects($this->never())->method('searchBySender');
		$response = $this->controller(['sender' => 'a@b.c'])->$action(...$args);
		$this->assertSame(501, $response->getStatus());
		$this->assertSame(['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'], $response->getData());
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function objectRoutes(): array {
		$routes = self::mailRoutes();
		unset($routes['search']);
		return $routes;
	}

	#[DataProvider('objectRoutes')]
	public function testDoesNotExistIs404(string $action, array $args): void {
		$this->emails->method('isMailAvailable')->willReturn(true);
		$this->objects->method('getObject')->willThrowException(new DoesNotExistException('gone'));
		$response = $this->controller()->$action(...$args);
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Object not found'], $response->getData());
	}

	public function testIndexPassesLimitAndOffsetAsIntegers(): void {
		$this->emails->method('isMailAvailable')->willReturn(true);
		$this->objects->method('getObject')->willReturn($this->object);
		$this->emails->expects($this->once())->method('getEmailsForObject')->with('uuid-1', new ListQuery(limit: 10, offset: 20))->willReturn(['results' => []]);
		$response = $this->controller(['limit' => '10', 'offset' => '20'])->index('reg', 'sch', 'id');
		$this->assertSame(['results' => []], $response->getData());
	}

	public function testIndexOtherFailureIs500WithItsMessage(): void {
		$this->emails->method('isMailAvailable')->willReturn(true);
		$this->objects->method('getObject')->willReturn($this->object);
		$this->emails->method('getEmailsForObject')->willThrowException(new Exception('mail db down'));
		$response = $this->controller()->index('reg', 'sch', 'id');
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'mail db down'], $response->getData());
	}

	public function testCreateOnMissingObjectIs404(): void {
		$this->emails->method('isMailAvailable')->willReturn(true);
		$this->objects->method('getObject')->willReturn(null);
		$this->emails->expects($this->never())->method('linkEmail');
		$response = $this->controller(['mailAccountId' => 1, 'mailMessageId' => 2])->create('reg', 'sch', 'id');
		$this->assertSame(404, $response->getStatus());
	}

	public function testCreateLinksWithIntegerIds(): void {
		$this->emails->method('isMailAvailable')->willReturn(true);
		$this->objects->method('getObject')->willReturn($this->object);
		$this->links->expects($this->once())->method('assertAccountOwnership')->with(7);
		$this->emails->expects($this->once())->method('linkEmail')->with(new EmailLinkRequest(objectUuid: 'uuid-1', registerId: 3, mailAccountId: 7, mailMessageId: 42))
			->willReturn(new EmailLink());
		$response = $this->controller(['mailAccountId' => '7', 'mailMessageId' => '42'])->create('reg', 'sch', 'id');
		$this->assertSame(201, $response->getStatus());
	}

	/**
	 * Exception codes from the link step and the status each answers.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function linkFailures(): array {
		return [
			'duplicate' => [409, 409],
			'forbidden' => [403, 403],
			'not found' => [404, 404],
			'other'     => [0, 400],
			'server'    => [500, 400],
		];
	}

	#[DataProvider('linkFailures')]
	public function testCreateFailureMapsTheExceptionCode(int $code, int $status): void {
		$this->emails->method('isMailAvailable')->willReturn(true);
		$this->objects->method('getObject')->willReturn($this->object);
		$this->emails->method('linkEmail')->willThrowException(new Exception('link failed', $code));
		$response = $this->controller(['mailAccountId' => 1, 'mailMessageId' => 2])->create('reg', 'sch', 'id');
		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['error' => 'link failed'], $response->getData());
	}

	/**
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function unlinkFailures(): array {
		return [
			'not found' => [404, 404],
			'other'     => [409, 400],
		];
	}

	#[DataProvider('unlinkFailures')]
	public function testDestroyFailureMapsTheExceptionCode(int $code, int $status): void {
		$this->emails->method('isMailAvailable')->willReturn(true);
		$this->objects->method('getObject')->willReturn($this->object);
		$this->links->expects($this->once())->method('unlinkEmail')->with('uuid-1', 5)
			->willThrowException(new Exception('unlink failed', $code));
		$response = $this->controller()->destroy('reg', 'sch', 'id', '5');
		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['error' => 'unlink failed'], $response->getData());
	}

	public function testSearchFailureIs500WithItsMessage(): void {
		$this->emails->method('isMailAvailable')->willReturn(true);
		$this->emails->expects($this->once())->method('searchBySender')->with('a@b.c')->willThrowException(new Exception('search down'));
		$response = $this->controller(['sender' => 'a@b.c'])->search();
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'search down'], $response->getData());
	}
}
