<?php

/**
 * OpenRegister - golden test for the refusal PermissionHandler logs.
 *
 * Pins, for a schema-rule decision, which callers are allowed and refused and
 * the context the refusal is logged with (the rule behind it, or the policy
 * reason when no rule names the verb), so the logging unit can be reshaped
 * without changing either.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 */
final class PermissionHandlerRefusalLogGoldenTest extends TestCase {

	private const GROUPS = ['alice' => ['editors'], 'bob' => ['staff']];

	private const REFUSED = '[PermissionHandler] Action refused; the rule that decided it is named below';

	/**
	 * The refusal contexts logged during the current case.
	 *
	 * @var array<int, array>
	 */
	private array $refusals = [];

	/**
	 * A handler whose session caller is the given user.
	 *
	 * @param string $caller The signed-in user.
	 *
	 * @return PermissionHandler
	 */
	private function newHandler(string $caller): PermissionHandler {
		$users = [];
		foreach (array_keys(self::GROUPS) as $uid) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[$uid] = $user;
		}

		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($users[$caller]);
		$manager = $this->createStub(IUserManager::class);
		$manager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($users[$uid] ?? null));
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(static fn (IUser $user): array => self::GROUPS[$user->getUID()]);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$registers = $this->createStub(RegisterMapper::class);
		$registers->method('getFirstRegisterWithSchema')->willReturn(null);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnMap([[RegisterMapperInterface::class, $registers]]);

		$logger = $this->createStub(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				if ($message === self::REFUSED) {
					unset($context['file'], $context['line']);
					$this->refusals[] = $context;
				}
			}
		);

		return new PermissionHandler(
			userSession: $session,
			userManager: $manager,
			groupManager: $groups,
			schemaMapper: $this->createStub(SchemaMapper::class),
			objectEntityMapper: $this->createStub(MagicMapper::class),
			conditionMatcher: $this->createStub(ConditionMatcher::class),
			appConfig: $appConfig,
			logger: $logger,
			container: $container
		);
	}//end newHandler()

	/**
	 * Allowed and refused callers, with the refusal context each logs.
	 *
	 * @return array<string, array>
	 */
	public static function cases(): array {
		$block = ['read' => ['editors'], 'update' => ['editors']];
		$rule  = static fn (string $action, ?array $rule, string $reason, string $user, array $groups, ?string $uuid): array => [
			'schemaId' => 5,
			'objectUuid' => $uuid,
			'action' => $action,
			'userId' => $user,
			'principals' => $groups,
			'rule' => $rule,
			'reason' => $reason,
		];

		return [
			'granted reader logs nothing' => ['alice', $block, 'read', false, true, []],
			'granted updater on a row logs nothing' => ['alice', $block, 'update', true, true, []],
			'caller outside the rule' => [
				'bob', $block, 'read', false, false,
				[$rule('read', ['editors'], 'the rule for this verb does not name this caller', 'bob', ['staff'], null)],
			],
			'caller outside the rule, on a row' => [
				'bob', $block, 'update', true, false,
				[$rule('update', ['editors'], 'the rule for this verb does not name this caller', 'bob', ['staff'], 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')],
			],
			'no rule names the verb' => [
				'alice', $block, 'delete', false, false,
				[$rule('delete', null, 'no authorization block names this verb', 'alice', ['editors'], null)],
			],
		];
	}//end cases()

	/**
	 * The verdict, and the refusal logged with it.
	 *
	 * @param string $caller   The signed-in user.
	 * @param array  $block    The schema's authorization block.
	 * @param string $action   The verb asked.
	 * @param bool   $onRow    Whether the check names a row.
	 * @param bool   $allowed  The expected verdict.
	 * @param array  $refusals The expected refusal contexts.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheVerdictAndTheRefusalLog(
		string $caller,
		array $block,
		string $action,
		bool $onRow,
		bool $allowed,
		array $refusals
	): void {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setAuthorization($block);

		$object = null;
		$owner  = null;
		if ($onRow === true) {
			$owner  = 'ghost';
			$object = new ObjectEntity();
			$object->setUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
			$object->setObject(['title' => 'x']);
			$object->setOwner('ghost');
		}

		$this->refusals = [];
		$verdict = $this->newHandler(caller: $caller)->hasPermission(schema: $schema, action: $action, objectOwner: $owner, object: $object);

		$this->assertSame($allowed, $verdict);
		$this->assertSame($refusals, $this->refusals);
	}//end testTheVerdictAndTheRefusalLog()
}//end class
