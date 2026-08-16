<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\ObjectSharingController}.
 *
 * Covers the object-scope read and the grant revocation. Both resolve the
 * target through the RBAC boundary first, and both must answer 404 — never 403
 * — for an object the caller may not read, so the endpoints cannot be used to
 * probe which object ids exist.
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectSharingController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * ObjectSharingControllerTest.
 */
class ObjectSharingControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Object resolver mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Owner-checked sharing write path mock.
	 *
	 * @var ObjectSharingService&MockObject
	 */
	private ObjectSharingService&MockObject $sharing;

	/**
	 * Controller under test.
	 *
	 * @var ObjectSharingController
	 */
	private ObjectSharingController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->sharing = $this->createMock(ObjectSharingService::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();

		$this->controller = new ObjectSharingController(
			'openregister',
			$this->request,
			$this->objectService,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->sharing,
			new NullLogger()
		);
	}//end setUp()

	/**
	 * Make the resolver answer with a real object carrying the given block.
	 *
	 * A real entity rather than a mock: the authorization block is reached
	 * through the Entity magic accessor, which a generated double does not
	 * implement.
	 *
	 * @param array<string,mixed>|null $authorization The stored authorization block.
	 *
	 * @return void
	 */
	private function resolvesToObjectWith(?array $authorization): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setAuthorization($authorization);
		$this->objectService->method('getObject')->willReturn($object);
	}//end resolvesToObjectWith()

	public function testScopeReturnsTheStoredScope(): void {
		$this->resolvesToObjectWith(['scope' => 'organisation', 'read' => ['group:staff']]);

		$response = $this->controller->scope('zaken', 'zaak', 'uuid-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('organisation', $response->getData()['scope']);
	}//end testScopeReturnsTheStoredScope()

	public function testScopeReturnsNullWhenNoScopeIsStored(): void {
		$this->resolvesToObjectWith(null);

		$response = $this->controller->scope('zaken', 'zaak', 'uuid-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertNull($response->getData()['scope']);
	}//end testScopeReturnsNullWhenNoScopeIsStored()

	public function testScopeReturns404ForAnObjectTheCallerMayNotRead(): void {
		$this->objectService->method('getObject')->willReturn(null);

		$response = $this->controller->scope('zaken', 'zaak', 'someone-elses');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Object not found', $response->getData()['message']);
	}//end testScopeReturns404ForAnObjectTheCallerMayNotRead()

	public function testDestroyShareRevokesTheGrantAndReturns204(): void {
		$this->resolvesToObjectWith(['scope' => 'private']);

		$this->sharing->expects($this->once())
			->method('revoke')
			->with($this->isInstanceOf(ObjectEntity::class), 'share-9');

		$response = $this->controller->destroyShare('zaken', 'zaak', 'uuid-1', 'share-9');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame([], $response->getData());
	}//end testDestroyShareRevokesTheGrantAndReturns204()

	public function testDestroyShareReturns403WhenTheCallerMayNotRevoke(): void {
		$this->resolvesToObjectWith(['scope' => 'private']);
		$this->sharing->method('revoke')
			->willThrowException(new NotAuthorizedException('Only the owner may revoke'));

		$response = $this->controller->destroyShare('zaken', 'zaak', 'uuid-1', 'share-9');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Only the owner may revoke', $response->getData()['message']);
	}//end testDestroyShareReturns403WhenTheCallerMayNotRevoke()

	public function testDestroyShareReturns404ForAnObjectTheCallerMayNotRead(): void {
		$this->objectService->method('getObject')->willReturn(null);
		$this->sharing->expects($this->never())->method('revoke');

		$response = $this->controller->destroyShare('zaken', 'zaak', 'someone-elses', 'share-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testDestroyShareReturns404ForAnObjectTheCallerMayNotRead()
}//end class
