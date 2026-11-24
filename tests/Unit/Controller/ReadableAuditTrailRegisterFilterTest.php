<?php

/**
 * The scoped audit list can be narrowed to one register by its id.
 *
 * The request reaches the real lister through the real controller, over a
 * trail mock that records the filters the query was run with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/record-history-on-every-detail-page/specs/audit-trail-immutable/spec.md#requirement-the-readable-audit-list-can-be-narrowed-to-one-register
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Support\AuditTrailListing;
use OCA\OpenRegister\Tests\Unit\Controller\Support\AuditTrailControllerFactory;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Tests\Support\BuildsAuditCursors;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests the register filter of the readable audit list.
 */
final class ReadableAuditTrailRegisterFilterTest extends TestCase {
	use BuildsAuditCursors;

	/**
	 * The filters each trail query ran with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queried = [];

	/**
	 * The filters the trail was queried with for one request.
	 *
	 * @param array<string, mixed> $params The query parameters.
	 *
	 * @return array<string, mixed> The filters of the first trail query.
	 */
	private function filtersFor(array $params): array {
		$this->queried = [];
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('findTrail')->willReturnCallback(AuditTrailListing::positional(
			function (?int $limit = null, ?int $offset = null, ?array $filters = []): array {
				$this->queried[] = ($filters ?? []);
				return [];
			}
		));

		$history = $this->createMock(ObjectHistoryForReaders::class);
		$history->method('readerColumns')->willReturnArgument(0);

		$lister = new ReadableAuditTrailLister(
			auditTrailMapper: $mapper,
			objectMapper: $this->createMock(MagicMapper::class),
			history: $history,
			cursors: $this->auditCursors()
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$controller = AuditTrailControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(LogService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(AuditHashService::class),
			$session,
			$this->createMock(IGroupManager::class),
			$lister,
			$this->createMock(AuditTrailPageService::class),
			$this->createMock(ObjectHistoryForReaders::class)
		);

		$this->assertSame(200, $controller->readable()->getStatus());
		$this->assertNotSame([], $this->queried, 'the trail was not queried');

		return $this->queried[0];
	}//end filtersFor()

	/**
	 * A register id narrows the trail query to that register.
	 *
	 * @return void
	 */
	public function testARegisterIdNarrowsTheQuery(): void {
		$filters = $this->filtersFor(params: ['register' => '5', 'action' => 'read', 'limit' => '10']);

		$this->assertSame(5, $filters['register']);
		$this->assertSame('read', $filters['action']);
	}//end testARegisterIdNarrowsTheQuery()

	/**
	 * A register that is not a whole number is ignored, as before.
	 *
	 * @return void
	 */
	public function testARegisterThatIsNotAnIdIsIgnored(): void {
		$this->assertArrayNotHasKey('register', $this->filtersFor(params: ['register' => 'humaniq']));
		$this->assertArrayNotHasKey('register', $this->filtersFor(params: ['register' => '5 OR 1=1']));
		$this->assertArrayNotHasKey('register', $this->filtersFor(params: []));
	}//end testARegisterThatIsNotAnIdIsIgnored()
}//end class
