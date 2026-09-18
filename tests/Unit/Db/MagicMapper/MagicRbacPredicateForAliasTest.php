<?php

/**
 * The access predicate a related-row subquery is handed.
 *
 * 🔴 THE FAILURE THIS GUARDS IS SILENT AND FAILS OPEN. Inside
 * `EXISTS (SELECT 1 FROM <related> r0 WHERE ...)` an unqualified `_owner`
 * still parses, and binds to the innermost FROM, so it looks correct. It is
 * correct by accident: the moment the related table lacks the column, SQL
 * resolves the name against the OUTER query instead, and the access check
 * passes by testing the wrong row. Nothing errors and nothing logs.
 *
 * 🔑 AND THE TWO DEGENERATE ANSWERS MATTER MORE THAN THE ORDINARY ONE. An
 * empty predicate AND-ed into a WHERE is not "no opinion", it is "admit
 * everything", so deny-all must come back as `FALSE` and never as an empty
 * string.
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

use InvalidArgumentException;
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
 * `buildRbacPredicateForAlias()`.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler::buildRbacPredicateForAlias
 */
class MagicRbacPredicateForAliasTest extends TestCase {

	/**
	 * A handler whose caller is the given user in the given groups.
	 *
	 * @param string|null   $userId The caller, or null when unauthenticated.
	 * @param array<string> $groups The caller's groups.
	 *
	 * @return MagicRbacHandler The handler.
	 */
	private function handlerFor(?string $userId, array $groups = []): MagicRbacHandler {
		$userSession = $this->createMock(IUserSession::class);
		if ($userId === null) {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$userSession->method('getUser')->willReturn($user);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new MagicRbacHandler(
			$userSession,
			$groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ConditionMatcher::class),
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
		$schema->setId(1108);
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()

	/**
	 * 🔴 EVERY COLUMN IS QUALIFIED WITH THE ALIAS.
	 *
	 * This is the whole reason the method exists. A bare `_owner` inside a
	 * subquery binds to whichever FROM happens to carry it, which is right by
	 * accident and silently wrong when the related table does not.
	 *
	 * @return void
	 */
	public function testEveryColumnIsQualifiedWithTheAlias(): void {
		$predicate = $this->handlerFor('alice')->buildRbacPredicateForAlias(
			schema: $this->schemaWith([]),
			alias: 'r0'
		);

		$this->assertStringContainsString('r0._owner', $predicate);
		$this->assertDoesNotMatchRegularExpression(
			'/(?<![A-Za-z0-9_.])_owner/',
			$predicate,
			'An unqualified column would bind to the wrong table inside a subquery.'
		);
	}//end testEveryColumnIsQualifiedWithTheAlias()

	/**
	 * A different alias moves every column with it.
	 *
	 * Pinned separately so a hardcoded `r0.` could not pass the test above.
	 *
	 * @return void
	 */
	public function testTheAliasIsTheOneAskedFor(): void {
		$predicate = $this->handlerFor('alice')->buildRbacPredicateForAlias(
			schema: $this->schemaWith([]),
			alias: 'related7'
		);

		$this->assertStringContainsString('related7._owner', $predicate);
		$this->assertStringNotContainsString('r0.', $predicate);
	}//end testTheAliasIsTheOneAskedFor()

	/**
	 * 🔑 DENY-ALL IS `FALSE`, NOT AN EMPTY STRING.
	 *
	 * An unauthenticated caller against a configured schema matches no rule.
	 * Returning '' would be AND-ed into the subquery as nothing at all, which
	 * reads as "admit everything": the exact inversion of the answer.
	 *
	 * @return void
	 */
	public function testDenyAllIsSaidOutLoudRatherThanLeftEmpty(): void {
		$predicate = $this->handlerFor(null)->buildRbacPredicateForAlias(
			schema: $this->schemaWith(['read' => ['editors']]),
			alias: 'r0'
		);

		$this->assertSame('FALSE', $predicate);
		$this->assertNotSame('', $predicate);
	}//end testDenyAllIsSaidOutLoudRatherThanLeftEmpty()

	/**
	 * An admin bypass is `TRUE`, which is also a predicate.
	 *
	 * @return void
	 */
	public function testAnAdminBypassIsATruePredicate(): void {
		$predicate = $this->handlerFor('root', ['admin'])->buildRbacPredicateForAlias(
			schema: $this->schemaWith(['read' => ['editors']]),
			alias: 'r0'
		);

		$this->assertSame('TRUE', $predicate);
	}//end testAnAdminBypassIsATruePredicate()

	/**
	 * The predicate is never empty, whoever asks.
	 *
	 * The clause it feeds refuses an empty access predicate, so an empty return
	 * here would turn a security guarantee into a thrown exception at best and
	 * a skipped check at worst.
	 *
	 * @return void
	 */
	public function testThePredicateIsNeverEmpty(): void {
		$callers = [
			['alice', []],
			['alice', ['editors']],
			[null, []],
			['root', ['admin']],
		];

		foreach ($callers as [$userId, $groups]) {
			$predicate = $this->handlerFor($userId, $groups)->buildRbacPredicateForAlias(
				schema: $this->schemaWith(['read' => ['editors']]),
				alias: 'r0'
			);

			$this->assertNotSame('', trim($predicate), 'Empty for ' . var_export($userId, true));
		}
	}//end testThePredicateIsNeverEmpty()

	/**
	 * An alias that is not an identifier is refused, not interpolated.
	 *
	 * The alias goes into the SQL as a bare identifier, because no engine takes
	 * a placeholder there.
	 *
	 * @return void
	 */
	public function testANonIdentifierAliasIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->handlerFor('alice')->buildRbacPredicateForAlias(
			schema: $this->schemaWith([]),
			alias: 'r0; DROP TABLE x --'
		);
	}//end testANonIdentifierAliasIsRefused()

	/**
	 * The unqualified callers are untouched.
	 *
	 * `buildRbacConditionsSql()` feeds UNION members that carry no alias, so it
	 * must keep emitting bare column names. Threading a prefix through the
	 * shared emitters could easily have changed them for everybody.
	 *
	 * @return void
	 */
	public function testTheUnionCallersStillGetUnqualifiedColumns(): void {
		$result = $this->handlerFor('alice')->buildRbacConditionsSql(
			schema: $this->schemaWith([]),
			action: 'read'
		);

		$joined = implode(' ', $result['conditions']);

		$this->assertStringContainsString('_owner', $joined);
		$this->assertStringNotContainsString('r0._owner', $joined);
		$this->assertStringNotContainsString('t._owner', $joined);
	}//end testTheUnionCallersStillGetUnqualifiedColumns()
}//end class
