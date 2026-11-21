<?php

/**
 * Characterisation tests for SchemaManageGate's manage-rule decision and the
 * mixed-schema batch check.
 *
 * Pins allowed AND refused outcomes of the manage rule (no session,
 * administrator, missing / empty / non-array rule, plain and `{group}`
 * entries, unreadable group membership, no shared group) and of
 * `firstUnmanageableRowSchema()` (rows without a schema skipped, each schema
 * looked up once and in row order, an unresolvable schema refused, the first
 * refused schema returned, null when every named schema passes).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden tests for the manage gate.
 */
class SchemaManageGateDecisionGoldenTest extends TestCase {

	private IUserSession&MockObject $session;

	private IGroupManager&MockObject $groups;

	private SchemaMapperInterface&MockObject $schemas;

	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->groups  = $this->createMock(IGroupManager::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
	}//end setUp()

	/**
	 * A gate for a signed-in non-admin in these groups (or with unreadable membership).
	 *
	 * @param array<int, string>|null $groupIds The user's groups; null when the lookup throws.
	 *
	 * @return SchemaManageGate
	 */
	private function gateFor(?array $groupIds): SchemaManageGate {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->willReturn(false);
		if ($groupIds === null) {
			$this->groups->method('getUserGroupIds')->willThrowException(new RuntimeException('down'));
		} else {
			$this->groups->method('getUserGroupIds')->willReturn($groupIds);
		}

		return new SchemaManageGate($this->session, $this->groups, $this->schemas);
	}//end gateFor()

	/**
	 * A schema with this authorization block.
	 *
	 * @param array<string, mixed>|null $authorization The block.
	 *
	 * @return Schema
	 */
	private function schema(?array $authorization): Schema {
		$schema = new Schema();
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * Every outcome of the manage rule for a signed-in non-administrator.
	 *
	 * @return void
	 */
	public function testManageRuleOutcomes(): void {
		$cases = [
			'no block'               => [null, ['editors'], false],
			'no manage key'          => [['read' => ['editors']], ['editors'], false],
			'manage not a list'      => [['manage' => 'editors'], ['editors'], false],
			'manage empty'           => [['manage' => []], ['editors'], false],
			'plain group shared'     => [['manage' => ['editors']], ['staff', 'editors'], true],
			'object group shared'    => [['manage' => [['group' => 'editors']]], ['editors'], true],
			'odd entries skipped'    => [['manage' => [7, ['group' => 9], ['name' => 'editors'], 'editors']], ['editors'], true],
			'no shared group'        => [['manage' => ['editors', ['group' => 'admins-ish']]], ['staff'], false],
			'membership unreadable'  => [['manage' => ['editors']], null, false],
		];

		foreach ($cases as $label => [$authorization, $groupIds, $expected]) {
			$this->setUp();
			$gate = $this->gateFor($groupIds);
			$this->assertSame($expected, $gate->mayManage($this->schema($authorization)), $label);
		}
	}//end testManageRuleOutcomes()

	/**
	 * Registers are decided by the same rule.
	 *
	 * @return void
	 */
	public function testRegistersUseTheSameRule(): void {
		$gate     = $this->gateFor(['editors']);
		$register = new Register();
		$register->setAuthorization(['manage' => ['editors']]);
		$this->assertTrue($gate->mayManageRegister($register));

		$register->setAuthorization(['manage' => ['others']]);
		$this->assertFalse($gate->mayManageRegister($register));
	}//end testRegistersUseTheSameRule()

	/**
	 * Without a session nobody may manage; an administrator always may.
	 *
	 * @return void
	 */
	public function testSessionAndAdministrator(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->groups->expects($this->never())->method('isAdmin');
		$anonymous = new SchemaManageGate($this->session, $this->groups, $this->schemas);
		$this->assertFalse($anonymous->mayManage($this->schema(['manage' => ['editors']])));

		$this->setUp();
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('root');
		$this->session->method('getUser')->willReturn($admin);
		$this->groups->method('isAdmin')->with('root')->willReturn(true);
		$this->groups->expects($this->never())->method('getUserGroupIds');
		$gate = new SchemaManageGate($this->session, $this->groups, $this->schemas);
		$this->assertTrue($gate->mayManage($this->schema(null)));
	}//end testSessionAndAdministrator()

	/**
	 * Rows are checked schema by schema, once each, in row order; the first refusal is returned.
	 *
	 * @return void
	 */
	public function testFirstUnmanageableRowSchema(): void {
		$gate   = $this->gateFor(['editors']);
		$looked = [];
		$this->schemas->method('find')->willReturnCallback(
			function (string $id) use (&$looked): Schema {
				$looked[] = $id;
				if ($id === 'gone') {
					throw new DoesNotExistException('gone');
				}

				return $this->schema(['manage' => [($id === 'locked' ? 'others' : 'editors')]]);
			}
		);

		$rows = [
			'not-a-row',
			['@self' => []],
			['@self' => ['schema' => '']],
			['@self' => ['schema' => ['x']]],
			['@self' => ['schema' => 12]],
			['@self' => ['schema' => 'open']],
			['@self' => ['schema' => '12']],
			['@self' => ['schema' => 'locked']],
			['@self' => ['schema' => 'gone']],
		];
		$this->assertSame('locked', $gate->firstUnmanageableRowSchema($rows));
		$this->assertSame(['12', 'open', 'locked'], $looked);

		$looked = [];
		$this->assertSame('gone', $gate->firstUnmanageableRowSchema([['@self' => ['schema' => 'open']], ['@self' => ['schema' => 'gone']]]));
		$this->assertSame(['open', 'gone'], $looked);

		$looked = [];
		$this->assertNull($gate->firstUnmanageableRowSchema([['@self' => ['schema' => 'open']], ['@self' => ['schema' => true]], []]));
		$this->assertSame(['open', '1'], $looked);
	}//end testFirstUnmanageableRowSchema()
}//end class
