<?php

/**
 * Characterization tests for NotificationRecipientResolver::resolveWithDiagnostics().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Notification\NotificationRecipientResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the uids and unresolved entries for a corpus of recipient specs that
 * reaches every recipient kind. Expected results live in
 * `fixtures/notification-recipient-resolver-golden.json`.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Notification\NotificationRecipientResolver
 */
class NotificationRecipientResolverCharacterizationTest extends TestCase {

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/notification-recipient-resolver-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => [spec, withObject, roleGroups].
	 *
	 * @return array<string, array{spec: array<int, mixed>, object?: bool, roleGroups?: array<string, mixed>}>
	 */
	public static function corpus(): array {
		return [
			'empty'             => ['spec' => []],
			'not-arrays'        => ['spec' => ['anna', 5, null]],
			'users'             => ['spec' => [['kind' => 'users', 'users' => ['anna', 'nobody', '', 7, 'bram', 'anna']]]],
			'users-scalar'      => ['spec' => [['kind' => 'users', 'users' => 'chris']]],
			'users-missing'     => ['spec' => [['kind' => 'users']]],
			'field'             => ['spec' => [['kind' => 'field', 'field' => 'assignee'], ['kind' => 'field', 'field' => 'ghost'], ['kind' => 'field', 'field' => 'empty'], ['kind' => 'field', 'field' => 'num'], ['kind' => 'field']]],
			'relation'          => ['spec' => [['kind' => 'relation', 'relation' => 'rel'], ['kind' => 'relation', 'relation' => 'relOne'], ['kind' => 'relation', 'relation' => ''], ['kind' => 'relation', 'relation' => 'missing']]],
			'watchers-no-object' => ['spec' => [['watchers' => true], ['kind' => 'watchers'], ['watchers' => 'yes', 'kind' => 'users', 'users' => ['anna']]]],
			'watchers-object'   => ['spec' => [['watchers' => true]], 'object' => true],
			'acl-no-object'     => ['spec' => [['kind' => 'object-acl']]],
			'acl-read'          => ['spec' => [['kind' => 'object-acl']], 'object' => true],
			'acl-manage'        => ['spec' => [['kind' => 'object-acl', 'permission' => 'manage']], 'object' => true],
			'expression'        => ['spec' => [['kind' => 'expression', 'resolver' => 'X']], 'object' => true],
			'expression-no-obj' => ['spec' => [['kind' => 'expression', 'resolver' => 'X']]],
			'groups'            => ['spec' => [['kind' => 'groups', 'groups' => ['team', 'gone', '', 3, 'quiet', 'broken', 'team']]]],
			'groups-scalar'     => ['spec' => [['kind' => 'groups', 'groups' => 'team']]],
			'role'              => ['spec' => [['kind' => 'role', 'role' => 'clerk'], ['kind' => 'role', 'role' => 'boss'], ['kind' => 'role'], ['kind' => 'role', 'role' => 'odd']], 'roleGroups' => ['clerk' => ['team', 'gone', 7], 'odd' => 'team']],
			'unknown-kind'      => ['spec' => [['kind' => 'robots'], []]],
			'mixed-dedupe'      => ['spec' => [['kind' => 'users', 'users' => ['bram']], ['kind' => 'groups', 'groups' => ['team']], ['kind' => 'field', 'field' => 'assignee'], ['kind' => 'role', 'role' => 'clerk']], 'roleGroups' => ['clerk' => ['gone']]],
		];
	}//end corpus()

	/**
	 * Resolve one corpus entry.
	 *
	 * @param array<string, mixed> $case The corpus entry.
	 *
	 * @return array<string, mixed>
	 */
	public function observe(array $case): array {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['anna', 'bram', 'chris', 'dirk', 'owner'], true));
		$userManager->method('get')->willReturnCallback(function (string $uid) {
			if (in_array($uid, ['anna', 'bram', 'chris', 'dirk', 'owner'], true) === false) {
				return null;
			}

			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			return $user;
		});
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(function (string $gid): ?IGroup {
			if ($gid === 'broken') {
				throw new \RuntimeException('LDAP is down');
			}

			$members = ['team' => ['chris', 'dirk'], 'quiet' => [], 'readers' => ['dirk']][$gid] ?? null;
			if ($members === null) {
				return null;
			}

			$users = [];
			foreach ($members as $uid) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$users[] = $user;
			}

			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn($users);
			return $group;
		});

		$resolver = new NotificationRecipientResolver(
			userManager: $userManager,
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class)
		);

		$object = null;
		if (($case['object'] ?? false) === true) {
			$object = new ObjectEntity();
			$object->setUuid('11111111-1111-1111-1111-111111111111');
			$object->setOwner('owner');
			$object->setGroups(['readers', 'gone']);
		}

		try {
			return $resolver->resolveWithDiagnostics(
				recipientsSpec: $case['spec'],
				data: ['assignee' => 'anna', 'ghost' => 'nobody', 'empty' => '', 'num' => 5, 'rel' => ['bram', 'nobody'], 'relOne' => 'chris'],
				object: $object,
				context: ['trigger' => 'created'],
				roleGroups: ($case['roleGroups'] ?? [])
			);
		} catch (\Throwable $e) {
			return ['exception' => get_class($e), 'message' => $e->getMessage()];
		}
	}//end observe()

	/**
	 * Data provider over the corpus.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function corpusProvider(): array {
		$rows = [];
		foreach (array_keys(self::corpus()) as $name) {
			$rows[$name] = [$name];
		}

		return $rows;
	}//end corpusProvider()

	/**
	 * resolveWithDiagnostics() returns exactly what was recorded.
	 *
	 * @param string $name The corpus entry.
	 *
	 * @return void
	 */
	#[DataProvider('corpusProvider')]
	public function testResolveWithDiagnosticsMatchesGolden(string $name): void {
		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);

		$this->assertSame($golden[$name], $this->observe(self::corpus()[$name]));
	}//end testResolveWithDiagnosticsMatchesGolden()

	/**
	 * Regenerates the golden file when OPENREGISTER_WRITE_GOLDEN is set; otherwise checks it has no orphans.
	 *
	 * @return void
	 */
	public function testGoldenCoversTheCorpus(): void {
		if (getenv('OPENREGISTER_WRITE_GOLDEN') === '1') {
			$out = [];
			foreach (self::corpus() as $name => $case) {
				$out[$name] = $this->observe($case);
			}

			file_put_contents(self::goldenPath(), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertSame(array_keys(self::corpus()), array_keys($golden));
	}//end testGoldenCoversTheCorpus()
}//end class
