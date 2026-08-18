<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\LinkedEntityController}.
 *
 * Covers the four link-mutation endpoints. The object-level pair
 * (`addObjectLink` / `removeObjectLink`) is `@NoAdminRequired` and maps a
 * write-permission denial to 403; the register- and schema-level adds mutate
 * shared configuration and are therefore admin-gated at the endpoint boundary —
 * a non-administrator must be refused BEFORE the service is reached.
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
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\LinkedEntityController;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\LinkedEntityService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * LinkedEntityControllerTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class LinkedEntityControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Linked entity service mock.
	 *
	 * @var LinkedEntityService&MockObject
	 */
	private LinkedEntityService&MockObject $service;

	/**
	 * Group manager mock (drives the admin gate).
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Controller under test.
	 *
	 * @var LinkedEntityController
	 */
	private LinkedEntityController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(LinkedEntityService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new LinkedEntityController(
			'openregister',
			$this->request,
			$this->service,
			new NullLogger(),
			$userSession,
			$this->groupManager
		);
	}//end setUp()

	public function testAddObjectLinkReturnsTheUpdatedIdsUnderTheTypeKey(): void {
		$this->request->method('getParams')->willReturn(['id' => 'card-7']);

		$this->service->expects($this->once())
			->method('addLink')
			->with('uuid-1', 'deck', 'card-7')
			->willReturn(['card-3', 'card-7']);

		$response = $this->controller->addObjectLink('uuid-1', 'deck');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['card-3', 'card-7'], $response->getData()['_deck']);
	}//end testAddObjectLinkReturnsTheUpdatedIdsUnderTheTypeKey()

	public function testAddObjectLinkRejectsAMissingIdWith400(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->expects($this->never())->method('addLink');

		$response = $this->controller->addObjectLink('uuid-1', 'deck');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Missing required field: id', $response->getData()['error']);
	}//end testAddObjectLinkRejectsAMissingIdWith400()

	public function testAddObjectLinkMapsAWritePermissionDenialTo403(): void {
		$this->request->method('getParams')->willReturn(['id' => 'card-7']);
		$this->service->method('addLink')
			->willThrowException(new NotAuthorizedException('No write permission on this object'));

		$response = $this->controller->addObjectLink('uuid-1', 'deck');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('No write permission on this object', $response->getData()['error']);
	}//end testAddObjectLinkMapsAWritePermissionDenialTo403()

	public function testRemoveObjectLinkReturnsTheRemainingIds(): void {
		$this->service->expects($this->once())
			->method('removeLink')
			->with('uuid-1', 'deck', 'card-7')
			->willReturn(['card-3']);

		$response = $this->controller->removeObjectLink('uuid-1', 'deck', 'card-7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['card-3'], $response->getData()['_deck']);
	}//end testRemoveObjectLinkReturnsTheRemainingIds()

	public function testRemoveObjectLinkMapsAWritePermissionDenialTo403(): void {
		$this->service->method('removeLink')
			->willThrowException(new NotAuthorizedException('No write permission on this object'));

		$response = $this->controller->removeObjectLink('uuid-1', 'deck', 'card-7');

		$this->assertSame(403, $response->getStatus());
	}//end testRemoveObjectLinkMapsAWritePermissionDenialTo403()

	public function testAddRegisterLinkIsRefusedForANonAdministrator(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->service->expects($this->never())->method('addLinkToRegister');

		$response = $this->controller->addRegisterLink('reg-uuid', 'deck');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('Admin privileges required', $response->getData()['error']);
	}//end testAddRegisterLinkIsRefusedForANonAdministrator()

	public function testAddRegisterLinkStoresTheLinkForAnAdministrator(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParams')->willReturn(['id' => 'board-2']);

		$this->service->expects($this->once())
			->method('addLinkToRegister')
			->with('reg-uuid', 'deck', 'board-2')
			->willReturn(['board-2']);

		$response = $this->controller->addRegisterLink('reg-uuid', 'deck');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['board-2'], $response->getData()['_deck']);
	}//end testAddRegisterLinkStoresTheLinkForAnAdministrator()

	public function testAddSchemaLinkIsRefusedForANonAdministrator(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->service->expects($this->never())->method('addLinkToSchema');

		$response = $this->controller->addSchemaLink('schema-uuid', 'contacts');

		$this->assertSame(403, $response->getStatus());
	}//end testAddSchemaLinkIsRefusedForANonAdministrator()

	public function testAddSchemaLinkStoresTheLinkForAnAdministrator(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParams')->willReturn(['id' => 'contact-5']);

		$this->service->expects($this->once())
			->method('addLinkToSchema')
			->with('schema-uuid', 'contacts', 'contact-5')
			->willReturn(['contact-5']);

		$response = $this->controller->addSchemaLink('schema-uuid', 'contacts');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['contact-5'], $response->getData()['_contacts']);
	}//end testAddSchemaLinkStoresTheLinkForAnAdministrator()
}//end class
