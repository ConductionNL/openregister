<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Timeline;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\EntriesClosedException;
use OCA\OpenRegister\Notifications\Db\EntryClosure;
use OCA\OpenRegister\Notifications\Db\EntryClosureMapper;
use OCA\OpenRegister\Notifications\Service\Timeline\EntryClosureService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelinePermissionException;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A record closed to new entries (object-archive-state C29.4, REQ-OAS-006): closing needs the manage
 * right and a reason and is audited; a closed record refuses a new entry naming the closure.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
#[CoversClass(EntryClosureService::class)]
#[CoversClass(EntriesClosedException::class)]
#[UsesClass(EntryClosure::class)]
#[UsesClass(ObjectEntity::class)]
final class EntryClosureServiceTest extends TestCase {

	/** @var array<string, EntryClosure> */
	private array $stored = [];

	/** @var array<int, array{0: string, 1: array<string, mixed>}> */
	private array $audits = [];

	private bool $mayManage = true;

	private function service(): EntryClosureService {
		$mapper = $this->createMock(EntryClosureMapper::class);
		$mapper->method('findForObject')->willReturnCallback(fn (string $objectUuid): ?EntryClosure => ($this->stored[$objectUuid] ?? null));
		$mapper->method('insert')->willReturnCallback(
			function (EntryClosure $entity): EntryClosure {
				$this->stored[(string)$entity->getObjectUuid()] = $entity;
				return $entity;
			}
		);
		$mapper->method('deleteForObject')->willReturnCallback(
			function (string $objectUuid): int {
				$had = isset($this->stored[$objectUuid]);
				unset($this->stored[$objectUuid]);
				return (int)$had;
			}
		);

		$visibility = $this->createMock(TimelineVisibilityService::class);
		$visibility->method('mayManage')->willReturnCallback(fn (): bool => $this->mayManage);
		$visibility->method('auditTimelineEvent')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context): bool {
				$this->audits[] = [$action, $context];
				return true;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new EntryClosureService(closures: $mapper, visibility: $visibility, userSession: $session);
	}

	private static function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-1');

		return $object;
	}

	public function testAnOpenRecordAcceptsEntries(): void {
		$service = $this->service();

		$service->assertOpen(objectUuid: 'case-1');

		$this->assertNull($service->closureOf(objectUuid: 'case-1'));
	}

	public function testClosingRecordsWhoWhenAndWhyAndIsAudited(): void {
		$service = $this->service();

		$closure = $service->close(object: self::object(), reason: '  beroep ingesteld  ');

		$this->assertSame('behandelaar', $closure['by']);
		$this->assertSame('beroep ingesteld', $closure['reason']);
		$this->assertNotNull($closure['at']);
		$this->assertSame($closure, $service->closureOf(objectUuid: 'case-1'));
		$this->assertSame([[EntryClosureService::CLOSE_AUDIT_ACTION, $closure]], $this->audits);
	}

	public function testAClosedRecordRefusesANewEntryNamingTheClosure(): void {
		$service = $this->service();
		$closure = $service->close(object: self::object(), reason: 'beroep ingesteld');

		try {
			$service->assertOpen(objectUuid: 'case-1');
			$this->fail('A closed record must refuse a new entry.');
		} catch (EntriesClosedException $e) {
			$this->assertSame(409, $e->getHttpStatus());
			$this->assertSame(
				['error' => $e->getMessage(), 'state' => 'closed', 'by' => 'behandelaar', 'at' => $closure['at'], 'reason' => 'beroep ingesteld'],
				$e->toResponseBody()
			);
			$this->assertStringContainsString('by behandelaar: beroep ingesteld', $e->getMessage());
		}
	}

	public function testClosingAClosedRecordKeepsTheFirstClosureAndAuditsOnce(): void {
		$service = $this->service();
		$first = $service->close(object: self::object(), reason: 'beroep ingesteld');

		$second = $service->close(object: self::object(), reason: 'another reason');

		$this->assertSame($first, $second);
		$this->assertCount(1, $this->audits);
	}

	public function testClosingNeedsAReason(): void {
		$this->expectException(TimelineValidationException::class);

		$this->service()->close(object: self::object(), reason: '   ');
	}

	public function testClosingNeedsTheManageRightAndWritesNothing(): void {
		$this->mayManage = false;
		$service = $this->service();

		try {
			$service->close(object: self::object(), reason: 'beroep ingesteld');
			$this->fail('A caller who may not manage the timeline must not close it.');
		} catch (TimelinePermissionException) {
		}

		$this->assertSame([], $this->stored);
		$this->assertSame([], $this->audits);
	}

	public function testReopeningRemovesTheClosureAndIsAuditedWithWhatWasClosed(): void {
		$service = $this->service();
		$closure = $service->close(object: self::object(), reason: 'beroep ingesteld');

		$this->assertTrue($service->reopen(object: self::object()));

		$service->assertOpen(objectUuid: 'case-1');
		$this->assertSame([EntryClosureService::REOPEN_AUDIT_ACTION, ['closed' => $closure, 'by' => 'behandelaar']], $this->audits[1]);
	}

	public function testReopeningAnOpenRecordChangesNothing(): void {
		$this->assertFalse($this->service()->reopen(object: self::object()));
		$this->assertSame([], $this->audits);
	}

	public function testReopeningNeedsTheManageRight(): void {
		$service = $this->service();
		$service->close(object: self::object(), reason: 'beroep ingesteld');
		$this->mayManage = false;

		$this->expectException(TimelinePermissionException::class);

		$service->reopen(object: self::object());
	}

	public function testTheEntityAnswersTheClosureShape(): void {
		$entity = new EntryClosure();
		$entity->setObjectUuid('case-2');
		$entity->setClosedBy('a');
		$entity->setClosedAt(new DateTime('2026-11-25T10:00:00+00:00'));
		$entity->setReason('r');

		$this->assertSame(['by' => 'a', 'at' => '2026-11-25T10:00:00+00:00', 'reason' => 'r'], $entity->closure());
		$this->assertSame(['id' => null, 'objectUuid' => 'case-2', 'by' => 'a', 'at' => '2026-11-25T10:00:00+00:00', 'reason' => 'r'], $entity->jsonSerialize());
	}
}
