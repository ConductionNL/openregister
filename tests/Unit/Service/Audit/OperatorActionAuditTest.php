<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;
use OCA\OpenRegister\Kernel\Contracts\OperatorActionAuditInterface;
use OCA\OpenRegister\Service\Audit\OperatorActionAudit;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An administrator's platform action becomes one audit entry naming them.
 *
 * @covers \OCA\OpenRegister\Service\Audit\OperatorActionAudit
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
class OperatorActionAuditTest extends TestCase {

	public function testTheEntryNamesTheCurrentUserAndCarriesTheChange(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$user->method('getDisplayName')->willReturn('Functional Administrator');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$written = [];
		$entries = $this->createMock(AuditEntryWriterInterface::class);
		$entries->expects($this->once())->method('writeAuditEntries')->willReturnCallback(function (array $batch) use (&$written): void {
			$written = $batch;
		});

		(new OperatorActionAudit(entries: $entries, userSession: $session))->record(action: 'dependency.reset', changed: ['key' => 'llm']);

		$this->assertCount(1, $written);
		$this->assertInstanceOf(AuditEntry::class, $written[0]);
		$this->assertSame('dependency.reset', $written[0]->action);
		$this->assertSame('admin', $written[0]->user);
		$this->assertSame('Functional Administrator', $written[0]->userName);
		$this->assertSame(['key' => 'llm'], $written[0]->changed);
		$this->assertNotSame('', $written[0]->uuid);
		$this->assertNull($written[0]->objectUuid);
	}

	public function testAFailedWriteReachesTheCaller(): void {
		$session = $this->createMock(IUserSession::class);
		$entries = $this->createMock(AuditEntryWriterInterface::class);
		$entries->method('writeAuditEntries')->willThrowException(new RuntimeException('database down'));

		$this->expectException(RuntimeException::class);
		(new OperatorActionAudit(entries: $entries, userSession: $session))->record(action: 'dependency.reset', changed: []);
	}

	public function testThePortIsBoundByTheCoreArea(): void {
		$this->assertSame(OperatorActionAudit::class, CoreServiceBindings::BINDINGS[OperatorActionAuditInterface::class] ?? null);
	}
}
