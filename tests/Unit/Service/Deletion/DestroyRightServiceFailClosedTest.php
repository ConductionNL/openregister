<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Deletion;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The destroy right refuses whenever it cannot be read: a failing
 * authorization lookup, a malformed grant or a failing permission check.
 *
 * @covers \OCA\OpenRegister\Service\Deletion\DestroyRightService
 * @uses   \OCA\OpenRegister\Service\Deletion\DestroyRightRun
 * @uses   \OCA\OpenRegister\Service\Deletion\DestructionRefusedException
 * @uses   \OCA\OpenRegister\Exception\RuleRefusedException
 * @uses   \OCA\OpenRegister\Service\Rbac\CallerContext
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 */
final class DestroyRightServiceFailClosedTest extends TestCase {

	/** @var PermissionHandlerInterface&MockObject */
	private PermissionHandlerInterface $permissions;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
	}

	private function service(): DestroyRightService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return new DestroyRightService($this->permissions, new CallerContext($session, $groups), $this->logger);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('document-1');
		$object->setOwner('behandelaar-1');

		return $object;
	}

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('documenten');

		return $schema;
	}

	public function testAnUnresolvableAuthorizationRefusesAsUndeclared(): void {
		$this->permissions->method('resolveAuthorization')->willThrowException(new RuntimeException('broken block'));
		$this->permissions->expects($this->never())->method('hasPermission');
		$this->logger->expects($this->once())->method('warning')->with(
			'[DestroyRight] Authorization unresolvable, refusing the destroy',
			['uuid' => 'document-1', 'error' => 'broken block']
		);

		$refusal = $this->service()->refusalFor($this->object(), $this->schema());

		$this->assertNotNull($refusal);
		$this->assertSame('destroy-right-undeclared', $refusal->getRule());
		$this->assertSame(403, $refusal->getStatusCode());
	}

	public function testAGrantThatIsNotAListRefusesAsUndeclared(): void {
		$this->permissions->method('resolveAuthorization')->willReturn(['destroy' => 'archivarissen']);
		$this->permissions->expects($this->never())->method('hasPermission');

		$refusal = $this->service()->refusalFor($this->object(), $this->schema());

		$this->assertSame('destroy-right-undeclared', $refusal?->getRule());
		$this->assertSame(
			'No group holds the destroy right on schema documenten. Declare it in the schema authorization block before anything can be destroyed.',
			$refusal?->getMessage()
		);
	}

	public function testOnlyStringGroupsAreReportedAsHoldingTheRight(): void {
		$this->permissions->method('resolveAuthorization')->willReturn(['destroy' => ['archivarissen', 7, null, 'beheer']]);
		$this->permissions->method('hasPermission')->willReturn(false);

		$refusal = $this->service()->refusalFor($this->object(), $this->schema());

		$this->assertSame('destroy-right-missing', $refusal?->getRule());
		$this->assertSame(['archivarissen', 'beheer'], $refusal?->toResponseBody()['grantedTo']);
	}

	public function testAFailingPermissionCheckRefusesAsMissing(): void {
		$this->permissions->method('resolveAuthorization')->willReturn(['destroy' => ['archivarissen']]);
		$this->permissions->method('hasPermission')->willThrowException(new RuntimeException('rbac down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[DestroyRight] Permission check failed, refusing the destroy',
			['uuid' => 'document-1', 'error' => 'rbac down']
		);

		$refusal = $this->service()->refusalFor($this->object(), $this->schema());

		$this->assertSame('destroy-right-missing', $refusal?->getRule());
		$this->assertSame('User behandelaar-1 does not hold the destroy right on schema documenten.', $refusal?->getMessage());
	}

	public function testASchemaWithoutSlugIsNamedByItsId(): void {
		$schema = new Schema();
		$schema->setId(42);
		$this->permissions->method('resolveAuthorization')->willReturn(null);

		$refusal = $this->service()->refusalFor($this->object(), $schema);

		$this->assertStringContainsString('on schema 42.', (string)$refusal?->getMessage());
	}
}
