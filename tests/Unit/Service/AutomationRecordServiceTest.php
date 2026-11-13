<?php

/**
 * Unit tests for AutomationRecordService and AutomationRecordsController: who sees which records.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Controller\AutomationRecordsController;
use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Db\AutomationRecordFilter;
use OCA\OpenRegister\Db\AutomationRecordMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\AutomationRecordService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An admin sees all; anyone else only records of objects they may read; nobody signed in sees nothing.
 *
 * @covers \OCA\OpenRegister\Service\AutomationRecordService
 * @covers \OCA\OpenRegister\Controller\AutomationRecordsController
 * @uses   \OCA\OpenRegister\Db\AutomationRecord
 * @uses   \OCA\OpenRegister\Db\AutomationRecordFilter
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 */
final class AutomationRecordServiceTest extends TestCase {

	private AutomationRecordMapper&MockObject $mapper;

	private ObjectOperationsInterface&MockObject $objects;

	private IUserSession&MockObject $session;

	private IGroupManager&MockObject $groups;

	protected function setUp(): void {
		$this->mapper  = $this->createMock(AutomationRecordMapper::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups  = $this->createMock(IGroupManager::class);
	}

	private function signIn(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->with($uid)->willReturn($admin);
	}

	private function service(): AutomationRecordService {
		return new AutomationRecordService($this->mapper, $this->objects, $this->session, $this->groups);
	}

	private function record(string $object, string $key = 'aut-1'): AutomationRecord {
		$record = new AutomationRecord();
		$record->setKind(AutomationRecord::KIND_NOTIFICATION);
		$record->setAutomationKey($key);
		$record->setObjectUuid($object);

		return $record;
	}

	public function testNobodySignedInSeesNothing(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->mapper->expects($this->never())->method('findFiltered');

		$this->assertSame([], $this->service()->find(new AutomationRecordFilter()));
	}

	public function testAnAdminSeesEveryRecordWithoutObjectLookups(): void {
		$this->signIn('admin', true);
		$records = [$this->record('a'), $this->record('b')];
		$this->mapper->expects($this->once())->method('findFiltered')->willReturn($records);
		$this->objects->expects($this->never())->method('find');

		$this->assertSame($records, $this->service()->find(new AutomationRecordFilter(keyPrefix: 'aut-')));
	}

	public function testAReaderSeesOnlyRecordsOfObjectsTheyMayRead(): void {
		$this->signIn('medewerker', false);
		$readable = $this->record('mine');
		$this->mapper->method('findFiltered')->willReturn([$readable, $this->record('foreign'), $this->record(''), $this->record('mine')]);
		$this->objects->expects($this->exactly(2))->method('find')->willReturnCallback(
			static function (int|string $id, $options, bool $_rbac, bool $_multitenancy): ?ObjectEntity {
				if ($_rbac === false || $_multitenancy === false) {
					throw new \LogicException('the lookup must run under the caller rules');
				}

				if ($id === 'foreign') {
					throw new NotAuthorizedException('no');
				}

				return new ObjectEntity();
			}
		);

		$visible = $this->service()->find(new AutomationRecordFilter(limit: 10));

		$this->assertCount(2, $visible);
		$this->assertSame($readable, $visible[0]);
		$this->assertSame('mine', $visible[1]->getObjectUuid());
	}

	public function testAnObjectThatIsNotFoundHidesItsRecord(): void {
		$this->signIn('medewerker', false);
		$this->mapper->method('findFiltered')->willReturn([$this->record('gone')]);
		$this->objects->method('find')->willReturn(null);

		$this->assertSame([], $this->service()->find(new AutomationRecordFilter()));
	}

	public function testAReaderPageIsFilledFromTheNextStoredPages(): void {
		$this->signIn('medewerker', false);
		$offsets = [];
		$this->mapper->method('findFiltered')->willReturnCallback(
			function (AutomationRecordFilter $page) use (&$offsets): array {
				$offsets[] = $page->offset;
				if ($page->offset === 0) {
					return [$this->record('foreign'), $this->record('mine')];
				}

				return [$this->record('mine')];
			}
		);
		$this->objects->method('find')->willReturnCallback(
			static fn (int|string $id): ?ObjectEntity => $id === 'mine' ? new ObjectEntity() : null
		);

		$visible = $this->service()->find(new AutomationRecordFilter(limit: 2));

		$this->assertCount(2, $visible);
		$this->assertSame([0, 2], $offsets);
	}

	public function testTheRouteRefusesAnAnonymousCaller(): void {
		$this->session->method('getUser')->willReturn(null);
		$controller = new AutomationRecordsController($this->createMock(IRequest::class), $this->service(), $this->session);

		$response = $controller->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testTheRouteReadsItsFiltersFromTheQuery(): void {
		$this->signIn('admin', true);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['kind' => 'notification', 'key' => 'aut-', 'limit' => '5', 'offset' => '10']);
		$record = $this->record('a');
		$this->mapper->expects($this->once())->method('findFiltered')->with(
			$this->callback(static fn (AutomationRecordFilter $f): bool => [$f->kind, $f->keyPrefix, $f->limit, $f->offset] === ['notification', 'aut-', 5, 10])
		)->willReturn([$record]);

		$response = (new AutomationRecordsController($request, $this->service(), $this->session))->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [$record], 'limit' => 5, 'offset' => 10], $response->getData());
	}

	public function testAStoreFailureIsAPlainServerError(): void {
		$this->signIn('admin', true);
		$this->mapper->method('findFiltered')->willThrowException(new \RuntimeException('connection lost'));

		$response = (new AutomationRecordsController($this->createMock(IRequest::class), $this->service(), $this->session))->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Automation records could not be read'], $response->getData());
	}
}
