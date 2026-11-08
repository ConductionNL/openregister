<?php

/**
 * AuditTrailController object-log characterisation tests.
 *
 * Pins what the per-object history route hands its services and answers, for
 * an administrator and for a reader, so the object identity can travel as one
 * value without the outcome moving.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailPageQuery;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\AuditTrailControllerFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of AuditTrailController::objects() for both callers.
 */
class AuditTrailObjectLogsCharacterisationTest extends TestCase {

	private IRequest&MockObject $request;

	private LogService&MockObject $logService;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private ObjectHistoryForReaders&MockObject $history;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn(['limit' => '10', 'page' => '3', 'search' => 'q']);
		$this->logService = $this->createMock(LogService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->history = $this->createMock(ObjectHistoryForReaders::class);
	}

	/**
	 * The controller, with the given caller signed in.
	 *
	 * @param bool $isAdmin Whether the caller administers the instance.
	 *
	 * @return AuditTrailController
	 */
	private function controller(bool $isAdmin): AuditTrailController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('u')->willReturn($isAdmin);

		return AuditTrailControllerFactory::create(
			'openregister',
			$this->request,
			$this->logService,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(AuditHashService::class),
			$this->userSession,
			$this->groupManager,
			$this->createMock(ReadableAuditTrailLister::class),
			new AuditTrailPageService(
				$this->createMock(AuditTrailPageQuery::class),
				$this->logService,
				$this->createMock(IJobList::class)
			),
			$this->history
		);
	}

	public function testAnAdministratorsPageAndCountUseTheSameObjectAndConfig(): void {
		$seen = [];
		$this->logService->expects($this->once())->method('getLogs')->willReturnCallback(
			function (...$args) use (&$seen): array {
				$seen['logs'] = $args;
				return [['id' => 1]];
			}
		);
		$this->logService->expects($this->once())->method('count')->willReturnCallback(
			function (...$args) use (&$seen): int {
				$seen['count'] = $args;
				return 25;
			}
		);
		$this->logService->method('maskExplicitReveal')->willReturnCallback(
			static fn (array $entries): array => array_merge($entries, [['masked' => true]])
		);

		$response = $this->controller(true)->objects('reg', 'sch', 'obj');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['reg', 'sch', 'obj'], array_slice($seen['logs'], 0, 3));
		$this->assertSame($seen['logs'], $seen['count']);
		$this->assertSame(10, $seen['logs'][3]['limit']);
		$this->assertSame(20, $seen['logs'][3]['offset']);
		$this->assertSame('q', $seen['logs'][3]['search']);
		$this->assertSame(
			[
				'results' => [['id' => 1], ['masked' => true]],
				'total' => 25,
				'page' => 3,
				'pages' => 3.0,
				'limit' => 10,
				'offset' => 20,
				'readEventsVisible' => true,
			],
			$response->getData()
		);
	}

	public function testAReaderIsAnsweredThroughTheHistoryWithTheObjectAndParams(): void {
		$this->logService->expects($this->never())->method('getLogs');
		$this->history->expects($this->once())->method('page')->willReturnCallback(
			function (...$args): array {
				$this->assertSame(['reg', 'sch', 'obj'], array_slice($args, 0, 3));
				$this->assertSame(10, $args[3]['limit']);
				$this->assertSame(20, $args[3]['offset']);
				return ['results' => [], 'total' => 0];
			}
		);

		$response = $this->controller(false)->objects('reg', 'sch', 'obj');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [], 'total' => 0], $response->getData());
	}

	public function testAReaderWhoMayNotReadTheObjectGets404(): void {
		$this->history->method('page')->willReturn(null);

		$response = $this->controller(false)->objects('reg', 'sch', 'obj');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Object not found'], $response->getData());
	}
}
