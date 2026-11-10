<?php

/**
 * Golden test: the structured entries of the authorization audit log
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\LinkSessionRuntime;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\AuthorizationChange;

/**
 * Pins message, keys, key order and values of every entry the three log
 * methods write, with and without a user, and the affected-schema count when
 * the register has schemas, has none, or cannot be read. Only the source
 * line number is left out: it is where the log call sits.
 */
class AuthorizationAuditServiceEntryGoldenTest extends TestCase {

	/** @var array<int, array{0: string, 1: array<string, mixed>}> The entries logged. */
	private array $entries = [];

	/** @var array<int, string> Side effects in order. */
	private array $events = [];

	/**
	 * The service over recording doubles.
	 *
	 * @param IUser|null $user     The session user.
	 * @param mixed      $register A register, an exception to throw, or a schemas value.
	 *
	 * @return AuthorizationAuditService
	 */
	private function service(?IUser $user, mixed $register = null): AuthorizationAuditService {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($user): ?IUser {
				$this->events[] = 'user';
				return $user;
			}
		);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			function (int $id) use ($register): Register {
				$this->events[] = 'find:' . $id;
				if ($register instanceof \Throwable) {
					throw $register;
				}

				$row = $this->createMock(Register::class);
				$row->method('getSchemas')->willReturn($register);
				return $row;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context): void {
				$this->events[] = 'log';
				$this->assertSame(true, is_int($context['line']));
				unset($context['line']);
				$this->entries[] = [$message, $context];
			}
		);

		return new AuthorizationAuditService(new LinkSessionRuntime($session, $logger), $registers);
	}//end service()

	/**
	 * A user double.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$user->method('getDisplayName')->willReturn('Ad Min');
		return $user;
	}//end user()

	/**
	 * The fields every entry starts with, in order.
	 *
	 * @param array<string, mixed> $fields The entry's own fields.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(array $fields): array {
		return [
			'file' => realpath(__DIR__ . '/../../../lib/Service/AuthorizationAuditService.php'),
			'event_type' => 'openregister_authorization',
		] + $fields;
	}//end entry()

	/**
	 * The schema entry, by a user and by the system.
	 *
	 * @return void
	 */
	public function testSchemaEntry(): void {
		$this->service($this->user())->recordSchemaChange(new AuthorizationChange(7, 'Zaken', ['read' => ['a']], null));
		$this->service(null)->recordSchemaChange(new AuthorizationChange(8, '', null, []));

		$this->assertSame(
			[
				['[AuthorizationAudit] Schema authorization changed', $this->entry(['entity_type' => 'schema', 'entity_id' => 7, 'entity_title' => 'Zaken', 'changed_by_user' => 'admin', 'changed_by_name' => 'Ad Min', 'old_authorization' => '{"read":["a"]}', 'new_authorization' => 'null'])],
				['[AuthorizationAudit] Schema authorization changed', $this->entry(['entity_type' => 'schema', 'entity_id' => 8, 'entity_title' => '', 'changed_by_user' => 'system', 'changed_by_name' => 'System', 'old_authorization' => 'null', 'new_authorization' => '[]'])],
			],
			$this->entries
		);
		$this->assertSame(['user', 'log', 'user', 'log'], $this->events);
	}//end testSchemaEntry()

	/**
	 * The register entry and its affected-schema count.
	 *
	 * @return void
	 */
	public function testRegisterEntry(): void {
		$this->service($this->user(), [1, 2, 3])->recordRegisterChange(change: new AuthorizationChange(entityId: 5, entityTitle: 'Reg', before: null, after: ['x' => 1]));
		$this->service(null, null)->recordRegisterChange(change: new AuthorizationChange(entityId: 6, entityTitle: 'Leeg', before: [], after: []));
		$this->service(null, new RuntimeException('gone'))->recordRegisterChange(change: new AuthorizationChange(entityId: 9, entityTitle: 'Weg', before: null, after: null));
		$this->service(null, new \Error('broken'))->recordRegisterChange(change: new AuthorizationChange(entityId: 10, entityTitle: 'Kapot', before: null, after: null));

		$expected = [];
		foreach ([[5, 'Reg', 'admin', 'Ad Min', 'null', '{"x":1}', 3], [6, 'Leeg', 'system', 'System', '[]', '[]', 0], [9, 'Weg', 'system', 'System', 'null', 'null', 0], [10, 'Kapot', 'system', 'System', 'null', 'null', 0]] as [$id, $title, $uid, $name, $old, $new, $count]) {
			$expected[] = [
				'[AuthorizationAudit] Register authorization changed',
				$this->entry(['entity_type' => 'register', 'entity_id' => $id, 'entity_title' => $title, 'changed_by_user' => $uid, 'changed_by_name' => $name, 'old_authorization' => $old, 'new_authorization' => $new, 'affected_schema_count' => $count]),
			];
		}

		$this->assertSame($expected, $this->entries);
		$this->assertSame(['user', 'find:5', 'log', 'user', 'find:6', 'log', 'user', 'find:9', 'log', 'user', 'find:10', 'log'], $this->events);
	}//end testRegisterEntry()

	/**
	 * The role-definition entry.
	 *
	 * @return void
	 */
	public function testRoleEntry(): void {
		$this->service($this->user())->recordRoleChange(change: new AuthorizationChange(entityId: 3, entityTitle: 'Reg', before: ['r' => []], after: null));
		$this->service(null)->recordRoleChange(change: new AuthorizationChange(entityId: 4, entityTitle: 'Reg2', before: null, after: ['w']));

		$this->assertSame(
			[
				['[AuthorizationAudit] Role definitions changed', $this->entry(['entity_type' => 'register_roles', 'entity_id' => 3, 'entity_title' => 'Reg', 'changed_by_user' => 'admin', 'changed_by_name' => 'Ad Min', 'old_roles' => '{"r":[]}', 'new_roles' => 'null'])],
				['[AuthorizationAudit] Role definitions changed', $this->entry(['entity_type' => 'register_roles', 'entity_id' => 4, 'entity_title' => 'Reg2', 'changed_by_user' => 'system', 'changed_by_name' => 'System', 'old_roles' => 'null', 'new_roles' => '["w"]'])],
			],
			$this->entries
		);
	}//end testRoleEntry()
}//end class
