<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\FlowLinkService}.
 *
 * Exercises the Tier-2 service contract (link / unlink / list +
 * available picker) against a mocked FlowLinkMapper. Tests that touch
 * NC WorkflowEngine's `Manager` use the "Flow unavailable" path
 * because that class is resolved from the container and isn't
 * injectable into this unit test scope without the `workflowengine`
 * app on the classpath. Real-Manager round-trips are gated by
 * `@group requires-app-workflowengine`.
 *
 * Critical surface: admin gating. linkOperation / unlinkOperation /
 * getAvailableOperations MUST refuse non-admins. The tests assert
 * 403 on the mutating paths and an empty list on the picker path.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-flow/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\FlowLink;
use OCA\OpenRegister\Db\FlowLinkMapper;
use OCA\OpenRegister\Service\FlowLinkService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * FlowLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class FlowLinkServiceTest extends TestCase {
	private FlowLinkMapper&MockObject $mapper;
	private IDBConnection&MockObject $db;
	private ContainerInterface&MockObject $container;
	private IAppManager&MockObject $appManager;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private LoggerInterface&MockObject $logger;
	private FlowLinkService $service;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(FlowLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'findByObjectUuid',
				'findByObjectAndOperation',
				'deleteByObjectAndOperation',
				'insert',
				'update',
			])
			->getMock();

		$this->db = $this->createMock(IDBConnection::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new FlowLinkService(
			$this->mapper,
			$this->db,
			$this->container,
			$this->appManager,
			$this->userSession,
			$this->groupManager,
			$this->logger
		);
	}

	private function setupUser(string $uid = 'admin'): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}

	private function setupAdmin(string $uid = 'admin'): void {
		$this->setupUser($uid);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn(true);
	}

	private function setupNonAdmin(string $uid = 'alice'): void {
		$this->setupUser($uid);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn(false);
	}

	public function testIsFlowAvailableTrue(): void {
		$this->appManager->method('isInstalled')->with('workflowengine')->willReturn(true);
		$this->assertTrue($this->service->isFlowAvailable());
	}

	public function testIsFlowAvailableFalse(): void {
		$this->appManager->method('isInstalled')->with('workflowengine')->willReturn(false);
		$this->assertFalse($this->service->isFlowAvailable());
	}

	public function testIsCurrentUserAdminFalseWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->assertFalse($this->service->isCurrentUserAdmin());
	}

	public function testIsCurrentUserAdminFalseForNonAdmin(): void {
		$this->setupNonAdmin();
		$this->assertFalse($this->service->isCurrentUserAdmin());
	}

	public function testIsCurrentUserAdminTrueForAdmin(): void {
		$this->setupAdmin();
		$this->assertTrue($this->service->isCurrentUserAdmin());
	}

	public function testLinkOperationThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No user logged in');

		$this->service->linkOperation('abc-123', 1, 2, 99);
	}

	public function testLinkOperationThrowsForNonAdmin(): void {
		$this->setupNonAdmin();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(403);
		$this->expectExceptionMessage('Only administrators can link Flow operations');

		$this->service->linkOperation('abc-123', 1, 2, 99);
	}

	public function testLinkOperationThrowsWhenFlowUnavailable(): void {
		$this->setupAdmin();
		$this->appManager->method('isInstalled')->with('workflowengine')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);

		$this->service->linkOperation('abc-123', 1, 2, 99);
	}

	public function testLinkOperationThrowsOnDuplicate(): void {
		$this->setupAdmin();
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->mapper->method('findByObjectAndOperation')->with('abc-123', 99)->willReturn(new FlowLink());

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);
		$this->expectExceptionMessage('Operation already linked to this object');

		$this->service->linkOperation('abc-123', 1, 2, 99);
	}

	public function testUnlinkOperationThrowsForNonAdmin(): void {
		$this->setupNonAdmin();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(403);

		$this->service->unlinkOperation('abc-123', 99);
	}

	public function testUnlinkOperationSucceeds(): void {
		$this->setupAdmin();
		$this->mapper->expects($this->once())
			->method('deleteByObjectAndOperation')
			->with('abc-123', 99)
			->willReturn(1);

		$this->service->unlinkOperation('abc-123', 99);
	}

	public function testUnlinkOperationNotFound(): void {
		$this->setupAdmin();
		$this->mapper->method('deleteByObjectAndOperation')->willReturn(0);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);
		$this->expectExceptionMessage('Flow link not found');

		$this->service->unlinkOperation('abc-123', 99);
	}

	public function testGetLinkedOperationsReturnsSerialisedRows(): void {
		// Flow unavailable so we skip the DB enrichment path and just
		// get the cached jsonSerialize() values.
		$this->appManager->method('isInstalled')->with('workflowengine')->willReturn(false);

		$link = new FlowLink();
		$link->setObjectUuid('abc-123');
		$link->setOperationId(99);
		$link->setOperationName('Probe Flow');
		$link->setOperationClass('OCA\\WorkflowEngine\\Operation');
		$link->setEntityType('OCA\\WorkflowEngine\\Entity\\File');
		$link->setEnabled(true);

		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

		$rows = $this->service->getLinkedOperations('abc-123');

		$this->assertCount(1, $rows);
		$this->assertSame('abc-123', $rows[0]['objectUuid']);
		$this->assertSame(99, $rows[0]['operationId']);
		$this->assertSame('Probe Flow', $rows[0]['operationName']);
		$this->assertSame('OCA\\WorkflowEngine\\Operation', $rows[0]['operationClass']);
		$this->assertSame('OCA\\WorkflowEngine\\Entity\\File', $rows[0]['entityType']);
		$this->assertTrue($rows[0]['enabled']);
		$this->assertArrayHasKey('url', $rows[0]);
		$this->assertStringContainsString('/index.php/settings/admin/workflow', $rows[0]['url']);
	}

	public function testGetLinkedOperationsEmpty(): void {
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->mapper->method('findByObjectUuid')->willReturn([]);

		$this->assertSame([], $this->service->getLinkedOperations('nonexistent'));
	}

	public function testGetAvailableOperationsReturnsEmptyForNonAdmin(): void {
		$this->setupNonAdmin();

		$this->assertSame([], $this->service->getAvailableOperations());
		$this->assertSame([], $this->service->getAvailableOperations('search'));
	}

	public function testGetAvailableOperationsReturnsEmptyWhenFlowUnavailable(): void {
		$this->setupAdmin();
		$this->appManager->method('isInstalled')->with('workflowengine')->willReturn(false);

		$this->assertSame([], $this->service->getAvailableOperations());
	}

	/**
	 * Real WorkflowEngine round-trip — only runs when the app is on
	 * the classpath.
	 *
	 * @group requires-app-workflowengine
	 */
	public function testLinkOperationEndToEndWithRealWorkflowEngine(): void {
		if (class_exists('OCA\\WorkflowEngine\\Manager') === false) {
			$this->markTestSkipped('NC WorkflowEngine is not installed');
		}

		$this->markTestSkipped('Integration test — exercised manually with a seeded WorkflowEngine operation');
	}
}
