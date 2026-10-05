<?php

/**
 * occ openregister:schema:reset-to-shipped, over the real reset service and the real guard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\ResetSchemaToShippedCommand;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineResetService;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Preview by default, apply only as a named administrator.
 */
class ResetSchemaToShippedCommandTest extends TestCase {

	private const SELF_READ = ['group' => 'authenticated', 'match' => ['ncUserId' => '$userId']];

	/** @var array<int, string> */
	private array $actions = [];

	private int $writes = 0;

	/** The user the session currently answers with. */
	private ?IUser $active = null;

	private Schema $schema;

	/**
	 * The command over the real service and guard; the session follows setUser().
	 *
	 * @return CommandTester
	 */
	private function tester(): CommandTester {
		$this->schema = new Schema();
		$this->schema->setId(329);
		$this->schema->setSlug('learner-profile');
		$this->schema->setProperties(['level' => ['type' => 'string']]);
		$this->schema->setRequired([]);
		$this->schema->setAuthorization(['read' => ['compliance-officers', 'hr', 'instructors']]);

		$store = $this->createMock(ShippedBaselineStore::class);
		$store->method('schemaSubject')->willReturnCallback(static fn (string $slug): string => ('schema:' . $slug));
		$store->method('read')->willReturn(
			[
				'definition' => [
					'properties' => ['level' => ['type' => 'string']],
					'required' => [],
					'authorization' => ['read' => ['instructors', 'hr', 'compliance-officers', self::SELF_READ]],
				],
				'app' => 'learniq',
				'appVersion' => '1.4.0',
				'recordedAt' => '2026-10-05T08:22:30+00:00',
			]
		);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				foreach ($entries as $entry) {
					$this->actions[] = $entry->getAction() . ' by ' . $entry->getUser();
				}

				return $entries;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->active);
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->active = $user;
			}
		);

		$parts = new DescriptorParts();
		$comparator = new DivergenceComparator(parts: $parts);
		$guard = new ShippedConfigurationGuard(
			baselines: $store,
			merge: new GuardedDescriptorMerge(parts: $parts, comparator: $comparator),
			comparator: $comparator,
			audit: $audit,
			session: $session,
			logger: $this->createMock(LoggerInterface::class)
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($this->schema);
		$schemas->method('update')->willReturnCallback(
			function (Schema $entity): Schema {
				$this->writes++;
				return $entity;
			}
		);

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$admin->method('getDisplayName')->willReturn('Admin');
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnMap([['admin', $admin], ['alice', $alice]]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => ($uid === 'admin'));

		$command = new ResetSchemaToShippedCommand(
			reset: new ShippedBaselineResetService(schemas: $schemas, guard: $guard),
			users: $users,
			groups: $groups,
			session: $session
		);

		return new CommandTester($command);
	}//end tester()

	/**
	 * Without --apply it shows both values and writes nothing.
	 *
	 * @return void
	 */
	public function testWithoutApplyItOnlyShowsTheChange(): void {
		$tester = $this->tester();
		$code = $tester->execute(['schema' => 'learner-profile', 'part' => 'authorization.read']);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('"ncUserId":"$userId"', $tester->getDisplay());
		$this->assertStringContainsString('Nothing written', $tester->getDisplay());
		$this->assertSame(0, $this->writes);
		$this->assertSame([], $this->actions);
	}//end testWithoutApplyItOnlyShowsTheChange()

	/**
	 * --apply without an administrator named is refused.
	 *
	 * @return void
	 */
	public function testApplyWithoutAnAdministratorIsRefused(): void {
		$tester = $this->tester();
		$this->assertSame(1, $tester->execute(['schema' => 'learner-profile', 'part' => 'authorization.read', '--apply' => true]));
		$this->assertSame(1, $tester->execute(['schema' => 'learner-profile', 'part' => 'authorization.read', '--apply' => true, '--actor' => 'alice']));
		$this->assertSame(0, $this->writes);
		$this->assertSame([], $this->actions);
	}//end testApplyWithoutAnAdministratorIsRefused()

	/**
	 * --apply as an administrator restores the rule, records the actor, and leaves no session behind.
	 *
	 * @return void
	 */
	public function testApplyAsAnAdministratorResetsAndRecords(): void {
		$tester = $this->tester();
		$code = $tester->execute(['schema' => 'learner-profile', 'part' => 'authorization.read', '--apply' => true, '--actor' => 'admin']);

		$this->assertSame(0, $code, $tester->getDisplay());
		$this->assertSame(1, $this->writes);
		$this->assertContains(self::SELF_READ, $this->schema->getAuthorization()['read']);
		$this->assertSame([ShippedConfigurationGuard::ACTION_RESET . ' by admin'], $this->actions);
		$this->assertNull($this->active);
	}//end testApplyAsAnAdministratorResetsAndRecords()
}//end class
