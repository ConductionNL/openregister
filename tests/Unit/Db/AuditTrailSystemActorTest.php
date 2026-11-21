<?php

/**
 * A write inside a system operation is audited as the system's.
 *
 * Pins that the audit row of a write made inside a system operation names
 * the system as its actor even when a user is signed in, and that outside
 * one the signed-in user stays the actor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class AuditTrailSystemActorTest extends TestCase {

	private function mapper(): AuditTrailMapper {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));
		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		return new AuditTrailMapper(
			$this->createMock(IDBConnection::class),
			new AuditTrailMapperServices($container, $this->createMock(LoggerInterface::class), $session, $request)
		);
	}//end mapper()

	private static function created(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(7);
		$object->setUuid('5b0e1c2d-3f4a-4b5c-8d6e-7f8091a2b3c4');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['waiver' => true]);

		return $object;
	}//end created()

	public function testAWriteInsideASystemOperationNamesTheSystem(): void {
		$mapper = $this->mapper();

		$row = SystemOperationContext::run(
			static fn (): AuditTrail => $mapper->buildEntry(change: new AuditedChange(old: null, new: self::created()))
		);

		$this->assertSame('System', $row->getUser());
		$this->assertSame('System', $row->getUserName());
	}//end testAWriteInsideASystemOperationNamesTheSystem()

	public function testOutsideASystemOperationTheSignedInUserActs(): void {
		$row = $this->mapper()->buildEntry(change: new AuditedChange(old: null, new: self::created()));

		$this->assertSame('alice', $row->getUser());
	}//end testOutsideASystemOperationTheSignedInUserActs()
}//end class
