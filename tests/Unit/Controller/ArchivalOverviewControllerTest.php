<?php

/**
 * The archival overview is for archivists and admins only.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/archival-overview-page/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\ArchivalOverviewController;
use OCA\OpenRegister\Service\Archival\ArchivalOverviewService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ArchivalOverviewControllerTest extends TestCase {

	private ArchivalOverviewService&MockObject $overview;

	private IUserSession&MockObject $session;

	private IGroupManager&MockObject $groups;

	private ArchivalOverviewController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->overview = $this->createMock(ArchivalOverviewService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);

		$this->controller = new ArchivalOverviewController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->overview,
			$this->session,
			$this->groups
		);
	}

	private function signIn(bool $archivist, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sanne');
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isInGroup')->with('sanne', 'archivaris')->willReturn($archivist);
		$this->groups->method('isAdmin')->with('sanne')->willReturn($admin);
	}

	public function testAnArchivistGetsTheOverview(): void {
		$this->signIn(archivist: true, admin: false);
		$this->overview->expects($this->once())->method('overview')->willReturn(['configured' => true]);

		$response = $this->controller->overview();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['configured' => true], $response->getData());
	}

	public function testAnAdminGetsTheOverview(): void {
		$this->signIn(archivist: false, admin: true);
		$this->overview->method('overview')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller->overview()->getStatus());
	}

	public function testANonArchivistIsRefused(): void {
		$this->signIn(archivist: false, admin: false);
		$this->overview->expects($this->never())->method('overview');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->overview()->getStatus());
	}

	public function testAnAnonymousCallerIsRefused(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->overview->expects($this->never())->method('overview');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->overview()->getStatus());
	}

	public function testDrawingUpAnswers201WithTheList(): void {
		$this->signIn(archivist: true, admin: false);
		$this->overview->method('drawUpList')->willReturn(['list' => ['uuid' => 'l-9', 'status' => 'in_review', 'objects' => [[], []]], 'refused' => []]);

		$response = $this->controller->drawUp();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['uuid' => 'l-9', 'status' => 'in_review', 'entryCount' => 2, 'refused' => []], $response->getData());
	}

	public function testNothingEligibleIsA422(): void {
		$this->signIn(archivist: true, admin: false);
		$this->overview->method('drawUpList')->willReturn(['list' => null, 'refused' => []]);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->drawUp()->getStatus());
	}

	public function testDrawingUpWithoutAListRegisterIsA409(): void {
		$this->signIn(archivist: false, admin: true);
		$this->overview->method('drawUpList')->willThrowException(new InvalidArgumentException('No destruction list register'));

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller->drawUp()->getStatus());
	}

	public function testANonArchivistCannotDrawUp(): void {
		$this->signIn(archivist: false, admin: false);
		$this->overview->expects($this->never())->method('drawUpList');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->drawUp()->getStatus());
	}
}
