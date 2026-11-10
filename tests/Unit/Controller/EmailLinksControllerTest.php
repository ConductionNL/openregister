<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\EmailLinksController}.
 *
 * Covers the two Mail picker sources — the session-scoped account list and the
 * per-account mailbox list — including the graceful 501 both endpoints return
 * when the Nextcloud Mail app is not installed, and the exception→status
 * mapping on the mailbox lookup.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\EmailLinksController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\EmailLinksControllerFactory;
use OCA\OpenRegister\Tests\Unit\Service\Object\PermissionAskArgs;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * EmailLinksControllerTest.
 */
class EmailLinksControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Email link service mock.
	 *
	 * @var EmailLinkService&MockObject
	 */
	private EmailLinkService&MockObject $service;

	/**
	 * Controller under test.
	 *
	 * @var EmailLinksController
	 */
	private EmailLinksController $controller;

	/**
	 * Object service mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * What the permission handler answers for `update`.
	 *
	 * @var boolean
	 */
	private bool $mayUpdate = true;

	/**
	 * The actions the permission handler was asked about.
	 *
	 * @var array<int, string>
	 */
	private array $askedActions = [];

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(EmailLinkService::class);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn(new Schema());
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$this->objectService->method('getObject')->willReturn($object);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(PermissionAskArgs::spread(
			function (Schema $schema, string $action): bool {
				$this->askedActions[] = $action;
				return $action === 'update' ? $this->mayUpdate : true;
			}
		));

		$this->controller = EmailLinksControllerFactory::create(
			'openregister',
			$this->request,
			$this->service,
			$this->objectService,
			$permissions
		);
	}//end setUp()

	public function testLinkNeedsTheObjectUpdateRight(): void {
		$this->service->method('isMailAvailable')->willReturn(true);
		$this->service->expects($this->never())->method('linkEmail');
		$this->mayUpdate = false;

		$this->assertSame(403, $this->controller->link('reg', 'sch', 'uuid-1')->getStatus());
		$this->assertSame(['update'], $this->askedActions, 'the right asked is update, not read');
	}//end testLinkNeedsTheObjectUpdateRight()

	public function testDestroyNeedsTheObjectUpdateRight(): void {
		$this->service->expects($this->never())->method('unlinkEmail');
		$this->mayUpdate = false;

		$this->assertSame(403, $this->controller->destroy('reg', 'sch', 'uuid-1', '7')->getStatus());
		$this->assertSame(['update'], $this->askedActions, 'the right asked is update, not read');
	}//end testDestroyNeedsTheObjectUpdateRight()

	public function testDestroyWithTheUpdateRightRemovesTheLink(): void {
		$this->service->expects($this->once())->method('unlinkEmail')->with('uuid-1', 7);

		$this->assertSame(200, $this->controller->destroy('reg', 'sch', 'uuid-1', '7')->getStatus());
	}//end testDestroyWithTheUpdateRightRemovesTheLink()

	public function testAccountsReturnsTheSessionScopedAccountList(): void {
		$this->service->method('isMailAvailable')->willReturn(true);
		$this->service->expects($this->once())
			->method('getAvailableAccounts')
			->willReturn(
				[
					['id' => 1, 'email' => 'alice@example.org'],
					['id' => 2, 'email' => 'alice@work.example'],
				]
			);

		$response = $this->controller->accounts();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['total']);
		$this->assertSame('alice@example.org', $response->getData()['results'][0]['email']);
	}//end testAccountsReturnsTheSessionScopedAccountList()

	public function testAccountsReturns501WhenMailIsNotInstalled(): void {
		$this->service->method('isMailAvailable')->willReturn(false);
		$this->service->expects($this->never())->method('getAvailableAccounts');

		$response = $this->controller->accounts();

		$this->assertSame(501, $response->getStatus());
		$this->assertSame('APP_NOT_AVAILABLE', $response->getData()['code']);
	}//end testAccountsReturns501WhenMailIsNotInstalled()

	public function testMailboxesReturnsTheMailboxesForTheNumericAccountId(): void {
		$this->service->method('isMailAvailable')->willReturn(true);
		$this->service->expects($this->once())
			->method('getMailboxesForAccount')
			->with(7)
			->willReturn([['databaseId' => 11, 'name' => 'INBOX']]);

		$response = $this->controller->mailboxes('7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
		$this->assertSame('INBOX', $response->getData()['results'][0]['name']);
	}//end testMailboxesReturnsTheMailboxesForTheNumericAccountId()

	public function testMailboxesReturns501WhenMailIsNotInstalled(): void {
		$this->service->method('isMailAvailable')->willReturn(false);
		$this->service->expects($this->never())->method('getMailboxesForAccount');

		$response = $this->controller->mailboxes('7');

		$this->assertSame(501, $response->getStatus());
	}//end testMailboxesReturns501WhenMailIsNotInstalled()

	public function testMailboxesMapsAServiceExceptionCodeToTheHttpStatus(): void {
		$this->service->method('isMailAvailable')->willReturn(true);
		$this->service->method('getMailboxesForAccount')
			->willThrowException(new Exception('Account not found', 404));

		$response = $this->controller->mailboxes('99');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('Account not found', $response->getData()['error']);
	}//end testMailboxesMapsAServiceExceptionCodeToTheHttpStatus()
}//end class
