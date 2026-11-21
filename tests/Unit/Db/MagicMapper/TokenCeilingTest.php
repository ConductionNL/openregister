<?php

/**
 * The token ceiling as the magic mapper applies it, and that it binds the administrator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/tasks.md#2-1c
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.


namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use DateTimeImmutable;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport;
use OCA\OpenRegister\Db\MagicMapper\RbacAccessCall;
use OCA\OpenRegister\Db\MagicMapper\RbacMatchSql;
use OCA\OpenRegister\Db\MagicMapper\RbacPermissionAsk;
use OCA\OpenRegister\Db\MagicMapper\RbacResolvers;
use OCA\OpenRegister\Db\MagicMapper\RbacSqlDialect;
use OCA\OpenRegister\Db\MagicMapper\TokenCeiling;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantReach;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantTarget;
use OCA\OpenRegister\Service\Rbac\TokenGrantTerms;
use OCA\OpenRegister\Service\Rbac\TokenGrantUse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\TokenCeiling
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacAccessCall
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacMatchSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacOperatorTerm
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacPermissionAsk
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacPermissionCheck
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacResolvers
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacSqlDialect
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\RbacCollaborators
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrant
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantNarrower
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantReach
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantSource
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantTarget
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantTerms
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantUse
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenNarrowing
 */
final class TokenCeilingTest extends TestCase {

	/**
	 * A supplier's grant: read on `zaak`, its own rows only.
	 *
	 * @param array $verbs The verbs granted.
	 *
	 * @return TokenGrant The grant.
	 */
	private static function supplierGrant(array $verbs = ['read']): TokenGrant {
		return new TokenGrant(
			verbs: $verbs,
			reach: new TokenGrantReach(schemas: ['zaak'], match: ['leverancier' => TokenGrantNarrower::TOKEN_SUBJECT]),
			terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable('+1 year')),
			tokenId: 'lev-42'
		);
	}//end supplierGrant()

	/**
	 * A schema `zaak` with a `leverancier` property.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setId(3);
		$schema->setSlug('zaak');
		$schema->setTitle('Zaak');
		$schema->setProperties(['leverancier' => ['type' => 'string'], 'status' => ['type' => 'string']]);
		$schema->setAuthorization(['read' => ['medewerkers'], 'update' => ['medewerkers']]);

		return $schema;
	}//end schema()

	/**
	 * The schema's block narrowed by a grant.
	 *
	 * @param TokenGrant $grant The grant.
	 *
	 * @return array<string, mixed> The narrowed block.
	 */
	private static function narrowed(TokenGrant $grant): array {
		return (new TokenGrantNarrower())->narrow(
			authorization: self::schema()->getAuthorization(),
			use: new TokenGrantUse(grant: $grant, target: new TokenGrantTarget(schemaSlug: 'zaak', registerSlug: 'zaken'))
		);
	}//end narrowed()

	/**
	 * A matcher that compares equality conditions the way ConditionMatcher does.
	 *
	 * @return ConditionMatcher The matcher.
	 */
	private function matcher(): ConditionMatcher {
		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('resolveDynamicValue')->willReturnArgument(0);
		$matcher->method('namesUnknownMetadata')->willReturn(false);
		$matcher->method('objectMatchesConditions')->willReturnCallback(
			static function (array $object, array $match): bool {
				foreach ($match as $key => $value) {
					if (($object[$key] ?? null) !== $value) {
						return false;
					}
				}

				return true;
			}
		);

		return $matcher;
	}//end matcher()

	/**
	 * The ceiling under test.
	 *
	 * @return TokenCeiling The ceiling.
	 */
	private function ceiling(): TokenCeiling {
		$matcher = $this->matcher();

		return new TokenCeiling(
			narrower: new TokenGrantNarrower(),
			compiler: new RbacMatchSql($matcher, new RbacSqlDialect($this->createMock(ContainerInterface::class), new NullLogger())),
			matcher: $matcher
		);
	}//end ceiling()

	/**
	 * A call on the schema with a resolved block.
	 *
	 * @param array|null $authorization The block.
	 * @param string     $action        The action.
	 *
	 * @return RbacAccessCall The call.
	 */
	private static function call(?array $authorization, string $action = 'read'): RbacAccessCall {
		$call = (new RbacAccessCall(schema: self::schema(), columnPrefix: 't.'))->forAction(action: $action);
		$call->authorization = $authorization;

		return $call;
	}//end call()

	public function testTheRowConditionBecomesThePredicate(): void {
		$predicate = $this->ceiling()->predicate(call: self::call(authorization: self::narrowed(grant: self::supplierGrant())));

		$this->assertNotNull($predicate);
		$this->assertStringContainsString('leverancier', $predicate);
		$this->assertStringContainsString("'lev-42'", $predicate);
		$this->assertNotSame(TokenCeiling::NONE, $predicate);
	}//end testTheRowConditionBecomesThePredicate()

	public function testAVerbTheGrantLacksReachesNoRow(): void {
		$this->assertSame(
			TokenCeiling::NONE,
			$this->ceiling()->predicate(call: self::call(authorization: self::narrowed(grant: self::supplierGrant()), action: 'update'))
		);
	}//end testAVerbTheGrantLacksReachesNoRow()

	public function testABlockThatWasNotNarrowedReachesNoRow(): void {
		$this->assertSame(TokenCeiling::NONE, $this->ceiling()->predicate(call: self::call(authorization: ['read' => ['medewerkers']])));
		$this->assertSame(TokenCeiling::NONE, $this->ceiling()->predicate(call: self::call(authorization: null)));
	}//end testABlockThatWasNotNarrowedReachesNoRow()

	public function testAGrantWithoutAConditionPutsNoPredicateOnTheRows(): void {
		$grant = new TokenGrant(verbs: ['read'], terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable('+1 year')), tokenId: 't');

		$this->assertNull($this->ceiling()->predicate(call: self::call(authorization: self::narrowed(grant: $grant))));
	}//end testAGrantWithoutAConditionPutsNoPredicateOnTheRows()

	public function testAConditionOnAColumnTheTableLacksReachesNoRow(): void {
		$grant = new TokenGrant(
			verbs: ['read'],
			reach: new TokenGrantReach(match: ['bestaatNiet' => 'x']),
			terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable('+1 year')),
			tokenId: 't'
		);

		$this->assertSame(TokenCeiling::NONE, $this->ceiling()->predicate(call: self::call(authorization: self::narrowed(grant: $grant))));
	}//end testAConditionOnAColumnTheTableLacksReachesNoRow()

	public function testRefusesAnObjectOutsideTheCondition(): void {
		$block = self::narrowed(grant: self::supplierGrant());
		$ceiling = $this->ceiling();

		$this->assertTrue($ceiling->refuses(authorization: $block, action: 'read', objectData: ['leverancier' => 'lev-99']));
		$this->assertFalse($ceiling->refuses(authorization: $block, action: 'read', objectData: ['leverancier' => 'lev-42']));
		$this->assertFalse($ceiling->refuses(authorization: $block, action: 'read', objectData: null), 'no object: the verbs alone decide');
		$this->assertTrue($ceiling->refuses(authorization: $block, action: 'update', objectData: ['leverancier' => 'lev-42']));
		$this->assertTrue($ceiling->refuses(authorization: ['read' => ['x']], action: 'read', objectData: ['leverancier' => 'lev-42']));
	}//end testRefusesAnObjectOutsideTheCondition()

	public function testUnderTheCeilingAnOpenResultIsTheCeilingAndEveryConditionIsAndedWithIt(): void {
		$this->assertSame(['bypass' => false, 'conditions' => ['c']], TokenCeiling::under(result: ['bypass' => true, 'conditions' => []], ceiling: 'c'));
		$this->assertSame(
			['bypass' => false, 'conditions' => ['(a) AND (c)', '(b) AND (c)']],
			TokenCeiling::under(result: ['bypass' => false, 'conditions' => ['a', 'b']], ceiling: 'c')
		);
		$this->assertSame(['bypass' => false, 'conditions' => []], TokenCeiling::under(result: ['bypass' => false, 'conditions' => []], ceiling: 'c'));
	}//end testUnderTheCeilingAnOpenResultIsTheCeilingAndEveryConditionIsAndedWithIt()

	/**
	 * An administrator's handler, optionally with a bound grant.
	 *
	 * @param TokenGrant|null $grant The grant the request carries.
	 *
	 * @return MagicRbacHandler The handler.
	 */
	private function adminHandler(?TokenGrant $grant): MagicRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['admin']);

		$block = self::schema()->getAuthorization();
		if ($grant !== null) {
			$block = self::narrowed(grant: $grant);
		}

		$permissionHandler = $this->createMock(PermissionHandlerInterface::class);
		$permissionHandler->method('resolveAuthorization')->willReturn($block);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($permissionHandler): ?PermissionHandlerInterface {
				if ($id === PermissionHandlerInterface::class) {
					return $permissionHandler;
				}

				return null;
			}
		);

		$source = new TokenGrantSource();
		$source->bind(grant: $grant);

		return new MagicRbacHandler(
			identity: new MagicMapperIdentity(
				userSession: $userSession,
				groupManager: $groupManager,
				userManager: $this->createMock(IUserManager::class)
			),
			settings: new MagicRbacSettings(appConfig: $this->createMock(IAppConfig::class)),
			support: new MagicRbacSupport(conditionMatcher: $this->matcher(), container: $container, logger: new NullLogger()),
			resolvers: new RbacResolvers(tokenGrantSource: $source)
		);
	}//end adminHandler()

	public function testTheAdministratorsRowPredicateIsTheCeiling(): void {
		$call = (new RbacAccessCall(schema: self::schema(), columnPrefix: 't.'))->forAction(action: 'read');
		$predicate = $this->adminHandler(grant: self::supplierGrant())->buildRbacRowPredicateSql(call: $call);

		$this->assertNotNull($predicate, 'an administrator holding a supplier token is not unfiltered');
		$this->assertStringContainsString("'lev-42'", $predicate);

		$this->assertNull(
			$this->adminHandler(grant: null)->buildRbacRowPredicateSql(call: self::call(authorization: null)),
			'without a token the administrator keeps the bypass'
		);
	}//end testTheAdministratorsRowPredicateIsTheCeiling()

	public function testTheAdministratorIsRefusedAnotherSuppliersRow(): void {
		$handler = $this->adminHandler(grant: self::supplierGrant());

		$this->assertFalse($handler->hasPermission(ask: self::ask(action: 'read', leverancier: 'lev-99')));
		$this->assertTrue($handler->hasPermission(ask: self::ask(action: 'read', leverancier: 'lev-42')));
		$this->assertFalse($handler->hasPermission(ask: self::ask(action: 'update', leverancier: 'lev-42')));
		$this->assertTrue(
			$this->adminHandler(grant: null)->hasPermission(ask: self::ask(action: 'update', leverancier: 'lev-99')),
			'without a token the administrator is unaffected'
		);
	}//end testTheAdministratorIsRefusedAnotherSuppliersRow()

	/**
	 * A one-object question about a supplier's row of `zaak`.
	 *
	 * @param string $action      The action.
	 * @param string $leverancier The row's supplier.
	 *
	 * @return RbacPermissionAsk The question.
	 */
	private static function ask(string $action, string $leverancier): RbacPermissionAsk {
		return new RbacPermissionAsk(schema: self::schema(), action: $action, objectOwner: null, objectData: ['leverancier' => $leverancier]);
	}//end ask()
}//end class
