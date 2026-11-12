<?php

/**
 * Characterisation: what occ openregister:tables:sync enumerates, reconciles and prints.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\TablesSyncCommand;
use OCA\OpenRegister\Service\ObjectSource\TablesCatalogueInterface;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaReconcilerInterface;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the disabled app, the empty user set, the admin users, --user and the summary line.
 */
class TablesSyncCommandTest extends TestCase {

	/**
	 * The Tables gateway double.
	 *
	 * @var TablesCatalogueInterface&MockObject
	 */
	private $reader;

	/**
	 * The reconciler double.
	 *
	 * @var TablesSchemaReconcilerInterface&MockObject
	 */
	private $reconciler;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private $groups;

	/**
	 * Wire fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reader = $this->createMock(TablesCatalogueInterface::class);
		$this->reconciler = $this->createMock(TablesSchemaReconcilerInterface::class);
		$this->groups = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * Run the command with the given input.
	 *
	 * @param array<string, mixed> $input The console input.
	 *
	 * @return array{0: int, 1: string} The exit code and the display.
	 */
	private function sync(array $input): array {
		$tester = new CommandTester(new TablesSyncCommand($this->reader, $this->reconciler, $this->groups));
		$code = $tester->execute($input);

		return [$code, $tester->getDisplay()];
	}//end sync()

	/**
	 * An admin group holding the given users.
	 *
	 * @param array<int, string> $uids The admin uids.
	 *
	 * @return void
	 */
	private function admins(array $uids): void {
		$users = [];
		foreach ($uids as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$this->groups->method('get')->with('admin')->willReturn($group);
	}//end admins()

	/**
	 * Without the Tables app nothing is enumerated.
	 *
	 * @return void
	 */
	public function testWithoutTheTablesAppNothingIsSynced(): void {
		$this->reader->method('isAvailable')->willReturn(false);
		$this->groups->expects($this->never())->method('get');
		$this->reconciler->expects($this->never())->method('reconcile');

		$this->assertSame([Command::SUCCESS, "Nextcloud Tables app is not enabled — nothing to sync.\n"], $this->sync([]));
	}//end testWithoutTheTablesAppNothingIsSynced()

	/**
	 * Without an admin group and without --user there is nobody to enumerate for.
	 *
	 * @return void
	 */
	public function testWithoutAdminsOrUserNothingIsSynced(): void {
		$this->reader->method('isAvailable')->willReturn(true);
		$this->groups->method('get')->with('admin')->willReturn(null);
		$this->reader->expects($this->never())->method('collectTableDescriptors');

		$this->assertSame([Command::SUCCESS, "No user to enumerate Tables for (pass --user=UID).\n"], $this->sync([]));
		$this->assertSame([Command::SUCCESS, "No user to enumerate Tables for (pass --user=UID).\n"], $this->sync(['--user' => '']));
	}//end testWithoutAdminsOrUserNothingIsSynced()

	/**
	 * The admins' tables are reconciled and the stats printed.
	 *
	 * @return void
	 */
	public function testTheAdminsTablesAreReconciled(): void {
		$this->reader->method('isAvailable')->willReturn(true);
		$this->admins(['admin', 'ops']);
		$this->reader->expects($this->once())->method('collectTableDescriptors')->with(['admin', 'ops'])->willReturn([['id' => 1], ['id' => 2], ['id' => 3]]);
		$this->reconciler->expects($this->once())->method('reconcile')->with([['id' => 1], ['id' => 2], ['id' => 3]])->willReturn(['seeded' => 2, 'retired' => 1, 'skipped' => 0]);

		$this->assertSame(
			[Command::SUCCESS, 'Reconciled the "tables" register: seeded=2, retired=1, skipped=0 (from 3 table(s)).' . "\n"],
			$this->sync([])
		);
	}//end testTheAdminsTablesAreReconciled()

	/**
	 * --user replaces the admins, who are still looked up first.
	 *
	 * @return void
	 */
	public function testTheUserOptionReplacesTheAdmins(): void {
		$this->reader->method('isAvailable')->willReturn(true);
		$this->groups->expects($this->once())->method('get')->with('admin')->willReturn(null);
		$this->reader->expects($this->once())->method('collectTableDescriptors')->with(['bob'])->willReturn([]);
		$this->reconciler->method('reconcile')->willReturn(['seeded' => 0, 'retired' => 0, 'skipped' => 0]);

		$this->assertSame(
			[Command::SUCCESS, 'Reconciled the "tables" register: seeded=0, retired=0, skipped=0 (from 0 table(s)).' . "\n"],
			$this->sync(['--user' => 'bob'])
		);
	}//end testTheUserOptionReplacesTheAdmins()
}//end class
