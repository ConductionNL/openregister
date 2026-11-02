<?php

/**
 * Tests for the `$memberOf` operator in ConditionMatcher: the single-object verdict.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/access-follows-membership/specs/rbac-scopes/spec.md#requirement-a-match-can-admit-by-the-callers-membership-records
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Rbac\MemberOfResolver;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * `$memberOf` resolves to `$in` over the caller's set, so the verdict follows the membership.
 */
class ConditionMatcherMemberOfTest extends TestCase {

	private const OPERAND = ['schema' => 'administration-membership', 'property' => 'administrationId'];

	private const MATCH = ['administrationId' => ['$memberOf' => self::OPERAND]];

	/**
	 * The user ids the membership rows were read for.
	 *
	 * @var array<int, string|null>
	 */
	private array $askedFor = [];

	/**
	 * A real resolver whose membership rows give the caller `$set`.
	 *
	 * @param string             $userId The caller the rows belong to.
	 * @param array<string>|null $set    The administrations, or null to make the operand unresolvable.
	 *
	 * @return MemberOfResolver
	 */
	private function resolver(string $userId, ?array $set): MemberOfResolver {
		$schema = new Schema();
		$schema->setId(50);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$register = new Register();
		$register->setId(8);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('getFirstRegisterWithSchema')->willReturn(8);
		$registers->method('find')->willReturn($register);

		$objects = $this->createMock(MagicMapper::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query) use ($set, $userId): array {
				$this->askedFor[] = $query['userId'];
				return array_map(
					static function (string $administration) use ($userId): ObjectEntity {
						$row = new ObjectEntity();
						$row->setObject(['userId' => $userId, 'administrationId' => $administration]);
						return $row;
					},
					($set ?? [])
				);
			}
		);

		return new MemberOfResolver($schemas, $registers, $this->containerGiving($objects), new OperatorEvaluator(new NullLogger()), new NullLogger());
	}//end resolver()

	/**
	 * A matcher whose caller is `$userId` and whose membership rows give `$set`.
	 *
	 * @param string|null        $userId The caller, or null for nobody.
	 * @param array<string>|null $set    The caller's administrations.
	 *
	 * @return ConditionMatcher
	 */
	private function matcher(?string $userId, ?array $set): ConditionMatcher {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);

		return new ConditionMatcher(
			$session,
			$this->createMock(ContainerInterface::class),
			new OperatorEvaluator(new NullLogger()),
			new NullLogger(),
			null,
			$this->resolver(userId: (string)$userId, set: $set)
		);
	}//end matcher()

	/**
	 * The operator resolves to `$in` over the caller's set.
	 *
	 * @return void
	 */
	public function testTheOperatorResolvesToInOverTheSet(): void {
		$matcher = $this->matcher('anna', ['adm-1', 'adm-2']);

		$this->assertSame(['$in' => ['adm-1', 'adm-2']], $matcher->resolveDynamicValue(['$memberOf' => self::OPERAND]));
		$this->assertSame(['anna'], $this->askedFor);
	}//end testTheOperatorResolvesToInOverTheSet()

	/**
	 * A member's administration is admitted, another administration is refused.
	 *
	 * @return void
	 */
	public function testTheVerdictFollowsTheMembership(): void {
		$matcher = $this->matcher('anna', ['adm-1', 'adm-2']);

		$this->assertTrue($matcher->objectMatchesConditions(['administrationId' => 'adm-1'], self::MATCH));
		$this->assertTrue($matcher->objectMatchesConditions(['administrationId' => 'adm-2'], self::MATCH));
		$this->assertFalse($matcher->objectMatchesConditions(['administrationId' => 'adm-3'], self::MATCH));
		$this->assertFalse($matcher->objectMatchesConditions([], self::MATCH), 'a row with no administration');
	}//end testTheVerdictFollowsTheMembership()

	/**
	 * A resolved relation is compared by its id, as for every other operator.
	 *
	 * @return void
	 */
	public function testAResolvedRelationIsComparedByItsId(): void {
		$matcher = $this->matcher('anna', ['adm-1']);

		$this->assertTrue($matcher->objectMatchesConditions(['administrationId' => ['id' => 'adm-1', 'name' => 'A']], self::MATCH));
	}//end testAResolvedRelationIsComparedByItsId()

	/**
	 * A caller without memberships is refused everything.
	 *
	 * @return void
	 */
	public function testAnEmptySetAdmitsNothing(): void {
		$matcher = $this->matcher('bram', []);

		$this->assertSame(['$in' => []], $matcher->resolveDynamicValue(['$memberOf' => self::OPERAND]));
		$this->assertFalse($matcher->objectMatchesConditions(['administrationId' => 'adm-1'], self::MATCH));
	}//end testAnEmptySetAdmitsNothing()

	/**
	 * Nobody signed in is asked about as nobody.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsAskedAboutAsNobody(): void {
		$matcher = $this->matcher(null, []);

		$this->assertFalse($matcher->objectMatchesConditions(['administrationId' => 'adm-1'], self::MATCH));
		$this->assertSame([], $this->askedFor, 'nobody signed in is a member of nothing, without a read');
	}//end testAnAnonymousCallerIsAskedAboutAsNobody()

	/**
	 * An unresolvable operand makes the whole property unresolvable, which denies.
	 *
	 * @return void
	 */
	public function testAnUnresolvableOperandDenies(): void {
		$matcher = $this->matcher('anna', ['adm-1']);
		$broken = ['administrationId' => ['$memberOf' => ['schema' => 'administration-membership']]];

		$this->assertNull($matcher->resolveDynamicValue(['$memberOf' => ['schema' => '']]));
		$this->assertFalse($matcher->objectMatchesConditions(['administrationId' => 'adm-1'], $broken));
	}//end testAnUnresolvableOperandDenies()

	/**
	 * A sibling `$in` narrows the set; other sibling operators still apply.
	 *
	 * @return void
	 */
	public function testSiblingOperatorsStillApply(): void {
		$matcher = $this->matcher('anna', ['adm-1', 'adm-2']);

		$withIn = ['$memberOf' => self::OPERAND, '$in' => ['adm-2', 'adm-9']];
		$this->assertSame(['$in' => ['adm-2']], $matcher->resolveDynamicValue($withIn));

		$withNe = ['administrationId' => ['$memberOf' => self::OPERAND, '$ne' => 'adm-2']];
		$this->assertTrue($matcher->objectMatchesConditions(['administrationId' => 'adm-1'], $withNe));
		$this->assertFalse($matcher->objectMatchesConditions(['administrationId' => 'adm-2'], $withNe));

		$this->assertNull(
			$matcher->resolveDynamicValue(['$memberOf' => self::OPERAND, '$in' => 'adm-2']),
			'a sibling $in that is not a list denies'
		);
	}//end testSiblingOperatorsStillApply()

	/**
	 * A matcher built without a resolver admits nobody through `$memberOf`.
	 *
	 * @return void
	 */
	public function testWithoutAResolverTheRuleAdmitsNobody(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$matcher = new ConditionMatcher(
			$session,
			$this->createMock(ContainerInterface::class),
			new OperatorEvaluator(new NullLogger()),
			new NullLogger()
		);

		$this->assertNull($matcher->resolveDynamicValue(['$memberOf' => self::OPERAND]));
		$this->assertFalse($matcher->objectMatchesConditions(['administrationId' => 'adm-1'], self::MATCH));
	}//end testWithoutAResolverTheRuleAdmitsNobody()

	/**
	 * A container that hands out the given MagicMapper, as the real one does on first read.
	 *
	 * @param MagicMapper $objects The mapper to hand out.
	 *
	 * @return ContainerInterface
	 */
	private function containerGiving(MagicMapper $objects): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): mixed => ($id === MagicMapper::class) ? $objects : null
		);
		return $container;
	}//end containerGiving()
}//end class
