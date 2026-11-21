<?php

/**
 * RegisterAccessAuditTest: a register write tells the Core access-change log what changed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\RegisterAccessAudit;
use OCA\OpenRegister\Controller\Support\RegisterUpdate;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Kernel\Contracts\Core\RegisterAccessChangeLogInterface;
use OCA\OpenRegister\Service\AuthorizationChange;
use PHPUnit\Framework\TestCase;

/**
 * The audit keeps the access settings from before the write and reports each changed one once.
 */
final class RegisterAccessAuditTest extends TestCase {

	private static function register(?array $authorization, ?array $roles, string $title = 'Zaken'): Register {
		$register = new Register();
		$register->setTitle($title);
		$register->setAuthorization($authorization);
		$register->setConfiguration($roles === null ? [] : ['roles' => $roles]);
		return $register;
	}

	public function testAnAuthorizationAndARoleChangeAreEachRecordedWithBeforeAndAfter(): void {
		$before = self::register(['read' => ['a']], ['clerk' => ['read']]);
		$after = self::register(['read' => ['b']], ['clerk' => ['write']], 'Zaken 2');
		$update = new RegisterUpdate(id: 7, data: ['authorization' => ['read' => ['b']], 'configuration' => ['roles' => ['clerk' => ['write']]]]);
		$log = $this->createMock(RegisterAccessChangeLogInterface::class);

		$log->expects($this->once())->method('recordRegisterChange')->with($this->callback(
			static fn (AuthorizationChange $change): bool => $change->entityId === 7 && $change->entityTitle === 'Zaken 2'
				&& $change->before === ['read' => ['a']] && $change->after === ['read' => ['b']]
		));
		$log->expects($this->once())->method('recordRoleChange')->with($this->callback(
			static fn (AuthorizationChange $change): bool => $change->entityId === 7
				&& $change->before === ['clerk' => ['read']] && $change->after === ['clerk' => ['write']]
		));

		$audit = new RegisterAccessAudit(update: $update, before: $before);
		$before->setAuthorization(['read' => ['changed after the snapshot']]);
		$audit->log(service: $log, after: $after);
	}

	public function testAWriteThatTouchesNeitherRecordsNothing(): void {
		$log = $this->createMock(RegisterAccessChangeLogInterface::class);
		$log->expects($this->never())->method('recordRegisterChange');
		$log->expects($this->never())->method('recordRoleChange');

		$audit = new RegisterAccessAudit(update: new RegisterUpdate(id: 7, data: ['title' => 'x']), before: self::register(null, null));
		$audit->log(service: $log, after: self::register(null, null));
	}

	public function testOnlyTheRolesChangeIsRecordedWhenOnlyRolesAreWritten(): void {
		$log = $this->createMock(RegisterAccessChangeLogInterface::class);
		$log->expects($this->never())->method('recordRegisterChange');
		$log->expects($this->once())->method('recordRoleChange')->with($this->callback(
			static fn (AuthorizationChange $change): bool => $change->before === null && $change->after === ['clerk' => []]
		));

		$update = new RegisterUpdate(id: 3, data: ['configuration' => ['roles' => ['clerk' => []]]]);
		$audit = new RegisterAccessAudit(update: $update, before: self::register(null, null));
		$audit->log(service: $log, after: self::register(null, ['clerk' => []]));
	}
}//end class
