<?php

/**
 * The read-rule fields on a built audit entry, and the read entry point that sets them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @covers \OCA\OpenRegister\Db\AuditTrail\AuditedChange
 */
final class AuditTrailReadRuleEntryTest extends TestCase {

	private function services(): AuditTrailMapperServices {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));

		return new AuditTrailMapperServices($container, $this->createMock(LoggerInterface::class), $this->createMock(IUserSession::class), $this->createMock(IRequest::class));
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(7);
		$object->setUuid('m-123');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['titel' => 'Lantaarnpaal']);

		return $object;
	}

	public function testABuiltReadEntryCarriesTheRuleDigestAndVersion(): void {
		$mapper = new AuditTrailMapper($this->createMock(IDBConnection::class), $this->services());

		$trail = $mapper->buildEntry(change: new AuditedChange(new: $this->object(), action: 'read', ruleDigest: str_repeat('a', 64), ruleVersion: '1.4.0'));

		$this->assertSame('read', $trail->getAction());
		$this->assertSame(str_repeat('a', 64), $trail->getRuleDigest());
		$this->assertSame('1.4.0', $trail->getRuleVersion());
		$this->assertSame(str_repeat('a', 64), $trail->jsonSerialize()['ruleDigest']);
	}

	public function testAnEntryWithoutARuleLeavesBothFieldsOutOfItsSealedForm(): void {
		$mapper = new AuditTrailMapper($this->createMock(IDBConnection::class), $this->services());

		$trail = $mapper->buildEntry(change: new AuditedChange(new: $this->object(), action: 'read'));

		$this->assertNull($trail->getRuleDigest());
		$this->assertArrayNotHasKey('ruleDigest', $trail->jsonSerialize());
		$this->assertArrayNotHasKey('ruleVersion', $trail->jsonSerialize());
	}

	public function testRecordReadRecordsAReadChangeWithTheRule(): void {
		$object = $this->object();
		$row = new AuditTrail();
		$mapper = $this->getMockBuilder(AuditTrailMapper::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class), $this->services()])
			->onlyMethods(['recordChange'])
			->getMock();
		$mapper->expects($this->once())->method('recordChange')
			->with(new AuditedChange(new: $object, action: 'read', ruleDigest: str_repeat('b', 64), ruleVersion: '2.0.0'))
			->willReturn($row);

		$this->assertSame($row, $mapper->recordRead(object: $object, ruleDigest: str_repeat('b', 64), ruleVersion: '2.0.0'));
	}
}
