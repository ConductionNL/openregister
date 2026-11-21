<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A `referrers` recipient: direct referrers and referrers through `of`, the
 * nested block resolved against each, the 200 cap recorded, and a reached
 * user without read on the triggering object left out.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-address-the-people-behind-referring-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper\MagicRelationReaderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Notifications\Service\Notification\ReadableRecipients;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientSource;
use OCA\OpenRegister\Notifications\Service\Notification\ReferrerRecipients;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(ReferrerRecipients::class)]
#[CoversClass(NotificationRecipientResolver::class)]
#[UsesClass(ReadableRecipients::class)]
#[UsesClass(RecipientSource::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Schema::class)]
#[UsesClass(Register::class)]
class NotificationRecipientResolverReferrersTest extends TestCase {

	/**
	 * The relation lookups asked, as [uuids, schemaId, registerId, fieldName].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $asked = [];

	private function object(string $uuid, array $data, string $owner = ''): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('5');
		$object->setOwner($owner);
		$object->setObject($data);

		return $object;
	}

	/**
	 * A resolver whose referrers lookup returns these rows per referred uuid.
	 *
	 * @param array<string, array<int, ObjectEntity>> $rowsByUuid The referring rows per referred uuid.
	 * @param array<int, string>                      $nonReaders Users without read on the triggering object.
	 *
	 * @return NotificationRecipientResolver
	 */
	private function resolver(array $rowsByUuid, array $nonReaders = []): NotificationRecipientResolver {
		$register = new Register();
		$register->setId(3);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(static function (string|int $id) use ($register): Register {
			if ($id !== 'softwarecatalogus') {
				throw new RuntimeException('no register '.$id);
			}

			return $register;
		});

		$usage = new Schema();
		$usage->setId(7);
		$version = new Schema();
		$version->setId(5);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(static fn (string|int $id): Schema => match ((string)$id) {
			'usage' => $usage,
			'5' => $version,
			default => throw new RuntimeException('no schema '.$id),
		});

		$relations = $this->createMock(MagicRelationReaderInterface::class);
		$relations->method('findByRelationBatchInSchema')->willReturnCallback(
			function (array $uuids, int $schemaId, int $registerId, string $fieldName) use ($rowsByUuid): array {
				$this->asked[] = [$uuids, $schemaId, $registerId, $fieldName];
				return ($rowsByUuid[$uuids[0]] ?? []);
			}
		);

		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			static fn (Schema $schema, string $action, ?string $userId = null): bool => $action === 'read'
				&& (int)$schema->getId() === 5
				&& in_array($userId, $nonReaders, true) === false
		);

		$referrers = new ReferrerRecipients(
			registers: $registers,
			schemas: $schemas,
			relations: $relations,
			readable: new ReadableRecipients(logger: new NullLogger(), permissions: $permissions),
			logger: new NullLogger()
		);
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => match ($id) {
			ReferrerRecipients::class => $referrers,
			default => throw new RuntimeException('no service '.$id),
		});

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);

		return new NotificationRecipientResolver(
			userManager: $users,
			groupManager: $this->createMock(IGroupManager::class),
			logger: new NullLogger(),
			serverContainer: $container
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function entry(array $extra = [], array $nested = [['kind' => 'field', 'field' => 'contact']]): array {
		return array_merge(['kind' => 'referrers', 'register' => 'softwarecatalogus', 'schema' => 'usage', 'property' => 'module', 'recipients' => $nested], $extra);
	}

	private function source(ObjectEntity $object): RecipientSource {
		return new RecipientSource(data: ($object->getObject() ?? []), object: $object, context: [], roleGroups: []);
	}

	public function testTheObjectsReferringToTheTriggeringOneAreReached(): void {
		$module = $this->object('mod-1', ['name' => 'Zaaksysteem X']);
		$resolver = $this->resolver([
			'mod-1' => [
				$this->object('u-1', ['module' => 'mod-1', 'contact' => 'alice']),
				$this->object('u-2', ['module' => ['mod-1', 'mod-9'], 'contact' => 'bob']),
				$this->object('u-3', ['module' => 'mod-9', 'contact' => 'mallory']),
			],
		]);

		$resolved = $resolver->resolveFromSource([$this->entry()], $this->source($module));

		$this->assertSame(['alice', 'bob'], $resolved['uids']);
		$this->assertSame([], $resolved['unresolved']);
		$this->assertSame([[['mod-1'], 7, 3, 'module']], $this->asked);
	}

	public function testOfReachesTheObjectsReferringToWhatTheTriggeringObjectPointsAt(): void {
		$version = $this->object('v-2', ['module' => 'mod-1', 'version' => '2.0']);
		$resolver = $this->resolver([
			'mod-1' => [$this->object('u-1', ['module' => 'mod-1'], owner: 'gemeente-a'), $this->object('u-2', ['module' => 'mod-1'], owner: 'gemeente-b')],
		]);

		$resolved = $resolver->resolveFromSource(
			[$this->entry(['of' => 'module'], [['kind' => 'object-acl', 'permission' => 'read']])],
			$this->source($version)
		);

		$this->assertSame(['gemeente-a', 'gemeente-b'], $resolved['uids']);
		$this->assertSame(['mod-1'], $this->asked[0][0]);
	}

	public function testAReachedUserWithoutReadOnTheTriggeringObjectIsLeftOut(): void {
		$module = $this->object('mod-1', []);
		$resolver = $this->resolver(
			['mod-1' => [$this->object('u-1', ['module' => 'mod-1', 'contact' => 'alice']), $this->object('u-2', ['module' => 'mod-1', 'contact' => 'eve'])]],
			nonReaders: ['eve']
		);

		$this->assertSame(['alice'], $resolver->resolveFromSource([$this->entry()], $this->source($module))['uids']);
	}

	public function testPastTwoHundredReferrersTheRuleSaysItStopped(): void {
		$rows = [];
		for ($i = 0; $i < 205; $i++) {
			$rows[] = $this->object('u-'.$i, ['module' => 'mod-1', 'contact' => 'user'.$i]);
		}

		$resolved = $this->resolver(['mod-1' => $rows])->resolveFromSource([$this->entry()], $this->source($this->object('mod-1', [])));

		$this->assertCount(200, $resolved['uids']);
		$this->assertSame('user199', $resolved['uids'][199]);
		$this->assertSame(
			[['kind' => 'referrers', 'id' => 'referrers-truncated', 'reason' => 'stopped at 200 of 205 referring objects']],
			$resolved['unresolved']
		);
	}

	public function testAnOfPropertyContributesAtMostTenReferredObjects(): void {
		$targets = [];
		for ($i = 0; $i < 12; $i++) {
			$targets[] = 'mod-'.$i;
		}

		$this->resolver([])->resolveFromSource([$this->entry(['of' => 'modules'])], $this->source($this->object('v-1', ['modules' => $targets])));

		$this->assertCount(10, $this->asked);
		$this->assertSame(['mod-9'], $this->asked[9][0]);
	}

	public function testAnUnknownRegisterResolvesNobodyAndSaysSo(): void {
		$resolved = $this->resolver([])->resolveFromSource(
			[$this->entry(['register' => 'nowhere'])],
			$this->source($this->object('mod-1', []))
		);

		$this->assertSame([], $resolved['uids']);
		$this->assertSame([['kind' => 'referrers', 'id' => 'nowhere/usage', 'reason' => 'register or schema not found']], $resolved['unresolved']);
	}

	public function testWithoutTheReferrersLookupTheEntryReachesNobody(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$resolver = new NotificationRecipientResolver(userManager: $users, groupManager: $this->createMock(IGroupManager::class), logger: new NullLogger());

		$resolved = $resolver->resolveFromSource([$this->entry()], $this->source($this->object('mod-1', [])));

		$this->assertSame([], $resolved['uids']);
		$this->assertSame('referrers lookup unavailable', $resolved['unresolved'][0]['reason']);
	}
}//end class
