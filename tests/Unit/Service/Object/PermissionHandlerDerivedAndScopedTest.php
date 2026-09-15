<?php

/**
 * A grant that ended, a grant that belongs elsewhere, and a grant nobody typed.
 *
 * The three cases of section 8 as the verdict sees them, rather than as the
 * readers of the rules see them. Each one is a subtraction or an addition that
 * has to survive the whole chain, and each has a control beside it: the same
 * fixture, one field different, answering the other way. Without that, a rule
 * that silently does nothing looks exactly like a rule that works.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\GrantConstraints;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tasks 8.1, 8.2 and 8.4, decided rather than described.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerDerivedAndScopedTest extends TestCase {

	/**
	 * Hands out a distinct schema id per schema built in a case.
	 *
	 * The verdict is memoised per request on (user, schema, action, owner,
	 * uuid), so two schemas sharing an id inside one case would have the first
	 * answer for the second.
	 *
	 * @var integer
	 */
	private int $schemaCounter = 0;

	/**
	 * Reset the counter before every case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->schemaCounter = 900;
	}//end setUp()

	/**
	 * A handler for one caller, over one register, with optional derived grants.
	 *
	 * @param string                 $userId       The caller.
	 * @param string[]               $groups       The caller's own group IDs.
	 * @param string                 $registerSlug The register the schema belongs to.
	 * @param DerivedGrantStore|null $derived      The store, when the case uses one.
	 *
	 * @return PermissionHandler The handler under test.
	 */
	private function handlerFor(
		string $userId,
		array $groups,
		string $registerSlug = 'zaken',
		?DerivedGrantStore $derived = null,
	): PermissionHandler {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($userId);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		return new PermissionHandler(
			$session,
			$userManager,
			$groupManager,
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: MagicMapper::class),
			$this->createMock(originalClassName: ConditionMatcher::class),
			$appConfig,
			new NullLogger(),
			$this->containerWith(registerSlug: $registerSlug),
			null,
			null,
			null,
			new DenyResolver(),
			new DenyEnforcementMode($appConfig, new NullLogger()),
			null,
			new GrantConstraints(),
			$derived,
			new DerivedGrantResolver()
		);
	}//end handlerFor()

	/**
	 * A container answering with one register.
	 *
	 * @param string $registerSlug The register's slug.
	 *
	 * @return ContainerInterface The container.
	 */
	private function containerWith(string $registerSlug): ContainerInterface {
		$register = new Register();
		$register->setId(9);
		$register->setTitle('Register');
		$register->setSlug($registerSlug);
		$register->setConfiguration([]);

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(9);
		$registerMapper->method('find')->willReturn($register);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registerMapper): mixed {
				if ($id === RegisterMapper::class) {
					return $registerMapper;
				}

				throw new \RuntimeException(sprintf('nothing registered for %s', $id));
			}
		);

		return $container;
	}//end containerWith()

	/**
	 * A schema carrying one block.
	 *
	 * @param array $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(array $authorization): Schema {
		$this->schemaCounter++;

		$schema = new Schema();
		$schema->setId($this->schemaCounter);
		$schema->setTitle('Zaak');
		$schema->setSlug('zaak');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()

	/**
	 * A store answering with one set of derived grants.
	 *
	 * @param array<int, array<string, mixed>> $grants The grants.
	 *
	 * @return DerivedGrantStore The store.
	 */
	private function storeAnswering(array $grants): DerivedGrantStore {
		$store = $this->createMock(originalClassName: DerivedGrantStore::class);
		$store->method('grantsFor')->willReturn($grants);

		return $store;
	}//end storeAnswering()

	/**
	 * 🔴 A grant that ended stops answering, with nothing having run.
	 *
	 * @return void
	 */
	public function testAGrantThatEndedNoLongerAdmits(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['waarnemers']);

		$this->assertFalse(
			$handler->hasPermission(
				schema: $this->schemaWith(
					['read' => [['group' => 'waarnemers', 'until' => '2020-01-01T00:00:00+01:00']]]
				),
				action: 'read',
				userId: 'ana'
			)
		);
	}//end testAGrantThatEndedNoLongerAdmits()

	/**
	 * The same grant with its end ahead still admits.
	 *
	 * The control: without it, the refusal above could be the entry shape being
	 * unreadable rather than the end having passed.
	 *
	 * @return void
	 */
	public function testTheSameGrantWithItsEndAheadStillAdmits(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['waarnemers']);

		$this->assertTrue(
			$handler->hasPermission(
				schema: $this->schemaWith(
					['read' => [['group' => 'waarnemers', 'until' => '2099-01-01T00:00:00+01:00']]]
				),
				action: 'read',
				userId: 'ana'
			)
		);
	}//end testTheSameGrantWithItsEndAheadStillAdmits()

	/**
	 * 🔴 A step's grant dies with the step.
	 *
	 * The spec scenario, written as the verdict sees it: a workflow step grants
	 * its assignee a right until the step's deadline, and the read after that
	 * deadline is refused. There is no second clock and no sweep. The step
	 * writes its own deadline into the grant, so a step that moves its deadline
	 * rewrites the grant the same way it wrote it, and a job that stopped
	 * running could never leave a right standing.
	 *
	 * @return void
	 */
	public function testAStepsGrantDiesWithTheStep(): void {
		$deadline = '2026-09-10T17:00:00+02:00';

		$granted = $this->handlerFor(userId: 'ana', groups: ['gasten']);
		$this->assertTrue(
			$granted->hasPermission(
				schema: $this->schemaWith(
					['read' => [['user' => 'ana', 'until' => '2099-01-01T00:00:00+01:00']]]
				),
				action: 'read',
				userId: 'ana'
			),
			'the assignee could not read it while the step was open, so the refusal below proves nothing'
		);

		$expired = $this->handlerFor(userId: 'ana', groups: ['gasten']);
		$this->assertFalse(
			$expired->hasPermission(
				schema: $this->schemaWith(['read' => [['user' => 'ana', 'until' => $deadline]]]),
				action: 'read',
				userId: 'ana'
			)
		);
	}//end testAStepsGrantDiesWithTheStep()

	/**
	 * 🔴 `manage` scoped to one register does not administer another.
	 *
	 * A delegated administrator is not a second administrator, and the whole
	 * difference is this refusal.
	 *
	 * @return void
	 */
	public function testScopedManageDoesNotReachAnotherRegister(): void {
		$block = ['manage' => [['group' => 'beheerders', 'scopedTo' => ['registers' => ['zaken']]]]];

		$inside = $this->handlerFor(userId: 'noor', groups: ['beheerders'], registerSlug: 'zaken');
		$this->assertTrue(
			$inside->hasPermission(schema: $this->schemaWith($block), action: 'manage', userId: 'noor'),
			'the scoped grant does not administer the register it names'
		);

		$outside = $this->handlerFor(userId: 'noor', groups: ['beheerders'], registerSlug: 'besluiten');
		$this->assertFalse(
			$outside->hasPermission(schema: $this->schemaWith($block), action: 'manage', userId: 'noor'),
			'the scoped grant administers a register it does not name'
		);
	}//end testScopedManageDoesNotReachAnotherRegister()

	/**
	 * 🔴 A group derived at sign-in is matched by the ordinary rules.
	 *
	 * Nothing downstream knows the group was derived, which is the point: a
	 * second matching path is a second place for the two to disagree.
	 *
	 * @return void
	 */
	public function testADerivedGroupIsMatchedLikeAnyOther(): void {
		$store = $this->storeAnswering(
			[['groups' => ['behandelaars'], 'role' => 'behandelaar', 'scopedTo' => null, 'until' => null]]
		);

		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], derived: $store);

		$this->assertTrue(
			$handler->hasPermission(
				schema: $this->schemaWith(['read' => ['behandelaars']]),
				action: 'read',
				userId: 'ana'
			)
		);
	}//end testADerivedGroupIsMatchedLikeAnyOther()

	/**
	 * The same caller without the derivation is refused.
	 *
	 * THE LEAST PRIVILEGED PROBE, and the control for the case above: this
	 * caller is in one group nobody granted anything, so the grant above came
	 * from the derivation and from nothing else.
	 *
	 * @return void
	 */
	public function testTheSameCallerWithoutTheDerivationIsRefused(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], derived: $this->storeAnswering([]));

		$this->assertFalse(
			$handler->hasPermission(
				schema: $this->schemaWith(['read' => ['behandelaars']]),
				action: 'read',
				userId: 'ana'
			)
		);
	}//end testTheSameCallerWithoutTheDerivationIsRefused()

	/**
	 * 🔴 A derived group scoped to one register is not held in another.
	 *
	 * @return void
	 */
	public function testADerivedGroupIsHeldOnlyInItsArea(): void {
		$grants = [
			[
				'groups' => ['behandelaars'],
				'role' => 'behandelaar',
				'scopedTo' => ['registers' => ['vergunningen']],
				'until' => null,
			],
		];

		$inside = $this->handlerFor(
			userId: 'ana',
			groups: ['gasten'],
			registerSlug: 'vergunningen',
			derived: $this->storeAnswering($grants)
		);
		$this->assertTrue(
			$inside->hasPermission(
				schema: $this->schemaWith(['read' => ['behandelaars']]),
				action: 'read',
				userId: 'ana'
			)
		);

		$outside = $this->handlerFor(
			userId: 'ana',
			groups: ['gasten'],
			registerSlug: 'handhaving',
			derived: $this->storeAnswering($grants)
		);
		$this->assertFalse(
			$outside->hasPermission(
				schema: $this->schemaWith(['read' => ['behandelaars']]),
				action: 'read',
				userId: 'ana'
			),
			'the derived group reached a register the rule does not name'
		);
	}//end testADerivedGroupIsHeldOnlyInItsArea()

	/**
	 * A derivation can never make somebody an administrator.
	 *
	 * The administrator check runs before the derived groups are folded in, so a
	 * rule that derived `admin` from a claim adds a group the caller holds and
	 * no bypass at all. The claim is asserted rather than assumed because this is
	 * the one privilege escalation this mechanism could introduce.
	 *
	 * @return void
	 */
	public function testADerivedAdminGroupIsNotAnAdministratorBypass(): void {
		$store = $this->storeAnswering([['groups' => ['admin'], 'role' => null, 'scopedTo' => null, 'until' => null]]);

		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], derived: $store);

		$this->assertFalse(
			$handler->hasPermission(
				schema: $this->schemaWith(['read' => ['behandelaars']]),
				action: 'read',
				userId: 'ana'
			),
			'a derived admin group bypassed the rules'
		);
	}//end testADerivedAdminGroupIsNotAnAdministratorBypass()
}//end class
