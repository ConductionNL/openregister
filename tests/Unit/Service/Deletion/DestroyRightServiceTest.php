<?php

/**
 * Unit tests for DestroyRightService — destroying is a right, not an admin
 * check.
 *
 * The important case is the one that used to pass: a caseworker holding
 * `delete` could purge, because the endpoint asked for `delete`. It now asks
 * for `destroy`, and the refusal names the rule.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DestroyRightServiceTest extends TestCase {
	private function service(
		?string $uid,
		bool $isAdmin,
		?array $authorization,
		bool $hasPermission,
	): DestroyRightService {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('resolveAuthorization')->willReturn($authorization);
		$permissions->method('hasPermission')->willReturn($hasPermission);

		return new DestroyRightService($permissions, $session, $groups, new NullLogger());
	}//end service()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('document-1');
		$object->setOwner('behandelaar-1');

		return $object;
	}//end object()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('documenten');

		return $schema;
	}//end schema()

	public function testACaseworkerCannotDestroy(): void {
		// The schema grants `destroy` to record managers only, and this caller
		// is not one. Holding `delete` is not the question any more.
		$refusal = $this->service(
			'behandelaar-1',
			false,
			['delete' => ['behandelaars'], 'destroy' => ['recordmanagers']],
			false
		)->refusalFor($this->object(), $this->schema());

		self::assertNotNull($refusal);
		self::assertSame('destroy-right-missing', $refusal->getRule());
		self::assertSame(403, $refusal->getStatusCode());
		self::assertStringContainsString('destroy right', $refusal->getMessage());
		self::assertSame(['recordmanagers'], $refusal->toResponseBody()['grantedTo']);
	}//end testACaseworkerCannotDestroy()

	public function testTheRecordManagerMay(): void {
		self::assertNull(
			$this->service(
				'recordmanager-1',
				false,
				['destroy' => ['recordmanagers']],
				true
			)->refusalFor($this->object(), $this->schema())
		);
	}//end testTheRecordManagerMay()

	public function testAnUndeclaredRightRefusesRatherThanDefaultingOpen(): void {
		$refusal = $this->service('behandelaar-1', false, ['delete' => ['behandelaars']], true)
			->refusalFor($this->object(), $this->schema());

		self::assertNotNull($refusal);
		self::assertSame('destroy-right-undeclared', $refusal->getRule());
	}//end testAnUndeclaredRightRefusesRatherThanDefaultingOpen()

	public function testAnUnresolvableSchemaRefuses(): void {
		$refusal = $this->service('behandelaar-1', false, ['destroy' => ['x']], true)
			->refusalFor($this->object(), null);

		self::assertNotNull($refusal);
		self::assertSame('schema-unresolvable', $refusal->getRule());
	}//end testAnUnresolvableSchemaRefuses()

	public function testAnAnonymousCallerRefusesWith401(): void {
		$refusal = $this->service(null, false, ['destroy' => ['x']], true)
			->refusalFor($this->object(), $this->schema());

		self::assertNotNull($refusal);
		self::assertSame('not-authenticated', $refusal->getRule());
		self::assertSame(401, $refusal->getStatusCode());
	}//end testAnAnonymousCallerRefusesWith401()

	public function testAnAdministratorStillBypassesSoNoInstanceLocksItselfOut(): void {
		self::assertNull(
			$this->service('admin', true, null, false)->refusalFor($this->object(), $this->schema())
		);
	}//end testAnAdministratorStillBypassesSoNoInstanceLocksItselfOut()

	public function testDestroyIsACanonicalActionSoASchemaCanDeclareIt(): void {
		$reflection = new \ReflectionClass(PermissionHandler::class);
		$actions = $reflection->getConstant('CANONICAL_ACTIONS');

		self::assertContains(DestroyRightService::ACTION, $actions);
	}//end testDestroyIsACanonicalActionSoASchemaCanDeclareIt()
}//end class
