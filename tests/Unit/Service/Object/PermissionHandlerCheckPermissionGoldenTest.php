<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionCheckOptions;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * PermissionHandler::checkPermission() refuses exactly what hasPermission() answers false, for every caller,
 * owner, object, register and RBAC flag, and lets everything else through.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerCheckPermissionGoldenTest extends TestCase {

	private const GROUPS = ['alice' => ['editors'], 'bob' => ['users'], 'root' => ['admin']];

	private const BLOCKS = [
		'editors read'      => ['read' => ['editors'], 'update' => ['editors']],
		'public read'       => ['read' => ['public'], 'update' => ['editors']],
		'conditional'       => ['read' => [['group' => 'users', 'match' => ['status' => 'open']]], 'update' => ['admin']],
		'nothing for users' => ['create' => ['editors']],
	];

	private const EXTRAS = [[], ['userId' => 'bob'], ['objectOwner' => 'bob'], ['object' => 'open'], ['object' => 'closed'], ['register' => '7']];

	/**
	 * A handler whose session user is the given uid, or nobody.
	 *
	 * @param string|null $sessionUid The session user.
	 *
	 * @return PermissionHandler
	 */
	private function handler(?string $sessionUid): PermissionHandler {
		$users = [];
		foreach (array_keys(self::GROUPS) as $uid) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			$users[$uid] = $user;
		}

		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn(($users[($sessionUid ?? '')] ?? null));
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($users[$uid] ?? null));
		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(fn (IUser $user): array => self::GROUPS[$user->getUID()]);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'root');
		$matcher = $this->createStub(ConditionMatcher::class);
		$matcher->method('objectMatchesConditions')->willReturnCallback(
			fn (array $object, array $match): bool => (($object['status'] ?? null) === ($match['status'] ?? null))
		);
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);

		return PermissionHandlerFactory::create(
			$session,
			$userManager,
			$groupManager,
			$this->createStub(SchemaMapper::class),
			$this->createStub(MagicMapper::class),
			$matcher,
			$appConfig,
			new NullLogger(),
			$this->createStub(ContainerInterface::class)
		);
	}//end handler()

	/**
	 * Every case: block, action, session user and the optional arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$cases = [];
		foreach (array_keys(self::BLOCKS) as $block) {
			foreach (['read', 'update'] as $action) {
				foreach (['alice', 'bob', null] as $session) {
					foreach (self::EXTRAS as $i => $extra) {
						foreach (['rbac' => true, 'norbac' => false] as $flag => $rbac) {
							$name         = sprintf('%s|%s|%s|%d|%s', $block, $action, ($session ?? 'anon'), $i, $flag);
							$cases[$name] = ['block' => $block, 'action' => $action, 'session' => $session, 'extra' => $extra, 'rbac' => $rbac];
						}
					}
				}
			}
		}

		return $cases;
	}//end cases()

	/**
	 * The schema and object of one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array{0: Schema, 1: ObjectEntity|null}
	 */
	private static function subject(array $case): array {
		$schema = new Schema();
		$schema->setId(11);
		$schema->setTitle('Zaak');
		$schema->setAuthorization(self::BLOCKS[$case['block']]);

		$object = null;
		if (isset($case['extra']['object']) === true) {
			$object = new ObjectEntity();
			$object->setUuid('obj-' . $case['extra']['object']);
			$object->setOwner('carol');
			$object->setRegister('7');
			$object->setSchema('11');
			$object->setObject(['status' => $case['extra']['object']]);
		}

		return [$schema, $object];
	}//end subject()

	/**
	 * Whether checkPermission() let the case through.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return bool|string True when allowed, the refusal message when refused.
	 */
	private function checked(array $case): bool|string {
		[$schema, $object] = self::subject($case);
		try {
			$this->handler($case['session'])->checkPermission(
				schema: $schema,
				action: $case['action'],
				options: new PermissionCheckOptions(
					userId: ($case['extra']['userId'] ?? null),
					objectOwner: ($case['extra']['objectOwner'] ?? null),
					object: $object,
					register: ($case['extra']['register'] ?? null)
				),
				_rbac: $case['rbac']
			);
		} catch (NotAuthorizedException $e) {
			return $e->getMessage();
		}

		return true;
	}//end checked()

	/**
	 * What hasPermission() answers for the case.
	 *
	 * @param array<string, mixed> $case     The case.
	 * @param bool                 $withUser Whether the case's user id is passed along.
	 *
	 * @return bool
	 */
	private function answered(array $case, bool $withUser = true): bool {
		[$schema, $object] = self::subject($case);
		$userId            = null;
		if ($withUser === true) {
			$userId = ($case['extra']['userId'] ?? null);
		}

		return $this->handler($case['session'])->hasPermission(
			schema: $schema,
			action: $case['action'],
			userId: $userId,
			objectOwner: ($case['extra']['objectOwner'] ?? null),
			_rbac: $case['rbac'],
			object: $object,
			register: ($case['extra']['register'] ?? null)
		);
	}//end answered()

	public function testCheckPermissionRefusesExactlyWhatHasPermissionDenies(): void {
		$seen = ['allowed' => 0, 'refused' => 0];
		foreach (self::cases() as $name => $case) {
			$checked  = $this->checked($case);
			$answered = $this->answered($case);
			$this->assertSame($answered, $checked === true, $name);
			if ($checked !== true) {
				$this->assertStringContainsString("permission to '{$case['action']}' objects in schema 'Zaak'", $checked, $name);
			}

			$seen['allowed'] += (int) ($checked === true);
			$seen['refused'] += (int) ($checked !== true);
		}

		$this->assertGreaterThan(0, $seen['allowed']);
		$this->assertGreaterThan(0, $seen['refused']);
	}//end testCheckPermissionRefusesExactlyWhatHasPermissionDenies()

	public function testTheComparisonNoticesADroppedArgument(): void {
		$differs = 0;
		foreach (self::cases() as $case) {
			$differs += (int) ($this->answered($case, withUser: false) !== ($this->checked($case) === true));
		}

		$this->assertGreaterThan(0, $differs, 'dropping the user id must change at least one outcome');
	}//end testTheComparisonNoticesADroppedArgument()
}//end class
