<?php

/**
 * Mutation-killing tests for the destroy-right gate.
 *
 * Written against the mutants Infection left alive in DestroyRightService:
 * the 403 on each refusal, the context a refusal carries back to the caller
 * (the right, and the groups that do hold it, filtered and re-indexed), and
 * the RBAC flag on the permission check, which must never be passed as off.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Deletion;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DestroyRightServiceMutationTest extends TestCase {
	/**
	 * Test double or fixture for permissions.
	 *
	 * @var PermissionHandlerInterface&MockObject
	 */
	private PermissionHandlerInterface&MockObject $permissions;

	protected function setUp(): void {
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
	}//end setUp()

	private function service(): DestroyRightService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar-2');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return new DestroyRightService($this->permissions, new CallerContext($session, $groups), new NullLogger());
	}//end service()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('document-1');
		$object->setOwner('behandelaar-1');

		return $object;
	}//end object()

	private function schema(?string $slug): Schema {
		$schema = new Schema();
		$schema->setId(5150);
		$schema->setSlug($slug);

		return $schema;
	}//end schema()

	public function testUnresolvableSchemaRefusesWithForbidden(): void {
		$refusal = $this->service()->refusalFor($this->object(), null);

		$this->assertNotNull($refusal);
		$this->assertSame('schema-unresolvable', $refusal->getRule());
		$this->assertSame(403, $refusal->getStatusCode());
	}//end testUnresolvableSchemaRefusesWithForbidden()

	public function testUndeclaredRightRefusesWithForbiddenAndNamesTheRight(): void {
		$this->permissions->method('resolveAuthorization')->willReturn(['read' => ['public']]);
		$this->permissions->expects($this->never())->method('hasPermission');

		$refusal = $this->service()->refusalFor($this->object(), $this->schema(null));

		$this->assertNotNull($refusal);
		$this->assertSame('destroy-right-undeclared', $refusal->getRule());
		$this->assertSame(403, $refusal->getStatusCode());
		$body = $refusal->toResponseBody();
		$this->assertSame('destroy', $body['right']);
		$this->assertStringContainsString('schema 5150.', $body['message']);
	}//end testUndeclaredRightRefusesWithForbiddenAndNamesTheRight()

	public function testMissingRightReportsTheGroupsThatHoldIt(): void {
		$this->permissions->method('resolveAuthorization')
			->willReturn(['destroy' => [3 => 'archivaris', 4 => 42, 7 => 'recordmanager']]);
		$this->permissions->method('hasPermission')->willReturn(false);

		$refusal = $this->service()->refusalFor($this->object(), $this->schema('documenten'));

		$this->assertNotNull($refusal);
		$this->assertSame('destroy-right-missing', $refusal->getRule());
		$this->assertSame(403, $refusal->getStatusCode());
		$body = $refusal->toResponseBody();
		$this->assertSame('destroy', $body['right']);
		$this->assertSame(['archivaris', 'recordmanager'], $body['grantedTo']);
		$this->assertSame(
			'User behandelaar-2 does not hold the destroy right on schema documenten.',
			$body['message']
		);
	}//end testMissingRightReportsTheGroupsThatHoldIt()

	public function testMissingRightNamesTheSchemaIdWhenItHasNoSlug(): void {
		$this->permissions->method('resolveAuthorization')->willReturn(['destroy' => ['archivaris']]);
		$this->permissions->method('hasPermission')->willReturn(false);

		$refusal = $this->service()->refusalFor($this->object(), $this->schema(null));

		$this->assertNotNull($refusal);
		$this->assertSame(
			'User behandelaar-2 does not hold the destroy right on schema 5150.',
			$refusal->toResponseBody()['message']
		);
	}//end testMissingRightNamesTheSchemaIdWhenItHasNoSlug()

	public function testPermissionCheckRunsWithRbacOnForTheCallerAndOwner(): void {
		$object = $this->object();
		$schema = $this->schema('documenten');
		$this->permissions->method('resolveAuthorization')->willReturn(['destroy' => ['archivaris']]);
		$this->permissions->expects($this->once())
			->method('hasPermission')
			->with($schema, 'destroy', 'behandelaar-2', 'behandelaar-1', true, $object)
			->willReturn(true);

		$this->assertNull($this->service()->refusalFor($object, $schema));
	}//end testPermissionCheckRunsWithRbacOnForTheCallerAndOwner()
}//end class
