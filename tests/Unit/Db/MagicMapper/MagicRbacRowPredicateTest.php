<?php

/**
 * The unaliased row predicate and the "does any rule admit me" question that
 * the aggregation fast path asks.
 *
 * Also pins a raw-SQL emitter defect found on the way: a conditional rule on
 * the `authenticated` pseudo-group (`{group: authenticated, match: {...}}`,
 * learniq's "the rows about me" shape) was admitted by the QueryBuilder
 * emitter and by hasPermission(), and denied by processConditionalRuleSql(),
 * because nobody is a member of a real group named `authenticated`.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacResolvers;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * `buildRbacRowPredicateSql()` and `callerQualifiesForAction()`.
 */
class MagicRbacRowPredicateTest extends TestCase {
	private const SELF_RULE = ['group' => 'authenticated', 'match' => ['learnerId' => '$userId']];

	/**
	 * A handler whose caller is the given user in the given groups.
	 *
	 * @param string        $userId The caller.
	 * @param array<string> $groups The caller's groups.
	 *
	 * @return MagicRbacHandler The handler.
	 */
	private function handlerFor(string $userId, array $groups = []): MagicRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$conditionMatcher = $this->createMock(ConditionMatcher::class);
		$conditionMatcher->method('resolveDynamicValue')->willReturnCallback(
			static fn (mixed $value): mixed => ($value === '$userId') ? $userId : $value
		);

		return new MagicRbacHandler(
			$userSession,
			$groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$conditionMatcher,
			$this->createMock(ContainerInterface::class),
			new NullLogger(),
			new RbacResolvers(
				objectScopeResolver: null,
				objectGrantResolver: null,
				denyResolver: new DenyResolver(new DenyEntryMatcher())
			)
		);
	}//end handlerFor()

	/**
	 * A schema carrying the given authorization block.
	 *
	 * @param array<string, mixed> $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(2201);
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()

	/**
	 * An admin is not restricted: null, not a predicate.
	 *
	 * @return void
	 */
	public function testAnAdminGetsNoPredicate(): void {
		$predicate = $this->handlerFor('root', ['admin'])->buildRbacRowPredicateSql(
			schema: $this->schemaWith(['read' => ['instructors']])
		);

		$this->assertNull($predicate);
	}//end testAnAdminGetsNoPredicate()

	/**
	 * The `authenticated` conditional rule narrows to the caller's own rows.
	 *
	 * @return void
	 */
	public function testTheAuthenticatedSelfRuleNarrowsToTheCallersRows(): void {
		$predicate = $this->handlerFor('learner-a', ['students'])->buildRbacRowPredicateSql(
			schema: $this->schemaWith(['read' => ['instructors', self::SELF_RULE]])
		);

		$this->assertNotNull($predicate);
		$this->assertStringContainsString("learner_id = 'learner-a'", $predicate);
	}//end testTheAuthenticatedSelfRuleNarrowsToTheCallersRows()

	/**
	 * A caller no rule admits gets a predicate that still admits its OWN
	 * objects (the owner rule) and nothing else, never an empty string.
	 *
	 * @return void
	 */
	public function testACallerNoRuleAdmitsIsNotGivenAnEmptyPredicate(): void {
		$predicate = $this->handlerFor('outsider', ['students'])->buildRbacRowPredicateSql(
			schema: $this->schemaWith(['read' => ['instructors']])
		);

		$this->assertNotNull($predicate);
		$this->assertNotSame('', trim($predicate));
		$this->assertStringNotContainsString('learner_id', $predicate);
	}//end testACallerNoRuleAdmitsIsNotGivenAnEmptyPredicate()

	/**
	 * Qualifying: a simple group rule, the authenticated self rule, and not a
	 * rule for a group the caller is not in.
	 *
	 * @return void
	 */
	public function testCallerQualifiesForAction(): void {
		$schema = $this->schemaWith(['read' => ['instructors', self::SELF_RULE], 'create' => ['instructors']]);

		$this->assertTrue($this->handlerFor('teacher', ['instructors'])->callerQualifiesForAction(schema: $schema));
		$this->assertTrue(
			$this->handlerFor('learner-a', ['students'])->callerQualifiesForAction(schema: $schema),
			'the authenticated self rule admits any signed-in caller to its own rows'
		);
		$this->assertFalse(
			$this->handlerFor('learner-a', ['students'])->callerQualifiesForAction(schema: $schema, action: 'create')
		);
		$this->assertFalse(
			$this->handlerFor('outsider', ['students'])->callerQualifiesForAction(
				schema: $this->schemaWith(['read' => ['instructors']])
			)
		);
	}//end testCallerQualifiesForAction()
}//end class
