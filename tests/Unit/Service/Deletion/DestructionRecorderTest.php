<?php

/**
 * Unit tests for DestructionRecorder — the record that outlives the object.
 *
 * Two properties matter and neither is visible from the happy path: the
 * record carries the actor, the time, the scope and the rule; and a record
 * that cannot be written refuses the destruction instead of letting it
 * proceed unrecorded.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Deletion;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\DestructionScope;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DestructionRecorderTest extends TestCase {
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);

			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn('Record Manager');
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-100');
		$object->setRegister('1');
		$object->setSchema('2');

		return $object;
	}//end object()

	public function testTheRecordNamesTheActorTheTimeAndWhatWentUnderWhichRule(): void {
		$captured = [];
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->expects(self::once())
			->method('createAuditTrailEntry')
			->willReturnCallback(
				function (ObjectEntity $object, string $action, array $context) use (&$captured): AuditTrail {
					$captured = ['action' => $action, 'context' => $context];

					return new AuditTrail();
				}
			);

		$recorder = new DestructionRecorder($mapper, $this->session('recordmanager-1'), new NullLogger());
		$record = $recorder->record(
			$this->object(),
			[
				'scope' => [DestructionScope::NOTES, DestructionScope::FILES],
				'destroyed' => [DestructionScope::NOTES => 4, DestructionScope::FILES => 2],
				'total' => 6,
				'failed' => [],
			],
			'destroy-right-granted'
		);

		// The action is the one name every scope excludes, so the record can
		// never be inside the scope it describes.
		self::assertSame(DestructionScope::DESTRUCTION_ACTION, $captured['action']);

		self::assertSame('recordmanager-1', $record['destroyedBy']);
		self::assertSame('Record Manager', $record['destroyedByName']);
		self::assertNotEmpty($record['destroyedAt']);
		self::assertSame('zaak-100', $record['objectUuid']);
		self::assertSame('destroy-right-granted', $record['rule']);
		self::assertSame(6, $record['destroyedTotal']);
		self::assertSame([DestructionScope::NOTES => 4, DestructionScope::FILES => 2], $record['destroyed']);
	}//end testTheRecordNamesTheActorTheTimeAndWhatWentUnderWhichRule()

	public function testAnUnrecordableDestructionRefusesRatherThanProceeding(): void {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('createAuditTrailEntry')->willThrowException(new \RuntimeException('ledger down'));

		$recorder = new DestructionRecorder($mapper, $this->session('recordmanager-1'), new NullLogger());

		$this->expectException(DestructionRefusedException::class);
		$this->expectExceptionMessage('nothing was destroyed');
		$recorder->record($this->object(), [], 'destroy-right-granted');
	}//end testAnUnrecordableDestructionRefusesRatherThanProceeding()

	public function testASystemActorIsNamedRatherThanLeftBlank(): void {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('createAuditTrailEntry')->willReturn(new AuditTrail());

		$recorder = new DestructionRecorder($mapper, $this->session(null), new NullLogger());
		$record = $recorder->record($this->object(), [], 'retention-sweep');

		self::assertSame('system', $record['destroyedBy']);
		self::assertSame('System', $record['destroyedByName']);
	}//end testASystemActorIsNamedRatherThanLeftBlank()
}//end class
