<?php

/**
 * Characterisation of FileSidebarController's responses: a file the caller cannot reach is
 * refused with 404 before the sidebar service is asked; a reachable file gets the service's
 * answer; a failing service is a 500 with a fixed message and one logged error.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Files\Controller\FileSidebarController;
use OCA\OpenRegister\Files\Controller\Support\FileSidebarControllerServices;
use OCA\OpenRegister\Files\Service\FileSidebarService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden allowed, refused and failed responses of both sidebar endpoints.
 *
 * @covers \OCA\OpenRegister\Files\Controller\FileSidebarController
 */
final class FileSidebarControllerResponsesGoldenTest extends TestCase {

	/**
	 * The sidebar service.
	 *
	 * @var FileSidebarService&MockObject
	 */
	private FileSidebarService&MockObject $sidebar;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sidebar = $this->createMock(FileSidebarService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The endpoints, with the service method each one asks.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function endpoints(): array {
		return [
			'objects' => ['getObjectsForFile', 'Failed to retrieve objects for file.'],
			'extraction status' => ['getExtractionStatus', 'Failed to retrieve extraction status.'],
		];
	}//end endpoints()

	/**
	 * A file outside the caller's folder is refused with 404, and the service is never asked.
	 *
	 * @param string $method  The endpoint and service method.
	 * @param string $failure The endpoint's failure message.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testUnreachableFileIsRefusedBeforeTheService(string $method, string $failure): void {
		$this->sidebar->expects($this->never())->method($method);
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller(reachable: false)->$method(999);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'File not found or access denied'], $response->getData());
	}//end testUnreachableFileIsRefusedBeforeTheService()

	/**
	 * Without a signed-in user the file is refused with 404 as well.
	 *
	 * @param string $method  The endpoint and service method.
	 * @param string $failure The endpoint's failure message.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testAnonymousCallerIsRefused(string $method, string $failure): void {
		$this->sidebar->expects($this->never())->method($method);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$controller = new FileSidebarController(
			$this->createMock(IRequest::class),
			new FileSidebarControllerServices($this->sidebar, $this->createMock(IRootFolder::class), $session, $this->logger)
		);

		$response = $controller->$method(5);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'File not found or access denied'], $response->getData());
	}//end testAnonymousCallerIsRefused()

	/**
	 * A reachable file gets the service's answer for that file.
	 *
	 * @param string $method  The endpoint and service method.
	 * @param string $failure The endpoint's failure message.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testReachableFileGetsTheServiceAnswer(string $method, string $failure): void {
		$this->sidebar->expects($this->once())->method($method)->with(42)->willReturn(['answer' => $method]);
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller(reachable: true)->$method(42);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'data' => ['answer' => $method]], $response->getData());
	}//end testReachableFileGetsTheServiceAnswer()

	/**
	 * A failing service is a 500 with the endpoint's fixed message, and the cause is logged once.
	 *
	 * @param string $method  The endpoint and service method.
	 * @param string $failure The endpoint's failure message.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testFailingServiceIsA500AndLogged(string $method, string $failure): void {
		$this->sidebar->method($method)->willThrowException(new Exception('disk gone'));
		$logged = [
			'getObjectsForFile' => '[FileSidebarController] Error fetching objects for file 42: disk gone',
			'getExtractionStatus' => '[FileSidebarController] Error fetching extraction status for file 42: disk gone',
		];
		$this->logger->expects($this->once())->method('error')->with($logged[$method]);

		$response = $this->controller(reachable: true)->$method(42);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => $failure], $response->getData());
	}//end testFailingServiceIsA500AndLogged()

	/**
	 * A controller whose caller does or does not reach every file.
	 *
	 * @param bool $reachable Whether the caller's folder resolves the file.
	 *
	 * @return FileSidebarController
	 */
	private function controller(bool $reachable): FileSidebarController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn($reachable === true ? [$this->createMock(Node::class)] : []);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		return new FileSidebarController(
			$this->createMock(IRequest::class),
			new FileSidebarControllerServices($this->sidebar, $root, $session, $this->logger)
		);
	}//end controller()
}//end class
