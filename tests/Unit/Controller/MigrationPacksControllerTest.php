<?php

/**
 * MigrationPacksControllerTest
 *
 * Unit tests for `MigrationPacksController`: anonymous callers are rejected
 * everywhere; reads (`index`/`show`) succeed for any authenticated user;
 * mutations (`create`/`update`/`destroy`) are admin-gated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\MigrationPacksController;
use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Service\MigrationPackService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MigrationPacksControllerTest extends TestCase {
	private MigrationPacksController $controller;
	private MigrationPackService&MockObject $service;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private IRequest&MockObject $request;

	/** @var IUser|null */
	private ?IUser $currentUser = null;

	/** @var bool */
	private bool $currentUserIsAdmin = false;

	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(MigrationPackService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn([]);

		// A single stubbed indirection instead of re-stubbing method()
		// twice on the same mock (PHPUnit 10 quirk: the second stub does
		// not override the first) — see ScheduledReportsControllerTest for
		// the established precedent.
		$this->userSession->method('getUser')->willReturnCallback(fn () => $this->currentUser);
		$this->groupManager->method('isAdmin')->willReturnCallback(fn () => $this->currentUserIsAdmin);

		$this->controller = new MigrationPacksController(
			'openregister',
			$this->request,
			$this->service,
			$this->userSession,
			$this->groupManager,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function setupUser(?string $uid, bool $isAdmin = false): void {
		if ($uid === null) {
			$this->currentUser = null;
			$this->currentUserIsAdmin = false;
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->currentUser = $user;
		$this->currentUserIsAdmin = $isAdmin;
	}

	public function testIndexRequiresAuthentication(): void {
		$this->setupUser(null);
		$response = $this->controller->index();
		$this->assertSame(401, $response->getStatus());
	}

	public function testIndexSucceedsForAnyAuthenticatedUser(): void {
		$this->setupUser('alice', false);
		$this->service->method('findAll')->willReturn([]);

		$response = $this->controller->index();
		$this->assertSame(200, $response->getStatus());
	}

	public function testShowRequiresAuthentication(): void {
		$this->setupUser(null);
		$response = $this->controller->show(1);
		$this->assertSame(401, $response->getStatus());
	}

	public function testShowSucceedsForAnyAuthenticatedUser(): void {
		$this->setupUser('alice', false);
		$pack = new MigrationPack();
		$pack->setPackSlug('a-pack');
		$this->service->method('find')->willReturn($pack);

		$response = $this->controller->show(1);
		$this->assertSame(200, $response->getStatus());
	}

	public function testShowReturns404WhenMissing(): void {
		$this->setupUser('alice', false);
		$this->service->method('find')->willThrowException(new DoesNotExistException('not found'));

		$response = $this->controller->show(999);
		$this->assertSame(404, $response->getStatus());
	}

	public function testCreateRequiresAuthentication(): void {
		$this->setupUser(null);
		$response = $this->controller->create();
		$this->assertSame(401, $response->getStatus());
	}

	public function testCreateRejectsNonAdmin(): void {
		$this->setupUser('alice', false);
		$response = $this->controller->create();
		$this->assertSame(403, $response->getStatus());
	}

	public function testCreateSucceedsForAdmin(): void {
		$this->setupUser('admin', true);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['id' => 'a-pack', 'name' => 'A Pack']);

		$controller = new MigrationPacksController(
			'openregister',
			$request,
			$this->service,
			$this->userSession,
			$this->groupManager,
			$this->createMock(LoggerInterface::class)
		);

		$pack = new MigrationPack();
		$pack->setPackSlug('a-pack');
		$this->service->expects($this->once())->method('create')->willReturn($pack);

		$response = $controller->create();
		$this->assertSame(201, $response->getStatus());
	}

	public function testCreateReturns422OnInvalidArgument(): void {
		$this->setupUser('admin', true);
		$this->service->method('create')->willThrowException(new InvalidArgumentException('bad pack'));

		$response = $this->controller->create();
		$this->assertSame(422, $response->getStatus());
	}

	public function testUpdateRejectsNonAdmin(): void {
		$this->setupUser('alice', false);
		$response = $this->controller->update(1);
		$this->assertSame(403, $response->getStatus());
	}

	public function testUpdateSucceedsForAdmin(): void {
		$this->setupUser('admin', true);
		$pack = new MigrationPack();
		$pack->setPackSlug('a-pack');
		$this->service->expects($this->once())->method('update')->willReturn($pack);

		$response = $this->controller->update(1);
		$this->assertSame(200, $response->getStatus());
	}

	public function testDestroyRejectsNonAdmin(): void {
		$this->setupUser('alice', false);
		$response = $this->controller->destroy(1);
		$this->assertSame(403, $response->getStatus());
	}

	public function testDestroySucceedsForAdmin(): void {
		$this->setupUser('admin', true);
		$this->service->expects($this->once())->method('delete');

		$response = $this->controller->destroy(1);
		$this->assertSame(204, $response->getStatus());
	}

	public function testImportRejectsNonAdmin(): void {
		$this->setupUser('alice', false);
		$response = $this->controller->import();
		$this->assertSame(403, $response->getStatus());
	}
}
