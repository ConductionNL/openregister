<?php

/**
 * A scope requiring a second factor lists nothing to a reader without one (REQ-IHC-003).
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
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport;
use OCA\OpenRegister\Db\MagicMapper\RbacAccessCall;
use OCA\OpenRegister\Db\MagicMapper\RbacFilterCall;
use OCA\OpenRegister\Db\MagicMapper\RbacResolvers;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\SecondFactorReadGate;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\SecondFactorReadGate
 */
class MagicRbacSecondFactorTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function handlerFor(string $userId, array $groups, bool $secondFactor): MagicRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$groupManager->method('isAdmin')->willReturn(in_array('admin', $groups, true));

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$registry = $this->createMock(IRegistry::class);
		$registry->method('getProviderStates')->willReturn(['totp' => $secondFactor]);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('getAllRegisterIdsWithSchema')->willReturn([]);
		$gate = new SecondFactorReadGate($registry, $users, $this->createMock(ISession::class), $registers, new NullLogger());

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[SecondFactorReadGate::class, $gate]]);

		$conditionMatcher = $this->createMock(ConditionMatcher::class);
		$conditionMatcher->method('resolveDynamicValue')->willReturnArgument(0);

		return new MagicRbacHandler(
			identity: new MagicMapperIdentity(userSession: $userSession, groupManager: $groupManager, userManager: $users),
			settings: new MagicRbacSettings(appConfig: $this->createMock(IAppConfig::class)),
			support: new MagicRbacSupport(conditionMatcher: $conditionMatcher, container: $container, logger: new NullLogger()),
			resolvers: new RbacResolvers(objectScopeResolver: null, objectGrantResolver: null, denyResolver: new DenyResolver(new DenyEntryMatcher()))
		);
	}//end handlerFor()

	private static function schema(bool $declares): Schema {
		$schema = new Schema();
		$schema->setId(4401);
		$schema->setTitle('Dossiers');
		$schema->setAuthorization(['read' => ['medewerkers']]);
		$schema->setConfiguration([Schema::SECOND_FACTOR_ANNOTATION => $declares]);

		return $schema;
	}//end schema()

	public function testAReaderWithoutTheFactorListsNothing(): void {
		$result = $this->handlerFor('ann', ['medewerkers'], secondFactor: false)
			->buildRbacConditionsSql(new RbacAccessCall(schema: self::schema(declares: true)));

		$this->assertSame(['bypass' => false, 'conditions' => []], $result);
	}//end testAReaderWithoutTheFactorListsNothing()

	public function testAnAdministratorWithoutTheFactorListsNothingEither(): void {
		$result = $this->handlerFor('root', ['admin'], secondFactor: false)
			->buildRbacConditionsSql(new RbacAccessCall(schema: self::schema(declares: true)));

		$this->assertSame(['bypass' => false, 'conditions' => []], $result);
	}//end testAnAdministratorWithoutTheFactorListsNothingEither()

	public function testWithTheFactorTheGrantsDecideAsBefore(): void {
		$declared = $this->handlerFor('ann', ['medewerkers'], secondFactor: true)
			->buildRbacConditionsSql(new RbacAccessCall(schema: self::schema(declares: true)));
		$plain = $this->handlerFor('ann', ['medewerkers'], secondFactor: true)
			->buildRbacConditionsSql(new RbacAccessCall(schema: self::schema(declares: false)));

		$this->assertNotSame([], $declared['conditions']);
		$this->assertSame($plain, $declared);
	}//end testWithTheFactorTheGrantsDecideAsBefore()

	public function testAnUndeclaredSchemaListsAsBeforeWithoutTheFactor(): void {
		$result = $this->handlerFor('ann', ['medewerkers'], secondFactor: false)
			->buildRbacConditionsSql(new RbacAccessCall(schema: self::schema(declares: false)));

		$this->assertNotSame([], $result['conditions']);
	}//end testAnUndeclaredSchemaListsAsBeforeWithoutTheFactor()

	public function testAWriteIsNotGatedHere(): void {
		$result = $this->handlerFor('root', ['admin'], secondFactor: false)
			->buildRbacConditionsSql((new RbacAccessCall(schema: self::schema(declares: true)))->forAction('update'));

		$this->assertTrue($result['bypass']);
	}//end testAWriteIsNotGatedHere()

	public function testTheQueryBuilderPathDeniesAll(): void {
		$this->queryLog = [];
		$this->handlerFor('root', ['admin'], secondFactor: false)->applyRbacFilters(
			new RbacFilterCall(qb: $this->recordingBuilder(), schema: self::schema(declares: true))
		);

		$this->assertNotSame([], $this->queryLog, 'An administrator without the factor is filtered, not bypassed.');
		$log = implode("\n", $this->queryLog);
		$this->assertStringContainsString('andWhere(', $log);
		$this->assertStringContainsString('eq(', $log, 'The query is clamped with 1 = 0.');
	}//end testTheQueryBuilderPathDeniesAll()

	public function testTheQueryBuilderPathLeavesAnAdministratorWithTheFactorUnfiltered(): void {
		$this->queryLog = [];
		$this->handlerFor('root', ['admin'], secondFactor: true)->applyRbacFilters(
			new RbacFilterCall(qb: $this->recordingBuilder(), schema: self::schema(declares: true))
		);

		$this->assertSame([], $this->queryLog);
	}//end testTheQueryBuilderPathLeavesAnAdministratorWithTheFactorUnfiltered()
}//end class
