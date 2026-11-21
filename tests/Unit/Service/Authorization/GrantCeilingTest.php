<?php

/**
 * Nobody grants a right they do not hold (REQ-RAH-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\GrantExceedsGranterException;
use OCA\OpenRegister\Service\Authorization\GrantCeiling;
use OCA\OpenRegister\Service\Authorization\GrantedRight;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for {@see GrantCeiling}.
 */
class GrantCeilingTest extends TestCase {

	/**
	 * The actions the granter holds, per test.
	 *
	 * @var array<int, string>
	 */
	private array $held = [];

	/**
	 * Whether the granter is a Nextcloud administrator.
	 */
	private bool $admin = false;

	/**
	 * Whether the permission check fails.
	 */
	private bool $broken = false;

	/**
	 * The check under test.
	 */
	private GrantCeiling $ceiling;

	/**
	 * The granter.
	 */
	private IUser $granter;

	/**
	 * Build the check over a granter whose rights each test sets.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->granter = $this->createMock(IUser::class);
		$this->granter->method('getUID')->willReturn('functioneel-beheer');

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'functioneel-beheer' && $this->admin === true);

		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (Schema $schema, string $action, ?string $userId=null): bool {
				if ($this->broken === true) {
					throw new RuntimeException('groups unavailable');
				}

				return $userId === 'functioneel-beheer' && in_array($action, $this->held, true);
			}
		);

		$this->ceiling = new GrantCeiling(groups: $groups, permissions: $permissions);
	}//end setUp()

	/**
	 * An administrator may grant any action, manage included.
	 *
	 * @return void
	 */
	public function testAnAdministratorMayGrantAnything(): void {
		$this->admin = true;

		$this->ceiling->assertWithin(granter: $this->granter, grants: $this->rights('delete', 'manage', 'sendMail'));

		$this->addToAssertionCount(1);
	}//end testAnAdministratorMayGrantAnything()

	/**
	 * An action the granter does not hold is refused, named with its schema.
	 *
	 * @return void
	 */
	public function testAnExcessActionIsRefused(): void {
		$this->held = ['read', 'update'];

		try {
			$this->ceiling->assertWithin(granter: $this->granter, grants: $this->rights('update', 'delete'));
			$this->fail('A grant of delete by a granter without delete was stored.');
		} catch (GrantExceedsGranterException $refusal) {
			$this->assertSame([['register' => null, 'schema' => 'publicatie', 'action' => 'delete']], $refusal->getExcess());
			$this->assertSame('grant-exceeds-granter', $refusal->body()['error']);
		}
	}//end testAnExcessActionIsRefused()

	/**
	 * Manage is never grantable by a non-administrator, even one the check says holds it.
	 *
	 * @return void
	 */
	public function testManageIsNeverGrantableByANonAdministrator(): void {
		$this->held = ['read', 'update', 'manage'];

		$this->expectException(GrantExceedsGranterException::class);

		$this->ceiling->assertWithin(granter: $this->granter, grants: $this->rights('manage'));
	}//end testManageIsNeverGrantableByANonAdministrator()

	/**
	 * A grant within the granter's rights passes.
	 *
	 * @return void
	 */
	public function testAGrantWithinTheGrantersRightsPasses(): void {
		$this->held = ['read', 'update'];

		$this->ceiling->assertWithin(granter: $this->granter, grants: $this->rights('read', 'update'));

		$this->addToAssertionCount(1);
	}//end testAGrantWithinTheGrantersRightsPasses()

	/**
	 * Every excess right is named, not only the first.
	 *
	 * @return void
	 */
	public function testEveryExcessRightIsNamed(): void {
		$this->held = ['read'];

		try {
			$this->ceiling->assertWithin(
				granter: $this->granter,
				grants: [
					new GrantedRight(schema: $this->schema(), action: 'update', register: 'woo'),
					new GrantedRight(schema: $this->schema(), action: 'read', register: 'woo'),
					new GrantedRight(schema: $this->schema(), action: 'delete', register: 'woo'),
				]
			);
			$this->fail('The excess grants were stored.');
		} catch (GrantExceedsGranterException $refusal) {
			$this->assertSame(
				[
					['register' => 'woo', 'schema' => 'publicatie', 'action' => 'update'],
					['register' => 'woo', 'schema' => 'publicatie', 'action' => 'delete'],
				],
				$refusal->getExcess()
			);
		}
	}//end testEveryExcessRightIsNamed()

	/**
	 * A right whose holding cannot be determined is not held.
	 *
	 * @return void
	 */
	public function testAFailingPermissionCheckRefuses(): void {
		$this->held = ['read'];
		$this->broken = true;

		$this->expectException(GrantExceedsGranterException::class);

		$this->ceiling->assertWithin(granter: $this->granter, grants: $this->rights('read'));
	}//end testAFailingPermissionCheckRefuses()

	/**
	 * A schema without a slug is named by its id.
	 *
	 * @return void
	 */
	public function testASchemaWithoutASlugIsNamedByItsId(): void {
		$schema = new Schema();
		$schema->setId(42);

		$this->assertSame(
			['register' => null, 'schema' => '42', 'action' => 'read'],
			(new GrantedRight(schema: $schema, action: 'read'))->named()
		);
	}//end testASchemaWithoutASlugIsNamedByItsId()

	/**
	 * Rights on the test schema.
	 *
	 * @param string ...$actions The actions given.
	 *
	 * @return array<int, GrantedRight> The rights.
	 */
	private function rights(string ...$actions): array {
		return array_map(fn (string $action): GrantedRight => new GrantedRight(schema: $this->schema(), action: $action), $actions);
	}//end rights()

	/**
	 * The schema rights are given on.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('publicatie');

		return $schema;
	}//end schema()
}//end class
